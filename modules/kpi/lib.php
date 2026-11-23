<?php
/**
 * ระบบตัวชี้วัด — ฟังก์ชันกลาง
 *   ตัวชี้วัด (ปีงบประมาณ/รอบ) → ระดับ (ประเด็นการประเมิน · คะแนนเต็ม) → กิจกรรม/หลักฐาน (ผู้ปฏิบัติ · วันที่ต้องส่ง · คะแนนที่ได้)
 *   สิทธิ์: ผู้ปฏิบัติ = บันทึกความก้าวหน้าในกิจกรรมที่ได้รับมอบ · ผู้ควบคุมตัวชี้วัด = จัดการตัวชี้วัดของตน (ผู้ควบคุม/ผู้สร้าง)
 *   · ผู้ดูแลระบบ = ทุกตัวชี้วัด
 */
require_once APP_ROOT . '/modules/calendar/lib.php';   // รายชื่อบุคลากร/กลุ่มงานสำหรับช่องเลือกผู้ปฏิบัติ (แบบเดียวกับปฏิทิน)

const KPI_MODULE = 'kpi';
const KPI_FILE_DIR = 'kpi';          // ภายใต้ uploads/
const KPI_WARN_DAYS = 15;            // แจ้งเตือนก่อนวันที่ต้องส่ง
const KPI_STATUS = [
    'pending'   => ['ยังไม่ส่ง', 'secondary'],
    'submitted' => ['ส่งแล้ว รอตรวจ', 'warning'],
    'done'      => ['ตรวจแล้ว', 'success'],
];

function kpi_can(string $role): bool
{
    return user_can(KPI_MODULE, $role);
}

/** ปีงบประมาณ (พ.ศ.) และรอบของวันที่ — รอบ 1 = ต.ค.–มี.ค. · รอบ 2 = เม.ย.–ก.ย. */
function kpi_period_of(string $date): array
{
    $y = (int)substr($date, 0, 4) + 543;
    $m = (int)substr($date, 5, 2);
    return [$m >= 10 ? $y + 1 : $y, ($m >= 10 || $m <= 3) ? 1 : 2];
}

/** ช่วงวันที่ของรอบ [Y-m-d, Y-m-d] */
function kpi_round_range(int $fy, int $round): array
{
    $ce = $fy - 543;
    return $round === 1 ? [($ce - 1) . '-10-01', $ce . '-03-31'] : [$ce . '-04-01', $ce . '-09-30'];
}

function kpi_round_text(int $fy, int $round, bool $withRange = true): string
{
    [$a, $b] = kpi_round_range($fy, $round);
    return 'รอบที่ ' . $round . '/' . $fy . ($withRange ? ' (' . thai_date($a) . ' – ' . thai_date($b) . ')' : '');
}

/** ปีงบประมาณ/รอบจาก ?fy=&r= (ไม่ระบุ = รอบปัจจุบัน) */
function kpi_period_param(): array
{
    [$fy, $r] = kpi_period_of(date('Y-m-d'));
    $qfy = int_param('fy');
    $qr = int_param('r');
    return [$qfy >= 2500 && $qfy <= 2700 ? $qfy : $fy, in_array($qr, [1, 2], true) ? $qr : $r];
}

/** ปีงบประมาณที่มีตัวชี้วัด + ปีปัจจุบัน/ปีถัดไป (ตัวเลือกในหน้า) */
function kpi_years(): array
{
    [$fy] = kpi_period_of(date('Y-m-d'));
    $ys = array_map('intval', db()->query('SELECT DISTINCT fiscal_year FROM kpi_indicators')->fetchAll(PDO::FETCH_COLUMN));
    $ys = array_unique(array_merge($ys, [$fy, $fy + 1]));
    rsort($ys);
    return $ys;
}

