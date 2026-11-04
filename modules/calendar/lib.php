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
    if (cal_is_guard($e)) {            // เวรศาลากลาง จัดการที่แผนการปฏิบัติงาน (plan_edit.php)
        return false;
    }
    $me = current_user();
    return cal_can('admin') || (cal_can('editor') && (int)$e['created_by'] === (int)($me['id'] ?? 0));
}

/**
 * กลุ่มผู้รับผิดชอบจากตำแหน่ง (ดู staff_category() ใน core/helpers.php):
 *   gov = ข้าราชการ (สถิติจังหวัด / ผู้อำนวยการ / นักวิชาการ… / เจ้าพนักงาน…) · emp = พนักงาน (พนักงานสถิติ / ผู้ช่วยพนักงานสถิติ)
 *   contract = จ้างเหมาบริการ · driver = พนักงานขับรถ (ไม่นับรวมใน "ทุกคน")
 */
function cal_staff_type(?string $position): string
{
    return match (staff_category($position)) {
        'chief', 'dir1', 'dir2', 'dir3', 'dir', 'gov' => 'gov',
        'emp', 'asst' => 'emp',
        'driver'      => 'driver',
        default       => 'contract',
    };
}

const CAL_STAFF_LABEL = ['gov' => 'ข้าราชการ', 'emp' => 'พนักงาน', 'contract' => 'จ้างเหมาบริการ', 'driver' => 'พนักงานขับรถ'];
const CAL_ALL_LABEL = 'เจ้าหน้าที่สำนักงานสถิติจังหวัดขอนแก่นทุกคน';

/**
 * ผู้ใช้ที่เลือกเป็นผู้รับผิดชอบได้ [id => [id, full_name, position, type]]
 * เฉพาะเจ้าหน้าที่ (รหัสเข้าใช้งานเป็นตัวเลข) — บัญชีที่ไม่ใช่ตัวเลขเป็นผู้ดูแลจากภายนอก
 */
function cal_users(): array
{
    static $users = null;
    if ($users === null) {
        $users = [];
        foreach (db()->query('SELECT id, username, sort_order, full_name, position, work_group_id FROM core_users WHERE is_active = 1 AND username REGEXP \'^[0-9]+$\'') as $u) {
            $u['type'] = cal_staff_type($u['position']);
            $users[(int)$u['id']] = $u;
        }
        $users = sort_staff($users);              // เรียงตามลำดับตำแหน่ง แล้วตามชื่อผู้ใช้
    }
    return $users;
}

/**
 * กิจกรรมส่วนตัว (visibility = private): เห็นเฉพาะผู้บันทึกและผู้รับผิดชอบ — ผู้อื่น (รวมผู้ดูแล/ผู้เยี่ยมชม) ไม่เห็น
 * เงื่อนไข SQL สำหรับผู้ใช้ปัจจุบัน (ใช้กับ alias e)
 */
function cal_visible_sql(): array
{
    $me = (int)(current_user()['id'] ?? 0);
    if (!$me) {
        return [" AND e.visibility = 'public'", []];
    }
    return [" AND (e.visibility = 'public' OR e.created_by = ? OR EXISTS (SELECT 1 FROM cal_event_users v WHERE v.event_id = e.id AND v.user_id = ?))", [$me, $me]];
}

/** ผู้ใช้ปัจจุบันเห็นกิจกรรมนี้ได้หรือไม่ */
function cal_can_see(array $e): bool
{
    if (($e['visibility'] ?? 'public') !== 'private') {
        return true;
    }
    $me = (int)(current_user()['id'] ?? 0);
    return $me && ((int)$e['created_by'] === $me || in_array($me, cal_assignees([(int)$e['id']])[(int)$e['id']] ?? [], true));
}

function cal_is_private(array $e): bool
{
    return ($e['visibility'] ?? 'public') === 'private';
}

