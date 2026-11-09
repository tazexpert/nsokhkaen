<?php
/**
 * ทะเบียนคุมเงินประจำงวดส่วนจังหวัด — 1 ทะเบียนต่อ (แผนงาน × งานและโครงการ (ถ้ามี) × หมวด) ของปีงบประมาณ
 *   หัว: ซ้ายบน แผนงาน / งานและโครงการ · กลาง ทะเบียนคุมเงินประจำงวดส่วนจังหวัด ปีงบประมาณ / หมวด
 *   ตาราง: วันที่ เดือน ปี | ที่เอกสาร | รายการ (+ รายละเอียด) | เพิ่ม (รับเข้า) | เบิก | คงเหลือ
 *   ท้ายแต่ละเดือน: รวมเดือนนี้ (เพิ่ม/เบิกของเดือน) · รวมตั้งแต่ต้นปี — คงเหลือ = ยอดคงเหลือ ณ สิ้นเดือน
 */
require_once __DIR__ . '/bootstrap.php';
require_module(BUD_MODULE, 'viewer');
$fy = bud_fy_param();
$works = bud_works(true);
$sources = bud_sources(true);
$plans = bud_plans();
$scope = (string)($_GET['scope'] ?? '');                   // '' = ทั้งหมด · 'p<id>' = ทั้งแผนงาน · 'w<id>' = งานและโครงการ
$scope = bud_scope_where($scope) ? $scope : '';
$src = isset($sources[$_GET['src'] ?? '']) ? $_GET['src'] : '';
// ช่วงเวลาภายในปีงบ (ค่าเริ่มต้น ต.ค. – ก.ย. ทั้งปี) · เริ่มหลัง ต.ค. = มียอดยกมา
$months = bud_fy_months($fy);
$from = in_array($_GET['from'] ?? '', $months, true) ? $_GET['from'] : $months[0];
$to = in_array($_GET['to'] ?? '', $months, true) ? $_GET['to'] : $months[11];
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$partial = $from !== $months[0] || $to !== $months[11];

$st = db()->prepare('SELECT * FROM bud_txns WHERE fiscal_year = ? ORDER BY txn_date, id');
$st->execute([$fy]);
$books = [];                                                // ['<plan_id>:<work_id>'][source_key] => ['pre' => [เพิ่ม, เบิก] ก่อนช่วง, 'rows' => รายการในช่วง]
foreach ($st as $t) {
    $ym = substr($t['txn_date'], 0, 7);
    if ($ym > $to) {
        continue;
    }
    $pid = bud_txn_plan($t);
    $wid = (int)$t['work_id'];
    $k = bud_source_key($t);
    if (($scope !== '' && $scope !== 'p' . $pid && $scope !== 'w' . $wid) || ($src !== '' && $k !== $src)) {
        continue;
    }
    $b = &$books[$pid . ':' . $wid][$k];
    $b ??= ['pre' => [0.0, 0.0], 'rows' => []];
    if ($ym < $from) {
        $b['pre'][$t['txn_type'] === 'receive' ? 0 : 1] += (float)$t['amount'];
    } else {
        $b['rows'][] = $t;
    }
    unset($b);
}
// ทะเบียนที่ไม่มีรายการในช่วงที่เลือก ไม่แสดง
foreach ($books as $bk => $bySrc) {
    $books[$bk] = array_filter($bySrc, fn($x) => $x['rows']);
}
$books = array_filter($books);
// เรียงตามลำดับแผนงาน → (ไม่ระบุงานก่อน) งานและโครงการ → หมวด · แผนงานไม่ระบุไว้ท้าย
$planOrder = array_flip(array_keys($plans));
$workOrder = array_flip(array_keys($works));
$rank = function (string $key) use ($planOrder, $workOrder): array {
    [$pid, $wid] = array_map('intval', explode(':', $key));
    return [$pid ? $planOrder[$pid] ?? PHP_INT_MAX - 1 : PHP_INT_MAX, $wid ? ($workOrder[$wid] ?? PHP_INT_MAX) + 1 : 0];
};
uksort($books, fn($a, $b) => $rank((string)$a) <=> $rank((string)$b));
$srcOrder = array_flip(array_keys($sources));
$srcRank = fn(string $k) => array_merge(isset($sources[$k]) ? bud_budget_rank($sources[$k]) : [999, ''], [$srcOrder[$k] ?? PHP_INT_MAX]);
foreach ($books as &$bySrc) {
    uksort($bySrc, fn($a, $b) => $srcRank((string)$a) <=> $srcRank((string)$b));       // ตามประเภทงบเหมือนรายงาน
}
unset($bySrc);
$count = array_sum(array_map('count', $books));
$money = fn(float $v) => $v == 0 ? '-' : ($v < 0 ? '<span class="neg">' . bud_money($v) . '</span>' : bud_money($v));

