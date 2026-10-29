<?php
/**
 * ระบบปฏิทินการปฏิบัติงาน: ค่าคงที่และฟังก์ชันที่ใช้ร่วมกัน
 *   viewer (ทุกคนที่ login) < editor (เพิ่มกิจกรรม แก้/ลบของตนเอง) < admin (แก้/ลบทุกรายการ)
 */

const CAL_MODULE = 'calendar';
const CAL_WEEKDAYS = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
const CAL_WEEKDAYS_SHORT = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

function cal_can(string $role): bool
{
    return user_can(CAL_MODULE, $role);
}

/** แก้ไข/ลบกิจกรรมนี้ได้หรือไม่ */
function cal_can_edit(array $e): bool
{
    $me = current_user();
    return cal_can('admin') || (cal_can('editor') && (int)$e['created_by'] === (int)($me['id'] ?? 0));
}

/**
 * ประเภทบุคลากรจากตำแหน่ง: 'gov' = ข้าราชการ, 'emp' = พนักงาน, null = อื่นๆ
 *   พนักงาน: พนักงานสถิติ, ผู้ช่วยพนักงานสถิติ
 *   ข้าราชการ: สถิติจังหวัด, นักวิชาการ…, ผู้อำนวยการ…, เจ้าพนักงาน…
 */
function cal_staff_type(?string $position): ?string
{
    $p = trim((string)$position);
    if (preg_match('/^(ผู้ช่วย)?พนักงานสถิติ/u', $p)) {
        return 'emp';
    }
    if (preg_match('/สถิติจังหวัด|^นักวิชาการ|^ผู้อำนวยการ|^เจ้าพนักงาน/u', $p)) {
        return 'gov';
    }
    return null;
}

const CAL_STAFF_LABEL = ['gov' => 'ข้าราชการ', 'emp' => 'พนักงาน', '' => 'อื่นๆ'];

/**
 * ผู้ใช้ที่เลือกเป็นผู้รับผิดชอบได้ [id => [id, full_name, position, type]]
 * เฉพาะเจ้าหน้าที่ (รหัสเข้าใช้งานเป็นตัวเลข) — บัญชีที่ไม่ใช่ตัวเลขเป็นผู้ดูแลจากภายนอก
 */
function cal_users(): array
{
    static $users = null;
    if ($users === null) {
        $users = [];
        foreach (db()->query('SELECT id, full_name, position FROM core_users WHERE is_active = 1 AND username REGEXP \'^[0-9]+$\' ORDER BY full_name') as $u) {
            $u['type'] = cal_staff_type($u['position']);
            $users[(int)$u['id']] = $u;
        }
    }
    return $users;
}

/** กิจกรรมที่คาบเกี่ยวช่วงวันที่ ($userId = เฉพาะที่ผู้ใช้นั้นรับผิดชอบ · $withDuty = false ไม่รวมเวรสำนักงาน) */
function cal_events_between(string $from, string $to, ?int $userId = null, bool $withDuty = true): array
{
    $sql = 'SELECT e.*, u.full_name AS created_by_name FROM cal_events e LEFT JOIN core_users u ON u.id = e.created_by
            WHERE e.event_date <= ? AND COALESCE(e.end_date, e.event_date) >= ?' . ($withDuty ? '' : " AND e.kind <> 'duty'");
    $params = [$to, $from];
    if ($userId) {
        $sql .= ' AND EXISTS (SELECT 1 FROM cal_event_users eu WHERE eu.event_id = e.id AND eu.user_id = ?)';
        $params[] = $userId;
    }
    $st = db()->prepare($sql . ' ORDER BY e.event_date, e.start_time IS NOT NULL, e.start_time, e.id');
    $st->execute($params);
    return $st->fetchAll();
}

/** ผู้รับผิดชอบของกิจกรรม [event_id => [user_id, ...]] */
function cal_assignees(array $eventIds): array
{
    $ids = array_filter(array_map('intval', $eventIds));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (db()->query('SELECT event_id, user_id FROM cal_event_users WHERE event_id IN (' . implode(',', $ids) . ')') as $r) {
        $out[(int)$r['event_id']][] = (int)$r['user_id'];
    }
    return $out;
}

