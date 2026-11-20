<?php
/**
 * การจัดเวรกิจกรรม: หมุนเวียนข้าราชการ 1 คน + กลุ่มกิจกรรม 1 กลุ่ม ต่อกิจกรรม
 *   คิวคิดเป็น "รอบ": ทุกคน/ทุกกลุ่มได้ 1 ครั้งต่อรอบ ตามลำดับรายชื่อ — ครบแล้วเริ่มรอบใหม่
 *   ข้าราชการข้ามได้ 1–3 คิว: คนที่ถูกข้ามยังอยู่หน้าคิว (ได้คิวถัดไป) · คนที่ถูกเลือกแล้วออกจากรอบนั้น
 *   ลำดับคิวคำนวณจากประวัติ (cal_rota_turns) ทุกครั้ง → ลบกิจกรรมในปฏิทิน = คืนคิวให้อัตโนมัติ
 *   จุดเริ่มต้น (ก่อนใช้ระบบ): คิวล่าสุดที่ได้ไปแล้ว — ข้าราชการ (core_settings.cal_rota_last_official = ชื่อ)
 *   และกลุ่ม (cal_rota_last_group) → ถือว่าตั้งแต่ลำดับแรกถึงคน/กลุ่มนั้นได้ไปแล้ว คิวเริ่มที่ลำดับถัดไป
 *   สถิติจังหวัดเข้าร่วมด้วย (เลือกต่อกิจกรรม): ถ้ายังไม่มีสถิติจังหวัดและผู้รักษาการเป็นข้าราชการที่ "ถึงคิว" พอดี
 *   → นับเป็นคิวของผู้รักษาการแล้ว (chief_counted) และเลือกข้าราชการคิวถัดไปเป็นข้าราชการของกิจกรรม
 */

const ROTA_MAX_SKIP = 3;

/** คีย์ประจำตัวในคิว: บัญชีผู้ใช้ หรือชื่อ (ผู้ไม่มีบัญชี) */
function rota_key(?int $userId, string $name): string
{
    return $userId ? 'u' . $userId : 'n' . preg_replace('/\s+/u', '', $name);
}

/** หาบัญชีจากชื่อ-สกุล (ไม่สนช่องว่าง/คำนำหน้า) — ผู้ไม่มีบัญชี = null */
function rota_find_user(string $name): ?int
{
    [$ids] = cal_parse_people($name);
    return count($ids) === 1 ? (int)$ids[0] : null;
}

/**
 * รายชื่อปัจจุบัน: ['officials' => [[id, user_id, name, key], ...], 'groups' => [group_no => [[...], ...]]]
 * ผู้ไม่มีบัญชีที่ภายหลังมีบัญชีแล้ว → ผูกบัญชีให้อัตโนมัติ
 */
function rota_members(): array
{
    $out = ['officials' => [], 'groups' => []];
    $link = db()->prepare('UPDATE cal_rota_members SET user_id = ? WHERE id = ?');
    foreach (db()->query('SELECT * FROM cal_rota_members ORDER BY kind, group_no, sort_order, id') as $r) {
        $uid = $r['user_id'] ? (int)$r['user_id'] : rota_find_user($r['name']);
        if ($uid && !$r['user_id']) {
            $link->execute([$uid, $r['id']]);
        }
        $m = ['id' => (int)$r['id'], 'user_id' => $uid, 'name' => preg_replace('/\s+/u', ' ', trim($r['name'])), 'key' => rota_key($uid, $r['name'])];
        if ($r['kind'] === 'official') {
            $out['officials'][] = $m;
        } else {
            $out['groups'][(int)$r['group_no']][] = $m;
        }
    }
    ksort($out['groups']);
    return $out;
}

/** มีคอลัมน์สถิติจังหวัดเข้าร่วม (migration 20261211) แล้วหรือยัง */
function rota_has_chief(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)db()->query("SHOW COLUMNS FROM cal_rota_turns LIKE 'chief_counted'")->fetch();
        } catch (PDOException $e) {
            $has = false;
        }
    }
    return $has;
}

/**
 * สถิติจังหวัด: ผู้มีตำแหน่งหลัก "สถิติจังหวัด…" — ไม่มี = ผู้รักษาการ/ควบตำแหน่ง (core_user_positions)
 * คืน ['user_id', 'name', 'acting' => bool, 'label'] หรือ null (ไม่มีทั้งสองแบบ)
 */
function rota_chief(): ?array
{
    $acting = null;
    foreach (sort_staff(db()->query('SELECT id, username, full_name, position, sort_order FROM core_users WHERE is_active = 1')->fetchAll()) as $u) {
        $name = preg_replace('/\s+/u', ' ', trim($u['full_name']));
        if (staff_category($u['position']) === 'chief') {
            return ['user_id' => (int)$u['id'], 'name' => $name, 'acting' => false, 'label' => trim($u['position'])];
        }
        foreach (staff_extras_of($u) as $x) {
            if (!$acting && staff_category($x['position']) === 'chief') {
                $acting = ['user_id' => (int)$u['id'], 'name' => $name, 'acting' => true, 'label' => $x['label']];
            }
        }
    }
    return $acting;
}

