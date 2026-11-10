<?php
/**
 * รายละเอียดของปีงบประมาณ — เปิดจากการ์ดในหน้าภาพรวม
 *   ?b=<งบรายจ่าย>  งบรายจ่าย 1 ประเภท (เช่น งบดำเนินงาน): รายการ → ค่าใช้จ่าย
 *   ?p=<id>         โครงการ 1 โครงการ (หมวดที่ผูกโครงการ): งบรายจ่าย → หมวด
 *   การ์ดสรุป · กราฟรายเดือน · รายการรับเข้า/เบิกจ่าย
 */
require_once __DIR__ . '/bootstrap.php';
require_module(BUD_MODULE, 'viewer');
$fy = bud_fy_param();
$months = bud_fy_months($fy);
$type = trim((string)($_GET['b'] ?? ''));
$pid = (int)($_GET['p'] ?? 0);
$sources = bud_sources(true);
$project = $pid ? (projects_all()[$pid] ?? null) : null;
if ($project) {
    $keys = array_keys(array_filter($sources, fn($s) => (int)($s['project_id'] ?? 0) === $pid || $s['key'] === 'p:' . $pid));
    $heading = $project['code'] . ($project['name'] !== $project['code'] ? ' ' . $project['name'] : '');
    $groupOf = fn(array $s) => bud_budget_type($s);                                   // งบรายจ่าย → หมวด
    $itemLabel = fn(array $s) => bud_label_in_type($s);
    $listTitle = 'งบรายจ่าย → หมวด';
} else {
    $keys = $type !== '' ? array_keys(array_filter($sources, fn($s) => bud_budget_type($s) === $type)) : [];
    $heading = $type;
    $groupOf = fn(array $s) => $s['expense'] ?? '';                                    // รายการ → ค่าใช้จ่าย
    $itemLabel = fn(array $s) => ($s['activity'] ?? '') !== '' ? $s['activity'] : (($s['expense'] ?? '') !== '' ? $s['expense'] : $s['label']);
    $listTitle = 'รายการ → ค่าใช้จ่าย';
}
if (!$keys) {
    flash($pid ? 'ไม่พบหมวดของโครงการนี้' : 'ไม่พบงบรายจ่ายนี้', 'warning');
    redirect('./?fy=' . $fy);
}
$totals = array_intersect_key(bud_totals($fy), array_flip($keys));
$byMonth = array_fill_keys($months, ['receive' => 0.0, 'spend' => 0.0]);
$bySrc = [];
foreach ($totals as $k => $ms) {
    foreach ($ms as $ym => $tt) {
        foreach ($tt as $t => $amt) {
            if (isset($byMonth[$ym])) {
                $byMonth[$ym][$t] += $amt;
            }
            $bySrc[$k][$t] = ($bySrc[$k][$t] ?? 0) + $amt;
        }
    }
}
$rec = array_sum(array_column($byMonth, 'receive'));
$spd = array_sum(array_column($byMonth, 'spend'));
$pct = $rec > 0 ? $spd / $rec * 100 : 0;
// รายการ → ค่าใช้จ่าย (เรียงตามลำดับหมวด) · แสดงทุกหมวดของงบนี้ที่ใช้งานหรือมีข้อมูล
$exp = [];
foreach ($keys as $k) {
    $s = $sources[$k];
    if (!$s['active'] && !isset($bySrc[$k])) {
        continue;
    }
    $e = $groupOf($s);
    $exp[$e]['items'][$k] = $bySrc[$k] ?? [];
    $exp[$e]['receive'] = ($exp[$e]['receive'] ?? 0) + ($bySrc[$k]['receive'] ?? 0);
    $exp[$e]['spend'] = ($exp[$e]['spend'] ?? 0) + ($bySrc[$k]['spend'] ?? 0);
}
if ($project) {
    uksort($exp, fn($a, $b) => bud_budget_rank(['budget' => (string)$a, 'label' => (string)$a]) <=> bud_budget_rank(['budget' => (string)$b, 'label' => (string)$b]));
} else {
    uksort($exp, fn($a, $b) => [(string)$a === '', 0] <=> [(string)$b === '', 0]);
}
// รายการรับเข้า/เบิกจ่ายของงบนี้
$ids = array_map(fn($k) => (int)substr($k, 2), array_filter($keys, fn($k) => $k[0] === 'i'));
$pids = array_map(fn($k) => (int)substr($k, 2), array_filter($keys, fn($k) => $k[0] === 'p'));
$where = [];
$args = [$fy];
if ($ids) {
    $where[] = 'item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    array_push($args, ...$ids);
}
if ($pids) {
    $where[] = '(item_id IS NULL AND project_id IN (' . implode(',', array_fill(0, count($pids), '?')) . '))';
    array_push($args, ...$pids);
}
$st = db()->prepare('SELECT * FROM bud_txns WHERE fiscal_year = ? AND (' . implode(' OR ', $where) . ') ORDER BY txn_date DESC, id DESC');
$st->execute($args);
$txns = $st->fetchAll();
$works = bud_works(true);
$plans = bud_plans();
$money = fn(float $v) => $v == 0 ? '-' : ($v < 0 ? '<span class="text-danger">' . bud_money($v) . '</span>' : bud_money($v));
$pctText = fn(float $r, float $s) => $r > 0 ? number_format($s / $r * 100, 1) . '%' : '-';