/** มีคอลัมน์ is_office (migration 20261213) แล้วหรือยัง — ยังไม่มี = ซ่อนตัวเลือก "ตัวชี้วัดจากสำนักงาน" */
function kpi_has_office(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)db()->query("SHOW COLUMNS FROM kpi_indicators LIKE 'is_office'")->fetch();
        } catch (PDOException $e) {
            $has = false;
        }
    }
    return $has;
}

/**
 * ผู้อำนวยการกลุ่ม (ตัวเลือกแรกของผู้ควบคุมตัวชี้วัด) — แยกรายกลุ่มงาน [gid => ['user_id', 'name', 'label' => 'ผอ.กว', 'group']]
 * เรียงตามลำดับกลุ่มงาน (cal_work_groups) · คนเดียวเป็น ผอ. หลายกลุ่ม (ควบ/รักษาการ) = แสดงแยกแต่ละกลุ่ม
 */
function kpi_directors(): array
{
    $out = [];
    $users = cal_users();
    foreach (cal_work_groups() as $gid => $g) {
        $d = $g['director'];
        if (!$d || !isset($users[$d])) {
            continue;
        }
        $label = cal_position_abbr($g['director_label'] ?: (string)$users[$d]['position'], str_starts_with((string)$g['director_label'], 'รักษาการ') ? 'acting' : '');
        $out[(int)$gid] = ['user_id' => (int)$d, 'name' => preg_replace('/\s+/u', ' ', trim($users[$d]['full_name'])),
                           'label' => $label ?? 'ผอ.' . $g['name'], 'group' => $g['name']];
    }
    return $out;
}