/** กิจกรรมที่คาบเกี่ยวช่วงวันที่ ($userId = เฉพาะที่ผู้ใช้นั้นรับผิดชอบ · $withDuty = false ไม่รวมเวรสำนักงาน) — กรองกิจกรรมส่วนตัวของผู้อื่นออกเสมอ */
function cal_events_between(string $from, string $to, ?int $userId = null, bool $withDuty = true, bool $withGuard = true): array
{
    [$visSql, $visParams] = cal_visible_sql();
    $sql = 'SELECT e.*, u.full_name AS created_by_name FROM cal_events e LEFT JOIN core_users u ON u.id = e.created_by
            WHERE e.event_date <= ? AND COALESCE(e.end_date, e.event_date) >= ?' . ($withDuty ? '' : " AND e.kind <> 'duty'") . ($withGuard ? '' : " AND e.kind <> 'guard'") . $visSql;
    $params = [$to, $from, ...$visParams];
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

/** ตำแหน่งย่อในตาราง: สถิติจังหวัด / ผอ.กว / ผอ.กป / ผอ.กข (รักษาการ = "รก." นำหน้า) — ไม่ใช่ตำแหน่งเหล่านี้คืน null */
const CAL_POS_ABBR = ['chief' => 'สถิติจังหวัด', 'dir1' => 'ผอ.กว', 'dir2' => 'ผอ.กป', 'dir3' => 'ผอ.กข'];

function cal_position_abbr(string $position, string $kind = ''): ?string
{
    $abbr = CAL_POS_ABBR[staff_category($position)] ?? null;
    return $abbr === null ? null : ($kind === 'acting' ? 'รก.' : '') . $abbr;
}

/**
 * ตำแหน่งย่อของผู้ใช้ในตาราง — แสดงตำแหน่งเดียว: ตำแหน่งหลัก (core_users.position)
 * ถ้าตำแหน่งหลักไม่ใช่สถิติจังหวัด/ผอ.กลุ่ม แต่มีตำแหน่งควบ/รักษาการที่เป็น → ใช้ตำแหน่งนั้นแทน · ไม่มีเลย = null (แสดงชื่อ)
 */
function cal_person_abbr(array $u): ?string
{
    foreach (array_merge([['position' => (string)($u['position'] ?? ''), 'kind' => '']], staff_extras_of($u)) as $p) {
        if (($a = cal_position_abbr($p['position'], $p['kind'])) !== null) {
            return $a;
        }
    }
    return null;
}

/** ตำแหน่งย่อที่เลือกแสดงได้ (ตำแหน่งหลักก่อน แล้วควบ/รักษาการ) [abbr => ตำแหน่งเต็ม] — ใช้ในหน้าเพิ่มกิจกรรม */
function cal_person_abbr_options(array $u): array
{
    $out = [];
    $primary = (string)($u['position'] ?? '');
    foreach (array_merge([['position' => $primary, 'kind' => '', 'label' => trim($primary)]], staff_extras_of($u)) as $p) {
        if (($a = cal_position_abbr($p['position'], $p['kind'])) !== null) {
            $out[$a] ??= $p['label'];
        }
    }
    return $out;
}

/** มีคอลัมน์ cal_event_users.show_as แล้วหรือยัง (migration 20261117) */
function cal_has_show_as(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)db()->query("SHOW COLUMNS FROM cal_event_users LIKE 'show_as'")->fetch();
        } catch (PDOException $e) {
            $has = false;
        }
    }
    return $has;
}

