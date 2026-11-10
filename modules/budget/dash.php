<?php
/** เนื้อหาหน้าภาพรวมงบประมาณ (เรียกจาก index.php — ใช้ตัวแปรที่คำนวณไว้ที่นั่น) */
$ti = 0;
$pbar = fn(float $r, float $s, string $c) => '<div class="d-flex align-items-center gap-2"><div class="dz-bar thin flex-grow-1" style="--bc:' . $c . ';max-width:70px"><span class="bar-fill" style="width:' . ($r > 0 ? min(100, round($s / $r * 100, 1)) : 0) . '%"></span></div><span class="dz-num">' . ($r > 0 ? number_format($s / $r * 100, 1) : '-') . '</span></div>';
$rank = $byProject;
uasort($rank, fn($a, $b) => $b['receive'] <=> $a['receive']);
?>
<?php $tiles = [
    ['ได้รับ', bud_money($rec), 'bi-box-arrow-in-down', '#2563eb', 'linear-gradient(135deg, #1d4ed8, #3b82f6)', 'รับเข้างบประมาณ', $spark(array_column($byMonth, 'receive'), '#fff'), 'txns?type=receive&fy=' . $fy],
    ['เบิกจ่าย', bud_money($spd), 'bi-box-arrow-up', '#ea580c', 'linear-gradient(135deg, #c2410c, #f97316)', 'เบิกจ่ายงบประมาณ', $spark(array_column($byMonth, 'spend'), '#fff'), 'txns?type=spend&fy=' . $fy],
    ['คงเหลือ', bud_money($rec - $spd), 'bi-wallet2', '#059669', 'linear-gradient(135deg, #047857, #10b981)', 'ได้รับ − เบิกจ่าย', '', 'report?fy=' . $fy],
]; ?>
<div class="row g-3 mb-3" data-reveal>
  <?php foreach ($tiles as [$label, $v, $icon, $tc, $tg, $sub, $sp, $href]): ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <a class="dz-tile dz-link dz-dark" href="<?= h($href) ?>" style="--tc:<?= $tc ?>;--tg:<?= $tg ?>">
      <div class="t-label"><span class="t-ico"><i class="bi <?= $icon ?>"></i></span><?= $label ?> (บาท)<span class="ms-auto" title="รายเดือน ต.ค. – ก.ย."><?= $sp ?></span></div>
      <div class="t-val dz-num text-nowrap"><?= $v ?></div><div class="t-sub"><?= h($sub) ?></div>
    </a>
  </div>
  <?php endforeach; ?>
  <div class="col-sm-6 col-xl-3 reveal-item">
    <div class="dz-tile dz-dark d-flex align-items-center gap-3" style="--tc:#0891b2;--tg:linear-gradient(135deg, #0e7490, #06b6d4)">
      <?= $ring($pct, '#fff', 78) ?>
      <div><div class="t-label">ร้อยละการเบิกจ่าย</div><div class="t-sub mt-1 dz-num">เบิกจ่าย <?= bud_money($spd) ?><br>จากที่ได้รับ <?= bud_money($rec) ?></div></div>
    </div>
  </div>
</div>