$pageTitle = 'ทะเบียนคุมเงินประจำงวดส่วนจังหวัด';
$active = 'ledger';
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>';
require __DIR__ . '/header.php';
?>
<form method="get" class="card card-body py-2 mb-3 bud-noprint">
  <div class="row g-2 align-items-end">
    <div class="col-4 col-md-2"><label class="form-label small mb-0">ปีงบประมาณ</label>
      <select name="fy" class="form-select form-select-sm" onchange="this.form.from.value='';this.form.to.value='';this.form.submit()">
        <?php foreach (bud_fy_list() as $y): ?><option value="<?= $y ?>"<?= $y === $fy ? ' selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
    <div class="col-4 col-md-2"><label class="form-label small mb-0">ตั้งแต่เดือน</label>
      <select name="from" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($months as $ym): ?><option value="<?= $ym ?>"<?= $ym === $from ? ' selected' : '' ?>><?= h(bud_month_label($ym, true)) ?></option><?php endforeach; ?></select></div>
    <div class="col-4 col-md-2"><label class="form-label small mb-0">ถึงเดือน</label>
      <select name="to" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($months as $ym): ?><option value="<?= $ym ?>"<?= $ym === $to ? ' selected' : '' ?>><?= h(bud_month_label($ym, true)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">แผนงาน / งานและโครงการ</label>
      <select name="scope" class="form-select form-select-sm" onchange="this.form.submit()"><?= bud_scope_options($scope) ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">หมวด</label>
      <select name="src" class="form-select form-select-sm" onchange="this.form.submit()"><?= bud_source_options($sources, $src) ?></select></div>
  </div>
  <div class="small text-muted mt-1">ทะเบียนแยกตามแผนงาน / งานและโครงการ (ถ้ามี) × หมวด · <?= number_format($count) ?> ทะเบียน · พิมพ์แล้วขึ้นหน้าใหม่ทุกทะเบียน</div>
</form>

<?php foreach ($books as $bk => $bySrc): [$pid, $wid] = array_map('intval', explode(':', (string)$bk)); $pl = $plans[$pid] ?? null; $w = $works[$wid] ?? null; ?>
  <?php foreach ($bySrc as $k => $book): $rows = $book['rows']; $s = $sources[$k] ?? ['label' => '-']; ?>
  <div class="card mb-4 bud-ledger">
    <div class="card-body">
      <div class="bud-ledger-head">
        <div class="bud-ledger-left">
          <div><b>แผนงาน</b> <?= h($pl['name'] ?? '-') ?></div>
          <?php if ($w): ?><div><b>งานและโครงการ</b> <?= h($w['name']) ?></div><?php endif; ?>
        </div>
        <div class="bud-ledger-center">
          <div class="bud-ledger-title">ทะเบียนคุมเงินประจำงวดส่วนจังหวัด ปีงบประมาณ <?= $fy ?></div>
          <?php if ($partial): ?><div class="small">ตั้งแต่เดือน<?= h(bud_month_label($from)) ?> ถึงเดือน<?= h(bud_month_label($to)) ?></div><?php endif; ?>
          <div><b>หมวด</b> <?= h($s['label']) ?></div>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0 bud-report bud-ledger-table">
          <thead class="table-light"><tr>
            <th class="text-center" style="width:7.5rem">วันที่ เดือน ปี</th><th class="text-center" style="width:9rem">ที่เอกสาร</th><th class="text-center">รายการ</th>
            <th class="text-center" style="width:8rem">เพิ่ม</th><th class="text-center" style="width:8rem">เบิก</th><th class="text-center" style="width:8.5rem">คงเหลือ</th>
          </tr></thead>
          <tbody>
          <?php [$cumAdd, $cumSpd] = $book['pre']; $bal = $cumAdd - $cumSpd; $n = count($rows);
          if ($from !== $months[0]): ?>
            <tr class="bud-sub"><td colspan="3" class="text-end">ยอดยกมา (<?= h(bud_month_label($months[0], true)) ?> – <?= h(bud_month_label(date('Y-m', strtotime($from . '-01 -1 month')), true)) ?>)</td>
              <td class="text-end"><?= $money($cumAdd) ?></td><td class="text-end"><?= $money($cumSpd) ?></td><td class="text-end"><?= $money($bal) ?></td></tr>
          <?php endif;
          foreach ($rows as $i => $t):
              $isAdd = $t['txn_type'] === 'receive';
              $amt = (float)$t['amount'];
              $bal += $isAdd ? $amt : -$amt;
              $ym = substr($t['txn_date'], 0, 7);
              if (!isset($mAdd) || $ym !== $curYm) { $curYm = $ym; $mAdd = 0.0; $mSpd = 0.0; }
              if ($isAdd) { $mAdd += $amt; $cumAdd += $amt; } else { $mSpd += $amt; $cumSpd += $amt; } ?>
            <tr>
              <td class="text-center"><?= h(thai_date($t['txn_date'])) ?></td>
              <td><?= h($t['doc_no']) ?></td>
              <td class="bud-name"><?= h($t['description']) ?><?php if ($t['detail'] !== ''): ?><div class="bud-ledger-detail"><?= nl2br(h($t['detail'])) ?></div><?php endif; ?></td>
              <td class="text-end"><?= $isAdd ? bud_money($amt) : '' ?></td>
              <td class="text-end"><?= $isAdd ? '' : bud_money($amt) ?></td>
              <td class="text-end"><?= $money($bal) ?></td>
            </tr>
            <?php if ($i === $n - 1 || substr($rows[$i + 1]['txn_date'], 0, 7) !== $ym): ?>
            <tr class="bud-sub"><td colspan="3" class="text-end">รวมเดือนนี้ (<?= h(bud_month_label($ym)) ?>)</td>
              <td class="text-end"><?= $money($mAdd) ?></td><td class="text-end"><?= $money($mSpd) ?></td><td class="text-end"><?= $money($bal) ?></td></tr>
            <tr class="bud-group"><td colspan="3" class="text-end">รวมตั้งแต่ต้นปี</td>
              <td class="text-end"><?= $money($cumAdd) ?></td><td class="text-end"><?= $money($cumSpd) ?></td><td class="text-end"><?= $money($cumAdd - $cumSpd) ?></td></tr>
            <?php endif; ?>
          <?php endforeach; unset($mAdd); ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endforeach; ?>
<?php if (!$books): ?><div class="card card-body text-center text-muted py-5">ไม่มีรายการรับเข้า/เบิกจ่ายในปีงบประมาณ <?= $fy ?><?= $partial ? ' ช่วง ' . h(bud_month_label($from, true) . ' – ' . bud_month_label($to, true)) : '' ?><?= $scope !== '' || $src !== '' ? ' ตามที่เลือก' : '' ?></div><?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