$pageTitle = ($project ? 'โครงการ ' : '') . $heading . ' ปีงบประมาณ ' . $fy;
$active = 'dashboard';
$pageActions = '<a href="./?fy=' . $fy . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> ภาพรวม</a>'
    . '<a href="report?fy=' . $fy . '" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-bar-graph"></i> รายงาน</a>';
require __DIR__ . '/header.php';
?>
<div class="row g-3 mb-4" data-reveal>
  <?php foreach ([
      ['ได้รับ', bud_money($rec), 'bi-box-arrow-in-down', 'primary'],
      ['เบิกจ่าย', bud_money($spd), 'bi-box-arrow-up', 'warning'],
      ['คงเหลือ', bud_money($rec - $spd), 'bi-wallet2', $rec - $spd < 0 ? 'danger' : 'success'],
  ] as [$label, $v, $icon, $color]): ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <div class="stat-card h-100"><span class="stat-icon text-bg-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
      <div class="min-w-0"><div class="stat-num text-nowrap"><?= $v ?></div><div class="stat-label"><?= h($label) ?> (บาท)</div></div></div>
  </div>
  <?php endforeach; ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <div class="stat-card h-100"><span class="stat-icon text-bg-info"><i class="bi bi-percent"></i></span>
      <div class="flex-grow-1 min-w-0"><div class="stat-num"><?= number_format($pct, 1) ?>%</div><div class="stat-label">ร้อยละการเบิกจ่าย</div>
        <div class="bud-progress mt-1"><span style="width:<?= min(100, $pct) ?>%"></span></div></div></div>
  </div>
</div>

