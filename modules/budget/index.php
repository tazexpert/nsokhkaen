<?php
/** ภาพรวมระบบงบประมาณ — ได้รับ / เบิกจ่าย / คงเหลือ ของปีงบประมาณ · กราฟรายเดือน · แยกตามประเภทงบ (จากชื่อหมวด) และหมวด */
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
// แยกตามประเภทงบ (จากชื่อหมวด) เรียงเหมือนรายงาน · หมวดในกลุ่มเรียงตามยอดมาก → น้อย
$srcInfo = fn($k) => $sources[$k] ?? ['label' => '-', 'color' => '#64748b'];
$byType = [];
foreach ($bySrc as $k => $a) {
    $t = bud_budget_type($srcInfo($k));
    $byType[$t] ??= ['rank' => bud_budget_rank($srcInfo($k)), 'receive' => 0, 'spend' => 0, 'src' => []];
    $byType[$t]['receive'] += $a['receive'] ?? 0;
    $byType[$t]['spend'] += $a['spend'] ?? 0;
    $byType[$t]['src'][$k] = $a;
    // งบประมาณ → ค่าใช้จ่าย (รวมทุกกิจกรรม)
    $e = $srcInfo($k)['expense'] ?? '';
    $byType[$t]['exp'][$e]['receive'] = ($byType[$t]['exp'][$e]['receive'] ?? 0) + ($a['receive'] ?? 0);
    $byType[$t]['exp'][$e]['spend'] = ($byType[$t]['exp'][$e]['spend'] ?? 0) + ($a['spend'] ?? 0);
    $byType[$t]['exp'][$e]['n'] = ($byType[$t]['exp'][$e]['n'] ?? 0) + 1;
}
uasort($byType, fn($a, $b) => $a['rank'] <=> $b['rank']);
foreach ($byType as &$tt) {
    uasort($tt['src'], fn($a, $b) => max($b['receive'] ?? 0, $b['spend'] ?? 0) <=> max($a['receive'] ?? 0, $a['spend'] ?? 0));
    // ค่าใช้จ่าย: ยอดมาก → น้อย · (ไม่ระบุ) ไว้ท้าย
    uksort($tt['exp'], fn($x, $y) => [(string)$x === '', -max($tt['exp'][$x]['receive'], $tt['exp'][$x]['spend'])] <=> [(string)$y === '', -max($tt['exp'][$y]['receive'], $tt['exp'][$y]['spend'])]);
}
unset($tt);
$typeColors = ['#2563eb', '#7c3aed', '#0891b2', '#16a34a', '#db2777', '#ca8a04', '#64748b'];

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

<?php if ($byType): ?>
<!-- แยกตามประเภทงบ: กล่องละประเภท -->
<div class="row g-3 mb-4" data-reveal>
  <?php $ti = 0; foreach ($byType as $t => $a): $c = $typeColors[min($ti++, count($typeColors) - 1)];
    $tp = $a['receive'] > 0 ? $a['spend'] / $a['receive'] * 100 : 0; ?>
  <div class="col-sm-6 col-xl reveal-item">
    <a href="report.php?fy=<?= $fy ?>" class="card h-100 text-decoration-none text-body bud-type" style="--c:<?= $c ?>">
      <div class="card-body">
        <div class="bud-type-name"><?= h($t) ?></div>
        <div class="d-flex justify-content-between small mt-2"><span>ได้รับ</span><b class="bud-receive"><?= bud_money($a['receive']) ?></b></div>
        <div class="d-flex justify-content-between small"><span>เบิกจ่าย</span><b class="bud-spend"><?= bud_money($a['spend']) ?></b></div>
        <div class="d-flex justify-content-between small"><span>คงเหลือ</span><b class="<?= $a['receive'] - $a['spend'] < 0 ? 'text-danger' : '' ?>"><?= bud_money($a['receive'] - $a['spend']) ?></b></div>
        <div class="bud-progress mt-2"><span style="width:<?= min(100, $tp) ?>%;background:var(--c)"></span></div>
        <div class="small text-muted text-end mt-1">เบิกจ่าย <?= number_format($tp, 1) ?>%</div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3" data-reveal>
  <div class="col-12 reveal-item">
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
</div>