<div class="row g-3" data-reveal>
  <div class="col-xl-8 reveal-item">
    <div class="dz-card dz-fix dz-fix-l">
      <div class="dz-card-h"><i class="bi bi-table text-primary"></i> งบรายจ่าย <a href="report?fy=<?= $fy ?>" class="ms-auto small fw-normal">รายงานฉบับเต็ม <i class="bi bi-chevron-right"></i></a></div>
      <div class="dz-card-b dz-scroll">
        <table class="dz-tbl dz-num">
          <thead><tr><th>งบรายจ่าย / รายการ</th><th>ได้รับ</th><th>เบิกจ่าย</th><th>คงเหลือ</th><th class="pc">ร้อยละ</th></tr></thead>
          <tbody>
          <?php foreach ($byType as $t => $a): $c = $typeHex[min($ti++, count($typeHex) - 1)]; ?>
            <tr class="g" style="--bc:<?= $c ?>">
              <td><a class="dz-link" href="type?<?= h(http_build_query(['fy' => $fy, 'b' => $t])) ?>"><?= h($t) ?> <i class="bi bi-chevron-right small dz-muted"></i></a></td>
              <td><?= bud_money($a['receive']) ?></td><td><?= bud_money($a['spend']) ?></td><td><?= bud_money($a['receive'] - $a['spend']) ?></td>
              <td class="pc"><?= $pbar($a['receive'], $a['spend'], $c) ?></td></tr>
            <?php foreach ($a['exp'] as $e => $ea):
              // รายการเดียวที่ชื่อเดียวกับงบรายจ่าย (หรือไม่ระบุ) → ไม่แสดงซ้ำ
              if (count($a['exp']) === 1 && ((string)$e === '' || (string)$e === (string)$t)) { continue; } ?>
            <tr class="e"><td><?= $e !== '' ? h($e) : '<span class="dz-muted">(ไม่ระบุรายการ)</span>' ?><?= $ea['n'] > 1 ? ' <span class="small dz-muted">· ' . (int)$ea['n'] . ' หมวด</span>' : '' ?></td>
              <td><?= bud_money($ea['receive']) ?></td><td><?= bud_money($ea['spend']) ?></td><td><?= bud_money($ea['receive'] - $ea['spend']) ?></td>
              <td class="pc"><?= $pbar($ea['receive'], $ea['spend'], $c) ?></td></tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <?php if (!$byType): ?><tr><td colspan="5" class="text-center dz-muted py-4">ยังไม่มีข้อมูลในปีงบประมาณนี้</td></tr><?php endif; ?>
          </tbody>
          <?php if ($byType): ?><tfoot><tr class="fw-bold"><td>รวมทั้งสิ้น</td><td><?= bud_money($rec) ?></td><td><?= bud_money($spd) ?></td><td><?= bud_money($rec - $spd) ?></td><td class="pc"><?= $pbar($rec, $spd, '#0f172a') ?></td></tr></tfoot><?php endif; ?>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-4 d-flex flex-column gap-3 dz-fix-r">
    <div class="dz-card reveal-item dz-vbars-sm">
      <div class="dz-card-h"><i class="bi bi-bar-chart text-primary"></i> รายเดือน
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
        <div class="bud-vlabels"><?php foreach ($months as $ym): ?><span><?= h(preg_replace('/\s+\d+$/', '', bud_month_label($ym, true))) ?></span><?php endforeach; ?></div>
      </div>
    </div>
    <div class="dz-card reveal-item dz-fix dz-fix-p">
      <div class="dz-card-h"><i class="bi bi-kanban text-primary"></i> โครงการ <span class="ms-auto small fw-normal dz-muted"><?= count($rank) ?> โครงการ</span></div>
      <div class="dz-card-b dz-scroll">
        <?php $no = 0; foreach ($rank as $pid => $a): $pj = $projects[$pid]; $tp = $a['receive'] > 0 ? $a['spend'] / $a['receive'] * 100 : 0; ?>
          <a class="dz-rank dz-link" href="type?<?= h(http_build_query(['fy' => $fy, 'p' => $pid])) ?>" style="--bc:<?= h($pj['color']) ?>">
            <span class="no"><?= ++$no ?></span>
            <span class="min-w-0 text-truncate"><span class="dz-badge"><?= h($pj['code']) ?></span> <span class="small dz-muted"><?= h($pj['name'] !== $pj['code'] ? $pj['name'] : '') ?></span></span>
            <span class="small dz-num text-end"><b><?= bud_money($a['receive']) ?></b><br><span class="dz-muted">เบิก <?= number_format($tp, 1) ?>%</span></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$rank): ?><p class="dz-muted mb-0">ยังไม่มีโครงการที่มีงบประมาณ</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>
