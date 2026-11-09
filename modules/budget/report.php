<?php
/**
 * รายงานงบประมาณ (ได้รับ / เบิกจ่าย)
 *   year   = สรุปรายปีงบประมาณ แยกตามหมวด
 *   month  = รายเดือน: ได้รับ/เบิกจ่ายในเดือน + สะสมตั้งแต่ต้นปีงบ + รายละเอียดรายการในเดือน
 *   matrix = ตารางรายเดือน ต.ค. – ก.ย. (ได้รับ หรือ เบิกจ่าย)
 */
require_once __DIR__ . '/bootstrap.php';
require_module(BUD_MODULE, 'viewer');
$fy = bud_fy_param();
$months = bud_fy_months($fy);
$mode = in_array($_GET['mode'] ?? '', ['year', 'month', 'matrix'], true) ? $_GET['mode'] : 'year';
$cur = date('Y-m');
$month = in_array($_GET['month'] ?? '', $months, true) ? $_GET['month'] : (in_array($cur, $months, true) ? $cur : $months[0]);
$mtype = ($_GET['mtype'] ?? '') === 'receive' ? 'receive' : 'spend';
$showAll = !empty($_GET['all']);
$totals = bud_totals($fy);
$sources = bud_sources(true);

// แถวรายงาน: ทุกชื่อโครงการที่มีข้อมูล (หรือทั้งหมดที่ใช้งาน เมื่อเลือก "แสดงทุกรายการ")
$rows = [];
foreach ($sources as $k => $s) {
    if (isset($totals[$k]) || ($showAll && $s['active'])) {
        $rows[$k] = $s;
    }
}
foreach (array_keys($totals) as $k) {
    $rows[$k] ??= ['key' => $k, 'label' => '(ไม่ทราบ)', 'group' => 'อื่น ๆ', 'color' => '#64748b'];
}
// จัดกลุ่ม/เรียงตามประเภทงบจากชื่อหมวด: งบดำเนินงาน → งบรายจ่ายอื่น → เงินนอกงบประมาณ → ประเภทอื่น (ภายในกลุ่มตามลำดับหมวด)
$srcOrder = array_flip(array_keys($sources));
foreach ($rows as $k => &$r) {
    $r['group'] = bud_budget_type($r);
    $r['rank'] = array_merge(bud_budget_rank($r), [$srcOrder[$k] ?? PHP_INT_MAX]);
}
unset($r);
uasort($rows, fn($a, $b) => $a['rank'] <=> $b['rank']);
$sum = function (string $k, string $type, ?string $from = null, ?string $to = null) use ($totals): float {
    $t = 0.0;
    foreach ($totals[$k] ?? [] as $ym => $a) {
        if (($from === null || $ym >= $from) && ($to === null || $ym <= $to)) {
            $t += $a[$type] ?? 0;
        }
    }
    return $t;
};

