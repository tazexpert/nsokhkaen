<?php
/**
 * รายละเอียดโอนเปลี่ยนแปลงครั้งที่ N/ปี (คลิกจากภาพรวมงบประมาณ) — ทุกแถวของเอกสารที่เป็นการโอนครั้งนั้น (bud_transfers ผูกด้วยที่เอกสาร)
 *   ส่วนที่ 1 รับเข้า: แถวของการโอน (วันที่ · ที่เอกสาร · รายการ · ได้รับ) — ช่องรายการ: รายละเอียด (ถ้ามี) ไม่มี = ค่าใช้จ่ายของหมวด
 *   ส่วนที่ 2 เบิกจ่าย: รายการเบิกจ่ายในปีงบประมาณที่ งบรายจ่าย · รายการ · ค่าใช้จ่าย ตรงกับหมวดที่ได้รับโอน
 *            จัดกลุ่มตามหมวด พร้อมยอดได้รับ/เบิกจ่าย/คงเหลือของหมวด
 */
require_once __DIR__ . '/bootstrap.php';
require_module(BUD_MODULE, 'viewer');
$fy = bud_fy_param();
$transfers = bud_transfers($fy);
$k = (string)($_GET['k'] ?? '');
if (!isset($transfers[$k])) {
    $k = (string)(array_key_first($transfers) ?? '');
}
$tr = $transfers[$k] ?? null;
$sources = bud_sources(true);
// หมวดที่ได้รับโอน (งบรายจ่าย · รายการ · ค่าใช้จ่าย) → รายการเบิกจ่ายทั้งปีงบประมาณที่หมวดตรงกัน
$catKey = function (array $t) use ($sources): string {
    $s = $sources[bud_source_key($t)] ?? null;
    return $s && isset($s['budget']) ? implode('|', [trim((string)$s['budget']), trim((string)$s['expense']), trim((string)$s['activity'])]) : bud_source_key($t);
};
$cats = [];                                          // catKey => ['source', 'receive', 'spend', 'txns' (เบิกจ่าย)]
$recv = [];
if ($tr) {
    foreach ($tr['txns'] as $t) {
        if ($t['txn_type'] === 'spend') {
            continue;
        }
        $recv[] = $t;
        $ck = $catKey($t);
        $cats[$ck] ??= ['source' => $sources[bud_source_key($t)] ?? null, 'receive' => 0.0, 'spend' => 0.0, 'txns' => []];
        $cats[$ck]['receive'] += (float)$t['amount'];
    }
    $st = db()->prepare("SELECT * FROM bud_txns WHERE fiscal_year = ? AND txn_type = 'spend' ORDER BY txn_date, id");
    $st->execute([$fy]);
    foreach ($st as $t) {
        $ck = $catKey($t);
        if (isset($cats[$ck])) {
            $cats[$ck]['txns'][] = $t;
            $cats[$ck]['spend'] += (float)$t['amount'];
        }
    }
}
$totalSpend = array_sum(array_column($cats, 'spend'));
$catName = function (?array $s): string {
    if (!$s) {
        return '-';
    }
    return isset($s['budget']) ? implode(' · ', array_filter([$s['budget'], $s['expense'], $s['activity']], fn($x) => trim((string)$x) !== '')) : $s['label'];
};
$itemText = function (array $t) use ($sources): string {          // ช่องรายการ: รายละเอียด (ถ้ามี) ไม่มี = ค่าใช้จ่ายของหมวด
    $s = $sources[bud_source_key($t)] ?? null;
    if (trim((string)$t['detail']) !== '') {
        return nl2br(h(trim($t['detail'])));
    }
    return h(($s['activity'] ?? '') !== '' ? $s['activity'] : (($s['expense'] ?? '') !== '' ? $s['expense'] : ($s['label'] ?? '-')));
};
$edit = bud_can('editor');

