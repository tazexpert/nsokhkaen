<?php
/** รายละเอียดกิจกรรม — ?modal=1 คืนเฉพาะเนื้อหาสำหรับ popup (ตารางการปฏิบัติงาน / ปฏิทิน) */
require_once __DIR__ . '/bootstrap.php';
$me = require_module_or_guest(CAL_MODULE);
$modal = !empty($_GET['modal']);
$st = db()->prepare('SELECT e.*, u.full_name AS created_by_name FROM cal_events e LEFT JOIN core_users u ON u.id = e.created_by WHERE e.id = ?');
$st->execute([int_param('id')]);
$e = $st->fetch();
if ($e && !cal_can_see($e)) {        // กิจกรรมส่วนตัวของผู้อื่น = ทำเหมือนไม่มี
    $e = null;
}
if (!$e && $modal) {
    http_response_code(404);
    exit('<div class="modal-body text-center text-muted py-5">ไม่พบกิจกรรม (อาจถูกลบไปแล้ว)</div>');
}
if (!$e) {
    flash('ไม่พบกิจกรรม', 'danger');
    redirect('./');
}
$users = cal_users();
$assignedIds = cal_assignees([(int)$e['id']])[(int)$e['id']] ?? [];
$ids = array_values(array_filter(array_keys($users), fn($id) => in_array($id, $assignedIds, true)));   // เฉพาะเจ้าหน้าที่ เรียงตามลำดับตำแหน่ง
$link = cal_safe_link($e['link']);
$active = 'month';
$pageTitle = $e['title'];
$pageActions = '<a href="./?m=' . h(substr($e['event_date'], 0, 7)) . '" class="btn btn-light"><i class="bi bi-calendar3"></i> ปฏิทิน</a>'
    . (cal_can_edit($e) ? '<a href="event_form?id=' . (int)$e['id'] . '" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> แก้ไข</a>' : '');
ob_start();
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card h-100"><div class="card-body">
    <?php if (in_array($me['id'], $ids, true)): ?><div class="alert alert-warning py-2"><i class="bi bi-person-check"></i> คุณเป็นผู้รับผิดชอบกิจกรรมนี้</div><?php endif; ?>
    <dl class="row mb-0">
      <?php if (cal_is_private($e)): ?><dt class="col-sm-3">การมองเห็น</dt><dd class="col-sm-9"><span class="badge text-bg-secondary"><i class="bi bi-lock-fill"></i> ส่วนตัว</span> <span class="small text-muted">เห็นเฉพาะผู้บันทึกและผู้รับผิดชอบ</span></dd><?php endif; ?>
      <dt class="col-sm-3">วันที่</dt><dd class="col-sm-9"><?= h(cal_range_text($e)) ?></dd>
      <?php if (cal_is_guard($e)): ?><dt class="col-sm-3">ประเภท</dt><dd class="col-sm-9"><span class="badge text-bg-danger"><i class="bi bi-shield-shaded"></i> เวรศาลากลาง</span>
        <span class="small text-muted">จากแผนการปฏิบัติงาน<?= cal_can('admin') ? ' · <a href="plan_edit?m=' . h(substr($e['event_date'], 0, 7)) . '">แก้ไขที่แผนการปฏิบัติงาน</a>' : '' ?></span></dd><?php endif; ?>
      <?php if (!cal_is_shift($e)): ?><dt class="col-sm-3">เวลา</dt><dd class="col-sm-9"><?= h(cal_time_text($e)) ?></dd><?php endif; ?>
      <?php if (!cal_is_duty($e)): ?><dt class="col-sm-3">สถานที่</dt><dd class="col-sm-9"><?= h($e['location'] ?: '-') ?></dd><?php endif; ?>
      <dt class="col-sm-3">ลิงก์ประกอบ</dt><dd class="col-sm-9"><?= $link ? '<a href="' . h($link) . '" target="_blank" rel="noopener" class="text-break">' . h($link) . '</a>' : '-' ?></dd>
      <dt class="col-sm-3">หมายเหตุ</dt><dd class="col-sm-9"><?= $e['note'] ? nl2br(h($e['note'])) : '-' ?></dd>
      <dt class="col-sm-3 text-muted small">บันทึกโดย</dt><dd class="col-sm-9 text-muted small"><?= h($e['created_by_name'] ?: '-') ?> · <?= h(thai_date($e['created_at'])) ?></dd>
    </dl>
  </div></div></div>
  <div class="col-lg-5"><div class="card h-100">
    <div class="card-header"><strong><i class="bi bi-people"></i> ผู้รับผิดชอบ</strong> <span class="badge text-bg-primary"><?= count($ids) ?> คน</span>
      <?php $group = cal_assignee_html($ids, $me['id'], 0); if (str_contains($group, 'cal-person')): ?><div class="small mt-1"><?= $group ?></div><?php endif; ?></div>
    <ul class="list-group list-group-flush" style="max-height:60vh;overflow:auto">
      <?php foreach ($ids as $uid): if (!isset($users[$uid])) continue; $u = $users[$uid]; ?>
        <li class="list-group-item<?= $uid === $me['id'] ? ' list-group-item-warning' : '' ?>"><?= h($u['full_name']) ?>
          <span class="d-block small text-muted"><?= h($u['position'] ?: '-') ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div></div>
