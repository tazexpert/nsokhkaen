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
    // งบรายจ่าย → รายการ (รวมทุกค่าใช้จ่าย)
    $e = $srcInfo($k)['expense'] ?? '';
    $byType[$t]['exp'][$e]['receive'] = ($byType[$t]['exp'][$e]['receive'] ?? 0) + ($a['receive'] ?? 0);
    $byType[$t]['exp'][$e]['spend'] = ($byType[$t]['exp'][$e]['spend'] ?? 0) + ($a['spend'] ?? 0);
    $byType[$t]['exp'][$e]['n'] = ($byType[$t]['exp'][$e]['n'] ?? 0) + 1;
}
uasort($byType, fn($a, $b) => $a['rank'] <=> $b['rank']);
foreach ($byType as &$tt) {
    uasort($tt['src'], fn($a, $b) => max($b['receive'] ?? 0, $b['spend'] ?? 0) <=> max($a['receive'] ?? 0, $a['spend'] ?? 0));
    // รายการ: ยอดมาก → น้อย · (ไม่ระบุ) ไว้ท้าย
    uksort($tt['exp'], fn($x, $y) => [(string)$x === '', -max($tt['exp'][$x]['receive'], $tt['exp'][$x]['spend'])] <=> [(string)$y === '', -max($tt['exp'][$y]['receive'], $tt['exp'][$y]['spend'])]);
}
unset($tt);
$typeColors = ['#2563eb', '#7c3aed', '#0891b2', '#16a34a', '#db2777', '#ca8a04', '#64748b'];
$typeBg = ['primary', 'success', 'warning', 'info', 'danger', 'dark'];                    // การ์ดงบรายจ่าย: สีหลัก
// แยกรายโครงการ: หมวดที่ผูกกับโครงการสำรวจ (และข้อมูลเดิมที่บันทึกด้วยโครงการ) — เฉพาะโครงการที่มีรายการในปีงบนี้
$projects = projects_all();
// สรุปย่อในการ์ด: ได้รับ | เบิกจ่าย | คงเหลือ (บรรทัดเดียว) + แถบร้อยละ
$sumBlock = fn(array $a, float $tp) => '<div class="bud-sum">'
    . '<div><span>ได้รับ</span><b class="bud-receive">' . bud_money($a['receive']) . '</b></div>'
    . '<div><span>เบิกจ่าย</span><b class="bud-spend">' . bud_money($a['spend']) . '</b></div>'
    . '<div><span>คงเหลือ</span><b' . ($a['receive'] - $a['spend'] < 0 ? ' class="text-danger"' : '') . '>' . bud_money($a['receive'] - $a['spend']) . '</b></div></div>'
    . '<div class="d-flex align-items-center gap-2 mt-1"><div class="bud-progress flex-grow-1"><span style="width:' . min(100, $tp) . '%;background:var(--c)"></span></div>'
    . '<span class="small text-muted text-nowrap">' . number_format($tp, 1) . '%</span></div>';
$byProject = [];
foreach ($bySrc as $k => $a) {
    $pid = (int)($k[0] === 'p' ? substr($k, 2) : ($srcInfo($k)['project_id'] ?? 0));
    if (!$pid || !isset($projects[$pid])) {
        continue;
    }
    $byProject[$pid] ??= ['receive' => 0, 'spend' => 0, 'src' => []];
    $byProject[$pid]['receive'] += $a['receive'] ?? 0;
    $byProject[$pid]['spend'] += $a['spend'] ?? 0;
    $byProject[$pid]['src'][$k] = $a;
}
$projOrder = array_flip(array_keys($projects));
uksort($byProject, fn($a, $b) => $projOrder[$a] <=> $projOrder[$b]);

