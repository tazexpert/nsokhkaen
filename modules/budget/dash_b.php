<?php
/** แดชบอร์ดงบประมาณ แบบ B — Tiles: การ์ดสีอ่อน + กราฟแท่งจิ๋ว · งบรายจ่ายพร้อมวงแหวนร้อยละ · โครงการเป็นไทล์เล็ก */
require __DIR__ . '/dash_switch.php';
$ti = 0;
$tiles = [
    ['ได้รับ', bud_money($rec), 'bi-box-arrow-in-down', '#2563eb', '#eff6ff', '#dbeafe', $spark(array_column($byMonth, 'receive'), '#93c5fd'), 'txns?type=receive&fy=' . $fy],
    ['เบิกจ่าย', bud_money($spd), 'bi-box-arrow-up', '#ea580c', '#fff7ed', '#fed7aa', $spark(array_column($byMonth, 'spend'), '#fdba74'), 'txns?type=spend&fy=' . $fy],
    ['คงเหลือ', bud_money($rec - $spd), 'bi-wallet2', '#059669', '#ecfdf5', '#a7f3d0', '', 'report?fy=' . $fy],
];
?>
<div class="row g-3 mb-3" data-reveal>
  <?php foreach ($tiles as [$label, $v, $icon, $tc, $tb, $tl, $sp, $href]): ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <a class="dz-tile d-block dz-link" href="<?= h($href) ?>" style="--tc:<?= $tc ?>;--tb:<?= $tb ?>;--tl:<?= $tl ?>">
      <div class="t-label"><span class="t-ico"><i class="bi <?= $icon ?>"></i></span><?= $label ?> (บาท)</div>
      <div class="d-flex align-items-end justify-content-between gap-2"><div class="t-val dz-num"><?= $v ?></div><?= $sp ?></div>
    </a>
  </div>
  <?php endforeach; ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <div class="dz-tile d-flex align-items-center gap-3" style="--tc:#0891b2;--tb:#ecfeff;--tl:#a5f3fc">
      <?= $ring($pct, '#0891b2', 74) ?>
      <div><div class="t-label">ร้อยละการเบิกจ่าย</div><div class="small dz-muted mt-1">เบิกจ่าย <?= bud_money($spd) ?><br>จากที่ได้รับ <?= bud_money($rec) ?></div></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3" data-reveal>
  <?php foreach ($byType as $t => $a): $c = $typeHex[min($ti++, count($typeHex) - 1)]; $tp = $a['receive'] > 0 ? $a['spend'] / $a['receive'] * 100 : 0; ?>
  <div class="col-md-6 col-xl-3 reveal-item">
    <a class="dz-card d-block h-100 p-3" href="type?<?= h(http_build_query(['fy' => $fy, 'b' => $t])) ?>" style="border-top:4px solid <?= $c ?>">
      <div class="dz-bt">
        <?= $ring($tp, $c, 76) ?>
        <div class="flex-grow-1 min-w-0"><div class="nm" style="color:<?= $c ?>"><?= h($t) ?></div>
          <div class="dz-kv dz-num mt-1"><span>ได้รับ</span><b><?= bud_money($a['receive']) ?></b><span>เบิกจ่าย</span><b><?= bud_money($a['spend']) ?></b><span>คงเหลือ</span><b><?= bud_money($a['receive'] - $a['spend']) ?></b></div></div>
      </div>
      <div class="dz-mini mt-2">
        <?php $j = 0; foreach ($a['exp'] as $e => $ea): if ($j++ >= 3) { break; } $ep = $ea['receive'] > 0 ? $ea['spend'] / $ea['receive'] * 100 : 0; ?>
          <div class="r"><span title="<?= h($e) ?>"><?= h($e !== '' ? $e : 'ไม่ระบุรายการ') ?></span><b class="dz-num"><?= number_format($ep, 0) ?>%</b></div>
          <div class="dz-bar thin" style="--bc:<?= $c ?>"><span class="bar-fill" style="width:<?= min(100, round($ep, 1)) ?>%"></span></div>
        <?php endforeach; ?>
        <?php if (count($a['exp']) > 3): ?><div class="dz-muted mt-1">+ อีก <?= count($a['exp']) - 3 ?> รายการ</div><?php endif; ?>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($byProject): ?>
<h2 class="h6 dz-muted mb-2"><i class="bi bi-kanban"></i> โครงการ</h2>
<div class="row g-3 mb-3" data-reveal>
  <?php foreach ($byProject as $pid => $a): $pj = $projects[$pid]; $tp = $a['receive'] > 0 ? $a['spend'] / $a['receive'] * 100 : 0; ?>
  <div class="col-6 col-md-4 col-xl-2 reveal-item">
    <a class="dz-card dz-ptile d-block" style="--bc:<?= h($pj['color']) ?>" href="type?<?= h(http_build_query(['fy' => $fy, 'p' => $pid])) ?>">
      <div class="d-flex justify-content-center mb-1"><?= $ring($tp, $pj['color'], 64) ?></div>
      <div class="code"><?= h($pj['code']) ?></div>
      <div class="nm" title="<?= h($pj['name']) ?>"><?= h($pj['name'] !== $pj['code'] ? $pj['name'] : ' ') ?></div>
      <div class="small dz-num mt-1"><b><?= bud_money($a['spend']) ?></b><div class="dz-muted">/ <?= bud_money($a['receive']) ?></div></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3" data-reveal>
  <div class="col-12 reveal-item">
    <div class="dz-card">
      <div class="dz-card-h"><i class="bi bi-bar-chart text-primary"></i> รับเข้า-เบิกจ่ายรายเดือน
        <span class="bud-legend small fw-normal ms-auto"><i style="background:<?= BUD_COLORS['receive'] ?>"></i>ได้รับ <i style="background:<?= BUD_COLORS['spend'] ?>" class="ms-2"></i>เบิกจ่าย</span></div>
      <div class="dz-card-b">
        <?php $max = max(1, ...array_map(fn($m) => max($m['receive'], $m['spend']), array_values($byMonth))); $i = 0; ?>
        <div class="bud-vbars">
          <?php foreach ($byMonth as $ym => $m): ?>
            <a class="bud-col text-decoration-none" href="report?<?= h(http_build_query(['fy' => $fy, 'mode' => 'month', 'month' => $ym])) ?>" title="<?= h(bud_month_label($ym) . ' · ได้รับ ' . bud_money($m['receive']) . ' · เบิกจ่าย ' . bud_money($m['spend'])) ?>">
              <?php foreach (['receive', 'spend'] as $type): ?><span class="bud-bar bud-anim <?= $type ?>" style="height:<?= round($m[$type] / $max * 100, 2) ?>%;--d:<?= $i++ * 38 ?>ms"></span><?php endforeach; ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="bud-vlabels"><?php foreach ($months as $ym): ?><span><?= h(bud_month_label($ym, true)) ?></span><?php endforeach; ?></div>
      </div>
    </div>
  </div>
</div>