/** ตัวชี้วัด 1 รายการ (พร้อมชื่อกลุ่มงาน/ผู้ควบคุม/ผู้จัดเก็บข้อมูล) */
function kpi_get(int $id): ?array
{
    $st = db()->prepare('SELECT i.*, g.name AS work_group_name, c.full_name AS controller_name, c.position AS controller_position, c.phone AS controller_phone,
                                d.full_name AS collector_name, d.position AS collector_position, d.phone AS collector_phone
                         FROM kpi_indicators i LEFT JOIN core_work_groups g ON g.id = i.work_group_id
                         LEFT JOIN core_users c ON c.id = i.controller_id LEFT JOIN core_users d ON d.id = i.collector_id WHERE i.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** จัดการตัวชี้วัดนี้ได้ (แก้ไข · ตรวจ/ให้คะแนน) — ผู้ดูแล หรือผู้ควบคุมตัวชี้วัดที่เป็นผู้ควบคุม/ผู้สร้างตัวชี้วัดนี้ */
function kpi_can_manage(?array $ind): bool
{
    if (kpi_can('admin')) {
        return true;
    }
    if (!$ind || !kpi_can('controller')) {
        return false;
    }
    $me = (int)(current_user()['id'] ?? 0);
    return $me && ((int)$ind['controller_id'] === $me || (int)$ind['created_by'] === $me);
}

/** ผู้ปฏิบัติของตัวชี้วัด [indicator_id => [user_id, ...]] */
function kpi_indicator_users(array $ids): array
{
    $out = [];
    $ids = array_filter(array_map('intval', $ids));
    if ($ids) {
        foreach (db()->query('SELECT indicator_id, user_id FROM kpi_indicator_users WHERE indicator_id IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int)$r['indicator_id']][] = (int)$r['user_id'];
        }
    }
    return $out;
}

/**
 * โครงสร้างเกณฑ์การให้คะแนนของตัวชี้วัด: [level, ...] แต่ละระดับมี 'activities' => [activity + 'users' (ผู้ปฏิบัติจริง) + 'own_users' (ที่ระบุ)]
 * กิจกรรมที่ไม่ระบุผู้ปฏิบัติ = ผู้ปฏิบัติของตัวชี้วัดทุกคน
 */
function kpi_structure(int $indicatorId, ?array $indUsers = null): array
{
    $indUsers ??= kpi_indicator_users([$indicatorId])[$indicatorId] ?? [];
    $st = db()->prepare('SELECT * FROM kpi_levels WHERE indicator_id = ? ORDER BY level_no, id');
    $st->execute([$indicatorId]);
    $levels = [];
    foreach ($st as $l) {
        $l['activities'] = [];
        $levels[(int)$l['id']] = $l;
    }
    $st = db()->prepare('SELECT * FROM kpi_activities WHERE indicator_id = ? ORDER BY sort_order, id');
    $st->execute([$indicatorId]);
    $acts = $st->fetchAll();
    $au = [];
    if ($acts) {
        foreach (db()->query('SELECT activity_id, user_id FROM kpi_activity_users WHERE activity_id IN (' . implode(',', array_map('intval', array_column($acts, 'id'))) . ')') as $r) {
            $au[(int)$r['activity_id']][] = (int)$r['user_id'];
        }
    }
    foreach ($acts as $a) {
        $a['own_users'] = $au[(int)$a['id']] ?? [];
        $a['users'] = $a['own_users'] ?: $indUsers;
        if (isset($levels[(int)$a['level_id']])) {
            $levels[(int)$a['level_id']]['activities'][] = $a;
        }
    }
    return array_values($levels);
}

/** คะแนน: [ได้, เต็ม] — คะแนนเต็มของระดับ (ไม่ระบุ = รวมคะแนนเต็มของกิจกรรม) · ได้ = คะแนนของกิจกรรมที่ตรวจแล้ว (ไม่เกินคะแนนเต็มของระดับ) */
function kpi_score(array $levels): array
{
    $got = 0.0;
    $max = 0.0;
    foreach ($levels as $l) {
        $lmax = $l['max_score'] !== null ? (float)$l['max_score'] : array_sum(array_map(fn($a) => (float)$a['max_score'], $l['activities']));
        $lgot = array_sum(array_map(fn($a) => $a['status'] === 'done' ? (float)$a['score'] : 0.0, $l['activities']));
        $max += $lmax;
        $got += $lmax > 0 ? min($lgot, $lmax) : $lgot;
    }
    return [$got, $max];
}

/** สถิติกิจกรรม: total / done / submitted / overdue / soon (ภายใน 15 วัน ยังไม่ส่ง) */
function kpi_activity_stats(array $levels): array
{
    $s = ['total' => 0, 'done' => 0, 'submitted' => 0, 'overdue' => 0, 'soon' => 0];
    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+' . KPI_WARN_DAYS . ' days'));
    foreach ($levels as $l) {
        foreach ($l['activities'] as $a) {
            $s['total']++;
            if ($a['status'] === 'done') {
                $s['done']++;
            } elseif ($a['status'] === 'submitted') {
                $s['submitted']++;
            } elseif ($a['due_date']) {
                if ($a['due_date'] < $today) {
                    $s['overdue']++;
                } elseif ($a['due_date'] <= $soon) {
                    $s['soon']++;
                }
            }
        }
    }
    return $s;
}

/** บันทึกความก้าวหน้า/ส่งหลักฐานในกิจกรรมนี้ได้ — ผู้ปฏิบัติของกิจกรรม หรือผู้จัดการตัวชี้วัด */
function kpi_can_report(array $ind, array $activity): bool
{
    $me = (int)(current_user()['id'] ?? 0);
    return kpi_can_manage($ind) || ($me && in_array($me, $activity['users'], true));
}

function kpi_num($v): string
{
    if ($v === null || $v === '') {
        return '-';
    }
    $f = (float)$v;
    return rtrim(rtrim(number_format($f, 2), '0'), '.');
}

/** ชื่อบุคลากร [id => ชื่อ-สกุล] */
function kpi_user_names(): array
{
    static $names = null;
    if ($names === null) {
        $names = [];
        foreach (db()->query('SELECT id, full_name FROM core_users') as $u) {
            $names[(int)$u['id']] = preg_replace('/\s+/u', ' ', trim($u['full_name']));
        }
    }
    return $names;
}

/** สถานะกิจกรรม (badge) — ยังไม่ส่งและเลยกำหนด = เกินกำหนด · ภายใน 15 วัน = ใกล้ครบกำหนด */
function kpi_status_badge(array $a): string
{
    [$label, $color] = KPI_STATUS[$a['status']] ?? KPI_STATUS['pending'];
    if ($a['status'] === 'pending' && $a['due_date']) {
        $days = (int)floor((strtotime($a['due_date']) - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0) {
            return '<span class="badge text-bg-danger">เกินกำหนด ' . -$days . ' วัน</span>';
        }
        if ($days <= KPI_WARN_DAYS) {
            return '<span class="badge text-bg-warning">' . ($days === 0 ? 'ครบกำหนดวันนี้' : 'อีก ' . $days . ' วัน') . '</span>';
        }
    }
    return '<span class="badge text-bg-' . $color . '">' . $label . '</span>';
}

/** บันทึกความก้าวหน้าของกิจกรรม [activity_id => [progress + 'files']] (ใหม่ก่อน) */
function kpi_progress_of(array $activityIds): array
{
    $out = [];
    $ids = array_filter(array_map('intval', $activityIds));
    if (!$ids) {
        return $out;
    }
    $rows = db()->query('SELECT p.*, u.full_name FROM kpi_progress p LEFT JOIN core_users u ON u.id = p.user_id
                         WHERE p.activity_id IN (' . implode(',', $ids) . ') ORDER BY p.id DESC')->fetchAll();
    $files = [];
    if ($rows) {
        foreach (db()->query('SELECT id, progress_id, file_name, file_size FROM kpi_files WHERE progress_id IN (' . implode(',', array_map('intval', array_column($rows, 'id'))) . ') ORDER BY id') as $f) {
            $files[(int)$f['progress_id']][] = $f;
        }
    }
    foreach ($rows as $r) {
        $r['files'] = $files[(int)$r['id']] ?? [];
        $out[(int)$r['activity_id']][] = $r;
    }
    return $out;
}

/** บันทึกไฟล์หลักฐาน (ตรวจแล้วด้วย msg_check_files) — ชื่อไฟล์สุ่มใหม่ เก็บชื่อเดิมไว้แสดง */
function kpi_save_files(int $progressId, array $files): void
{
    $sub = KPI_FILE_DIR . '/' . date('Ym');
    $dir = UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ uploads/' . $sub);
    }
    if (!is_file(UPLOAD_DIR . '/' . KPI_FILE_DIR . '/.htaccess')) {    // ห้ามเปิดไฟล์ตรงจาก URL (IIS ใช้ web.config ในโฟลเดอร์)
        @file_put_contents(UPLOAD_DIR . '/' . KPI_FILE_DIR . '/.htaccess', "Require all denied\n");
    }
    $ins = db()->prepare('INSERT INTO kpi_files (progress_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?)');
    foreach ($files as $f) {
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ ตรวจสอบสิทธิ์การเขียนโฟลเดอร์ uploads/kpi');
        }
        $orig = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]/u', '_', basename((string)$f['name'])), 0, 200);
        $ins->execute([$progressId, $sub . '/' . $name, $orig ?: $name, (int)$f['size']]);
    }
}

/** ลบไฟล์จริงของกิจกรรม (ก่อนลบกิจกรรม/ระดับ/ตัวชี้วัด — แถวในตารางลบตาม FK) */
function kpi_delete_files_of(string $where, array $params): void
{
    $st = db()->prepare("SELECT f.file_path FROM kpi_files f JOIN kpi_progress p ON p.id = f.progress_id JOIN kpi_activities a ON a.id = p.activity_id WHERE $where");
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $path) {
        delete_upload($path);
    }
}

/** ข้อความหลายบรรทัด → HTML */
function kpi_text(?string $s): string
{
    return nl2br(h(trim((string)$s)));
}
