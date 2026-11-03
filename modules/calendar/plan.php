<?php
/**
 * แผนการปฏิบัติงาน (รายเดือน) — ตารางคน × วัน เหมือนไฟล์ Excel เดิม · ทุกคนที่ login ดูได้
 * - สรุปจำนวนวันต่อรหัสงาน / เวรสำนักงาน / เวรศาลากลาง คำนวณอัตโนมัติ + แถวรวมคนออกสนามรายวัน
 * - เวรสำนักงานดึงจากปฏิทิน · แก้ไข/นำเข้า/รหัสงาน: ผู้ดูแลปฏิทิน
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/plan_lib.php';
$me = require_module(CAL_MODULE);
[$y, $m] = plan_ym($_GET['m'] ?? null);
$ym = sprintf('%04d-%02d', $y, $m);
$prev = date('Y-m', strtotime($ym . '-01 -1 month'));
$next = date('Y-m', strtotime($ym . '-01 +1 month'));
$days = (int)date('t', strtotime($ym . '-01'));
$isAdmin = cal_can('admin');

$plan = plan_get($y, $m);
$codes = plan_codes();
$people = $plan ? plan_people((int)$plan['id']) : [];
$cells = $plan ? plan_cells((int)$plan['id']) : [];
$duty = plan_duty_map($y, $m);
$guardId = plan_guard_code_id($codes);

// รหัสงานสนามที่แสดงในคอลัมน์สรุป: ที่เปิดใช้ + ที่มีในแผนเดือนนี้
$used = [];
foreach ($cells as $byDay) {
    foreach ($byDay as $ids) {
        foreach ($ids as $id) {
            $used[$id] = true;
        }
    }
}
$fieldCodes = array_filter($codes, fn($c) => $c['kind'] === 'field' && ($c['is_active'] || isset($used[(int)$c['id']])));

$pageTitle = 'แผนการปฏิบัติงาน';
$active = 'plan';
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>'
    . ($isAdmin ? '<a href="plan_edit.php?m=' . $ym . '" class="btn btn-primary"><i class="bi bi-pencil-square"></i> ' . ($plan ? 'แก้ไขแผน' : 'สร้างแผน') . '</a>'
        . '<a href="plan_import.php?m=' . $ym . '" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel"></i> นำเข้า Excel</a>'
        . '<a href="plan_codes.php" class="btn btn-outline-secondary"><i class="bi bi-tags"></i> รหัสงาน</a>' : '');
$wd = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
$today = date('Y-m-d');
require __DIR__ . '/header.php';
?>
<div class="card plan-card">
  <div class="card-header d-flex flex-wrap align-items-center gap-2 no-print">
    <a href="?m=<?= $prev ?>" class="btn btn-light btn-sm" aria-label="เดือนก่อน"><i class="bi bi-chevron-left"></i></a>
    <h2 class="h5 mb-0 mx-1"><?= h(plan_month_text($y, $m)) ?></h2>
    <a href="?m=<?= $next ?>" class="btn btn-light btn-sm" aria-label="เดือนถัดไป"><i class="bi bi-chevron-right"></i></a>
    <a href="?m=<?= date('Y-m') ?>" class="btn btn-outline-secondary btn-sm">เดือนนี้</a>
    <?php if ($plan): ?><span class="ms-auto small text-muted">ปรับปรุงล่าสุด <?= h(thai_date($plan['updated_at'])) ?> <?= h(substr($plan['updated_at'], 11, 5)) ?> น.<?= $plan['updated_by_name'] ? ' โดย ' . h($plan['updated_by_name']) : '' ?></span><?php endif; ?>
  </div>
  <h1 class="plan-print-title d-none d-print-block">แผนการปฏิบัติงานสนามโครงการต่าง ๆ ประจำเดือน <?= h(plan_month_text($y, $m)) ?><br><?= h(ORG_NAME) ?></h1>

  <?php if (!$plan): ?>
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-grid-3x3 fs-1 d-block mb-2"></i>ยังไม่มีแผนการปฏิบัติงานเดือน<?= h(plan_month_text($y, $m)) ?>
      <?php if ($isAdmin): ?><div class="mt-3 d-flex gap-2 justify-content-center">
        <a href="plan_import.php?m=<?= $ym ?>" class="btn btn-primary"><i class="bi bi-file-earmark-excel"></i> นำเข้าจาก Excel</a>
        <a href="plan_edit.php?m=<?= $ym ?>" class="btn btn-outline-primary"><i class="bi bi-pencil-square"></i> สร้างแผนในหน้าเว็บ</a></div><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-responsive plan-scroll">
    <table class="table table-bordered table-sm align-middle mb-0 plan-grid">
      <thead>
        <tr>
          <th rowspan="2" class="text-center plan-no">ที่</th>
          <th rowspan="2" class="plan-name">รายชื่อผู้ปฏิบัติงาน</th>
          <?php for ($d = 1; $d <= $days; $d++): $date = sprintf('%s-%02d', $ym, $d); $w = (int)date('w', strtotime($date)); ?>
            <th class="text-center plan-day<?= $w === 0 || $w === 6 ? ' we' : '' ?><?= $date === $today ? ' today' : '' ?>"><?= $d ?></th>
          <?php endfor; ?>
          <th colspan="<?= count($fieldCodes) + 2 ?>" class="text-center plan-sum-head">สรุปจำนวนวัน</th>
        </tr>
        <tr>
          <?php for ($d = 1; $d <= $days; $d++): $date = sprintf('%s-%02d', $ym, $d); $w = (int)date('w', strtotime($date)); ?>
            <th class="text-center plan-wd<?= $w === 0 || $w === 6 ? ' we' : '' ?><?= $date === $today ? ' today' : '' ?>"><?= $wd[$w] ?></th>
          <?php endfor; ?>
          <?php foreach ($fieldCodes as $c): ?><th class="text-center plan-sum" style="--c:<?= h($c['color']) ?>" title="<?= h($c['name']) ?>"><?= h($c['code']) ?></th><?php endforeach; ?>
          <th class="text-center plan-sum" style="--c:#16a34a" title="เวรสำนักงาน (จากปฏิทิน)">เวร</th>
          <th class="text-center plan-sum" style="--c:#dc2626" title="เวรศาลากลาง">*</th>
        </tr>
      </thead>
      <tbody>
      <?php $dayField = array_fill(1, $days, 0);
      foreach ($people as $i => $p):
        $pid = (int)$p['id'];
        $sum = [];
        $dutyN = 0;
        $guardN = 0;
        $mine = (int)$p['user_id'] === (int)$me['id']; ?>
        <tr class="<?= $mine ? 'plan-me' : '' ?>">
          <td class="text-center text-muted"><?= $i + 1 ?></td>
          <td class="plan-name text-nowrap"><?= h(preg_replace('/\s+/u', ' ', $p['name'])) ?><?= $p['user_id'] ? '' : ' <i class="bi bi-person-dash text-muted small" title="ไม่มีบัญชีในระบบ"></i>' ?></td>
          <?php for ($d = 1; $d <= $days; $d++):
            $ids = $cells[$pid][$d] ?? [];
            $date = sprintf('%s-%02d', $ym, $d);
            $w = (int)date('w', strtotime($date));
            $hasDuty = $p['user_id'] && !empty($duty[(int)$p['user_id']][$d]);
            $hasGuard = $guardId && in_array($guardId, $ids, true);
            $first = null;
            $field = false;
            foreach ($ids as $cid) {
                if (($codes[$cid]['kind'] ?? '') === 'field') {
                    $sum[$cid] = ($sum[$cid] ?? 0) + 1;
                    $first ??= $codes[$cid];
                    $field = true;
                }
            }
            if ($field) { $dayField[$d]++; }
            if ($hasDuty) { $dutyN++; }
            if ($hasGuard) { $guardN++; }
            $title = implode(' + ', array_filter(array_merge(
                array_map(fn($cid) => $codes[$cid]['kind'] === 'field' ? $codes[$cid]['code'] . ($codes[$cid]['name'] !== $codes[$cid]['code'] ? ' (' . $codes[$cid]['name'] . ')' : '') : '', $ids),
                [$hasDuty ? 'เวรสำนักงาน' : '', $hasGuard ? 'เวรศาลากลาง' : '']))); ?>
            <td class="text-center plan-cell<?= $w === 0 || $w === 6 ? ' we' : '' ?><?= $date === $today ? ' today' : '' ?><?= $first ? ' has' : '' ?>"
                <?= $first ? 'style="--c:' . h($first['color']) . '"' : '' ?><?= $title !== '' ? ' title="' . h($d . ' ' . thai_month_long($m) . ': ' . $title) . '"' : '' ?>>
              <?php foreach ($ids as $cid): if (($codes[$cid]['kind'] ?? '') === 'field'): ?><span class="pc"><?= h($codes[$cid]['code']) ?></span><?php endif; endforeach; ?>
              <?php if ($hasDuty): ?><span class="pc-duty">เวร</span><?php endif; ?>
              <?php if ($hasGuard): ?><span class="pc-guard">*</span><?php endif; ?>
            </td>
          <?php endfor; ?>
          <?php foreach ($fieldCodes as $cid => $c): ?><td class="text-center plan-sumv"><?= !empty($sum[$cid]) ? $sum[$cid] : '' ?></td><?php endforeach; ?>
          <td class="text-center plan-sumv"><?= $dutyN ?: '' ?></td>
          <td class="text-center plan-sumv"><?= $guardN ?: '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$people): ?><tr><td colspan="<?= $days + count($fieldCodes) + 4 ?>" class="text-center text-muted py-4">ยังไม่มีรายชื่อในแผน</td></tr><?php endif; ?>
      </tbody>
      <?php if ($people): ?>
      <tfoot>
        <tr class="plan-total">
          <td></td><td class="plan-name">ออกสนาม (คน)</td>
          <?php for ($d = 1; $d <= $days; $d++): $w = (int)date('w', strtotime(sprintf('%s-%02d', $ym, $d))); ?>
            <td class="text-center<?= $w === 0 || $w === 6 ? ' we' : '' ?>"><?= $dayField[$d] ?: '' ?></td>
          <?php endfor; ?>
          <td colspan="<?= count($fieldCodes) + 2 ?>"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <div class="card-footer small plan-legend">
    <?php foreach ($fieldCodes as $c): ?><span class="me-3 text-nowrap"><span class="plan-chip" style="--c:<?= h($c['color']) ?>"><?= h($c['code']) ?></span> <?= h($c['name'] !== $c['code'] ? $c['name'] : '') ?></span><?php endforeach; ?>
    <span class="me-3 text-nowrap"><span class="pc-duty">เวร</span> เวรสำนักงาน (จากปฏิทิน)</span>
    <span class="me-3 text-nowrap"><span class="pc-guard">*</span> <?= h($codes[$guardId]['name'] ?? 'เวรศาลากลาง') ?></span>
    <?php if ($plan['note']): ?><div class="mt-2"><b>หมายเหตุ:</b> <?= nl2br(h($plan['note'])) ?></div><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<style>
  .plan-scroll { max-height: 78vh; }
  .plan-grid { font-size: .82rem; white-space: nowrap; }
  .plan-grid th, .plan-grid td { padding: .2rem .25rem; }
  .plan-grid thead th { position: sticky; top: 0; z-index: 2; background: var(--bs-body-bg); }
  .plan-grid thead tr:nth-child(2) th { top: 1.65rem; }
  .plan-grid .plan-name { position: sticky; left: 0; z-index: 1; background: var(--bs-body-bg); min-width: 180px; }
  .plan-grid thead .plan-name { z-index: 3; }
  .plan-day, .plan-wd { min-width: 2.4rem; }
  .plan-grid .we { background-color: #f1f5f9; }
  .plan-grid th.today, .plan-grid td.today { box-shadow: inset 0 0 0 2px #f59e0b; }
  .plan-cell.has { background: color-mix(in srgb, var(--c) 16%, white); color: color-mix(in srgb, var(--c) 85%, black); font-weight: 600; }
  .plan-cell .pc + .pc::before { content: ' '; }
  .pc-duty { display: inline-block; font-size: .68rem; padding: 0 .25rem; border-radius: .25rem; background: #dcfce7; color: #15803d; font-weight: 600; }
  .pc-guard { color: #dc2626; font-weight: 700; font-size: 1rem; line-height: 1; }
  .plan-sum { color: var(--c); }
  .plan-sumv { font-weight: 600; background: #f8fafc; }
  .plan-sum-head { background: #f8fafc !important; }
  tr.plan-me td { background-color: #fffbeb; }
  tr.plan-me td.plan-cell.has { background: color-mix(in srgb, var(--c) 22%, #fffbeb); }
  tr.plan-me .plan-name { font-weight: 700; background: #fffbeb; }
  .plan-total td { font-weight: 600; background: #f8fafc; }
  .plan-chip { display: inline-block; padding: 0 .4rem; border-radius: .3rem; font-weight: 600; color: var(--c); background: color-mix(in srgb, var(--c) 16%, white); }
  @media print {
    @page { size: A4 landscape; margin: 8mm; }
    .plan-scroll { max-height: none; overflow: visible; }
    .plan-grid { font-size: 7pt; }
    .plan-grid th, .plan-grid td { padding: 1px 2px; }
    .plan-grid thead th, .plan-grid .plan-name { position: static; }
    .plan-day, .plan-wd { min-width: 0; }
    .plan-grid .plan-name { min-width: 0; }
    .plan-print-title { font-size: 11pt; text-align: center; margin: 0 0 6px; }
    .plan-card { box-shadow: none !important; border: 0; }
    .plan-cell.has, .plan-grid .we, .pc-duty, .plan-chip { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    tr.plan-me td, tr.plan-me .plan-name { background: transparent; font-weight: normal; }
  }
</style>
<?php require __DIR__ . '/footer.php'; ?>
