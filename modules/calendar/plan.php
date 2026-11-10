<?php
/**
 * แผนการปฏิบัติงาน (รายเดือน) — ตารางคน × วัน เหมือนไฟล์ Excel เดิม · ทุกคนที่ login ดูได้
 * - สรุปจำนวนวันต่อรหัสงาน / เวรสำนักงาน / เวรศาลากลาง คำนวณอัตโนมัติ + แถวรวมคนออกสนามรายวัน
 * - เวรสำนักงานดึงจากปฏิทิน · แก้ไข/นำเข้า/รหัสงาน: ผู้ดูแลปฏิทิน · ผู้บันทึกกิจกรรมแก้ไขแถวของตนได้ (plan_edit.php)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/plan_lib.php';
require_once __DIR__ . '/att_lib.php';
$me = require_module(CAL_MODULE);
[$y, $m] = plan_ym($_GET['m'] ?? null);

// เจ้าของรายการ (ไม่ใช่ผู้ดูแล) แก้ "ข" (ขาดราชการ) → "ล" (รับรองการมาปฏิบัติราชการ) ของตนเองได้อย่างเดียว
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'att_certify') {
    check_csrf();
    $date = (string)post('date');
    if (att_certify((int)$me['id'], $date)) {
        flash('บันทึกรับรองการมาปฏิบัติราชการ วันที่ ' . thai_date($date) . ' แล้ว');
    } else {
        flash('แก้ได้เฉพาะวันที่เป็น "ขาดราชการ" ของตนเองเท่านั้น', 'warning');
    }
    redirect('plan?m=' . (preg_match('/^\d{4}-\d{2}/', $date) ? substr($date, 0, 7) : sprintf('%04d-%02d', $y, $m)));
}
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
$att = att_month($y, $m);                 // เวลาปฏิบัติราชการ [user_id][day]
$trips = att_trip_map($y, $m);           // กิจกรรมไปราชการ [user_id][day]
$attAt = db()->prepare('SELECT MAX(report_at) FROM cal_attendance WHERE work_date BETWEEN ? AND ?');
$attAt->execute([sprintf('%04d-%02d-01', $y, $m), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $m)))]);
$attAt = $attAt->fetchColumn();
$guardId = plan_guard_code_id($codes);

// คอลัมน์สรุป: โครงการที่ดำเนินการในเดือนนี้ + โครงการที่มีในแผนเดือนนี้
$used = [];
foreach ($cells as $byDay) {
    foreach ($byDay as $ids) {
        foreach ($ids as $id) {
            $used[$id] = true;
        }
    }
}
$monthProjects = plan_month_projects($codes, $y, $m);
$fieldCodes = array_filter($codes, fn($c) => $c['kind'] === 'field' && (isset($monthProjects[(int)$c['id']]) || isset($used[(int)$c['id']])));

$pageTitle = 'แผนการปฏิบัติงาน';
$active = 'plan';
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>'
    . ($isAdmin ? '<a href="plan_edit?m=' . $ym . '" class="btn btn-primary"><i class="bi bi-pencil-square"></i> ' . ($plan ? 'แก้ไขแผน' : 'สร้างแผน') . '</a>'
        . '<a href="plan_import?m=' . $ym . '" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel"></i> นำเข้า Excel</a>'
        . '<a href="att_import" class="btn btn-outline-primary"><i class="bi bi-clock-history"></i> นำเข้าเวลาปฏิบัติราชการ</a>'
        . '<a href="' . h(module_url('ea', 'projects')) . '" class="btn btn-outline-secondary"><i class="bi bi-kanban"></i> โครงการ</a>'
        : (cal_can('editor') && $plan ? '<a href="plan_edit?m=' . $ym . '" class="btn btn-primary"><i class="bi bi-pencil-square"></i> แก้ไขแผนของฉัน</a>' : ''));
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
        <a href="plan_import?m=<?= $ym ?>" class="btn btn-primary"><i class="bi bi-file-earmark-excel"></i> นำเข้าจาก Excel</a>
        <a href="plan_edit?m=<?= $ym ?>" class="btn btn-outline-primary"><i class="bi bi-pencil-square"></i> สร้างแผนในหน้าเว็บ</a></div><?php endif; ?>
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
          <th colspan="<?= count($fieldCodes) + 4 ?>" class="text-center plan-sum-head">สรุปจำนวนวัน</th>
        </tr>
        <tr>
          <?php for ($d = 1; $d <= $days; $d++): $date = sprintf('%s-%02d', $ym, $d); $w = (int)date('w', strtotime($date)); ?>
            <th class="text-center plan-wd<?= $w === 0 || $w === 6 ? ' we' : '' ?><?= $date === $today ? ' today' : '' ?>"><?= $wd[$w] ?></th>
          <?php endfor; ?>
          <?php foreach ($fieldCodes as $c): ?><th class="text-center plan-sum" style="--c:<?= h($c['color']) ?>" title="<?= h($c['name']) ?>"><?= h($c['code']) ?></th><?php endforeach; ?>
          <th class="text-center plan-sum" style="--c:#16a34a" title="เวรสำนักงาน (จากปฏิทิน)">เวร</th>
          <th class="text-center plan-sum" style="--c:#dc2626" title="เวรศาลากลาง">*</th>
          <th class="text-center plan-sum" style="--c:#b45309" title="สาย (จากเวลาปฏิบัติราชการ)">ส</th>
          <th class="text-center plan-sum" style="--c:#dc2626" title="ขาดราชการ">ข</th>
        </tr>
      </thead>
      <tbody>
      <?php $dayField = array_fill(1, $days, 0);
      foreach ($people as $i => $p):
        $pid = (int)$p['id'];
        $sum = [];
        $dutyN = 0;
        $guardN = 0;
        $lateN = 0;
        $absentN = 0;
        $uid = (int)$p['user_id'];
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
            // เวลาปฏิบัติราชการ: ส / ลาเช้า / ลาบ่าย / ล / ร / ข / การลา
            // แสดงเฉพาะแถวของตนเอง (ผู้ดูแลปฏิทินดูได้ทุกแถว)
            $as = $uid && ($mine || $isAdmin) ? att_status($att[$uid][$d] ?? null, $field, $trips[$uid][$d] ?? null) : null;
            if ($as && $as['code'] === 'ส') { $lateN++; }
            if ($as && $as['code'] === 'ข') { $absentN++; }
            if ($hasDuty) { $dutyN++; }
            if ($hasGuard) { $guardN++; }
            $title = implode(' + ', array_filter(array_merge(
                array_map(fn($cid) => $codes[$cid]['kind'] === 'field' ? $codes[$cid]['code'] . ($codes[$cid]['name'] !== $codes[$cid]['code'] ? ' (' . $codes[$cid]['name'] . ')' : '') : '', $ids),
                [$hasDuty ? 'เวรสำนักงาน' : '', $hasGuard ? 'เวรศาลากลาง' : '', $as ? $as['title'] : '']))); ?>
            <td class="text-center plan-cell<?= $w === 0 || $w === 6 ? ' we' : '' ?><?= $date === $today ? ' today' : '' ?><?= $first ? ' has' : '' ?>"
                <?= $first ? 'style="--c:' . h($first['color']) . '"' : '' ?><?= $title !== '' ? ' title="' . h($d . ' ' . thai_month_long($m) . ': ' . $title) . '"' : '' ?>>
              <?php foreach ($ids as $cid): if (($codes[$cid]['kind'] ?? '') === 'field'): ?><span class="pc"><?= h($codes[$cid]['code']) ?></span><?php endif; endforeach; ?>
              <?php if ($hasDuty): ?><span class="pc-duty">เวร</span><?php endif; ?>
              <?php if ($hasGuard): ?><span class="pc-guard">*</span><?php endif; ?>
              <?php if ($as): if (!empty($as['certifiable']) && $mine): ?><button type="button" class="att-chip <?= $as['cls'] ?>" data-att-certify data-date="<?= $date ?>"><?= h($as['code']) ?></button>
              <?php else: ?><span class="att-chip <?= $as['cls'] ?>"><?= h($as['code']) ?></span><?php endif; endif; ?>
            </td>
          <?php endfor; ?>
          <?php foreach ($fieldCodes as $cid => $c): ?><td class="text-center plan-sumv"><?= !empty($sum[$cid]) ? $sum[$cid] : '' ?></td><?php endforeach; ?>
          <td class="text-center plan-sumv"><?= $dutyN ?: '' ?></td>
          <td class="text-center plan-sumv"><?= $guardN ?: '' ?></td>
          <td class="text-center plan-sumv" style="color:#b45309"><?= $lateN ?: '' ?></td>
          <td class="text-center plan-sumv" style="color:#dc2626"><?= $absentN ?: '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$people): ?><tr><td colspan="<?= $days + count($fieldCodes) + 6 ?>" class="text-center text-muted py-4">ยังไม่มีรายชื่อในแผน</td></tr><?php endif; ?>
      </tbody>
      <?php if ($people): ?>
      <tfoot>
        <tr class="plan-total">
          <td></td><td class="plan-name">ออกสนาม (คน)</td>
          <?php for ($d = 1; $d <= $days; $d++): $w = (int)date('w', strtotime(sprintf('%s-%02d', $ym, $d))); ?>
            <td class="text-center<?= $w === 0 || $w === 6 ? ' we' : '' ?>"><?= $dayField[$d] ?: '' ?></td>
          <?php endfor; ?>
          <td colspan="<?= count($fieldCodes) + 4 ?>"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <div class="card-footer small plan-legend">
    <?php foreach ($fieldCodes as $c): ?><span class="me-3 text-nowrap"><span class="plan-chip" style="--c:<?= h($c['color']) ?>"><?= h($c['code']) ?></span> <?= h($c['name'] !== $c['code'] ? $c['name'] : '') ?></span><?php endforeach; ?>
    <span class="me-3 text-nowrap"><span class="pc-duty">เวร</span> เวรสำนักงาน (จากปฏิทิน)</span>
    <span class="me-3 text-nowrap"><span class="pc-guard">*</span> <?= h($codes[$guardId]['name'] ?? 'เวรศาลากลาง') ?></span>
    <div class="mt-1">เวลาปฏิบัติราชการ<?= $isAdmin ? '' : ' (แสดงเฉพาะของคุณ)' ?><?= $attAt ? ' (ข้อมูล ณ ' . h(thai_date(substr($attAt, 0, 10)) . ' ' . substr($attAt, 11, 5)) . ' น.)' : ' (ยังไม่ได้นำเข้า)' ?>:
      <span class="me-2 text-nowrap"><span class="att-chip att-late">ส</span> สาย</span>
      <span class="me-2 text-nowrap"><span class="att-chip att-half">ลาเช้า</span>/<span class="att-chip att-half">ลาบ่าย</span></span>
      <span class="me-2 text-nowrap"><span class="att-chip att-pending">ล</span> ลงเวลาไม่ครบ</span>
      <span class="me-2 text-nowrap"><span class="att-chip att-cert">ล</span> รับรองการมาปฏิบัติราชการ</span>
      <span class="me-2 text-nowrap"><span class="att-chip att-trip">ร</span> ไปราชการ</span>
      <span class="me-2 text-nowrap"><span class="att-chip att-absent">ข</span> ขาดราชการ (เจ้าของคลิกเพื่อรับรอง เมื่อทำใบรับรองแล้ว)</span>
      <span class="me-2 text-nowrap"><span class="att-chip att-leave">ป</span> การลาตามไฟล์</span></div>
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
<form method="post" id="attCertForm" class="d-none"><?= csrf_field() ?><input type="hidden" name="action" value="att_certify"><input type="hidden" name="date"></form>
<script>
// เจ้าของคลิก "ข" → ยืนยันว่าทำใบรับรองแล้ว → เปลี่ยนเป็น "ล" รับรองการมาปฏิบัติราชการ
document.querySelectorAll('[data-att-certify]').forEach(b => b.addEventListener('click', () => {
  if (!confirm('วันที่ ' + b.dataset.date.split('-').reverse().join('/') + ' ขาดราชการ (ไม่ได้ลงเวลา)\nทำใบรับรองการมาปฏิบัติราชการแล้ว — เปลี่ยนเป็น "รับรองการมาปฏิบัติราชการ (ล)"?')) { return; }
  const f = document.getElementById('attCertForm');
  f.date.value = b.dataset.date;
  f.submit();
}));
</script>
<?php require __DIR__ . '/footer.php'; ?>