<div class="row g-3 mb-4" data-reveal>
  <div class="col-lg-6 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong>รับเข้า-เบิกจ่ายรายเดือน</strong>
        <span class="bud-legend small ms-auto"><i style="background:<?= BUD_COLORS['receive'] ?>"></i>ได้รับ <i style="background:<?= BUD_COLORS['spend'] ?>" class="ms-2"></i>เบิกจ่าย</span></div>
      <div class="card-body">
        <?php $max = max(1, ...array_map(fn($m) => max($m['receive'], $m['spend']), array_values($byMonth))); $i = 0; ?>
        <div class="bud-vbars">
          <?php foreach ($byMonth as $ym => $m): ?>
            <div class="bud-col" title="<?= h(bud_month_label($ym) . ' · ได้รับ ' . bud_money($m['receive']) . ' · เบิกจ่าย ' . bud_money($m['spend']) . ' บาท') ?>">
              <?php foreach (['receive', 'spend'] as $t): ?>
                <span class="bud-bar bud-anim <?= $t ?>" style="height:<?= round($m[$t] / $max * 100, 2) ?>%;--d:<?= $i++ * 38 ?>ms"></span>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="bud-vlabels"><?php foreach ($months as $ym): ?><span><?= h(bud_month_label($ym, true)) ?></span><?php endforeach; ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-6 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong><?= h($listTitle) ?></strong>
        <span class="bud-legend small ms-auto"><i style="background:<?= BUD_COLORS['spend'] ?>"></i>เบิกจ่าย / ได้รับ</span></div>
      <div class="card-body bud-src-list">
        <?php $n = 0; foreach ($exp as $e => $ea): $n++; ?>
          <div class="bud-type-head"><?= $n ?>. <?= $e !== '' ? h($e) : '<span class="text-muted">(ไม่ระบุรายการ)</span>' ?>
            <span class="ms-auto text-nowrap"><b><?= bud_money($ea['spend']) ?></b> / <?= bud_money($ea['receive']) ?></span></div>
          <?php $m2 = 0; foreach ($ea['items'] as $k => $a): $s = $sources[$k]; $r = $a['receive'] ?? 0; $sp = $a['spend'] ?? 0; $base = max($r, $sp, 0.01); $m2++; ?>
            <a class="bud-src-row2 text-decoration-none text-body" href="txns?<?= h(http_build_query(['type' => $sp > 0 ? 'spend' : 'receive', 'fy' => $fy, 'src' => $k])) ?>" title="<?= h($s['label']) ?>">
              <div class="small"><?= $n ?>.<?= $m2 ?> <?= h($itemLabel($s)) ?><?= $s['active'] ? '' : ' <span class="text-muted">(ปิดใช้งาน)</span>' ?></div>
              <div class="d-flex align-items-center gap-2">
                <span class="bar-track bud-mini"><span class="bar-fill bud-fill<?= $sp > $r ? ' over' : '' ?>" style="width:<?= round($sp / $base * 100, 1) ?>%"></span></span>
                <span class="small text-nowrap"><b><?= bud_money($sp) ?></b> / <?= bud_money($r) ?><span class="text-muted ms-1"><?= $pctText($r, $sp) ?></span></span>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3" data-reveal>
  <div class="col-12 reveal-item">
    <div class="card">
      <div class="card-header d-flex align-items-center"><strong>รายการรับเข้า / เบิกจ่าย</strong><span class="ms-auto small text-muted"><?= number_format(count($txns)) ?> รายการ</span></div>
      <div class="table-responsive" style="max-height:480px">
        <table class="table table-sm table-hover align-middle mb-0 bud-table">
          <thead class="table-light" style="position:sticky;top:0;z-index:1"><tr><th class="text-nowrap">วันที่</th><th>ที่เอกสาร</th><th>รายการ</th><th>หมวด</th><th class="text-end">ได้รับ</th><th class="text-end">เบิกจ่าย</th></tr></thead>
          <tbody>
          <?php foreach ($txns as $t): $s = $sources[bud_source_key($t)] ?? null; $pl = $plans[bud_txn_plan($t)] ?? null; $w = $works[(int)$t['work_id']] ?? null; ?>
            <tr>
              <td class="text-nowrap"><?= h(thai_date($t['txn_date'])) ?></td>
              <td class="text-nowrap small"><?= h($t['doc_no']) ?></td>
              <td><?= h($t['description']) ?><?php if ($t['detail'] !== ''): ?><div class="small text-muted"><?= nl2br(h($t['detail'])) ?></div><?php endif; ?></td>
              <td class="small"><?= $s ? h(bud_label_in_type($s)) : '-' ?><?php if ($pl): ?><div class="text-muted"><?= h($pl['name']) ?><?= $w ? ' › ' . h($w['name']) : '' ?></div><?php endif; ?></td>
              <td class="text-end text-nowrap bud-receive"><?= $t['txn_type'] === 'receive' ? bud_money($t['amount']) : '' ?></td>
              <td class="text-end text-nowrap bud-spend"><?= $t['txn_type'] === 'spend' ? bud_money($t['amount']) : '' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$txns): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีรายการในปีงบประมาณนี้</td></tr><?php endif; ?>
          </tbody>
          <?php if ($txns): ?><tfoot class="table-light fw-bold"><tr><td colspan="4" class="text-end">รวม</td><td class="text-end"><?= bud_money($rec) ?></td><td class="text-end"><?= bud_money($spd) ?></td></tr></tfoot><?php endif; ?>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
