<?php
/**
 * อ่าน "รายงานการขอเบิกเงินคงคลัง" จากระบบ GFMIS (PDF เช่น ขบ02 - ขอเบิกเงินงบประมาณที่ไม่อ้างใบสั่งซื้อฯ)
 *   เบราว์เซอร์อ่านข้อความด้วย pdf.js แล้วจัดเป็นบรรทัด → ส่งมาเป็น [[ช่อง, ช่อง, ...], ...] (ดู spend_import.php)
 *   หัวเอกสาร: เลขที่เอกสาร "2027 - 3600000324" · วันที่เอกสาร / วันที่ผ่านรายการ (dd/mm/พ.ศ.) · สถานะ · ประเภทเอกสาร
 *   รายการ: เฉพาะบรรทัด "เดบิต" (บรรทัดเครดิต = หน่วยเบิกจ่าย ไม่นำมาใช้) — รหัส/ชื่อบัญชี · จำนวนเงินที่ขอเบิก
 *     บรรทัดถัดไป: แหล่งเงินทุน (7011220 ค่าใช้สอย /งบส่วนราชการ) · รหัสงบประมาณ + ชื่อ · กิจกรรมหลัก · ข้อความในรายการ
 */

/** "26/10/2569" → "2026-10-26" (null = ไม่ถูกต้อง) */
function gfmis_date(string $s): ?string
{
    if (!preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
        return null;
    }
    $y = (int)$m[3] > 2400 ? (int)$m[3] - 543 : (int)$m[3];
    return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
}

function gfmis_money(string $s): ?float
{
    return preg_match('/^-?[\d,]+\.\d{2}$/', trim($s)) ? (float)str_replace(',', '', trim($s)) : null;
}

/**
 * $lines = [[cell, ...], ...] → ['doc_no', 'doc_year', 'date', 'post_date', 'status', 'doc_type', 'form', 'debits' => [...], 'errors' => [...]]
 * debits: ['line', 'account', 'account_name', 'amount', 'source', 'budget_code', 'budget_name', 'activity', 'text']
 */
function gfmis_parse(array $lines): array
{
    $r = ['doc_no' => '', 'doc_year' => '', 'date' => null, 'post_date' => null, 'status' => '', 'doc_type' => '', 'form' => '', 'debits' => [], 'errors' => []];
    $cells = array_values(array_map(fn($l) => array_values(array_filter(array_map(fn($c) => trim(preg_replace('/\s+/u', ' ', (string)$c)), (array)$l), fn($c) => $c !== '')), $lines));
    $value = function (array $cs, string $label): ?string {        // ช่องถัดจากหัวข้อ เช่น "เลขที่เอกสาร" → ": 2027 - 3600000324"
        foreach ($cs as $i => $c) {
            if ($c === $label && isset($cs[$i + 1])) {
                return trim(ltrim($cs[$i + 1], ': '));
            }
            if (str_starts_with($c, $label . ' :') || str_starts_with($c, $label . ':')) {
                return trim(ltrim(substr($c, strlen($label)), ' :'));
            }
        }
        return null;
    };
    $cur = null;
    foreach ($cells as $cs) {
        if (!$cs) {
            continue;
        }
        $joined = implode(' ', $cs);
        if (preg_match('/^ขบ\d+/u', $cs[0])) {
            $r['form'] = $cs[0];
        }
        if (($v = $value($cs, 'เลขที่เอกสาร')) !== null && preg_match('/(\d{4})\s*-\s*(\d+)/', $v, $m)) {
            [$r['doc_year'], $r['doc_no']] = [$m[1], $m[2]];
        }
        if (($v = $value($cs, 'วันที่เอกสาร')) !== null) {
            $r['date'] = gfmis_date($v);
        }
        if (($v = $value($cs, 'วันที่ผ่านรายการ')) !== null) {
            $r['post_date'] = gfmis_date($v);
        }
        if (($v = $value($cs, 'สถานะ')) !== null) {
            $r['status'] = $v;
        }
        if (($v = $value($cs, 'ประเภทเอกสาร')) !== null) {
            $r['doc_type'] = $v;
        }
        if (str_starts_with($cs[0], 'หมายเหตุ') || str_starts_with($cs[0], 'ผู้บันทึก')) {
            $cur = null;
            continue;
        }
        // บรรทัดรายการ: "2" "เดบิต" "5101020106 เงินสมทบกองทุนประกัน" "10,500.00" … / "1" "เครดิต A110500036 …"
        if (preg_match('/^\d{1,3}$/', $cs[0]) && isset($cs[1]) && preg_match('/^(เดบิต|เครดิต)\s*(.*)$/u', $cs[1], $m)) {
            $cur = null;
            if ($m[1] !== 'เดบิต') {
                continue;
            }
            $rest = array_slice($cs, 2);
            if ($m[2] !== '') {
                array_unshift($rest, $m[2]);
            }
            $acc = $rest[0] ?? '';
            $amount = null;
            foreach (array_slice($rest, 1) as $c) {
                if (($amount = gfmis_money($c)) !== null) {
                    break;
                }
            }
            preg_match('/^(\S+)\s*(.*)$/u', $acc, $am);
            $r['debits'][] = ['line' => (int)$cs[0], 'account' => $am[1] ?? '', 'account_name' => $am[2] ?? '', 'amount' => $amount,
                              'source' => '', 'budget_code' => '', 'budget_name' => '', 'activity' => '', 'text' => ''];
            $cur = count($r['debits']) - 1;
            continue;
        }
        if ($cur === null) {
            continue;
        }
        $d = &$r['debits'][$cur];
        // ชื่อบัญชีขึ้นบรรทัดใหม่ เช่น "เงินสมทบกองทุนประกัน" + "สังคม" (อยู่หน้าหน่วยต้นทุน 10 หลักในบรรทัดเดียวกัน)
        if ($d['source'] === '' && count($cs) > 1 && !preg_match('/^\d/', $cs[0]) && array_filter($cs, fn($c) => preg_match('/^\d{10}$/', $c))) {
            $d['account_name'] .= array_shift($cs);
        }
        $rest = [];
        foreach ($cs as $c) {
            if (preg_match('/^\d{10}$/', $c)) {                                   // หน่วยต้นทุน
                continue;
            }
            if (preg_match('/^(\d{7})\s+(.+)$/u', $c, $m) && $d['source'] === '') {   // แหล่งเงินทุน
                $d['source'] = $m[1] . ' ' . $m[2];
                continue;
            }
            if (preg_match('/^\d{20}$/', $c)) {                                   // รหัสงบประมาณ (ชื่ออยู่ช่องถัดไป)
                $d['budget_code'] = $c;
                continue;
            }
            if ($d['budget_code'] !== '' && $d['budget_name'] === '' && str_starts_with($c, 'รายการงบ')) {
                $d['budget_name'] = $c;
                continue;
            }
            if (preg_match('/^\d{17}\s+(.+)$/u', $c, $m)) {                       // กิจกรรมหลัก
                $d['activity'] = $m[1];
                continue;
            }
            if (preg_match('/^(\d{15}|P\d+)$/', $c) || $c === 'ขอนแก่น') {        // กิจกรรมย่อย / รหัสอื่น
                continue;
            }
            $rest[] = $c;
        }
        if ($rest) {
            if ($d['budget_code'] !== '') {
                $d['text'] = trim($d['text'] . ' ' . implode(' ', $rest));         // ข้อความในรายการ
            }
        }
        unset($d);
    }
    if ($r['doc_no'] === '') {
        $r['errors'][] = 'ไม่พบเลขที่เอกสาร — ไม่ใช่รายงานการขอเบิกเงินคงคลังจาก GFMIS';
    }
    if (!$r['debits']) {
        $r['errors'][] = 'ไม่พบรายการเดบิต';
    }
    return $r;
}

