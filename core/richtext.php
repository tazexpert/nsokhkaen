<?php
/**
 * ข้อความจัดรูปแบบ (Quill 2) ใช้ร่วมกัน: สมุดบันทึก, กล่องจดหมาย
 * - ฝั่งเบราว์เซอร์: richtext_assets() + assets/js/richtext.js → richEditor('#id', {...}) · ป้ายภาษาไทยใน assets/css/richtext.css
 * - ฝั่งเซิร์ฟเวอร์: richtext_clean() กรอง HTML ให้เหลือเฉพาะรูปแบบที่ตัวแก้ไขใช้ (ทั้งก่อนบันทึกและก่อนแสดง)
 * - แสดงผล: richtext_view() (ต้องโหลด richtext_assets() เพื่อให้รายการ/เช็กลิสต์แสดงถูกต้อง)
 */

const QUILL_CDN = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/';

/** แท็ก <link>/<script> สำหรับ $headExtra — $editor = false ถ้าใช้แค่แสดงผล */
function richtext_assets(bool $editor = true): string
{
    $out = '<link href="' . QUILL_CDN . 'quill.snow.css" rel="stylesheet">'
         . '<link href="' . h(asset_v('assets/css/richtext.css')) . '" rel="stylesheet">';
    if ($editor) {
        $out .= '<script src="' . QUILL_CDN . 'quill.js"></script><script src="' . h(asset_v('assets/js/richtext.js')) . '"></script>';
    }
    return $out;
}

/** แสดงเนื้อหา (ผ่านตัวกรอง) ด้วยสไตล์เดียวกับตัวแก้ไข */
function richtext_view(string $text, string $class = ''): string
{
    return '<div class="ql-snow"><div class="ql-editor richtext-view ' . h($class) . '">' . richtext_clean(richtext_from_text($text)) . '</div></div>';
}


/** ข้อความล้วน (บันทึก/ข้อความแบบเดิม) → HTML ย่อหน้า (ที่เป็น HTML อยู่แล้วคืนค่าเดิม) */
function richtext_from_text(string $text): string
{
    if ($text === '' || ltrim($text)[0] === '<') {
        return $text;
    }
    $out = '';
    foreach (explode("\n", $text) as $line) {
        $out .= '<p>' . ($line === '' ? '<br>' : h($line)) . '</p>';
    }
    return $out;
}

/**
 * กรอง HTML ให้เหลือเฉพาะแท็ก/แอตทริบิวต์ที่ตัวแก้ไขใช้ (กันสคริปต์แฝง) — ใช้ทั้งก่อนบันทึกและก่อนแสดงผล
 * คืน '' ถ้าไม่มีเนื้อหา
 */
function richtext_clean(string $html): string
{
    if (trim($html) === '') {
        return '';
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) {
        return '';
    }
    richtext_clean_node($root);
    $out = '';
    foreach ($root->childNodes as $c) {
        $out .= $doc->saveHTML($c);
    }
    if (trim(html_entity_decode(strip_tags($out), ENT_QUOTES, 'UTF-8')) === '' && stripos($out, '<li') === false) {
        return '';
    }
    return $out;
}

function richtext_clean_node(DOMNode $node): void
{
    static $allowed = ['p', 'br', 'h1', 'h2', 'h3', 'strong', 'b', 'em', 'i', 'u', 's', 'span', 'a', 'ol', 'ul', 'li', 'blockquote', 'sub', 'sup'];
    static $drop = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form', 'input', 'textarea', 'button', 'select', 'link', 'meta', 'base', 'img', 'video', 'audio'];
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMElement) {
            $tag = strtolower($child->tagName);
            if (in_array($tag, $drop, true)) {
                $node->removeChild($child);
                continue;
            }
            richtext_clean_node($child);
            if (!in_array($tag, $allowed, true)) {         // แท็กอื่น: เก็บเนื้อหาข้างใน ตัดแท็กทิ้ง
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            richtext_clean_attrs($child, $tag);
        } elseif (!($child instanceof DOMText)) {           // คอมเมนต์ / อื่น ๆ
            $node->removeChild($child);
        }
    }
}

function richtext_clean_attrs(DOMElement $el, string $tag): void
{
    $keep = [];
    foreach (iterator_to_array($el->attributes) as $attr) {
        $name = strtolower($attr->name);
        $val = trim($attr->value);
        if ($name === 'class') {
            $cls = array_filter(preg_split('/\s+/', $val), fn($c) => preg_match('/^ql-(align-(center|right|justify)|indent-[1-8]|ui)$/', $c));
            if ($cls) {
                $keep['class'] = implode(' ', $cls);
            }
        } elseif ($name === 'style') {
            $css = [];
            foreach (explode(';', $val) as $decl) {
                if (preg_match('/^\s*(color|background-color)\s*:\s*(#[0-9a-f]{3,6}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\))\s*$/i', $decl, $m)) {
                    $css[] = strtolower($m[1]) . ': ' . $m[2];
                }
            }
            if ($css) {
                $keep['style'] = implode('; ', $css) . ';';
            }
        } elseif ($name === 'href' && $tag === 'a' && preg_match('~^(https?://|mailto:|tel:)~i', $val)) {
            $keep['href'] = $val;
        } elseif ($name === 'data-list' && $tag === 'li' && in_array($val, ['bullet', 'ordered', 'checked', 'unchecked'], true)) {
            $keep['data-list'] = $val;
        }
    }
    while ($el->attributes->length) {
        $el->removeAttribute($el->attributes->item(0)->name);
    }
    foreach ($keep as $k => $v) {
        $el->setAttribute($k, $v);
    }
    if ($tag === 'a' && isset($keep['href'])) {
        $el->setAttribute('target', '_blank');
        $el->setAttribute('rel', 'noopener noreferrer');
    }
    if ($tag === 'span' && ($keep['class'] ?? '') === 'ql-ui') {
        $el->setAttribute('contenteditable', 'false');
    }
}
