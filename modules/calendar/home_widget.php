<?php
/**
 * ปฏิทินการปฏิบัติงาน (รายเดือน) บนหน้าหลัก (/index.php) — แสดงก่อนกล่องเลือกระบบ · ใช้ month_view.php ร่วมกับหน้าปฏิทินรายเดือน
 * พารามิเตอร์บนหน้าหลัก: cal_m (เดือน Y-m) / cal_mine (งานของฉัน) · ผู้เยี่ยมชม (ไม่ได้ login): ดูได้อย่างเดียว
 * เปิดหน้า = popup สรุปรายวันของวันนี้ (เหมือนหน้าปฏิทินรายเดือน) · คลิกวันที่เพื่อเปลี่ยนวัน
 */
require_once __DIR__ . '/lib.php';
(function () {                                    // แยกตัวแปรออกจากหน้าหลัก
    $me = current_user() ?? guest_user();
    $guest = !empty($me['is_guest']);
    $m = $_GET['cal_m'] ?? date('Y-m');
    if (!is_string($m) || !preg_match('/^\d{4}-\d{2}$/', $m) || !checkdate((int)substr($m, 5, 2), 1, (int)substr($m, 0, 4))) {
        $m = date('Y-m');
    }
    $mine = !$guest && !empty($_GET['cal_mine']);
    $qs = fn(array $p) => '?' . http_build_query(array_filter([
        'cal_m'    => ($p['m'] ?? $m) === date('Y-m') ? null : ($p['m'] ?? $m),
        'cal_mine' => array_key_exists('mine', $p) ? $p['mine'] : ($mine ? 1 : null),
    ])) . '#calendar';
    $calBase = module_url('calendar', '');
    $calAutoOpen = true;
    ?>
<link href="<?= h(asset_v('modules/calendar/assets/css/calendar.css')) ?>" rel="stylesheet">
<div id="calendar" class="cal-home-month mb-4">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <div class="home-section-title tone-blue mb-0"><i class="bi bi-calendar3"></i> ปฏิทินการปฏิบัติงาน <small>คลิกวันที่เพื่อดูสรุปรายวัน</small></div>
    <div class="ms-auto d-flex gap-2">
      <a href="<?= h(module_url('calendar', 'list')) ?>" class="btn btn-sm btn-light"><i class="bi bi-list-task"></i> ตารางกิจกรรม</a>
      <?php if (!$guest && user_can('calendar', 'editor', $me)): ?><a href="<?= h(module_url('calendar', 'event_form')) ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a><?php endif; ?>
    </div>
  </div>
  <?php require __DIR__ . '/month_view.php'; ?>
</div>
<?= cal_modal_script() ?>
<?php
})();
