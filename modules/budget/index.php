<?php
/**
 * ภาพรวมระบบงบประมาณ ของปีงบประมาณ
 *   ไทล์สีเข้ม: ได้รับ / เบิกจ่าย / คงเหลือ / ร้อยละ · ตารางงบรายจ่าย → รายการ (คลิกงบไปหน้ารายละเอียด)
 *   กราฟรับเข้า-เบิกจ่ายรายเดือน · อันดับโครงการตามยอดได้รับ (คลิกไปหน้ารายละเอียดโครงการ)
 */
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
// แยกรายโครงการ: หมวดที่ผูกกับโครงการสำรวจ (และข้อมูลเดิมที่บันทึกด้วยโครงการ) — เฉพาะโครงการที่มีรายการในปีงบนี้
$projects = projects_all();
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

$headExtra = '<link href="' . h(asset_v('modules/budget/assets/css/dash.css')) . '" rel="stylesheet">';
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
$pageActions .= '</select></form>' . (bud_can('editor') ? '<a href="txn_form?type=receive" class="btn btn-primary"><i class="bi bi-box-arrow-in-down"></i> รับเข้า</a><a href="txn_form?type=spend" class="btn btn-warning"><i class="bi bi-box-arrow-up"></i> เบิกจ่าย</a>' : '');
require __DIR__ . '/header.php';
require __DIR__ . '/dash.php';
require __DIR__ . '/footer.php';
