<?php
/** ภาพรวมระบบงบประมาณ — ได้รับ / เบิกจ่าย / คงเหลือ ของปีงบประมาณ · กราฟรายเดือน · แยกตามชื่อโครงการที่ได้รับ */
require_once __DIR__ . '/bootstrap.php';
require_module(BUD_MODULE, 'viewer');
$fy = bud_fy_param();
$months = bud_fy_months($fy);
$totals = bud_totals($fy);
$sources = bud_sources(true);
$byMonth = array_fill_keys($months, ['receive' => 0, 'spend' => 0]);
$bySrc = [];
foreach ($totals as $k => $ms) {
    foreach ($ms as $ym => $tt) {
        foreach ($tt as $type => $amt) {
            if (isset($byMonth[$ym])) {
                $byMonth[$ym][$type] += $amt;
            }
            $bySrc[$k][$type] = ($bySrc[$k][$type] ?? 0) + $amt;
        }
    }
}
$rec = array_sum(array_column($byMonth, 'receive'));
$spd = array_sum(array_column($byMonth, 'spend'));
$pct = $rec > 0 ? $spd / $rec * 100 : 0;
uasort($bySrc, fn($a, $b) => max($b['receive'] ?? 0, $b['spend'] ?? 0) <=> max($a['receive'] ?? 0, $a['spend'] ?? 0));
$st = db()->prepare('SELECT * FROM bud_txns WHERE fiscal_year = ? ORDER BY txn_date DESC, id DESC LIMIT 8');
$st->execute([$fy]);
$recent = $st->fetchAll();

$pageTitle = 'ภาพรวมงบประมาณ ปีงบประมาณ ' . $fy;
$active = 'dashboard';
$pageActions = '<form method="get" class="d-flex align-items-center gap-2"><label class="small text-muted text-nowrap">ปีงบประมาณ</label><select name="fy" class="form-select form-select-sm" onchange="this.form.submit()">';
foreach (bud_fy_list() as $y) {
    $pageActions .= '<option value="' . $y . '"' . ($y === $fy ? ' selected' : '') . '>' . $y . '</option>';
}
$pageActions .= '</select></form>' . (bud_can('editor') ? '<a href="txn_form.php?type=receive" class="btn btn-primary"><i class="bi bi-box-arrow-in-down"></i> รับเข้า</a><a href="txn_form.php?type=spend" class="btn btn-warning"><i class="bi bi-box-arrow-up"></i> เบิกจ่าย</a>' : '');
require __DIR__ . '/header.php';
?>
<div class="row g-3 mb-4" data-reveal>
  <?php foreach ([
      ['ได้รับ', bud_money($rec), 'bi-box-arrow-in-down', 'primary', 'บาท · รับเข้างบประมาณ', 'txns.php?type=receive&fy=' . $fy],
      ['เบิกจ่าย', bud_money($spd), 'bi-box-arrow-up', 'warning', 'บาท · เบิกจ่ายงบประมาณ', 'txns.php?type=spend&fy=' . $fy],
      ['คงเหลือ', bud_money($rec - $spd), 'bi-wallet2', $rec - $spd < 0 ? 'danger' : 'success', 'บาท · ได้รับ − เบิกจ่าย', 'report.php?fy=' . $fy],
  ] as [$label, $v, $icon, $color, $sub, $href]): ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <a href="<?= h($href) ?>" class="stat-card h-100 text-decoration-none text-body"><span class="stat-icon text-bg-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
      <div class="min-w-0"><div class="stat-num text-nowrap"><?= $v ?></div><div class="stat-label"><?= h($label) ?></div><div class="small text-muted"><?= h($sub) ?></div></div></a>
  </div>
  <?php endforeach; ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <div class="stat-card h-100"><span class="stat-icon text-bg-info"><i class="bi bi-percent"></i></span>
      <div class="flex-grow-1 min-w-0"><div class="stat-num"><?= number_format($pct, 1) ?>%</div><div class="stat-label">ร้อยละการเบิกจ่าย</div>
        <div class="bud-progress mt-1"><span style="width:<?= min(100, $pct) ?>%"></span></div></div></div>
  </div>
</div>