// ตัวเลือกหน้าตาแดชบอร์ด (ทดลอง): ?design=a | b | c — ว่าง = แบบปัจจุบัน
$design = in_array($_GET['design'] ?? '', ['a', 'b', 'c'], true) ? $_GET['design'] : '';
if ($design) {
    $headExtra = '<link href="' . h(asset_v('modules/budget/assets/css/dash.css')) . '" rel="stylesheet">';
}
$typeHex = ['#2563eb', '#10b981', '#f59e0b', '#06b6d4', '#ef4444', '#475569'];       // สีของงบรายจ่ายตามลำดับ
// วงแหวนร้อยละ (SVG) — animation วาดเส้นเมื่อเลื่อนมาเห็น
$ring = fn(float $p, string $color, int $size = 72, string $label = '') => '<svg class="dz-ring" width="' . $size . '" height="' . $size . '" viewBox="0 0 36 36" style="--p:' . round(min(100, max(0, $p)), 1) . ';--rc:' . h($color) . '">'
    . '<circle class="dz-ring-bg" cx="18" cy="18" r="15.9" pathLength="100"/><circle class="dz-ring-fg" cx="18" cy="18" r="15.9" pathLength="100"/>'
    . '<text x="18" y="' . ($label !== '' ? '18' : '20.5') . '" text-anchor="middle" class="dz-ring-t">' . number_format($p, $p >= 100 ? 0 : 1) . '%</text>'
    . ($label !== '' ? '<text x="18" y="24" text-anchor="middle" class="dz-ring-l">' . h($label) . '</text>' : '') . '</svg>';
// กราฟแท่งจิ๋ว 12 เดือน (SVG)
$spark = function (array $vals, string $color): string {
    $max = max(1, ...$vals);
    $w = 6; $gap = 2; $h = 28; $out = '';
    foreach (array_values($vals) as $i => $v) {
        $bh = max(1, round($v / $max * $h, 1));
        $out .= '<rect x="' . ($i * ($w + $gap)) . '" y="' . ($h - $bh) . '" width="' . $w . '" height="' . $bh . '" rx="1.5" style="--d:' . ($i * 40) . 'ms"/>';
    }
    return '<svg class="dz-spark" width="' . (12 * ($w + $gap)) . '" height="' . $h . '" style="fill:' . h($color) . '">' . $out . '</svg>';
};
$pageTitle = 'ภาพรวมงบประมาณ ปีงบประมาณ ' . $fy;
$active = 'dashboard';
$pageActions = '<form method="get" class="d-flex align-items-center gap-2"><label class="small text-muted text-nowrap">ปีงบประมาณ</label><select name="fy" class="form-select form-select-sm" onchange="this.form.submit()">';
foreach (bud_fy_list() as $y) {
    $pageActions .= '<option value="' . $y . '"' . ($y === $fy ? ' selected' : '') . '>' . $y . '</option>';
}
$pageActions .= '</select>' . ($design ? '<input type="hidden" name="design" value="' . $design . '">' : '') . '</form>' . (bud_can('editor') ? '<a href="txn_form?type=receive" class="btn btn-primary"><i class="bi bi-box-arrow-in-down"></i> รับเข้า</a><a href="txn_form?type=spend" class="btn btn-warning"><i class="bi bi-box-arrow-up"></i> เบิกจ่าย</a>' : '');
require __DIR__ . '/header.php';
if ($design) {
    require __DIR__ . '/dash_' . $design . '.php';
    require __DIR__ . '/footer.php';
    return;
}
?>
<div class="row g-2 mb-3 bud-compact" data-reveal>
  <?php foreach ([
      ['ได้รับ', bud_money($rec), 'bi-box-arrow-in-down', 'primary', 'บาท · รับเข้างบประมาณ', 'txns?type=receive&fy=' . $fy],
      ['เบิกจ่าย', bud_money($spd), 'bi-box-arrow-up', 'warning', 'บาท · เบิกจ่ายงบประมาณ', 'txns?type=spend&fy=' . $fy],
      ['คงเหลือ', bud_money($rec - $spd), 'bi-wallet2', $rec - $spd < 0 ? 'danger' : 'success', 'บาท · ได้รับ − เบิกจ่าย', 'report?fy=' . $fy],
  ] as [$label, $v, $icon, $color, $sub, $href]): ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <a href="<?= h($href) ?>" class="stat-card bud-stat h-100 text-decoration-none text-body" title="<?= h($sub) ?>"><span class="stat-icon text-bg-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
      <div class="min-w-0"><div class="stat-num text-nowrap"><?= $v ?></div><div class="stat-label"><?= h($label) ?> (บาท)</div></div></a>
  </div>
  <?php endforeach; ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <div class="stat-card bud-stat h-100"><span class="stat-icon text-bg-info"><i class="bi bi-percent"></i></span>
      <div class="flex-grow-1 min-w-0"><div class="stat-num"><?= number_format($pct, 1) ?>%</div><div class="stat-label">ร้อยละการเบิกจ่าย</div>
        <div class="bud-progress mt-1"><span style="width:<?= min(100, $pct) ?>%"></span></div></div></div>
  </div>