/** ผู้อำนวยการ / สถิติจังหวัด → แสดงตำแหน่งต่อท้ายชื่อในตาราง */
function cal_show_position(?string $position): bool
{
    return (bool)preg_match('/^(ผู้อำนวยการ|สถิติจังหวัด)/u', trim((string)$position));
}

/** ชื่อผู้รับผิดชอบ (ข้อความล้วน) เช่น "นางเยาวภา มณีฉาย (ผู้อำนวยการกลุ่ม...)" */
function cal_person_text(array $u): string
{
    $name = preg_replace('/\s+/u', ' ', trim($u['full_name']));
    return $name . (cal_show_position($u['position'] ?? '') ? ' (' . trim($u['position']) . ')' : '');
}

/** สรุปผู้รับผิดชอบ: "เจ้าหน้าที่สำนักงานสถิติจังหวัดขอนแก่นทุกคน" / "ข้าราชการ" / "พนักงาน" หรือรายชื่อ */
function cal_assignee_html(array $userIds, int $meId = 0, int $max = 6): string
{
    $users = cal_users();
    $ids = array_values(array_filter($userIds, fn($id) => isset($users[$id])));
    if (!$ids) {
        return '<span class="text-muted">-</span>';
    }
    sort($ids);
    $group = fn(?string $t) => array_keys(array_filter($users, fn($u) => $t === '*' || $u['type'] === $t));
    foreach (['*' => 'เจ้าหน้าที่สำนักงานสถิติจังหวัดขอนแก่นทุกคน', 'gov' => 'ข้าราชการ', 'emp' => 'พนักงาน'] as $t => $label) {
        $g = $group($t);
        sort($g);
        if ($g && $ids === $g) {
            return '<span class="badge text-bg-primary text-wrap text-start" title="' . count($g) . ' คน">' . $label . '</span>'
                . (in_array($meId, $ids, true) ? ' <span class="badge text-bg-warning">รวมคุณ</span>' : '');
        }
    }
    usort($ids, fn($a, $b) => ($b === $meId) <=> ($a === $meId));      // ตัวเองขึ้นก่อน
    $chips = array_map(fn($id) => cal_show_position($users[$id]['position'])
        ? '<span class="cal-person pos' . ($id === $meId ? ' me' : '') . '">' . h(preg_replace('/\s+/u', ' ', trim($users[$id]['full_name']))) . ' <small>' . h(trim($users[$id]['position'])) . '</small></span>'
        : '<span class="cal-person' . ($id === $meId ? ' me' : '') . '">' . h($users[$id]['full_name']) . '</span>',
        array_slice($ids, 0, $max));
    $more = count($ids) - $max;
    return implode(' ', $chips) . ($more > 0 ? ' <span class="small text-muted" title="' . h(implode(', ', array_map(fn($id) => cal_person_text($users[$id]), array_slice($ids, $max)))) . '">+' . $more . ' คน</span>' : '');
}

/** กิจกรรมจบแล้ววันนี้ (เลยเวลาสิ้นสุดของวันสุดท้าย) — ยังแสดงในตารางจนถึงสิ้นวัน */
function cal_is_done(array $e): bool
{
    $last = $e['end_date'] ?: $e['event_date'];
    return $last === date('Y-m-d') && $e['end_time'] && substr($e['end_time'], 0, 5) <= date('H:i');
}

/** เวลาแบบไทย "09.00" จากเวลาในฐานข้อมูล "09:00:00" */
function cal_time_thai(?string $t): string
{
    return $t ? str_replace(':', '.', substr($t, 0, 5)) : '';
}

/**
 * แปลงเวลาที่กรอก/นำเข้า เป็นเวลาในฐานข้อมูล "09:00:00"
 *   รองรับ แบบไทย "9.00" / "09.00 น.", "09:00", "0930", "9", ค่าเวลาจาก Excel (0.375)
 *   ว่าง หรือ "-" = null · ผิดรูปแบบ = false
 */