/**
 * ข้าราชการที่เลือกได้ในกิจกรรมนี้ (ถึงคิว + ข้ามได้ 1–ROTA_MAX_SKIP คิว)
 * สถิติจังหวัดเข้าร่วม และผู้รักษาการอยู่ในคิวข้าราชการ → ตัดออกจากตัวเลือก (เป็นสถิติจังหวัดในกิจกรรมนี้แล้ว)
 *   ถ้าผู้รักษาการ "ถึงคิว" พอดี → นับเป็นคิวของคนนั้น (counted) — ตัวเลือกเริ่มที่คิวถัดไป
 * คืน [choices, counted]
 */
function rota_choices(array $queue, ?array $chief, bool $chiefJoins): array
{
    $counted = false;
    if ($chiefJoins && $chief) {
        $ck = rota_key($chief['user_id'], $chief['name']);
        $idx = array_search($ck, array_column($queue, 'key'), true);
        if ($idx !== false) {
            $counted = $idx === 0;
            array_splice($queue, $idx, 1);
        }
    }
    return [array_slice($queue, 0, ROTA_MAX_SKIP + 1), $counted];
}

/** เวรที่จัดแล้ว เรียงตามลำดับที่จัด (เก่า → ใหม่) พร้อมข้อมูลกิจกรรม */
function rota_turns(): array
{
    return db()->query('SELECT t.*, e.event_date, e.start_time, e.end_time, e.title, e.location
        FROM cal_rota_turns t JOIN cal_events e ON e.id = t.event_id ORDER BY t.id')->fetchAll();
}

/**
 * คิวในรอบปัจจุบัน: ไล่ประวัติตามลำดับ — คนที่ได้แล้วออกจากรอบ ครบทุกคนเริ่มรอบใหม่
 * $order = คีย์ตามลำดับรายชื่อ · $picked = คีย์ที่ได้ในแต่ละเวร (ตามลำดับ)
 * คืน [round, remaining keys (ตามลำดับ)]
 */
function rota_queue(array $order, array $picked): array
{
    $round = 1;
    $done = [];
    foreach ($picked as $k) {
        if (!in_array($k, $order, true)) {
            continue;                          // ไม่อยู่ในรายชื่อแล้ว
        }
        $done[$k] = true;
        if (count($done) >= count($order)) {
            $round++;
            $done = [];
        }
    }
    return [$round, array_values(array_filter($order, fn($k) => !isset($done[$k])))];
}

/**
 * สถานะคิว: ['official_round', 'officials' => [member ตามคิวที่เหลือ], 'group_round', 'groups' => [group_no ตามคิวที่เหลือ], 'members']
 */
function rota_state(?array $members = null, ?array $turns = null): array
{
    $members ??= rota_members();
    $turns ??= rota_turns();
    $byKey = array_column($members['officials'], null, 'key');
    $alias = [];                                   // คีย์ชื่อ → คีย์ปัจจุบัน (บันทึกไว้ตอนยังไม่มีบัญชี แล้วภายหลังผูกบัญชี)
    foreach ($members['officials'] as $m) {
        $alias[rota_key(null, $m['name'])] = $m['key'];
    }
    $hist = [];
    foreach ($turns as $t) {                       // ผู้รักษาการที่นับเป็นคิวแล้ว (chief_counted) มาก่อนข้าราชการของกิจกรรมนั้น
        if (!empty($t['chief_counted'])) {
            $k = rota_key($t['chief_user_id'] ? (int)$t['chief_user_id'] : null, (string)$t['chief_name']);
            $hist[] = $alias[$k] ?? $k;
        }
        $k = rota_key($t['official_user_id'] ? (int)$t['official_user_id'] : null, $t['official_name']);
        $hist[] = $alias[$k] ?? $k;
    }
    // จุดเริ่มต้นคิว: ลำดับแรกถึงคน/กลุ่มล่าสุดที่ได้ไปแล้ว (ก่อนใช้ระบบ) นับเป็นเวรที่จัดแล้ว
    $startO = [];
    $last = rota_key(null, (string)setting('cal_rota_last_official', ''));
    if (isset($alias[$last])) {
        foreach (array_keys($byKey) as $k) {
            $startO[] = $k;
            if ($k === $alias[$last]) {
                break;
            }
        }
    }
    $gOrder = array_map('strval', array_keys($members['groups']));
    $lastG = (string)(int)setting('cal_rota_last_group', '0');
    $startG = in_array($lastG, $gOrder, true) ? array_slice($gOrder, 0, array_search($lastG, $gOrder, true) + 1) : [];
    [$oRound, $oQueue] = rota_queue(array_keys($byKey), array_merge($startO, $hist));
    [$gRound, $gQueue] = rota_queue($gOrder, array_merge($startG, array_map(fn($t) => (string)$t['group_no'], $turns)));
    return ['official_round' => $oRound, 'officials' => array_map(fn($k) => $byKey[$k], $oQueue),
            'group_round' => $gRound, 'groups' => array_map('intval', $gQueue), 'members' => $members];
}

/** ข้อความรายชื่อสมาชิกกลุ่ม */
function rota_group_names(array $members, int $groupNo): string
{
    return implode(', ', array_column($members['groups'][$groupNo] ?? [], 'name'));
}

/** ชื่อต้น (ไม่มีคำนำหน้า/นามสกุล) — ใช้ในข้อความไลน์ */
function rota_first_name(string $name): string
{
    $n = preg_replace('/^(นางสาว|น\.ส\.|นาง|นาย|ว่าที่ร้อยตรี|ว่าที่\s*ร\.ต\.|ดร\.)\s*/u', '', trim($name));
    return preg_split('/\s+/u', $n)[0] ?? $n;
}

/**
 * ข้อความสำหรับคัดลอกไปวางในไลน์กลุ่ม (1 เวรกิจกรรม)
 *   เรียน <ตำแหน่งย่อ/ชื่อ> และเจ้าหน้าที่กลุ่มที่ N · กิจกรรม วันที่ เวลา · สถานที่ · การแต่งกาย · ผู้ปฏิบัติ (ชื่อต้นของสมาชิกกลุ่ม)
 *   ข้าราชการ: ตำแหน่งย่อ (ผอ.กว / ผอ.กป / ผอ.กด) ถ้ามี ไม่มี = "คุณ" + ชื่อต้น (เช่น คุณจงกล) · สถิติจังหวัดเข้าร่วม = "สถิติจังหวัด" / "รก.สถิติจังหวัด"
 */
function rota_line_text(array $t, array $members): string
{
    static $users = null;
    if ($users === null) {
        $users = array_column(db()->query('SELECT id, username, full_name, position FROM core_users')->fetchAll(), null, 'id');
    }
    $label = function (?int $uid, string $name, bool $asChief = false) use ($users): string {
        $u = $uid ? ($users[$uid] ?? null) : null;
        if ($asChief) {
            if ($u && staff_category($u['position']) === 'chief') {
                return 'สถิติจังหวัด';
            }
            foreach ($u ? staff_extras_of($u) : [] as $x) {
                if (staff_category($x['position']) === 'chief') {
                    return cal_position_abbr($x['position'], $x['kind']) ?? 'สถิติจังหวัด';
                }
            }
            return 'สถิติจังหวัด';
        }
        return ($u ? cal_person_abbr($u) : null) ?? 'คุณ' . rota_first_name($name);   // ไม่มีตำแหน่งย่อ = "คุณ" + ชื่อต้น
    };
    $heads = [];
    if (!empty($t['chief_name'])) {
        $heads[] = $label($t['chief_user_id'] ? (int)$t['chief_user_id'] : null, (string)$t['chief_name'], true);
    }
    $heads[] = $label($t['official_user_id'] ? (int)$t['official_user_id'] : null, (string)$t['official_name']);
    $g = (int)$t['group_no'];
    $title = trim((string)$t['title']);
    $date = (int)substr($t['event_date'], 8, 2) . ' ' . thai_month_long((int)substr($t['event_date'], 5, 2)) . ' ' . ((int)substr($t['event_date'], 0, 4) + 543);
    $lines = [
        'เรียน ' . implode(', ', $heads) . ' และเจ้าหน้าที่กลุ่มที่ ' . $g,
        '           ' . (preg_match('/^เข้าร่วม/u', $title) ? '' : 'เข้าร่วม') . $title . ' ในวันที่ ' . $date
            . ($t['start_time'] ? ' เวลา ' . substr($t['start_time'], 0, 5) . ' น.' : ''),
    ];
    if (trim((string)$t['location']) !== '') {
        $lines[] = 'ณ ' . trim($t['location']);
    }
    if (trim((string)$t['dress']) !== '') {
        $lines[] = 'การแต่งกาย';
        $lines[] = trim($t['dress']);
    }
    $lines[] = 'ผู้ปฏิบัติ';
    foreach ($heads as $h) {
        $lines[] = '  ' . $h;
    }
    $lines[] = ' เจ้าหน้าที่กลุ่มที่ ' . $g;
    foreach ($members['groups'][$g] ?? [] as $m) {
        $lines[] = '  - ' . rota_first_name($m['name']);
    }
    return implode("\n", $lines);
}