/** ข้อความสำหรับจับคู่ (ตัดช่องว่าง / วงเล็บ / จุด) */
function gfmis_norm(string $s): string
{
    return preg_replace('/[\s().\/\-]+/u', '', $s);
}

/**
 * แนะนำหมวด (src) แผนงาน งานและโครงการ จาก ข้อความในรายการ / ชื่อบัญชี / แหล่งเงินทุน
 *   1) ประวัติ: รายการเบิกจ่ายเดิมที่ "รายการ" เหมือนกัน (ตัด "เดือน…" ออก) → ใช้หมวด/แผนงานเดิม
 *   2) ชื่อค่าใช้จ่ายของหมวด (bud_items.activity_name) ที่อยู่ในข้อความ — ยาวที่สุด
 * คืน ['src' => 'i:<id>'|'', 'plan_id' => int|null, 'work_id' => int|null, 'how' => 'history'|'name'|'']
 */
function gfmis_suggest(array $debit, string $desc, array $sources): array
{
    static $hist = null;
    $key = fn(string $s) => gfmis_norm(preg_replace('/\s*(ประจำ)?เดือน\s*\S+\s*\d{4}.*$/u', '', $s));
    if ($hist === null) {
        $hist = [];
        foreach (db()->query("SELECT description, project_id, item_id, plan_id, work_id FROM bud_txns WHERE txn_type = 'spend' ORDER BY id") as $t) {
            $hist[$key($t['description'])] = ['src' => bud_source_key($t), 'plan_id' => bud_txn_plan($t) ?: null, 'work_id' => $t['work_id'] ? (int)$t['work_id'] : null];
        }
    }
    $k = $key($desc);
    if ($k !== '' && isset($hist[$k]) && isset($sources[$hist[$k]['src']])) {
        return $hist[$k] + ['how' => 'history'];
    }
    $hay = gfmis_norm($desc . ' ' . $debit['account_name'] . ' ' . $debit['text']);
    $best = '';
    $bestLen = 0;
    foreach ($sources as $sk => $s) {
        foreach ([$s['activity'] ?? '', $s['expense'] ?? ''] as $name) {
            $n = gfmis_norm((string)$name);
            if ($n !== '' && mb_strlen($n) > $bestLen && str_contains($hay, $n)) {
                [$best, $bestLen] = [$sk, mb_strlen($n)];
            }
        }
    }
    return ['src' => $best, 'plan_id' => null, 'work_id' => null, 'how' => $best ? 'name' : ''];
}