function cal_parse_time(?string $v)
{
    $v = trim(preg_replace('/\s*น\.?$/u', '', trim((string)$v)));
    if ($v === '' || $v === '-') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}):(\d{2})/', $v, $x) || preg_match('/^(\d{1,2})[:.](\d{2})(?:[:.]\d{2})?$/', $v, $x)
        || preg_match('/^(\d{2})(\d{2})$/', $v, $x)) {
        [$h, $i] = [(int)$x[1], (int)$x[2]];
    } elseif (is_numeric($v) && (float)$v >= 0 && (float)$v < 1) {             // เวลาแบบ Excel (เศษของวัน)
        $min = (int)round((float)$v * 1440);
        [$h, $i] = [intdiv($min, 60), $min % 60];
    } elseif (preg_match('/^\d{1,2}$/', $v)) {
        [$h, $i] = [(int)$v, 0];
    } else {
        return false;
    }
    return $h <= 23 && $i <= 59 ? sprintf('%02d:%02d:00', $h, $i) : false;
}

/** เวลา เช่น "09.00–12.00 น." หรือ "ทั้งวัน" */
function cal_time_text(array $e): string
{
    $s = cal_time_thai($e['start_time']);
    $t = cal_time_thai($e['end_time']);
    if ($s === '' && $t === '') {
        return 'ทั้งวัน';
    }
    return $s . ($t !== '' ? '–' . $t : '') . ' น.';
}

/** วันที่แบบไทย เช่น "จันทร์ 28 ต.ค. 2569" */
function cal_date_text(string $date, bool $weekday = true): string
{
    $ts = strtotime($date);
    return ($weekday ? CAL_WEEKDAYS[(int)date('w', $ts)] . ' ' : '') . thai_date($date);
}

/** ช่วงวันที่ของกิจกรรม */
function cal_range_text(array $e): string
{
    if ($e['end_date'] && $e['end_date'] !== $e['event_date']) {
        return thai_date($e['event_date']) . ' – ' . thai_date($e['end_date']);
    }
    return cal_date_text($e['event_date']);
}

/** ลิงก์ประกอบที่ปลอดภัย (http/https เท่านั้น) */
function cal_safe_link(?string $url): ?string
{
    $url = trim((string)$url);
    return preg_match('#^https?://#i', $url) ? $url : null;
}

/**
 * ตารางกิจกรรมจัดกลุ่มตามวัน (ใช้ในหน้าหลักและหน้าตารางกิจกรรม)
 * กิจกรรมหลายวันแสดงใต้วันแรกที่อยู่ในช่วง $from
 */
function cal_render_table(array $events, string $from, int $meId, bool $showActions = true): string
{
    if (!$events) {
        return '<div class="text-center text-muted py-4"><i class="bi bi-calendar-x fs-3 d-block"></i>ไม่มีกิจกรรมในช่วงนี้</div>';
    }
    $assignees = cal_assignees(array_column($events, 'id'));
    $groups = [];
    foreach ($events as $e) {
        $groups[max($e['event_date'], $from)][] = $e;
    }
    ksort($groups);
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $base = module_url(CAL_MODULE);
    $html = '<div class="table-responsive"><table class="table align-middle mb-0 cal-table"><thead><tr>'
        . '<th style="width:120px">เวลา</th><th>กิจกรรม</th><th>สถานที่</th><th>ผู้รับผิดชอบ</th><th>ลิงก์ / หมายเหตุ</th></tr></thead><tbody>';
    foreach ($groups as $day => $list) {
        $tag = $day === $today ? ' <span class="badge text-bg-danger">วันนี้</span>' : ($day === $tomorrow ? ' <span class="badge text-bg-warning">พรุ่งนี้</span>' : '');
        $html .= '<tr class="cal-day"><th colspan="5"><i class="bi bi-calendar-event"></i> ' . h(cal_date_text($day)) . $tag . '</th></tr>';
        foreach ($list as $e) {
            $ids = $assignees[(int)$e['id']] ?? [];
            $mine = in_array($meId, $ids, true);
            $link = cal_safe_link($e['link']);
            $multi = $e['end_date'] && $e['end_date'] !== $e['event_date'];
            $duty = cal_is_duty($e);
            $done = cal_is_done($e);
            $html .= '<tr class="' . trim(($duty ? 'cal-duty ' : '') . ($mine ? 'cal-mine ' : '') . ($done ? 'cal-done' : '')) . '">'
                . '<td class="text-nowrap">' . ($duty ? '<span class="text-muted">-</span>' : h(cal_time_text($e))) . ($multi ? '<div class="small text-muted">' . h(cal_range_text($e)) . '</div>' : '') . '</td>'
                . '<td><a href="' . h($base . 'event_view.php?id=' . (int)$e['id']) . '" class="fw-semibold text-decoration-none">' . ($duty ? '<i class="bi bi-shield-check"></i> ' : '') . h($e['title']) . '</a>'
                . ($mine ? ' <span class="badge text-bg-warning">งานของคุณ</span>' : '')
                . ($done ? ' <span class="badge text-bg-secondary"><i class="bi bi-check2"></i> จบแล้ว</span>' : '') . '</td>'
                . '<td>' . ($e['location'] ? '<i class="bi bi-geo-alt text-muted"></i> ' . h($e['location']) : '<span class="text-muted">-</span>') . '</td>'
                . '<td class="cal-people">' . cal_assignee_html($ids, $meId) . '</td>'
                . '<td class="small">' . ($link ? '<a href="' . h($link) . '" target="_blank" rel="noopener"><i class="bi bi-link-45deg"></i> เปิดลิงก์</a>' : '')
                . ($e['note'] ? '<div class="text-muted">' . nl2br(h(mb_strimwidth($e['note'], 0, 160, '…'))) . '</div>' : '')
                . ($showActions && cal_can_edit($e) ? '<a href="' . h($base . 'event_form.php?id=' . (int)$e['id']) . '" class="btn btn-sm btn-light float-end" title="แก้ไข"><i class="bi bi-pencil"></i></a>' : '')
                . '</td></tr>';
        }
    }
    return $html . '</tbody></table></div>';
}