$pageTitle = $tr ? $tr['title'] : 'โอนเปลี่ยนแปลง ปีงบประมาณ ' . $fy;
$active = 'dashboard';
$pageActions = '<a href="./?fy=' . $fy . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> ภาพรวมงบประมาณ</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="print()"><i class="bi bi-printer"></i> พิมพ์</button>';
require __DIR__ . '/header.php';
?>
<?php if (!$tr): ?>
  <div class="alert alert-info">ปีงบประมาณ <?= $fy ?> ยังไม่มีรายการ "โอนเปลี่ยนแปลงครั้งที่ …"</div>
<?php else: ?>
<ul class="nav nav-pills mb-3 no-print">
  <?php foreach ($transfers as $x): ?>
    <li class="nav-item"><a class="nav-link<?= (string)$x['k'] === $k ? ' active' : '' ?>" href="transfer?<?= h(http_build_query(['fy' => $fy, 'k' => $x['k']])) ?>" title="<?= h($x['title']) ?>"><?= h($x['label'] !== '' ? 'ครั้งที่ ' . $x['label'] : mb_strimwidth($x['title'], 0, 30, '…')) ?></a></li>
  <?php endforeach; ?>
</ul>

<div class="row g-3 mb-3" data-reveal>
  <?php foreach ([['ได้รับ — โอนครั้งนี้ (บาท)', bud_money($tr['receive']), 'bi-box-arrow-in-down', BUD_COLORS['receive'], count($recv) . ' รายการ · ' . count($cats) . ' หมวด'],
                  ['เบิกจ่าย — หมวดเดียวกัน (บาท)', bud_money($totalSpend), 'bi-box-arrow-up', BUD_COLORS['spend'], array_sum(array_map(fn($c) => count($c['txns']), $cats)) . ' รายการ · ทั้งปีงบประมาณ ' . $fy],
                  ['คงเหลือ (บาท)', bud_money($tr['receive'] - $totalSpend), 'bi-wallet2', '#059669', 'ได้รับ − เบิกจ่าย']] as $i => [$lbl, $v, $ic, $c, $sub]): ?>
    <div class="col-sm-4 reveal-item" style="--d:<?= $i * 70 ?>ms">
      <div class="card h-100"><div class="card-body py-2">
        <div class="small text-muted"><i class="bi <?= $ic ?>" style="color:<?= $c ?>"></i> <?= $lbl ?></div>
        <div class="fs-4 fw-bold" style="color:<?= $c ?>"><?= $v ?></div>
        <div class="small text-muted"><?= h($sub) ?></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header"><strong><?= h($tr['title']) ?></strong>
    <span class="small text-muted ms-2"><?= h(thai_date($tr['from'])) ?><?= $tr['to'] !== $tr['from'] ? ' – ' . h(thai_date($tr['to'])) : '' ?></span></div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 bud-transfer">
      <thead class="table-light"><tr><th style="width:8.5em">วันที่</th><th>ที่เอกสาร</th><th>รายการ</th>
        <th class="text-end">ได้รับ</th><th class="text-end">เบิกจ่าย</th><?php if ($edit): ?><th class="no-print"></th><?php endif; ?></tr></thead>
      <!-- ส่วนที่ 1: รับเข้า -->
      <tbody class="bt-recv">
        <tr class="bt-sec"><th colspan="<?= $edit ? 6 : 5 ?>"><i class="bi bi-box-arrow-in-down"></i> รับเข้า — <?= h($tr['title']) ?></th></tr>
        <?php foreach ($recv as $t): ?>
          <tr>
            <td class="text-nowrap"><?= h(thai_date($t['txn_date'])) ?></td>
            <td class="font-monospace text-nowrap"><?= h($t['doc_no']) ?></td>
            <td><?= $itemText($t) ?></td>
            <td class="text-end text-nowrap bt-amt-r"><?= bud_money($t['amount']) ?></td>
            <td></td>
            <?php if ($edit): ?><td class="no-print"><a href="txn_form?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <tr class="bt-sum"><td colspan="3" class="text-end">รวมรับเข้า</td><td class="text-end"><?= bud_money($tr['receive']) ?></td><td></td><?php if ($edit): ?><td class="no-print"></td><?php endif; ?></tr>
      </tbody>
      <!-- ส่วนที่ 2: เบิกจ่าย (หมวดเดียวกับที่ได้รับโอน) -->
      <tbody class="bt-spend">
        <tr class="bt-sec"><th colspan="<?= $edit ? 6 : 5 ?>"><i class="bi bi-box-arrow-up"></i> เบิกจ่าย — งบรายจ่าย · รายการ · ค่าใช้จ่าย เดียวกับที่ได้รับโอน
          <span class="fw-normal small">(ทั้งปีงบประมาณ <?= $fy ?>)</span></th></tr>
        <?php foreach ($cats as $c): ?>
          <tr class="bt-cat"><td colspan="3"><i class="bi bi-tag"></i> <?= h($catName($c['source'])) ?></td>
            <td class="text-end text-nowrap small">ได้รับ <?= bud_money($c['receive']) ?></td>
            <td class="text-end text-nowrap small">คงเหลือ <?= bud_money($c['receive'] - $c['spend']) ?></td><?php if ($edit): ?><td class="no-print"></td><?php endif; ?></tr>
          <?php foreach ($c['txns'] as $t): ?>
            <tr>
              <td class="text-nowrap"><?= h(thai_date($t['txn_date'])) ?></td>
              <td class="font-monospace text-nowrap"><?= h($t['doc_no']) ?></td>
              <td><?= h(trim((string)$t['description'])) ?><?= trim((string)$t['detail']) !== '' ? '<div class="small">' . nl2br(h(trim($t['detail']))) . '</div>' : '' ?></td>
              <td></td>
              <td class="text-end text-nowrap bt-amt-s"><?= bud_money($t['amount']) ?></td>
              <?php if ($edit): ?><td class="no-print"><a href="txn_form?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$c['txns']): ?><tr><td colspan="<?= $edit ? 6 : 5 ?>" class="text-center small py-2">ยังไม่มีการเบิกจ่ายในหมวดนี้</td></tr><?php endif; ?>
        <?php endforeach; ?>
        <tr class="bt-sum"><td colspan="3" class="text-end">รวมเบิกจ่าย</td><td></td><td class="text-end"><?= bud_money($totalSpend) ?></td><?php if ($edit): ?><td class="no-print"></td><?php endif; ?></tr>
      </tbody>
      <tfoot><tr class="table-light fw-bold"><td colspan="3" class="text-end">คงเหลือ (ได้รับ − เบิกจ่าย)</td>
        <td colspan="2" class="text-end"><?= bud_money($tr['receive'] - $totalSpend) ?></td><?php if ($edit): ?><td class="no-print"></td><?php endif; ?></tr></tfoot>
    </table>
  </div>
  <div class="card-footer small">เบิกจ่าย = รายการเบิกจ่ายทั้งปีงบประมาณที่หมวดตรงกับหมวดที่ได้รับโอนครั้งนี้ (ถ้าหมวดเดียวกันได้รับโอนหลายครั้ง ยอดเบิกจ่ายจะแสดงในทุกครั้ง)</div>
</div>
<style>
  .bud-transfer .bt-sec th { font-weight: 700; color: #fff; }
  .bud-transfer .bt-recv .bt-sec th { background: <?= BUD_COLORS['receive'] ?>; }
  .bud-transfer .bt-spend .bt-sec th { background: <?= BUD_COLORS['spend'] ?>; }
  .bud-transfer .bt-recv td:first-child { box-shadow: inset 4px 0 0 <?= BUD_COLORS['receive'] ?>; }
  .bud-transfer .bt-spend td:first-child { box-shadow: inset 4px 0 0 <?= BUD_COLORS['spend'] ?>; }
  .bud-transfer .bt-cat td { background: color-mix(in srgb, <?= BUD_COLORS['spend'] ?> 10%, #fff); font-weight: 600; }
  .bud-transfer .bt-sum td { font-weight: 700; background: #f8fafc; }
  .bud-transfer .bt-amt-r { color: <?= BUD_COLORS['receive'] ?>; font-weight: 600; }
  .bud-transfer .bt-amt-s { color: <?= BUD_COLORS['spend'] ?>; font-weight: 600; }
  @media print { .bud-transfer .bt-sec th, .bud-transfer .bt-cat td { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
