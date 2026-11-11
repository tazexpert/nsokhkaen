<?php
/** สรุปการยืมรายกลุ่ม (คลิกจากการ์ด "สรุปการยืม" หน้าครุภัณฑ์): ยอดตามรุ่น · ผู้ยืม · เลขที่คงเหลือ */
require_once __DIR__ . '/bootstrap.php';
require_module(INV_MODULE, 'admin');   // รายละเอียดผู้ยืม: เฉพาะผู้ดูแล
$groups = eq_loan_groups();
$gi = int_param('g');
if (!isset($groups[$gi])) {
    redirect('equipment');
}
$g = $groups[$gi];
$pct = fn(int $n, int $t) => $t ? round($n / $t * 100) : 0;
$listHref = 'equipment?' . http_build_query($g['filter']) . '#list';

$pageTitle = 'สรุปการยืม ' . $g['label'];
$pageActions = '<a href="equipment" class="btn btn-light"><i class="bi bi-arrow-left"></i> ครุภัณฑ์</a>'
    . '<a href="' . h($listHref) . '" class="btn btn-outline-primary"><i class="bi bi-list-ul"></i> รายการครุภัณฑ์กลุ่มนี้</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="print()"><i class="bi bi-printer"></i> พิมพ์</button>';
require __DIR__ . '/header.php';
?>
<ul class="nav nav-pills mb-3 no-print">
  <?php foreach ($groups as $i => $x): ?>
    <li class="nav-item"><a class="nav-link<?= $i === $gi ? ' active' : '' ?>" href="equipment_group?g=<?= $i ?>"><?= h($x['label']) ?>
      <span class="badge <?= $i === $gi ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1"><?= (int)$x['borrowed'] ?>/<?= (int)$x['total'] ?></span></a></li>
  <?php endforeach; ?>
</ul>

<div class="row g-3 mb-4" data-reveal>
  <?php foreach ([['ยืมอยู่', $g['borrowed'], 'primary', 'bi-box-arrow-up-right'], ['คงเหลือให้ยืม', $g['available'], 'success', 'bi-check-circle'],
                  ['ทั้งหมด', $g['total'], 'dark', 'bi-collection'], ['เสีย/รอจำหน่าย', $g['other'], 'secondary', 'bi-tools']] as $k => [$lbl, $n, $c, $ic]): ?>
  <div class="col-6 col-lg-3 reveal-item" style="--d:<?= $k * 70 ?>ms">
    <div class="card h-100"><div class="card-body p-3">
      <div class="small text-muted"><i class="bi <?= $ic ?> text-<?= $c ?>"></i> <?= $lbl ?></div>
      <div class="fs-3 fw-bold text-<?= $c ?>"><?= number_format($n) ?></div>
      <?php if ($k < 2): ?><span class="bar-track d-block"><span class="bar-fill<?= $k ? ' grp-ok' : '' ?>" style="width:<?= $pct($n, $g['total']) ?>%"></span></span>
        <div class="small text-muted mt-1"><?= $pct($n, $g['total']) ?>% ของทั้งหมด</div><?php endif; ?>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-4">
  <div class="card-header"><strong><i class="bi bi-tablet"></i> แยกตามรุ่น (<?= count($g['models']) ?> รุ่น)</strong></div>
  <div class="table-responsive">
    <table class="table align-middle mb-0" data-reveal>
      <thead><tr><th>รุ่น</th><th class="text-center">ปีงบ</th><th class="text-center">ทั้งหมด</th><th class="text-center text-primary">ยืมอยู่</th>
        <th class="text-center text-success">คงเหลือ</th><th class="text-center text-secondary">เสีย/รอจำหน่าย</th><th style="width:22%">สัดส่วนการยืม</th></tr></thead>
      <tbody>
      <?php foreach ($g['models'] as $name => $m): ?>
        <tr>
          <td><?= h($name) ?></td>
          <td class="text-center"><?= $m['fy'] ?: '-' ?></td>
          <td class="text-center fw-semibold"><?= number_format($m['total']) ?></td>
          <td class="text-center"><?= number_format($m['borrowed']) ?></td>
          <td class="text-center"><?= number_format($m['available']) ?></td>
          <td class="text-center"><?= $m['other'] ? number_format($m['other']) : '<span class="text-muted">0</span>' ?></td>
          <td><div class="d-flex align-items-center gap-2"><span class="bar-track"><span class="bar-fill" style="width:<?= $pct($m['borrowed'], $m['total']) ?>%"></span></span>
            <span class="small text-muted" style="width:3em"><?= $pct($m['borrowed'], $m['total']) ?>%</span></div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$g['models']): ?><tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีครุภัณฑ์ในกลุ่มนี้</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header"><strong><i class="bi bi-person-fill text-primary"></i> ผู้ยืม (<?= count($g['loans']) ?>)</strong>
    <span class="small text-muted ms-2">เรียงตามกำหนดคืนใกล้ที่สุดก่อน</span></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th style="width:3em">#</th><th>ผู้ยืม</th><th>รุ่น</th><th>เลขครุภัณฑ์</th><th>เลขที่หนังสือ e-office</th><th>กำหนดคืน</th></tr></thead>
      <tbody>
      <?php foreach ($g['loans'] as $i => $l): ?>
        <tr class="<?= $l['over'] ? 'table-danger' : '' ?>">
          <td><?= $i + 1 ?></td>
          <td><?= h($l['name']) ?></td>
          <td class="small"><?= h($l['model']) ?></td>
          <td class="font-monospace small"><a href="equipment_view?id=<?= $l['equipment_id'] ?>" class="text-decoration-none"><?= h($l['asset']) ?></a></td>
          <td class="text-nowrap"><a href="loan_view?id=<?= $l['loan_id'] ?>"><?= h($l['ref']) ?></a></td>
          <td class="text-nowrap"><?= h(thai_date($l['due'])) ?><?= $l['over'] ? ' <span class="badge text-bg-danger">เกินกำหนด</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$g['loans']): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีผู้ยืม</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong><i class="bi bi-check-circle text-success"></i> คงเหลือให้ยืม (<?= count($g['free']) ?>)</strong></div>
  <div class="card-body">
    <?php if ($g['free']): ?>
      <div class="d-flex flex-wrap gap-1">
        <?php foreach ($g['free'] as $f): ?><a href="equipment_view?id=<?= $f['equipment_id'] ?>" class="badge text-bg-light border font-monospace fw-normal text-decoration-none" title="<?= h($f['model']) ?>"><?= h($f['asset']) ?></a><?php endforeach; ?>
      </div>
    <?php else: ?><span class="text-muted">ไม่มี</span><?php endif; ?>
  </div>
</div>
<style>
.bar-fill.grp-ok{background:linear-gradient(90deg,#2ed8b6,#59e0c5)}
@media print{.nav-pills{display:none}}
</style>
<?php require __DIR__ . '/footer.php'; ?>
