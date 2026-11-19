<?php
/** ปฏิทินรายเดือน: กิจกรรมของทุกคนในสำนักงาน (กิจกรรมที่ตนรับผิดชอบเน้นสี) · คลิกวันที่ = แผงสรุปรายวันด้านขวา (ใครมีกิจกรรมบ้าง) */
require_once __DIR__ . '/bootstrap.php';
$me = require_module_or_guest(CAL_MODULE);
$m = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $m) || !checkdate((int)substr($m, 5, 2), 1, (int)substr($m, 0, 4))) {
    $m = date('Y-m');
}
$guest = !empty($me['is_guest']);            // ผู้เยี่ยมชม: ดูได้อย่างเดียว ไม่มี "งานของฉัน"
$mine = !$guest && !empty($_GET['mine']);
$qs = fn(array $p) => '?' . http_build_query(array_filter($p + ['m' => $m, 'mine' => $mine ? 1 : null]));
$calBase = '';
$calAutoOpen = true;

$active = $mine ? 'mine' : 'month';
$pageTitle = 'ปฏิทินการปฏิบัติงาน';
$pageActions = (cal_can('admin') ? '<a href="import" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> นำเข้า CSV</a>' : '')
    . (cal_can('editor') ? '<a href="event_form" class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a>' : '');
require __DIR__ . '/header.php';
?>
<?php require __DIR__ . '/month_view.php'; ?>
<?= cal_modal_script() ?>
<?php require __DIR__ . '/footer.php'; ?>