// คอลัมน์ของแต่ละโหมด: [หัวคอลัมน์, fn(key) => จำนวน]
if ($mode === 'year') {
    $cols = [['ได้รับ', fn($k) => $sum($k, 'receive')], ['เบิกจ่าย', fn($k) => $sum($k, 'spend')], ['คงเหลือ', fn($k) => $sum($k, 'receive') - $sum($k, 'spend')]];
    $title = 'รายงานงบประมาณ ปีงบประมาณ ' . $fy;
} elseif ($mode === 'month') {
    $cols = [['ได้รับ (เดือนนี้)', fn($k) => $sum($k, 'receive', $month, $month)], ['เบิกจ่าย (เดือนนี้)', fn($k) => $sum($k, 'spend', $month, $month)],
             ['ได้รับ (สะสม)', fn($k) => $sum($k, 'receive', null, $month)], ['เบิกจ่าย (สะสม)', fn($k) => $sum($k, 'spend', null, $month)],
             ['คงเหลือ', fn($k) => $sum($k, 'receive', null, $month) - $sum($k, 'spend', null, $month)]];
    $title = 'รายงานงบประมาณ ประจำเดือน' . bud_month_label($month) . ' (ปีงบประมาณ ' . $fy . ')';
} else {
    $cols = array_map(fn($ym) => [bud_month_label($ym, true), fn($k) => $sum($k, $mtype, $ym, $ym)], $months);
    $cols[] = ['รวม', fn($k) => $sum($k, $mtype)];
    $title = 'รายงาน' . BUD_TYPES[$mtype] . 'รายเดือน ปีงบประมาณ ' . $fy;
}
// โครงสร้างรายงาน: งบรายจ่าย (กลุ่ม) → 1. รายการ → 1.1 ค่าใช้จ่าย
//   รายการที่มีหมวดเดียวและไม่มีค่าใช้จ่าย = แถวเดียว "1. รายการ" · หมวดที่ไม่มีรายการ = แถว "n. ค่าใช้จ่าย" (หรือชื่องบรายจ่าย)
$zero = array_fill(0, count($cols), 0.0);
$add = fn(array $a, array $b) => array_map(fn($x, $y) => $x + $y, $a, $b);
$buckets = [];                                              // [กลุ่ม][รายการ] => [keys] (ตามลำดับที่พบ)
foreach ($rows as $k => $s) {
    $e = isset($s['budget']) && $s['expense'] !== '' ? $s['expense'] : "\0" . $k;   // ไม่มีรายการ = แถวเดี่ยว
    $buckets[$s['group']][$e][] = $k;
}
$table = [];                                                // [กลุ่ม] => [node: num, label, level, vals, key|null, head]
$groupSum = [];
$grand = $zero;
foreach ($buckets as $g => $exps) {
    $n = 0;
    $groupSum[$g] = $zero;
    foreach ($exps as $e => $keys) {
        $n++;
        $vals = [];
        foreach ($keys as $k) {
            $vals[$k] = array_map(fn($c) => $c[1]($k), $cols);
        }
        $sumE = array_reduce($vals, $add, $zero);
        $groupSum[$g] = $add($groupSum[$g], $sumE);
        $first = $rows[$keys[0]];
        if (str_starts_with((string)$e, "\0")) {
            $label = isset($first['budget']) ? ($first['activity'] !== '' ? $first['activity'] : $first['budget']) : $first['label'];
            $table[$g][] = ['num' => $n . '.', 'label' => $label, 'level' => 1, 'vals' => $sumE, 'key' => $keys[0], 'head' => false];
        } elseif (count($keys) === 1 && $first['activity'] === '') {
            $table[$g][] = ['num' => $n . '.', 'label' => $e, 'level' => 1, 'vals' => $sumE, 'key' => $keys[0], 'head' => false];
        } else {
            $table[$g][] = ['num' => $n . '.', 'label' => $e, 'level' => 1, 'vals' => $sumE, 'key' => null, 'head' => true];
            $m = 0;
            foreach ($keys as $k) {
                $act = $rows[$k]['activity'];
                $table[$g][] = ['num' => $n . '.' . ++$m, 'label' => $act !== '' ? $act : '(ไม่ระบุค่าใช้จ่าย)', 'level' => 2, 'vals' => $vals[$k], 'key' => $k, 'head' => false];
            }
        }
    }
    $grand = $add($grand, $groupSum[$g]);
}
$withPct = $mode !== 'matrix';
$pctOf = fn(array $v) => $mode === 'year' ? [$v[0], $v[1]] : [$v[2], $v[3]];   // ร้อยละเบิกจ่าย = เบิกจ่าย / ได้รับ
$pctText = function (array $v) use ($pctOf): string {
    [$r, $s] = $pctOf($v);
    return $r > 0 ? number_format($s / $r * 100, 1) : '-';
};

// รายละเอียดรายการในเดือน
$detail = [];
if ($mode === 'month') {
    $st = db()->prepare("SELECT * FROM bud_txns WHERE fiscal_year = ? AND DATE_FORMAT(txn_date, '%Y-%m') = ? ORDER BY txn_type, txn_date, id");
    $st->execute([$fy, $month]);
    $detail = $st->fetchAll();
}

