<?php
/**
 * โครงการที่รับผิดชอบของสำนักงาน (core_projects) — จัดการที่ projects.php
 * ตัวย่อ (code) ใช้เป็นรหัสงานในแผนการปฏิบัติงาน (modules/calendar/plan*.php)
 * ผู้จัดการโครงการได้: ผู้ดูแลระบบกลาง และผู้ดูแลปฏิทิน (ผู้จัดทำแผนการปฏิบัติงาน)
 */

function projects_can_manage(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && (!empty($user['is_superadmin']) || user_can('calendar', 'admin', $user));
}

/** โครงการทั้งหมด [id => row] เรียงตามลำดับ */
function projects_all(): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (db()->query('SELECT * FROM core_projects ORDER BY sort_order, id') as $p) {
            $all[(int)$p['id']] = $p;
        }
    }
    return $all;
}

/** โครงการมีช่วงปฏิบัติงานคาบเกี่ยวช่วงวันที่ที่กำหนด (ไม่ระบุช่วง = ทุกช่วง) */
function project_in_range(array $p, string $from, string $to): bool
{
    return (!$p['start_date'] || $p['start_date'] <= $to) && (!$p['end_date'] || $p['end_date'] >= $from);
}

/** สถานะ: [ข้อความ, สี] */
function project_status(array $p): array
{
    $today = date('Y-m-d');
    if (!$p['is_active']) {
        return ['ปิดใช้งาน', 'secondary'];
    }
    if ($p['start_date'] && $p['start_date'] > $today) {
        return ['ยังไม่เริ่ม', 'info'];
    }
    if ($p['end_date'] && $p['end_date'] < $today) {
        return ['สิ้นสุดแล้ว', 'dark'];
    }
    return ['ดำเนินการ', 'success'];
}

/** ช่วงวันที่แบบไทย เช่น "1 ต.ค. 2569 – 30 ก.ย. 2570" */
function project_period_text(array $p): string
{
    if (!$p['start_date'] && !$p['end_date']) {
        return '-';
    }
    return ($p['start_date'] ? thai_date($p['start_date']) : '…') . ' – ' . ($p['end_date'] ? thai_date($p['end_date']) : '…');
}
