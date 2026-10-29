<?php
/** รายละเอียดกิจกรรม */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(CAL_MODULE);
$st = db()->prepare('SELECT e.*, u.full_name AS created_by_name FROM cal_events e LEFT JOIN core_users u ON u.id = e.created_by WHERE e.id = ?');
$st->execute([int_param('id')]);
$e = $st->fetch();
if (!$e) {
    flash('ไม่พบกิจกรรม', 'danger');
    redirect('index.php');
}
$ids = cal_assignees([(int)$e['id']])[(int)$e['id']] ?? [];
$users = cal_users();
$link = cal_safe_link($e['link']);
$active = 'month';
$pageTitle = $e['title'];
$pageActions = '<a href="index.php?m=' . h(substr($e['event_date'], 0, 7)) . '" class="btn btn-light"><i class="bi bi-calendar3"></i> ปฏิทิน</a>'
    . (cal_can_edit($e) ? '<a href="event_form.php?id=' . (int)$e['id'] . '" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> แก้ไข</a>' : '');
require __DIR__ . '/header.php';
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card h-100"><div class="card-body">
    <?php if (in_array($me['id'], $ids, true)): ?><div class="alert alert-warning py-2"><i class="bi bi-person-check"></i> คุณเป็นผู้รับผิดชอบกิจกรรมนี้</div><?php endif; ?>
    <dl class="row mb-0">
      <dt class="col-sm-3">วันที่</dt><dd class="col-sm-9"><?= h(cal_range_text($e)) ?></dd>
      <?php if (!cal_is_duty($e)): ?><dt class="col-sm-3">เวลา</dt><dd class="col-sm-9"><?= h(cal_time_text($e)) ?></dd><?php endif; ?>
      <?php if (!cal_is_duty($e)): ?><dt class="col-sm-3">สถานที่</dt><dd class="col-sm-9"><?= h($e['location'] ?: '-') ?></dd><?php endif; ?>
      <dt class="col-sm-3">ลิงก์ประกอบ</dt><dd class="col-sm-9"><?= $link ? '<a href="' . h($link) . '" target="_blank" rel="noopener" class="text-break">' . h($link) . '</a>' : '-' ?></dd>
      <dt class="col-sm-3">หมายเหตุ</dt><dd class="col-sm-9"><?= $e['note'] ? nl2br(h($e['note'])) : '-' ?></dd>
      <dt class="col-sm-3 text-muted small">บันทึกโดย</dt><dd class="col-sm-9 text-muted small"><?= h($e['created_by_name'] ?: '-') ?> · <?= h(thai_date($e['created_at'])) ?></dd>
    </dl>
  </div></div></div>
  <div class="col-lg-5"><div class="card h-100">
    <div class="card-header"><strong><i class="bi bi-people"></i> ผู้รับผิดชอบ</strong> <span class="badge text-bg-primary"><?= count($ids) ?> คน</span>
      <div class="small mt-1"><?= cal_assignee_html($ids, $me['id'], 0) ?></div></div>
    <ul class="list-group list-group-flush" style="max-height:60vh;overflow:auto">
      <?php foreach ($ids as $uid): if (!isset($users[$uid])) continue; $u = $users[$uid]; ?>
        <li class="list-group-item<?= $uid === $me['id'] ? ' list-group-item-warning' : '' ?>"><?= h($u['full_name']) ?>
          <span class="d-block small text-muted"><?= h($u['position'] ?: '-') ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div></div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
