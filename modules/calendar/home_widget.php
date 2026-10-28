<?php
/**
 * ตารางการปฏิบัติงาน บนหน้าหลัก (/index.php) — แสดงก่อนกล่องเลือกระบบ
 * ตัวแปรจากหน้าหลัก: $user
 */
require_once __DIR__ . '/lib.php';
$calDays = (int)($_GET['cal_days'] ?? 7);
$calDays = in_array($calDays, [1, 7, 14, 30], true) ? $calDays : 7;
$calMine = !empty($_GET['cal_mine']);
$calFrom = date('Y-m-d');
$calTo = date('Y-m-d', strtotime('+' . ($calDays - 1) . ' days'));
$calEvents = cal_events_between($calFrom, $calTo, $calMine ? $user['id'] : null);
$calQs = fn(array $p) => '?' . http_build_query(array_filter($p + ['cal_days' => $calDays, 'cal_mine' => $calMine ? 1 : null])) . '#calendar';
?>
<div class="card mb-4 cal-home" id="calendar">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <strong class="me-2"><?= module_logo(module_def('calendar'), 22) ?> ตารางการปฏิบัติงาน</strong>
    <div class="btn-group btn-group-sm">
      <?php foreach ([1 => 'วันนี้', 7 => '7 วัน', 14 => '14 วัน', 30 => '30 วัน'] as $d => $label): ?>
        <a href="<?= h($calQs(['cal_days' => $d])) ?>" class="btn btn-outline-primary<?= $calDays === $d ? ' active' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <div class="btn-group btn-group-sm">
      <a href="<?= h('?' . http_build_query(['cal_days' => $calDays]) . '#calendar') ?>" class="btn btn-outline-secondary<?= $calMine ? '' : ' active' ?>">ทุกคน</a>
      <a href="<?= h($calQs(['cal_mine' => 1])) ?>" class="btn btn-outline-secondary<?= $calMine ? ' active' : '' ?>">งานของฉัน</a>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="<?= h(module_url('calendar', 'index.php')) ?>" class="btn btn-sm btn-light"><i class="bi bi-calendar3"></i> ปฏิทินรายเดือน</a>
      <?php if (user_can('calendar', 'editor', $user)): ?><a href="<?= h(module_url('calendar', 'event_form.php')) ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a><?php endif; ?>
    </div>
  </div>
  <?= cal_render_table($calEvents, $calFrom, $user['id']) ?>
</div>
<?php unset($calDays, $calMine, $calFrom, $calTo, $calEvents, $calQs); ?>