if (($_GET['export'] ?? '') === 'csv') {
    $head = array_merge(['งบรายจ่าย / รายการ / ค่าใช้จ่าย'], array_column($cols, 0), $withPct ? ['ร้อยละเบิกจ่าย'] : []);
    $out = [];
    foreach ($table as $g => $list) {
        $out[] = [$g];
        foreach ($list as $nd) {
            $out[] = array_merge([str_repeat('    ', $nd['level']) . $nd['num'] . ' ' . $nd['label']], array_map(fn($v) => number_format($v, 2, '.', ''), $nd['vals']), $withPct ? [$pctText($nd['vals'])] : []);
        }
        $out[] = array_merge(['รวม' . $g], array_map(fn($v) => number_format($v, 2, '.', ''), $groupSum[$g]), $withPct ? [$pctText($groupSum[$g])] : []);
    }
    $out[] = array_merge(['รวมทั้งสิ้น'], array_map(fn($v) => number_format($v, 2, '.', ''), $grand), $withPct ? [$pctText($grand)] : []);
    bud_csv('budget_report_' . $mode . '_' . $fy . ($mode === 'month' ? '_' . $month : '') . '.csv', $head, $out);
}

$money = fn(float $v) => $v == 0 ? '-' : '<span class="' . ($v < 0 ? 'neg' : '') . '">' . bud_money($v) . '</span>';
$qs = fn(array $p) => '?' . http_build_query(array_filter($p + ['fy' => $fy, 'mode' => $mode, 'month' => $mode === 'month' ? $month : null, 'mtype' => $mode === 'matrix' ? $mtype : null, 'all' => $showAll ? 1 : null], fn($v) => $v !== null && $v !== ''));
$pageTitle = 'รายงานงบประมาณ';
$active = 'report';
$pageActions = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>'
    . '<a href="' . h($qs(['export' => 'csv'])) . '" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> ส่งออก CSV</a>';
require __DIR__ . '/header.php';
?>
<div class="card card-body py-2 mb-3 bud-noprint">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-auto"><div class="btn-group btn-group-sm">
      <?php foreach (['year' => 'รายปีงบ', 'month' => 'รายเดือน', 'matrix' => 'ตารางรายเดือน'] as $mk => $ml): ?>
        <a href="<?= h('?' . http_build_query(['fy' => $fy, 'mode' => $mk])) ?>" class="btn btn-outline-primary<?= $mode === $mk ? ' active' : '' ?>"><?= $ml ?></a>
      <?php endforeach; ?></div></div>
    <input type="hidden" name="mode" value="<?= $mode ?>">
    <div class="col-auto"><label class="form-label small mb-0">ปีงบประมาณ</label>
      <select name="fy" class="form-select form-select-sm" onchange="if(this.form.month)this.form.month.value='';this.form.submit()">
        <?php foreach (bud_fy_list() as $y): ?><option value="<?= $y ?>"<?= $y === $fy ? ' selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
    <?php if ($mode === 'month'): ?>
    <div class="col-auto"><label class="form-label small mb-0">เดือน</label>
      <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($months as $ym): ?><option value="<?= $ym ?>"<?= $ym === $month ? ' selected' : '' ?>><?= h(bud_month_label($ym)) ?></option><?php endforeach; ?></select></div>
    <?php elseif ($mode === 'matrix'): ?>
    <div class="col-auto"><label class="form-label small mb-0">แสดง</label>
      <select name="mtype" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach (BUD_TYPES as $tk => $tl): ?><option value="<?= $tk ?>"<?= $tk === $mtype ? ' selected' : '' ?>><?= $tl ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="col-auto form-check ms-2 mb-1"><input type="checkbox" name="all" value="1" id="bAll" class="form-check-input" onchange="this.form.submit()"<?= $showAll ? ' checked' : '' ?>>
      <label for="bAll" class="form-check-label small">แสดงทุกรายการ (รวมที่ยังไม่มีข้อมูล)</label></div>
  </form>
</div>

