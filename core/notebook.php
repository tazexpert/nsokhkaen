<?php
/**
 * สมุดบันทึกส่วนตัว: เข้ารหัส/ถอดรหัสเนื้อหา (AES-256-GCM)
 * - กุญแจหลัก: ค่าคงที่ NOTEBOOK_KEY (base64 ของ 32 ไบต์) ใน config.local.php ถ้ามี
 *   ไม่เช่นนั้นสร้างอัตโนมัติครั้งแรกที่ storage/notebook_key.php (ไม่อยู่ใน git · เป็นไฟล์ .php จึงเปิดผ่านเว็บไม่เห็นค่า)
 * - แต่ละคนใช้กุญแจย่อยของตัวเอง (HMAC จากกุญแจหลัก + id ผู้ใช้) → ข้อความในฐานข้อมูลอ่านไม่ออก และสลับแถวข้ามคนไม่ได้
 * - ถ้ากุญแจหลักหาย จะเปิดบันทึกเดิมไม่ได้อีก → ต้องสำรองไฟล์กุญแจไว้คู่กับการสำรองฐานข้อมูล (แต่เก็บแยกที่กัน)
 */

const NOTEBOOK_KEY_FILE = APP_ROOT . '/storage/notebook_key.php';

function notebook_master_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('เซิร์ฟเวอร์ไม่ได้เปิดใช้ PHP openssl (php.ini: extension=openssl)');
    }
    if (defined('NOTEBOOK_KEY')) {
        $raw = base64_decode((string)NOTEBOOK_KEY, true);
    } else {
        if (!is_file(NOTEBOOK_KEY_FILE)) {
            if (!is_dir(dirname(NOTEBOOK_KEY_FILE))) {
                mkdir(dirname(NOTEBOOK_KEY_FILE), 0700, true);
            }
            $fh = @fopen(NOTEBOOK_KEY_FILE, 'x');      // 'x' = สร้างใหม่เท่านั้น (กันสองคำขอสร้างกุญแจทับกัน)
            if ($fh) {
                fwrite($fh, "<?php\n// กุญแจเข้ารหัสสมุดบันทึก — ห้ามลบ/แก้ไข และต้องสำรองไว้ (ถ้าหาย บันทึกเดิมจะเปิดไม่ได้)\nreturn '"
                    . base64_encode(random_bytes(32)) . "';\n");
                fclose($fh);
            }
        }
        $raw = base64_decode((string)(include NOTEBOOK_KEY_FILE), true);
    }
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('กุญแจเข้ารหัสสมุดบันทึกไม่ถูกต้อง');
    }
    return $key = $raw;
}

function notebook_user_key(int $userId): string
{
    return hash_hmac('sha256', 'notebook-user:' . $userId, notebook_master_key(), true);
}

function notebook_encrypt(string $plain, int $userId): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', notebook_user_key($userId), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('เข้ารหัสไม่สำเร็จ');
    }
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

/** คืน null ถ้าถอดรหัสไม่ได้ (กุญแจไม่ตรง / ข้อมูลเสียหาย) */
function notebook_decrypt(string $stored, int $userId): ?string
{
    if (strncmp($stored, 'v1:', 3) !== 0 || ($bin = base64_decode(substr($stored, 3), true)) === false || strlen($bin) < 28) {
        return null;
    }
    $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', notebook_user_key($userId), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
    return $plain === false ? null : $plain;
}

/* ---------- รูปแบบข้อความ (ตัวหนา หัวข้อ รายการ ฯลฯ จาก Quill) ---------- */

/** บันทึกแบบเดิมที่เป็นข้อความล้วน → HTML ย่อหน้า (บันทึกที่เป็น HTML อยู่แล้วคืนค่าเดิม) */
function notebook_to_html(string $text): string
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
function notebook_clean_html(string $html): string
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
    notebook_clean_node($root);
    $out = '';
    foreach ($root->childNodes as $c) {
        $out .= $doc->saveHTML($c);
    }
    if (trim(html_entity_decode(strip_tags($out), ENT_QUOTES, 'UTF-8')) === '' && stripos($out, '<li') === false) {
        return '';
    }
    return $out;
}

function notebook_clean_node(DOMNode $node): void
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
            notebook_clean_node($child);
            if (!in_array($tag, $allowed, true)) {         // แท็กอื่น: เก็บเนื้อหาข้างใน ตัดแท็กทิ้ง
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            notebook_clean_attrs($child, $tag);
        } elseif (!($child instanceof DOMText)) {           // คอมเมนต์ / อื่น ๆ
            $node->removeChild($child);
        }
    }
}

function notebook_clean_attrs(DOMElement $el, string $tag): void
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
