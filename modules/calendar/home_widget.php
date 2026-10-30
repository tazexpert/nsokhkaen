<?php
/**
 * ตารางการปฏิบัติงาน บนหน้าหลัก (/index.php) — แสดงก่อนกล่องเลือกระบบ
 * ผู้เยี่ยมชม (ไม่ได้ login): ดูได้อย่างเดียว ไม่มี "งานของฉัน" / "เพิ่มกิจกรรม"
 */
require_once __DIR__ . '/lib.php';
$calUser = current_user() ?? guest_user();
$calGuest = !empty($calUser['is_guest']);
// ช่วงเวลา: ทั้งหมด (ตั้งแต่วันนี้) / วันนี้ / รายสัปดาห์ (ถึงวันอาทิตย์นี้) / รายเดือน (ถึงสิ้นเดือนนี้) / เดือนหน้า — ค่าเริ่มต้น ทั้งหมด / ทุกคน
$calRanges = ['all' => 'ทั้งหมด', 'today' => 'วันนี้', 'week' => 'รายสัปดาห์', 'month' => 'รายเดือน', 'next' => 'เดือนหน้า'];
$calRange = $_GET['cal_range'] ?? 'all';
$calRange = isset($calRanges[$calRange]) ? $calRange : 'all';
$calMine = !$calGuest && !empty($_GET['cal_mine']);
$calDuty = !empty($_GET['cal_duty']);            // ค่าเริ่มต้นไม่แสดงเวรสำนักงาน
[$calFrom, $calTo] = match ($calRange) {
    'today' => [date('Y-m-d'), date('Y-m-d')],
    'week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
    'next'  => [date('Y-m-01', strtotime('first day of next month')), date('Y-m-t', strtotime('first day of next month'))],
    'month' => [date('Y-m-01'), date('Y-m-t')],
    default => [date('Y-m-d'), '9999-12-31'],
};
// รายการของวันที่ผ่านไปแล้วไม่แสดง (รายการของวันนี้ยังอยู่แม้จบแล้ว จะหายไปในวันถัดไป)
$calFrom = max($calFrom, date('Y-m-d'));
$calLabel = $calRange === 'all' ? 'ทั้งหมด ตั้งแต่ ' . thai_date($calFrom) : (in_array($calRange, ['month', 'next'], true)
    ? thai_month_long((int)substr($calTo, 5, 2)) . ' ' . ((int)substr($calTo, 0, 4) + 543)
        . ($calFrom !== date('Y-m-01', strtotime($calTo)) ? ' (ตั้งแต่ ' . thai_date($calFrom) . ')' : '')
    : ($calFrom === $calTo ? cal_date_text($calFrom) : thai_date($calFrom) . ' – ' . thai_date($calTo)));
$calEvents = cal_events_between($calFrom, $calTo, $calMine ? $calUser['id'] : null, $calDuty);
$calQs = fn(array $p) => '?' . http_build_query(array_filter($p + ['cal_range' => $calRange === 'all' ? null : $calRange, 'cal_mine' => $calMine ? 1 : null, 'cal_duty' => $calDuty ? 1 : null]));
?>
<link href="<?= h(asset_v('modules/calendar/assets/css/calendar.css')) ?>" rel="stylesheet">
<div class="card mb-4 cal-home whiteboard" id="calendar">
 <div class="wb-surface">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <strong class="me-2"><?= module_logo(module_def('calendar'), 22) ?> ตารางการปฏิบัติงาน</strong>
    <div class="btn-group btn-group-sm">
      <?php foreach ($calRanges as $r => $label): ?>
        <a href="<?= h($calQs(['cal_range' => $r === 'all' ? null : $r])) ?>" class="btn btn-outline-primary<?= $calRange === $r ? ' active' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$calGuest): ?>
    <div class="btn-group btn-group-sm">
      <a href="<?= h($calQs(['cal_mine' => 0])) ?>" class="btn btn-outline-secondary<?= $calMine ? '' : ' active' ?>">ทุกคน</a>
      <a href="<?= h($calQs(['cal_mine' => 1])) ?>" class="btn btn-outline-secondary<?= $calMine ? ' active' : '' ?>">งานของฉัน</a>
    </div>
    <?php endif; ?>
    <a href="<?= h($calQs(['cal_duty' => $calDuty ? null : 1])) ?>" class="btn btn-sm <?= $calDuty ? 'btn-success' : 'btn-outline-success' ?>" title="<?= $calDuty ? 'ซ่อน' : 'แสดง' ?>เวรสำนักงาน">
      <i class="bi <?= $calDuty ? 'bi-check-square' : 'bi-square' ?>"></i> เวรสำนักงาน</a>
    <div class="ms-auto d-flex gap-2">
      <a href="<?= h(module_url('calendar', 'index.php')) ?>" class="btn btn-sm btn-light"><i class="bi bi-calendar3"></i> ปฏิทินรายเดือน</a>
      <?php if (!$calGuest && user_can('calendar', 'editor', $calUser)): ?><a href="<?= h(module_url('calendar', 'event_form.php')) ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a><?php endif; ?>
    </div>
  </div>
  <div class="px-3 pt-2 small text-muted"><i class="bi bi-calendar-range"></i> <?= h($calLabel) ?> · <?= count($calEvents) ?> รายการ</div>
  <?= cal_render_table($calEvents, $calFrom, (int)$calUser['id'], !$calGuest) ?>
 </div>
 <div class="wb-tray" aria-hidden="true"><span class="wb-marker blue"></span><span class="wb-marker red"></span><span class="wb-marker black"></span><span class="wb-eraser"></span></div>
</div>
<?= cal_modal_script() ?>
<?php unset($calUser, $calGuest, $calRanges, $calRange, $calLabel, $calMine, $calDuty, $calFrom, $calTo, $calEvents, $calQs); ?>
