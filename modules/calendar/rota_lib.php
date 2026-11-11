<?php
/**
 * การจัดเวรกิจกรรม: หมุนเวียนข้าราชการ 1 คน + กลุ่มกิจกรรม 1 กลุ่ม ต่อกิจกรรม
 *   คิวคิดเป็น "รอบ": ทุกคน/ทุกกลุ่มได้ 1 ครั้งต่อรอบ ตามลำดับรายชื่อ — ครบแล้วเริ่มรอบใหม่
 *   ข้าราชการข้ามได้ 1–3 คิว: คนที่ถูกข้ามยังอยู่หน้าคิว (ได้คิวถัดไป) · คนที่ถูกเลือกแล้วออกจากรอบนั้น
 *   ลำดับคิวคำนวณจากประวัติ (cal_rota_turns) ทุกครั้ง → ลบกิจกรรมในปฏิทิน = คืนคิวให้อัตโนมัติ
 *   จุดเริ่มต้น (ก่อนใช้ระบบ): คิวล่าสุดที่ได้ไปแล้ว — ข้าราชการ (core_settings.cal_rota_last_official = ชื่อ)
 *   และกลุ่ม (cal_rota_last_group) → ถือว่าตั้งแต่ลำดับแรกถึงคน/กลุ่มนั้นได้ไปแล้ว คิวเริ่มที่ลำดับถัดไป
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
    $hist = array_map(function ($t) use ($alias) {
        $k = rota_key($t['official_user_id'] ? (int)$t['official_user_id'] : null, $t['official_name']);
        return $alias[$k] ?? $k;
    }, $turns);
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