</div>

<?php if ($byType): ?>
<!-- สรุปตามงบรายจ่าย → รายการ: การ์ดละงบ (อยู่เหนือกราฟ) -->
<div class="row g-2 mb-3 bud-compact" data-reveal>
  <?php $ti = 0; foreach ($byType as $t => $a): $bg = $typeBg[min($ti, count($typeBg) - 1)]; $c = $typeColors[min($ti++, count($typeColors) - 1)];
    $tp = $a['receive'] > 0 ? $a['spend'] / $a['receive'] * 100 : 0; ?>
  <div class="col-md-6 col-xl-3 reveal-item">
    <a href="type?<?= h(http_build_query(['fy' => $fy, 'b' => $t])) ?>" class="card h-100 text-decoration-none bud-type bud-solid text-bg-<?= $bg ?>" style="--c:#fff">
      <div class="card-body">
        <div class="bud-type-name"><?= h($t) ?></div>
        <?= $sumBlock($a, $tp) ?>
        <!-- รายการของงบนี้ (รวมทุกค่าใช้จ่าย): เบิกจ่าย / ได้รับ -->
        <div class="bud-type-exp">
          <?php foreach ($a['exp'] as $e => $ea): $er = $ea['receive']; $es = $ea['spend']; $ep = $er > 0 ? $es / $er * 100 : ($es > 0 ? 100 : 0); ?>
          <div class="bud-type-exp-row" title="<?= h(($e !== '' ? $e : 'ไม่ระบุรายการ') . ' · ได้รับ ' . bud_money($er) . ' · เบิกจ่าย ' . bud_money($es) . ' · คงเหลือ ' . bud_money($er - $es) . ($ea['n'] > 1 ? ' · ' . $ea['n'] . ' หมวด' : '')) ?>">
            <div class="bud-line"><span class="nm"><?= $e !== '' ? h($e) : '<span class="text-muted">(ไม่ระบุรายการ)</span>' ?></span>
              <span class="am"><b><?= bud_money($es) ?></b><span class="text-muted"> / <?= bud_money($er) ?></span></span></div>
            <span class="bar-track bud-thin"><span class="bar-fill bud-fill<?= $es > $er ? ' over' : '' ?>" style="width:<?= min(100, round($ep, 1)) ?>%"></span></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($byProject): ?>