<?php if ($byType): ?>
<!-- สรุปตาม งบประมาณ → ค่าใช้จ่าย -->
<div class="row g-3 mt-1" data-reveal>
  <div class="col-12 reveal-item">
    <div class="card">
      <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong>สรุปตามงบประมาณ → ค่าใช้จ่าย</strong>
        <span class="bud-legend small ms-auto"><i style="background:<?= BUD_COLORS['spend'] ?>"></i>เบิกจ่าย (เทียบกับที่ได้รับ)</span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 bud-exp-table">
          <thead class="table-light"><tr><th>งบประมาณ / ค่าใช้จ่าย</th><th class="text-end">ได้รับ</th><th class="text-end">เบิกจ่าย</th><th class="text-end">คงเหลือ</th><th style="width:22%">ร้อยละเบิกจ่าย</th></tr></thead>
          <tbody>
          <?php $ti = 0; foreach ($byType as $t => $ta): $c = $typeColors[min($ti++, count($typeColors) - 1)]; $tr = $ta['receive']; $ts = $ta['spend']; ?>
            <tr class="bud-exp-type" style="--c:<?= $c ?>">
              <td><span class="bud-dot" style="background:<?= $c ?>"></span><?= h($t) ?></td>
              <td class="text-end"><?= bud_money($tr) ?></td><td class="text-end"><?= bud_money($ts) ?></td>
              <td class="text-end<?= $tr - $ts < 0 ? ' text-danger' : '' ?>"><?= bud_money($tr - $ts) ?></td>
              <td><div class="d-flex align-items-center gap-2"><span class="bar-track"><span class="bar-fill" style="width:<?= $tr > 0 ? min(100, round($ts / $tr * 100, 1)) : 0 ?>%;background:var(--c)"></span></span><span class="small text-nowrap"><?= $tr > 0 ? number_format($ts / $tr * 100, 1) . '%' : '-' ?></span></div></td>
            </tr>
            <?php foreach ($ta['exp'] as $e => $ea): $er = $ea['receive']; $es = $ea['spend']; ?>
            <tr>
              <td class="ps-4"><?= $e !== '' ? h($e) : '<span class="text-muted">(ไม่ระบุค่าใช้จ่าย)</span>' ?><?= $ea['n'] > 1 ? ' <span class="small text-muted">· ' . (int)$ea['n'] . ' หมวด</span>' : '' ?></td>
              <td class="text-end"><?= bud_money($er) ?></td><td class="text-end"><?= bud_money($es) ?></td>
              <td class="text-end<?= $er - $es < 0 ? ' text-danger' : '' ?>"><?= bud_money($er - $es) ?></td>
              <td><div class="d-flex align-items-center gap-2"><span class="bar-track"><span class="bar-fill bud-fill<?= $es > $er ? ' over' : '' ?>" style="width:<?= $er > 0 ? min(100, round($es / $er * 100, 1)) : ($es > 0 ? 100 : 0) ?>%"></span></span><span class="small text-nowrap"><?= $er > 0 ? number_format($es / $er * 100, 1) . '%' : '-' ?></span></div></td>
            </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="table-light fw-bold"><td>รวมทั้งสิ้น</td><td class="text-end"><?= bud_money($rec) ?></td><td class="text-end"><?= bud_money($spd) ?></td>
            <td class="text-end<?= $rec - $spd < 0 ? ' text-danger' : '' ?>"><?= bud_money($rec - $spd) ?></td><td class="small"><?= number_format($pct, 1) ?>%</td></tr></tfoot>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mt-1" data-reveal>
  <div class="col-12 reveal-item">
    <div class="card">
      <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong>แยกตามงบ / หมวด</strong>
        <span class="bud-legend small ms-auto"><i style="background:<?= BUD_COLORS['spend'] ?>"></i>เบิกจ่าย (เทียบกับที่ได้รับ)</span></div>
      <div class="card-body">
        <?php foreach ($byType as $t => $ta): ?>
          <div class="bud-type-head"><?= h($t) ?><span class="ms-auto text-nowrap"><b><?= bud_money($ta['spend']) ?></b> / <?= bud_money($ta['receive']) ?></span></div>
        <?php foreach ($ta['src'] as $k => $a): $s = $srcInfo($k);
          $r = $a['receive'] ?? 0; $sp = $a['spend'] ?? 0; $base = max($r, $sp, 0.01); ?>
          <a class="bud-src-row text-decoration-none text-body" href="txns.php?<?= h(http_build_query(['type' => $sp > 0 ? 'spend' : 'receive', 'fy' => $fy, 'src' => $k])) ?>">
            <span class="bud-src-name" title="<?= h($s['label']) ?>"><span class="bud-dot" style="background:<?= h($s['color']) ?>"></span><?= h(bud_label_in_type($s)) ?></span>
            <span class="bar-track"><span class="bar-fill bud-fill<?= $sp > $r ? ' over' : '' ?>" style="width:<?= round($sp / $base * 100, 1) ?>%"></span></span>
            <span class="bud-src-val text-nowrap"><b><?= bud_money($sp) ?></b> / <?= bud_money($r) ?><span class="text-muted small ms-1"><?= $r > 0 ? number_format($sp / $r * 100, 1) . '%' : '' ?></span></span>
          </a>
        <?php endforeach; endforeach; ?>
        <?php if (!$bySrc): ?><p class="text-muted mb-0">ยังไม่มีข้อมูล<?= bud_can('editor') ? ' — <a href="txn_form.php?type=receive">บันทึกรับเข้างบประมาณ</a>' : '' ?></p><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