<div class="bud-print-title"><?= h($title) ?><div style="font-size:10pt;font-weight:400">สำนักงานสถิติจังหวัดขอนแก่น</div></div>
<div class="card">
  <div class="card-header bud-noprint"><strong><?= h($title) ?></strong></div>
  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle mb-0 bud-report">
      <thead class="table-light"><tr>
        <th>งบรายจ่าย / รายการ / ค่าใช้จ่าย</th>
        <?php foreach ($cols as $c): ?><th class="text-end"><?= h($c[0]) ?></th><?php endforeach; ?>
        <?php if ($withPct): ?><th class="text-end">ร้อยละเบิกจ่าย</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($table as $g => $list): ?>
        <tr class="bud-group"><td colspan="<?= count($cols) + 1 + ($withPct ? 1 : 0) ?>"><?= h($g) ?></td></tr>
        <?php foreach ($list as $nd): ?>
          <tr class="<?= $nd['head'] ? 'bud-exp-head' : '' ?>">
            <td class="bud-name bud-lv<?= $nd['level'] ?>"><span class="bud-num"><?= h($nd['num']) ?></span>
              <?php if ($nd['key'] !== null): ?><a class="text-body text-decoration-none" href="txns.php?<?= h(http_build_query(['type' => $mode === 'matrix' ? $mtype : 'spend', 'fy' => $fy, 'src' => $nd['key'], 'month' => $mode === 'month' ? $month : null])) ?>" title="<?= h($rows[$nd['key']]['label']) ?>"><?= h($nd['label']) ?></a>
              <?php else: ?><?= h($nd['label']) ?><?php endif; ?></td>
            <?php foreach ($nd['vals'] as $v): ?><td class="text-end"><?= $money($v) ?></td><?php endforeach; ?>
            <?php if ($withPct): ?><td class="text-end"><?= $pctText($nd['vals']) ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (count($table) > 1): ?>
          <tr class="bud-sub"><td class="text-end">รวม<?= h($g) ?></td>
            <?php foreach ($groupSum[$g] as $v): ?><td class="text-end"><?= $money($v) ?></td><?php endforeach; ?>
            <?php if ($withPct): ?><td class="text-end"><?= $pctText($groupSum[$g]) ?></td><?php endif; ?></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$table): ?><tr><td colspan="<?= count($cols) + 2 ?>" class="text-center text-muted py-4">ยังไม่มีข้อมูลในปีงบประมาณนี้</td></tr><?php endif; ?>
      </tbody>
      <?php if ($table): ?>
      <tfoot><tr class="table-light fw-bold"><td class="text-end">รวมทั้งสิ้น</td>
        <?php foreach ($grand as $v): ?><td class="text-end"><?= $money($v) ?></td><?php endforeach; ?>
        <?php if ($withPct): ?><td class="text-end"><?= $pctText($grand) ?></td><?php endif; ?></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php if ($mode === 'month'): ?>
<div class="card mt-3">
  <div class="card-header"><strong>รายละเอียดรายการ เดือน<?= h(bud_month_label($month)) ?></strong></div>
  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle mb-0 bud-report">
      <thead class="table-light"><tr><th>วันที่</th><th>ที่เอกสาร</th><th>รายการ</th><th>หมวด</th><th class="text-end">ได้รับ</th><th class="text-end">เบิกจ่าย</th></tr></thead>
      <tbody>
      <?php foreach ($detail as $t): ?>
        <tr><td><?= h(thai_date($t['txn_date'])) ?></td><td><?= h($t['doc_no']) ?></td><td class="bud-name"><?= h($t['description']) ?><?php if ($t['detail'] !== ''): ?><div class="small text-muted"><?= nl2br(h($t['detail'])) ?></div><?php endif; ?></td>
          <td class="bud-name"><?= h($sources[bud_source_key($t)]['label'] ?? '-') ?></td>
          <td class="text-end"><?= $t['txn_type'] === 'receive' ? bud_money($t['amount']) : '' ?></td>
          <td class="text-end"><?= $t['txn_type'] === 'spend' ? bud_money($t['amount']) : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$detail): ?><tr><td colspan="6" class="text-center text-muted py-3">ไม่มีรายการในเดือนนี้</td></tr><?php endif; ?>
      </tbody>
      <?php if ($detail): ?><tfoot><tr class="table-light fw-bold"><td colspan="4" class="text-end">รวม</td>
        <td class="text-end"><?= bud_money(array_sum(array_map(fn($t) => $t['txn_type'] === 'receive' ? $t['amount'] : 0, $detail))) ?></td>
        <td class="text-end"><?= bud_money(array_sum(array_map(fn($t) => $t['txn_type'] === 'spend' ? $t['amount'] : 0, $detail))) ?></td></tr></tfoot><?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