<!-- แยกรายโครงการ (เฉพาะโครงการที่มีงบประมาณ) -->
<h2 class="h6 text-muted mb-2 mt-1"><i class="bi bi-kanban"></i> แยกรายโครงการ</h2>
<div class="row g-2 mb-3 bud-compact" data-reveal>
  <?php foreach ($byProject as $pid => $a): $pj = $projects[$pid]; $c = $pj['color'] ?: '#0d6efd';
    $tp = $a['receive'] > 0 ? $a['spend'] / $a['receive'] * 100 : 0; ?>
  <div class="col-md-6 col-xl-3 reveal-item">
    <a href="type?<?= h(http_build_query(['fy' => $fy, 'p' => $pid])) ?>" class="card h-100 text-decoration-none text-body bud-type" style="--c:<?= h($c) ?>">
      <div class="card-body">
        <div class="bud-type-name text-truncate" title="<?= h($pj['name']) ?>"><?= h($pj['code']) ?><?php if ($pj['name'] !== $pj['code']): ?> <span class="fw-normal text-muted small"><?= h($pj['name']) ?></span><?php endif; ?></div>
        <?= $sumBlock($a, $tp) ?>
        <?php if (count($a['src']) > 1): ?>
        <div class="bud-type-exp">
          <?php foreach ($a['src'] as $k => $sa): $s = $srcInfo($k); $er = $sa['receive'] ?? 0; $es = $sa['spend'] ?? 0; $ep = $er > 0 ? $es / $er * 100 : ($es > 0 ? 100 : 0); ?>
          <div class="bud-type-exp-row" title="<?= h($s['label']) ?>">
            <div class="bud-line"><span class="nm"><?= h(bud_label_in_type($s)) ?></span>
              <span class="am"><b><?= bud_money($es) ?></b><span class="text-muted"> / <?= bud_money($er) ?></span></span></div>
            <span class="bar-track bud-thin"><span class="bar-fill bud-fill<?= $es > $er ? ' over' : '' ?>" style="width:<?= min(100, round($ep, 1)) ?>%"></span></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: $k = array_key_first($a['src']); ?>
        <div class="small mt-1 text-truncate text-muted"><i class="bi bi-list-ul"></i> <?= h(bud_label_in_type($srcInfo($k))) ?></div>
        <?php endif; ?>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-2 bud-compact" data-reveal>
  <div class="col-lg-6 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong>สรุปการรับเข้า-เบิกจ่าย งบประมาณรายเดือน</strong>
        <span class="bud-legend small ms-auto"><i style="background:<?= BUD_COLORS['receive'] ?>"></i>ได้รับ <i style="background:<?= BUD_COLORS['spend'] ?>" class="ms-2"></i>เบิกจ่าย</span></div>
      <div class="card-body">
        <?php $max = max(1, ...array_map(fn($m) => max($m['receive'], $m['spend']), array_values($byMonth))); $i = 0; ?>
        <div class="bud-vbars">
          <?php foreach ($byMonth as $ym => $m): ?>
            <a class="bud-col text-decoration-none" href="report?<?= h(http_build_query(['fy' => $fy, 'mode' => 'month', 'month' => $ym])) ?>"
               title="<?= h(bud_month_label($ym) . ' · ได้รับ ' . bud_money($m['receive']) . ' · เบิกจ่าย ' . bud_money($m['spend']) . ' บาท') ?>">
              <?php foreach (['receive', 'spend'] as $type): ?>
                <span class="bud-bar bud-anim <?= $type ?>" style="height:<?= round($m[$type] / $max * 100, 2) ?>%;--d:<?= $i++ * 38 ?>ms"></span>
              <?php endforeach; ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="bud-vlabels"><?php foreach ($months as $ym): ?><span><?= h(bud_month_label($ym, true)) ?></span><?php endforeach; ?></div>
      </div>
    </div>
  </div>
  <div class="col-lg-6 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong>แยกตามงบ / หมวด</strong>
        <span class="bud-legend small ms-auto"><i style="background:<?= BUD_COLORS['spend'] ?>"></i>เบิกจ่าย / ได้รับ</span></div>
      <div class="card-body bud-src-list">
        <?php foreach ($byType as $t => $ta): ?>
          <div class="bud-type-head"><?= h($t) ?><span class="ms-auto text-nowrap"><b><?= bud_money($ta['spend']) ?></b> / <?= bud_money($ta['receive']) ?></span></div>
        <?php foreach ($ta['src'] as $k => $a): $s = $srcInfo($k);
          $r = $a['receive'] ?? 0; $sp = $a['spend'] ?? 0; $base = max($r, $sp, 0.01); ?>
          <a class="bud-src-row2 text-decoration-none text-body" href="txns?<?= h(http_build_query(['type' => $sp > 0 ? 'spend' : 'receive', 'fy' => $fy, 'src' => $k])) ?>" title="<?= h($s['label']) ?>">
            <div class="small"><span class="bud-dot" style="background:<?= h($s['color']) ?>"></span><?= h(bud_label_in_type($s)) ?></div>
            <div class="d-flex align-items-center gap-2">
              <span class="bar-track bud-mini"><span class="bar-fill bud-fill<?= $sp > $r ? ' over' : '' ?>" style="width:<?= round($sp / $base * 100, 1) ?>%"></span></span>
              <span class="small text-nowrap"><b><?= bud_money($sp) ?></b> / <?= bud_money($r) ?><span class="text-muted ms-1"><?= $r > 0 ? number_format($sp / $r * 100, 1) . '%' : '' ?></span></span>
            </div>
          </a>
        <?php endforeach; endforeach; ?>
        <?php if (!$bySrc): ?><p class="text-muted mb-0">ยังไม่มีข้อมูล<?= bud_can('editor') ? ' — <a href="txn_form?type=receive">บันทึกรับเข้างบประมาณ</a>' : '' ?></p><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