/** ตำแหน่งที่เลือกให้แสดง ต่อกิจกรรม [event_id][user_id] => "ผอ.กว / ผอ.กข" (เฉพาะที่เลือกไว้) */
function cal_assignee_show_as(array $eventIds): array
{
    $ids = array_filter(array_map('intval', $eventIds));
    $out = [];
    if (!$ids) {
        return $out;
    }
    try {
        foreach (db()->query('SELECT event_id, user_id, show_as FROM cal_event_users WHERE show_as IS NOT NULL AND event_id IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int)$r['event_id']][(int)$r['user_id']] = $r['show_as'];
        }
    } catch (PDOException $e) {
        // ยังไม่ได้รัน migration 20261117
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

/** ผู้ใช้ในกลุ่ม: '*' = ทุกคน (ไม่รวมพนักงานขับรถ) · '*+driver' = ทุกคนรวมพนักงานขับรถ · หรือ gov / emp / contract / driver */
function cal_group_ids(string $t): array
{
    if (preg_match('/^g(\d+)$/', $t, $m)) {          // กลุ่มงาน (g + id) — ดู cal_work_groups()
        return cal_work_groups()[(int)$m[1]]['ids'] ?? [];
    }
    return array_keys(array_filter(cal_users(), fn($u) => match ($t) {
        '*'        => $u['type'] !== 'driver',
        '*+driver' => true,
        default    => $u['type'] === $t,
    }));
}

/** สรุปผู้รับผิดชอบ: "เจ้าหน้าที่สำนักงานสถิติจังหวัดขอนแก่นทุกคน" / "ข้าราชการ" / "พนักงาน" หรือรายชื่อ */
/**
 * กลุ่มงาน [id => ['name', 'director' => user id|null, 'ids' => [...]]] — เฉพาะกลุ่มที่มีอย่างน้อย 2 คน
 * สมาชิก = เจ้าหน้าที่ที่กำหนดกลุ่มงานในบัญชีผู้ใช้ + ผู้อำนวยการกลุ่ม (จากตำแหน่ง "ผู้อำนวยการ" + ชื่อกลุ่ม
 * ทั้งตำแหน่งหลักและตำแหน่งควบ/รักษาการ — คนเดียวเป็นผู้อำนวยการได้หลายกลุ่ม) · director_label = ตำแหน่งที่ใช้แสดง
 */
function cal_work_groups(): array
{
    static $groups = null;
    if ($groups !== null) {
        return $groups;
    }
    $groups = [];
    $users = cal_users();
    foreach (work_groups() as $gid => $name) {
        $ids = [];
        $director = null;
        $dirLabel = '';
        $key = preg_replace('/\s+/u', '', $name);
        foreach ($users as $id => $u) {
            $positions = array_merge([['position' => (string)$u['position'], 'label' => trim((string)$u['position'])]], staff_extras_of($u));
            foreach ($positions as $p) {
                $pos = preg_replace('/\s+/u', '', $p['position']);
                if ($director === null && str_starts_with($pos, 'ผู้อำนวยการ') && str_contains($pos, $key)) {
                    $director = $id;
                    $dirLabel = $p['label'];
                    $ids[] = $id;
                }
            }
            if ((int)$u['work_group_id'] === (int)$gid) {
                $ids[] = $id;
            }
        }
        if (count(array_unique($ids)) >= 2) {
            $groups[(int)$gid] = ['name' => $name, 'director' => $director, 'director_label' => $dirLabel, 'ids' => array_values(array_unique($ids))];
        }
    }
    // เรียงตามลำดับตำแหน่งผู้อำนวยการกลุ่ม (ผอ.กลุ่มวิชาการฯ → ปฏิบัติการฯ → ขับเคลื่อนฯ ตาม staff_sort_key) แล้วตาม id
    $rank = fn($gid) => staff_sort_key(['username' => '0', 'position' => 'ผู้อำนวยการ' . $groups[$gid]['name']])[1];
    uksort($groups, fn($a, $b) => [$rank($a), $a] <=> [$rank($b), $b]);
    return $groups;
}

/** ชื่อกลุ่มงาน (ไม่สนช่องว่าง · มีคำว่า "บุคลากร" นำหน้าได้) → id */
function cal_work_group_by_name(string $k): ?int
{
    $k = preg_replace('/^บุคลากร/u', '', preg_replace('/\s+/u', '', $k));
    foreach (cal_work_groups() as $gid => $g) {
        if (preg_replace('/\s+/u', '', $g['name']) === $k) {
            return $gid;
        }
    }
    return null;
}

/** ข้อความแทนทั้งกลุ่มงาน: "ชื่อผู้อำนวยการ ตำแหน่ง + บุคลากรกลุ่ม…" */
function cal_work_group_label(int $gid): string
{
    $g = cal_work_groups()[$gid] ?? null;
    if (!$g) {
        return '';
    }
    $users = cal_users();
    $label = 'บุคลากร' . $g['name'];
    if ($g['director'] && isset($users[$g['director']])) {
        $d = $users[$g['director']];
        $label = preg_replace('/\s+/u', ' ', trim($d['full_name'])) . ' ' . ($g['director_label'] ?: trim($d['position'])) . ' + ' . $label;
    }
    return $label;
}

/**
 * กลุ่มงานในตาราง: แบบเดียวกับรายชื่อปกติ — "ผอ.กว + บุคลากรกลุ่ม…" (ตำแหน่งย่อ ไม่แสดงชื่อ · ชี้เมาส์เห็นชื่อ)
 * ผู้อำนวยการคนเดียวกันหลายกลุ่ม → แสดงครั้งเดียว (ตำแหน่งหลัก) แล้วตามด้วยบุคลากรของแต่ละกลุ่ม
 */
function cal_work_groups_html(array $gids, int $meId = 0, array $showAs = []): string
{
    $groups = cal_work_groups();
    $users = cal_users();
    $byDir = [];                                   // director id (0 = ไม่มี) => [gid, ...] ตามลำดับกลุ่ม
    foreach ($gids as $gid) {
        if (isset($groups[$gid])) {
            $d = $groups[$gid]['director'] && isset($users[$groups[$gid]['director']]) ? (int)$groups[$gid]['director'] : 0;
            $byDir[$d][] = $gid;
        }
    }
    $plus = ' <span class="cal-plus">+</span> ';
    $parts = [];
    foreach ($byDir as $did => $list) {
        $chips = [];
        if ($did) {
            $u = $users[$did];
            $pos = array_values(array_unique(array_map(fn($gid) => $groups[$gid]['director_label'] ?: trim($u['position']), $list)));
            $abbr = $showAs[$did] ?? cal_person_abbr($u) ?? preg_replace('/\s+/u', ' ', trim($u['full_name']));     // ตำแหน่งที่เลือก / ตำแหน่งหลัก
            $chips[] = '<span class="cal-person pos' . ($did === $meId ? ' me' : '') . '" title="' . h(preg_replace('/\s+/u', ' ', trim($u['full_name'])) . ' — ' . implode(' / ', $pos)) . '">'
                . h($abbr) . '</span>';
        }
        foreach ($list as $gid) {
            $chips[] = '<span class="cal-person" title="' . count($groups[$gid]['ids']) . ' คน">' . h('บุคลากร' . $groups[$gid]['name']) . '</span>';
        }
        $parts[] = implode($plus, $chips);
    }
    return implode($plus, $parts);
}

function cal_assignee_html(array $userIds, int $meId = 0, int $max = 6, array $showAs = []): string
{
    $users = cal_users();
    $ids = array_values(array_filter($userIds, fn($id) => isset($users[$id])));
    if (!$ids) {
        return '<span class="text-muted">-</span>';
    }
    $sorted = $ids;
    sort($sorted);
    $hasDriver = (bool)cal_group_ids('driver');
    $groups = ($hasDriver ? ['*+driver' => CAL_ALL_LABEL . ' + พนักงานขับรถ'] : [])
        + ['*' => CAL_ALL_LABEL, 'gov' => 'ข้าราชการ', 'emp' => 'พนักงาน', 'contract' => 'จ้างเหมาบริการ', 'driver' => 'พนักงานขับรถ'];
    foreach ($groups as $t => $label) {
        $g = cal_group_ids($t);
        sort($g);
        if ($g && $sorted === $g) {
            return '<span class="badge text-bg-primary text-wrap text-start" title="' . count($g) . ' คน">' . $label . '</span>'
                . (in_array($meId, $ids, true) ? ' <span class="badge text-bg-warning">รวมคุณ</span>' : '');
        }
    }
    // กลุ่มงานที่ถูกเลือกครบทั้งกลุ่ม → "ผู้อำนวยการ ตำแหน่ง + บุคลากรกลุ่ม…" (หลายกลุ่มต่อด้วย +) แล้วตามด้วยรายชื่อที่เหลือ
    $labels = [];
    $rest = $ids;
    foreach (cal_work_groups() as $gid => $g) {
        if (!array_diff($g['ids'], $ids)) {                 // ครบทั้งกลุ่ม (ผู้อำนวยการคนเดียวกันอยู่ได้หลายกลุ่ม)
            $labels[] = $gid;
            $rest = array_values(array_diff($rest, $g['ids']));
        }
    }
    $groupHtml = '';
    if ($labels) {
        $groupHtml = cal_work_groups_html($labels, $meId, $showAs) . ' ';
        if (!$rest) {
            return $groupHtml;
        }
        $ids = $rest;
    }
    $order = array_flip(array_keys($users));                            // เรียงตามลำดับตำแหน่ง (เหมือนหน้าบุคลากร)
    usort($ids, fn($a, $b) => $order[$a] <=> $order[$b]);
    // สถิติจังหวัด / ผอ.กลุ่ม → แสดงเฉพาะตำแหน่งย่อ (ชี้เมาส์เห็นชื่อ-ตำแหน่งเต็ม) · คนอื่นแสดงชื่อ
    $chips = array_map(fn($id) => ($abbr = $showAs[$id] ?? cal_person_abbr($users[$id])) !== null
        ? '<span class="cal-person pos' . ($id === $meId ? ' me' : '') . '" title="' . h(preg_replace('/\s+/u', ' ', trim($users[$id]['full_name'])) . ' — ' . implode(' / ', staff_position_lines($users[$id]))) . '">' . h($abbr) . '</span>'
        : '<span class="cal-person' . ($id === $meId ? ' me' : '') . '">' . h($users[$id]['full_name']) . '</span>',
        array_slice($ids, 0, $max));
    $more = count($ids) - $max;
    return $groupHtml . implode(' ', $chips) . ($more > 0 ? ' <span class="small text-muted" title="' . h(implode(', ', array_map(fn($id) => cal_person_text($users[$id]), array_slice($ids, $max)))) . '">+' . $more . ' คน</span>' : '');
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
    $showAs = cal_assignee_show_as(array_column($events, 'id'));
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
            $guard = cal_is_guard($e);
            $done = cal_is_done($e);
            $html .= '<tr class="' . trim(($duty ? 'cal-duty ' : '') . ($guard ? 'cal-guard ' : '') . ($mine ? 'cal-mine ' : '') . ($done ? 'cal-done' : '')) . '">'
                . '<td class="text-nowrap">' . ($duty || $guard ? '<span class="text-muted">-</span>' : h(cal_time_text($e))) . ($multi ? '<div class="small text-muted">' . h(cal_range_text($e)) . '</div>' : '') . '</td>'
                . '<td><a href="' . h($base . 'event_view.php?id=' . (int)$e['id']) . '" class="fw-semibold text-decoration-none">' . ($duty ? '<i class="bi bi-shield-check"></i> ' : '') . ($guard ? '<i class="bi bi-shield-shaded"></i> ' : '') . h($e['title']) . '</a>'
                . (cal_is_private($e) ? ' <span class="badge text-bg-secondary" title="เห็นเฉพาะผู้บันทึกและผู้รับผิดชอบ"><i class="bi bi-lock-fill"></i> ส่วนตัว</span>' : '')
                . ($mine ? ' <span class="badge text-bg-warning">งานของคุณ</span>' : '')
                . ($done ? ' <span class="badge text-bg-secondary"><i class="bi bi-check2"></i> จบแล้ว</span>' : '') . '</td>'
                . '<td>' . ($e['location'] ? '<i class="bi bi-geo-alt text-muted"></i> ' . h($e['location']) : '<span class="text-muted">-</span>') . '</td>'
                . '<td class="cal-people">' . cal_assignee_html($ids, $meId, 6, $showAs[(int)$e['id']] ?? []) . '</td>'
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
        if (in_array($k, ['ทุกคน', 'ทั้งหมด', 'ทั้งสำนักงาน'], true)) {          // ทุกคน = ไม่รวมพนักงานขับรถ
            $ids = array_merge($ids, cal_group_ids('*'));
        } elseif (in_array($k, ['ข้าราชการ', 'ข้าราชการทุกคน'], true)) {
            $ids = array_merge($ids, cal_group_ids('gov'));
        } elseif (in_array($k, ['พนักงาน', 'พนักงานทุกคน'], true)) {
            $ids = array_merge($ids, cal_group_ids('emp'));
        } elseif (in_array($k, ['จ้างเหมาบริการ', 'จ้างเหมา'], true)) {
            $ids = array_merge($ids, cal_group_ids('contract'));
        } elseif (in_array($k, ['พนักงานขับรถ', 'คนขับรถ'], true)) {
            $ids = array_merge($ids, cal_group_ids('driver'));
        } elseif (($gid = cal_work_group_by_name($k)) !== null) {          // กลุ่มงาน เช่น "กลุ่มวิชาการสถิติและวางแผน" / "บุคลากรกลุ่ม…"
            $ids = array_merge($ids, cal_group_ids('g' . $gid));
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

/** เวรศาลากลาง — สร้างจากแผนการปฏิบัติงาน (รหัส *) แสดงในตารางและแจ้งเตือนผู้รับผิดชอบ */
const CAL_GUARD_TITLE = 'เวรศาลากลาง';

function cal_is_guard(array $e): bool
{
    return ($e['kind'] ?? 'event') === 'guard';
}

/** เวรสำนักงาน / เวรศาลากลาง: ทั้งวัน ไม่ระบุเวลา */
function cal_is_shift(array $e): bool
{
    return cal_is_duty($e) || cal_is_guard($e);
}

/**
 * popup รายละเอียดกิจกรรม: คลิกลิงก์ event_view.php ในตาราง/ปฏิทิน → เปิด modal (โหลด event_view.php?modal=1)
 * ถ้าโหลดไม่ได้ จะเปิดหน้ารายละเอียดตามปกติ — เรียกครั้งเดียวต่อหน้า
 */
function cal_modal_script(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return <<<'HTML'
<div class="modal fade" id="calEventModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content" id="calEventModalContent"></div></div></div>
<script>
(() => {
  const box = document.getElementById('calEventModalContent');
  const loading = '<div class="modal-body text-center py-5 text-muted"><div class="spinner-border text-primary mb-2"></div><div>กำลังโหลด…</div></div>';
  document.addEventListener('click', ev => {
    const a = ev.target.closest('.cal-table a[href*="event_view.php"], .cal-grid a[href*="event_view.php"]');
    if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button !== 0 || typeof bootstrap === 'undefined') return;
    ev.preventDefault();
    box.innerHTML = loading;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('calEventModal')).show();
    const url = new URL(a.href, location.href); url.searchParams.set('modal', '1');
    fetch(url, { credentials: 'same-origin' })
      .then(r => { if (!r.ok && r.status !== 404) throw 0; return r.text(); })
      .then(html => { if (!/modal-(body|header)/.test(html)) throw 0; box.innerHTML = html; })
      .catch(() => { location.href = a.href; });
  });
})();
</script>
HTML;
}