/** ผู้รับผิดชอบจากข้อความ → [user ids, ชื่อที่หาไม่พบ] */
function cal_parse_people(string $v): array
{
    $users = cal_users();
    $byName = [];
    foreach ($users as $u) {
        $n = preg_replace('/\s+/u', '', $u['full_name']);
        $byName[$n][] = (int)$u['id'];
        $byName[preg_replace('/^(นางสาว|นาง|นาย|ว่าที่ร้อยตรี|ว่าที่ ร.ต.|ดร\.)/u', '', $n)][] = (int)$u['id'];   // ไม่ใส่คำนำหน้าก็ได้
    }
    $byUsername = [];
    foreach (db()->query('SELECT id, username FROM core_users WHERE is_active = 1 AND username IS NOT NULL') as $r) {
        $byUsername[mb_strtolower($r['username'])] = (int)$r['id'];
    }
    $ids = [];
    $missing = [];
    foreach (preg_split('/[,;\n|]+/u', $v) as $part) {
        $p = trim($part);
        if ($p === '') {
            continue;
        }
        $k = preg_replace('/\s+/u', '', $p);
        if (in_array($k, ['ทุกคน', 'ทั้งหมด', 'ทั้งสำนักงาน'], true)) {
            $ids = array_merge($ids, array_keys($users));
        } elseif (in_array($k, ['ข้าราชการ', 'ข้าราชการทุกคน'], true)) {
            $ids = array_merge($ids, array_keys(array_filter($users, fn($u) => $u['type'] === 'gov')));
        } elseif (in_array($k, ['พนักงาน', 'พนักงานทุกคน'], true)) {
            $ids = array_merge($ids, array_keys(array_filter($users, fn($u) => $u['type'] === 'emp')));
        } elseif (isset($byName[$k]) && count(array_unique($byName[$k])) === 1) {
            $ids[] = $byName[$k][0];
        } elseif (isset($byUsername[mb_strtolower($p)])) {
            $ids[] = $byUsername[mb_strtolower($p)];
        } else {
            $missing[] = $p . (isset($byName[$k]) ? ' (ชื่อซ้ำหลายบัญชี — ใช้ชื่อผู้ใช้แทน)' : '');
        }
    }
    return [array_values(array_unique(array_map('intval', $ids))), $missing];
}

/* ---------- เวรสำนักงาน ---------- */
const CAL_DUTY_TITLE = 'เวรสำนักงาน';

function cal_is_duty(array $e): bool
{
    return ($e['kind'] ?? 'event') === 'duty';
}
