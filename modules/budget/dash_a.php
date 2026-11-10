<?php
/** แดชบอร์ดงบประมาณ แบบ A — Executive: แถบสรุปเด่น + วงแหวนร้อยละ · งบรายจ่ายเป็นแถว · กราฟรายเดือน + โครงการ */
require __DIR__ . '/dash_switch.php';
$ti = 0;
?>
<div data-reveal class="mb-3">
  <div class="dz-hero reveal-item">
    <div class="row g-3 align-items-center">
      <div class="col-lg-9">
        <div class="k-label mb-2"><i class="bi bi-bank"></i> งบประมาณ ปีงบประมาณ <?= $fy ?> (ต.ค. <?= substr((string)($fy - 1), 2) ?> – ก.ย. <?= substr((string)$fy, 2) ?>)</div>
        <div class="row g-3">
          <div class="col-sm-4"><div class="k-label">ได้รับ</div><div class="k-val dz-num"><?= bud_money($rec) ?></div><div class="k-sub">บาท · รับเข้างบประมาณ</div></div>
          <div class="col-sm-4 k-sep"><div class="k-label">เบิกจ่าย</div><div class="k-val dz-num"><?= bud_money($spd) ?></div><div class="k-sub">บาท · <?= number_format($pct, 1) ?>% ของที่ได้รับ</div></div>
          <div class="col-sm-4 k-sep"><div class="k-label">คงเหลือ</div><div class="k-val dz-num"><?= bud_money($rec - $spd) ?></div><div class="k-sub">บาท · พร้อมใช้จ่าย</div></div>
        </div>
      </div>
      <div class="col-lg-3 d-flex justify-content-lg-end justify-content-center"><?= $ring($pct, '#fff', 132, 'เบิกจ่ายแล้ว') ?></div>
    </div>
  </div>
</div>

<div data-reveal class="mb-3">
  <div class="dz-card reveal-item">
    <div class="dz-card-h"><i class="bi bi-layers text-primary"></i> งบรายจ่าย <span class="ms-auto small fw-normal dz-muted">เบิกจ่าย / ได้รับ</span></div>
    <div class="dz-card-b">
      <?php foreach ($byType as $t => $a): $c = $typeHex[min($ti++, count($typeHex) - 1)]; $tp = $a['receive'] > 0 ? $a['spend'] / $a['receive'] * 100 : 0; ?>
        <a class="dz-row dz-link" style="--bc:<?= $c ?>" href="type?<?= h(http_build_query(['fy' => $fy, 'b' => $t])) ?>">
          <div class="min-w-0"><div class="nm"><i></i><?= h($t) ?></div>
            <div class="sub"><?= h(implode(' · ', array_map(fn($e) => $e !== '' ? $e : 'ไม่ระบุรายการ', array_keys($a['exp'])))) ?></div></div>
          <div><div class="dz-bar"><span class="bar-fill" style="width:<?= min(100, round($tp, 1)) ?>%"></span></div>
            <div class="small dz-muted mt-1">เบิกจ่าย <?= number_format($tp, 1) ?>%</div></div>
          <div class="vals dz-num"><b><?= bud_money($a['spend']) ?></b> <span class="dz-muted">/ <?= bud_money($a['receive']) ?></span>
            <div class="small dz-muted">คงเหลือ <?= bud_money($a['receive'] - $a['spend']) ?></div></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$byType): ?><p class="dz-muted mb-0">ยังไม่มีข้อมูลในปีงบประมาณนี้</p><?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3" data-reveal>
  <div class="col-lg-7 reveal-item">
    <div class="dz-card h-100">
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
  <div class="col-lg-5 reveal-item">
    <div class="dz-card h-100">
      <div class="dz-card-h"><i class="bi bi-kanban text-primary"></i> โครงการ <span class="ms-auto small fw-normal dz-muted"><?= count($byProject) ?> โครงการ</span></div>
      <div class="dz-card-b dz-plist">
        <?php foreach ($byProject as $pid => $a): $pj = $projects[$pid]; $tp = $a['receive'] > 0 ? $a['spend'] / $a['receive'] * 100 : 0; ?>
          <a class="it dz-link" style="--bc:<?= h($pj['color']) ?>" href="type?<?= h(http_build_query(['fy' => $fy, 'p' => $pid])) ?>">
            <div class="min-w-0 text-truncate"><span class="dz-badge"><?= h($pj['code']) ?></span> <span class="small dz-muted"><?= h($pj['name'] !== $pj['code'] ? $pj['name'] : '') ?></span></div>
            <div class="small fw-bold dz-num"><?= number_format($tp, 1) ?>%</div>
            <div class="dz-bar thin" style="grid-column:1 / -1"><span class="bar-fill" style="width:<?= min(100, round($tp, 1)) ?>%"></span></div>
            <div class="small dz-muted dz-num" style="grid-column:1 / -1"><?= bud_money($a['spend']) ?> / <?= bud_money($a['receive']) ?> บาท</div>
          </a>
        <?php endforeach; ?>
        <?php if (!$byProject): ?><p class="dz-muted mb-0">ยังไม่มีโครงการที่มีงบประมาณ</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>
