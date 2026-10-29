<?php
/**
 * ตารางการปฏิบัติงาน บนหน้าหลัก (/index.php) — แสดงก่อนกล่องเลือกระบบ
 * ตัวแปรจากหน้าหลัก: $user
 */
require_once __DIR__ . '/lib.php';
// ช่วงเวลา: วันนี้ / รายสัปดาห์ (ถึงวันอาทิตย์นี้) / รายเดือน (ถึงสิ้นเดือนนี้) / เดือนหน้า — ค่าเริ่มต้น รายเดือน / ทุกคน
$calRanges = ['today' => 'วันนี้', 'week' => 'รายสัปดาห์', 'month' => 'รายเดือน', 'next' => 'เดือนหน้า'];
$calRange = $_GET['cal_range'] ?? 'month';
$calRange = isset($calRanges[$calRange]) ? $calRange : 'month';
$calMine = !empty($_GET['cal_mine']);
$calDuty = !empty($_GET['cal_duty']);            // ค่าเริ่มต้นไม่แสดงเวรสำนักงาน
[$calFrom, $calTo] = match ($calRange) {
    'today' => [date('Y-m-d'), date('Y-m-d')],
    'week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
    'next'  => [date('Y-m-01', strtotime('first day of next month')), date('Y-m-t', strtotime('first day of next month'))],
    default => [date('Y-m-01'), date('Y-m-t')],
};
// รายการของวันที่ผ่านไปแล้วไม่แสดง (รายการของวันนี้ยังอยู่แม้จบแล้ว จะหายไปในวันถัดไป)
$calFrom = max($calFrom, date('Y-m-d'));
$calLabel = in_array($calRange, ['month', 'next'], true)
    ? thai_month_long((int)substr($calTo, 5, 2)) . ' ' . ((int)substr($calTo, 0, 4) + 543)
        . ($calFrom !== date('Y-m-01', strtotime($calTo)) ? ' (ตั้งแต่ ' . thai_date($calFrom) . ')' : '')
    : ($calFrom === $calTo ? cal_date_text($calFrom) : thai_date($calFrom) . ' – ' . thai_date($calTo));
$calEvents = cal_events_between($calFrom, $calTo, $calMine ? $user['id'] : null, $calDuty);
$calQs = fn(array $p) => '?' . http_build_query(array_filter($p + ['cal_range' => $calRange === 'month' ? null : $calRange, 'cal_mine' => $calMine ? 1 : null, 'cal_duty' => $calDuty ? 1 : null])) . '#calendar';
?>
<link href="<?= h(asset_v('modules/calendar/assets/css/calendar.css')) ?>" rel="stylesheet">
<div class="card mb-4 cal-home" id="calendar">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <strong class="me-2"><?= module_logo(module_def('calendar'), 22) ?> ตารางการปฏิบัติงาน</strong>
    <div class="btn-group btn-group-sm">
      <?php foreach ($calRanges as $r => $label): ?>
        <a href="<?= h($calQs(['cal_range' => $r === 'month' ? null : $r])) ?>" class="btn btn-outline-primary<?= $calRange === $r ? ' active' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <div class="btn-group btn-group-sm">
      <a href="<?= h($calQs(['cal_mine' => 0])) ?>" class="btn btn-outline-secondary<?= $calMine ? '' : ' active' ?>">ทุกคน</a>
      <a href="<?= h($calQs(['cal_mine' => 1])) ?>" class="btn btn-outline-secondary<?= $calMine ? ' active' : '' ?>">งานของฉัน</a>
    </div>
    <a href="<?= h($calQs(['cal_duty' => $calDuty ? 0 : 1])) ?>" class="btn btn-sm <?= $calDuty ? 'btn-success' : 'btn-outline-success' ?>" title="<?= $calDuty ? 'ซ่อน' : 'แสดง' ?>เวรสำนักงาน">
      <i class="bi <?= $calDuty ? 'bi-check-square' : 'bi-square' ?>"></i> เวรสำนักงาน</a>
    <div class="ms-auto d-flex gap-2">
      <a href="<?= h(module_url('calendar', 'index.php')) ?>" class="btn btn-sm btn-light"><i class="bi bi-calendar3"></i> ปฏิทินรายเดือน</a>
      <?php if (user_can('calendar', 'editor', $user)): ?><a href="<?= h(module_url('calendar', 'event_form.php')) ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a><?php endif; ?>
    </div>
  </div>
  <div class="px-3 pt-2 small text-muted"><i class="bi bi-calendar-range"></i> <?= h($calLabel) ?> · <?= count($calEvents) ?> รายการ</div>
  <?= cal_render_table($calEvents, $calFrom, $user['id']) ?>
</div>
<?php unset($calRanges, $calRange, $calLabel, $calMine, $calDuty, $calFrom, $calTo, $calEvents, $calQs); ?>