<div class="row g-3" data-reveal>
  <div class="col-xl-8 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong>ได้รับ / เบิกจ่าย รายเดือน</strong>
        <span class="bud-legend small ms-auto"><i style="background:<?= BUD_COLORS['receive'] ?>"></i>ได้รับ <i style="background:<?= BUD_COLORS['spend'] ?>" class="ms-2"></i>เบิกจ่าย</span></div>
      <div class="card-body">
        <?php $max = max(1, ...array_map(fn($m) => max($m['receive'], $m['spend']), array_values($byMonth))); $i = 0; ?>
        <div class="bud-vbars">
          <?php foreach ($byMonth as $ym => $m): ?>
            <a class="bud-col text-decoration-none" href="report.php?<?= h(http_build_query(['fy' => $fy, 'mode' => 'month', 'month' => $ym])) ?>"
               title="<?= h(bud_month_label($ym) . ' · ได้รับ ' . bud_money($m['receive']) . ' · เบิกจ่าย ' . bud_money($m['spend']) . ' บาท') ?>">
              <?php foreach (['receive', 'spend'] as $type): ?>
                <span class="bud-bar bud-anim <?= $type ?>" style="height:<?= round($m[$type] / $max * 100, 2) ?>%;--d:<?= $i++ * 38 ?>ms"></span>
              <?php endforeach; ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="bud-vlabels"><?php foreach ($months as $ym): ?><span><?= h(bud_month_label($ym, true)) ?></span><?php endforeach; ?></div>
        <div class="small text-muted mt-2">สูงสุด <?= bud_money($max) ?> บาท/เดือน · คลิกแท่งเพื่อดูรายงานเดือนนั้น</div>
      </div>
    </div>
  </div>
  <div class="col-xl-4 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center"><strong>รายการล่าสุด</strong><a href="report.php?fy=<?= $fy ?>" class="btn btn-sm btn-light ms-auto">รายงาน <i class="bi bi-chevron-right"></i></a></div>
      <div class="list-group list-group-flush">
        <?php foreach ($recent as $t): $s = $sources[bud_source_key($t)] ?? null; ?>
          <div class="list-group-item d-flex gap-2">
            <i class="bi <?= $t['txn_type'] === 'spend' ? 'bi-arrow-up-right-circle-fill bud-spend' : 'bi-arrow-down-left-circle-fill bud-receive' ?> fs-5"></i>
            <div class="min-w-0 flex-grow-1"><div class="text-truncate"><?= h($t['description']) ?></div>
              <div class="small text-muted text-truncate"><?= h(thai_date($t['txn_date'])) ?><?= $s ? ' · ' . h($s['label']) : '' ?></div></div>
            <div class="text-end text-nowrap fw-semibold bud-<?= $t['txn_type'] ?>"><?= bud_money($t['amount']) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$recent): ?><div class="list-group-item text-muted">ยังไม่มีรายการในปีงบประมาณนี้</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1" data-reveal>
  <div class="col-12 reveal-item">
    <div class="card">
      <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong>แยกตามชื่อโครงการที่ได้รับ</strong>
        <span class="bud-legend small ms-auto"><i style="background:<?= BUD_COLORS['spend'] ?>"></i>เบิกจ่าย (เทียบกับที่ได้รับ)</span></div>
      <div class="card-body">
        <?php foreach ($bySrc as $k => $a): $s = $sources[$k] ?? ['label' => '-', 'color' => '#64748b'];
          $r = $a['receive'] ?? 0; $sp = $a['spend'] ?? 0; $base = max($r, $sp, 0.01); ?>
          <a class="bud-src-row text-decoration-none text-body" href="txns.php?<?= h(http_build_query(['type' => $sp > 0 ? 'spend' : 'receive', 'fy' => $fy, 'src' => $k])) ?>">
            <span class="bud-src-name"><span class="bud-dot" style="background:<?= h($s['color']) ?>"></span><?= h($s['label']) ?></span>
            <span class="bar-track"><span class="bar-fill bud-fill<?= $sp > $r ? ' over' : '' ?>" style="width:<?= round($sp / $base * 100, 1) ?>%"></span></span>
            <span class="bud-src-val text-nowrap"><b><?= bud_money($sp) ?></b> / <?= bud_money($r) ?><span class="text-muted small ms-1"><?= $r > 0 ? number_format($sp / $r * 100, 1) . '%' : '' ?></span></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$bySrc): ?><p class="text-muted mb-0">ยังไม่มีข้อมูล<?= bud_can('editor') ? ' — <a href="txn_form.php?type=receive">บันทึกรับเข้างบประมาณ</a>' : '' ?></p><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