</div>
<?php
$detail = ob_get_clean();
if ($modal) {                     // popup: หัว + เนื้อหา + ปุ่ม (ไม่มี layout)
    $base = module_url(CAL_MODULE);
    // หัวแบบโปสเตอร์: สีตามประเภท (ของฉัน บานเย็น · เวรสำนักงาน เขียว · เวรศาลากลาง แดง · อื่น ๆ ฟ้า) + บล็อกวันที่
    $isMine = in_array($me['id'], $assignedIds, true);      // ผู้รับผิดชอบทั้งหมด (เหมือนสีในปฏิทิน)
    [$ec, $kind, $icon] = cal_is_duty($e) ? ['#16a34a', 'เวรสำนักงาน', 'bi-shield-check'] : (cal_is_guard($e) ? ['#dc2626', 'เวรศาลากลาง', 'bi-shield-shaded']
        : ($isMine ? ['#db2777', 'งานของคุณ', 'bi-person-check'] : ['#0284c7', 'กิจกรรม', 'bi-calendar-event']));
    $mon = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][(int)substr($e['event_date'], 5, 2)];
    $dayNo = (int)substr($e['event_date'], 8, 2) . ($e['end_date'] && $e['end_date'] !== $e['event_date'] && substr($e['end_date'], 0, 7) === substr($e['event_date'], 0, 7) ? '–' . (int)substr($e['end_date'], 8, 2) : '');
    $sub = cal_is_shift($e) ? cal_range_text($e) : cal_range_text($e) . ' · ' . cal_time_text($e) . ($e['location'] ? ' · ' . $e['location'] : '');
    echo '<div class="modal-header ev-poster" style="--ec:' . $ec . '">'
        . '<div class="ev-date"><div class="d">' . h($dayNo) . '</div><div class="m">' . h($mon) . '</div></div>'
        . '<div class="min-w-0 flex-grow-1"><div class="ev-kind"><i class="bi ' . $icon . '"></i> ' . $kind . (cal_is_private($e) ? ' · <i class="bi bi-lock-fill"></i> ส่วนตัว' : '') . '</div>'
        . '<h5 class="modal-title">' . h($e['title']) . '</h5><div class="ev-sub">' . h($sub) . '</div></div>'
        . '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button></div>'
        . '<div class="modal-body ev-poster-body" style="--ec:' . $ec . '">' . $detail . '</div>'
        . '<div class="modal-footer">'
        . '<a href="' . h($base . '?m=' . substr($e['event_date'], 0, 7)) . '" class="btn btn-light me-auto"><i class="bi bi-calendar3"></i> ดูในปฏิทิน</a>'
        . (cal_can_edit($e) ? '<a href="' . h($base . 'event_form?id=' . (int)$e['id']) . '" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> แก้ไข</a>' : '')
        . '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">ปิด</button></div>';
    exit;
}
require __DIR__ . '/header.php';
echo $detail;
require __DIR__ . '/footer.php';
