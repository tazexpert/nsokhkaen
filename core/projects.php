<?php
/**
 * โครงการที่รับผิดชอบของสำนักงาน (core_projects) — จัดการที่ระบบบริหารโครงการ (modules/ea/projects.php)
 * ตัวย่อ (code) ใช้เป็นรหัสงานในแผนการปฏิบัติงาน (modules/calendar/plan*.php)
 * ผู้จัดการโครงการได้: ผู้ดูแลระบบกลาง ผู้ดูแลปฏิทิน (ผู้จัดทำแผนการปฏิบัติงาน) และผู้ดูแลระบบบริหารโครงการ
 */

/** คาบการสำรวจของโครงการ (core_projects.survey_period) */
const PROJECT_PERIODS = ['monthly' => 'รายเดือน', 'quarterly' => 'รายไตรมาส', 'yearly' => 'รายปี'];

function project_period_label(?string $p): string
{
    return PROJECT_PERIODS[(string)$p] ?? '';
}

/**
 * คาบการสำรวจจากประเภทการแจงนับในไฟล์นำเข้า: Q1–Q4 = รายไตรมาส · Month = รายเดือน
 * (บัญชีมอบหมายไม่มีประเภท: มีเดือน = รายเดือน · มีแต่ปี = รายปี) — คืน '' ถ้าระบุไม่ได้
 */
function project_period_detect(array $types, bool $hasMonth = true): string
{
    $q = $m = 0;
    foreach ($types as $t) {
        $t = strtoupper(trim((string)$t));
        if (preg_match('/^Q[1-4]$/', $t)) {
            $q++;
        } elseif ($t === 'MONTH' || $t === 'M') {
            $m++;
        } elseif ($t === 'YEAR' || $t === 'Y') {
            return 'yearly';
        }
    }
    if ($q || $m) {
        return $q >= $m ? 'quarterly' : 'monthly';
    }
    return $hasMonth ? '' : 'yearly';
}

/** ประเภทการแจงนับ (enum_type) ของ EA ใหม่ตามคาบการสำรวจของโครงการ */
function project_enum_type(string $period, ?int $month): string
{
    if ($period === 'quarterly' && $month) {
        return 'Q' . (int)ceil($month / 3);
    }
    return $period === 'monthly' ? 'Month' : '';
}

function projects_can_manage(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user && (!empty($user['is_superadmin']) || user_can('calendar', 'admin', $user) || user_can('ea', 'admin', $user));
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
