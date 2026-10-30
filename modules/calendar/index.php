<?php
/** ปฏิทินรายเดือน: กิจกรรมของทุกคนในสำนักงาน (กิจกรรมที่ตนรับผิดชอบเน้นสี) */
require_once __DIR__ . '/bootstrap.php';
$me = require_module_or_guest(CAL_MODULE);
$m = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $m) || !checkdate((int)substr($m, 5, 2), 1, (int)substr($m, 0, 4))) {
    $m = date('Y-m');
}
$guest = !empty($me['is_guest']);            // ผู้เยี่ยมชม: ดูได้อย่างเดียว ไม่มี "งานของฉัน"
$mine = !$guest && !empty($_GET['mine']);
$first = $m . '-01';
$last = date('Y-m-t', strtotime($first));
$gridStart = date('Y-m-d', strtotime($first . ' -' . date('w', strtotime($first)) . ' days'));          // อาทิตย์ก่อนวันที่ 1
$gridEnd = date('Y-m-d', strtotime($last . ' +' . (6 - (int)date('w', strtotime($last))) . ' days'));    // เสาร์หลังวันสุดท้าย
$events = cal_events_between($gridStart, $gridEnd, $mine ? $me['id'] : null);
$assignees = cal_assignees(array_column($events, 'id'));
$byDay = [];
foreach ($events as $e) {
    $d = max($e['event_date'], $gridStart);
    $end = min($e['end_date'] ?: $e['event_date'], $gridEnd);
    for (; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $byDay[$d][] = $e;
    }
}
$ts = strtotime($first);
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$today = date('Y-m-d');
$qs = fn(array $p) => '?' . http_build_query(array_filter($p + ['m' => $m, 'mine' => $mine ? 1 : null]));

$active = $mine ? 'mine' : 'month';
$pageTitle = 'ปฏิทินการปฏิบัติงาน';
$pageActions = cal_can('editor') ? '<a href="import.php" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> นำเข้า CSV</a>'
    . '<a href="event_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a>' : '';
require __DIR__ . '/header.php';
?>
<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center gap-2">
    <a href="<?= h($qs(['m' => $prev])) ?>" class="btn btn-light btn-sm"><i class="bi bi-chevron-left"></i></a>
    <h2 class="h5 mb-0 mx-1"><?= h(thai_month_long((int)date('n', $ts))) ?> <?= (int)date('Y', $ts) + 543 ?></h2>
    <a href="<?= h($qs(['m' => $next])) ?>" class="btn btn-light btn-sm"><i class="bi bi-chevron-right"></i></a>
    <a href="<?= h($qs(['m' => date('Y-m')])) ?>" class="btn btn-outline-secondary btn-sm">เดือนนี้</a>
    <div class="btn-group btn-group-sm ms-auto<?= $guest ? ' invisible' : '' ?>">
      <a href="<?= h('?m=' . $m) ?>" class="btn btn-outline-primary<?= $mine ? '' : ' active' ?>">ทุกคน</a>
      <a href="<?= h('?m=' . $m . '&mine=1') ?>" class="btn btn-outline-primary<?= $mine ? ' active' : '' ?>">เฉพาะงานของฉัน</a>
    </div>
    <a href="list.php?from=<?= h($first) ?>&to=<?= h($last) ?><?= $mine ? '&mine=1' : '' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-task"></i> แบบตาราง</a>
  </div>
  <div class="cal-grid">
    <?php foreach (CAL_WEEKDAYS_SHORT as $i => $w): ?><div class="cal-wd<?= $i === 0 ? ' sun' : '' ?>"><?= $w ?></div><?php endforeach; ?>
    <?php for ($d = $gridStart; $d <= $gridEnd; $d = date('Y-m-d', strtotime($d . ' +1 day'))):
        $out = substr($d, 0, 7) !== $m; ?>
      <div class="cal-cell<?= $out ? ' out' : '' ?><?= $d === $today ? ' today' : '' ?>">
        <div class="cal-dnum"><?php if (cal_can('editor')): ?><a href="event_form.php?date=<?= $d ?>" class="cal-add" title="เพิ่มกิจกรรมวันนี้"><i class="bi bi-plus"></i></a><?php endif; ?><?= (int)substr($d, 8, 2) ?></div>
        <?php foreach ($byDay[$d] ?? [] as $e): $isMine = in_array($me['id'], $assignees[(int)$e['id']] ?? [], true); ?>
          <a href="event_view.php?id=<?= (int)$e['id'] ?>" class="cal-ev<?= cal_is_duty($e) ? ' duty' : '' ?><?= $isMine ? ' mine' : '' ?>" title="<?= h(cal_time_text($e) . ' ' . $e['title'] . ($e['location'] ? ' @ ' . $e['location'] : '')) ?>">
            <?php if ($e['start_time'] && $d === $e['event_date']): ?><span class="t"><?= cal_time_thai($e['start_time']) ?></span><?php endif; ?><?= cal_is_private($e) ? '<i class="bi bi-lock-fill me-1" title="ส่วนตัว"></i>' : '' ?><?= h($e['title']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endfor; ?>
  </div>
  <div class="card-footer small text-muted"><?php if (!$guest): ?><span class="cal-ev mine d-inline-block px-2">งานของคุณ</span> <?php endif; ?><span class="cal-ev d-inline-block px-2">งานของผู้อื่น</span> <span class="cal-ev duty d-inline-block px-2">เวรสำนักงาน</span> · คลิกกิจกรรมเพื่อดูรายละเอียด</div>
</div>
<?= cal_modal_script() ?>
<?php require __DIR__ . '/footer.php'; ?>
