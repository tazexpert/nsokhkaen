<?php
/** ตารางกิจกรรมตามช่วงวันที่ (ค่าเริ่มต้น: วันนี้ + 30 วัน) */
require_once __DIR__ . '/bootstrap.php';
$me = require_module_or_guest(CAL_MODULE);
$valid = fn($d) => is_string($d) && DateTime::createFromFormat('!Y-m-d', $d) ? $d : null;
$from = $valid($_GET['from'] ?? null) ?? date('Y-m-d');
$to = $valid($_GET['to'] ?? null) ?? date('Y-m-d', strtotime($from . ' +30 days'));
if ($to < $from) {
    $to = $from;
}
$guest = !empty($me['is_guest']);            // ผู้เยี่ยมชม: ดูได้อย่างเดียว ไม่มี "งานของฉัน"
$mine = !$guest && !empty($_GET['mine']);
$events = cal_events_between($from, $to, $mine ? $me['id'] : null);

$active = $mine ? 'mine' : 'list';
$pageTitle = $mine ? 'งานของฉัน' : 'ตารางกิจกรรม';
$pageActions = cal_can('editor') ? '<a href="import.php" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> นำเข้า CSV</a>'
    . '<a href="event_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a>' : '';
require __DIR__ . '/header.php';
?>
<div class="card">
  <div class="card-header no-print">
    <form class="row g-2 align-items-center">
      <div class="col-auto"><input type="date" name="from" class="form-control form-control-sm" value="<?= h($from) ?>"></div>
      <div class="col-auto">ถึง</div>
      <div class="col-auto"><input type="date" name="to" class="form-control form-control-sm" value="<?= h($to) ?>"></div>
      <div class="col-auto<?= $guest ? ' d-none' : '' ?>"><div class="form-check"><input class="form-check-input" type="checkbox" name="mine" value="1" id="mine" <?= $mine ? 'checked' : '' ?>>
        <label class="form-check-label" for="mine">เฉพาะงานของฉัน</label></div></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> แสดง</button></div>
      <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-light" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button></div>
    </form>
  </div>
  <div class="px-3 pt-2 small text-muted"><?= h(cal_date_text($from)) ?> – <?= h(cal_date_text($to)) ?> · <?= count($events) ?> กิจกรรม</div>
  <?= cal_render_table($events, $from, $me['id']) ?>
</div>
<?= cal_modal_script() ?>
<?php require __DIR__ . '/footer.php'; ?>
