<?php
/**
 * ภาพรวมตัวชี้วัด (ปีงบประมาณ/รอบ): การ์ดสรุป · ตารางตัวชี้วัด (น้ำหนัก ความก้าวหน้า คะแนน) · กิจกรรมใกล้ครบกำหนด/เกินกำหนด
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(KPI_MODULE);
$pdo = db();
[$fy, $round] = kpi_period_param();
// ประเภท: ทั้งหมด / ตัวชี้วัดจากสำนักงาน / ตัวชี้วัดอื่น (ต้องรัน migration 20261213 ก่อน)
$hasOffice = kpi_has_office();
$src = $hasOffice && in_array($_GET['src'] ?? '', ['office', 'other'], true) ? $_GET['src'] : '';
$st = $pdo->prepare('SELECT i.*, g.name AS work_group_name, c.full_name AS controller_name FROM kpi_indicators i
                     LEFT JOIN core_work_groups g ON g.id = i.work_group_id LEFT JOIN core_users c ON c.id = i.controller_id
                     WHERE i.fiscal_year = ? AND i.round_no = ?' . ($src ? ' AND i.is_office = ' . ($src === 'office' ? 1 : 0) : '') . '
                     ORDER BY i.sort_order, i.code + 0, i.code, i.id');
$st->execute([$fy, $round]);
$rows = $st->fetchAll();
$indUsers = kpi_indicator_users(array_column($rows, 'id'));
$today = date('Y-m-d');
$sum = ['weight' => 0.0, 'wscore' => 0.0, 'total' => 0, 'sent' => 0, 'overdue' => 0, 'soon' => 0];
$due = [];                                   // กิจกรรมที่ยังไม่ส่ง: เกินกำหนด / ภายใน 15 วัน
foreach ($rows as &$r) {
    $r['levels'] = kpi_structure((int)$r['id'], $indUsers[(int)$r['id']] ?? []);
    [$r['got'], $r['max']] = kpi_score($r['levels']);
    $r['stats'] = kpi_activity_stats($r['levels']);
    $r['wscore'] = $r['weight'] !== null && $r['max'] > 0 ? $r['got'] / $r['max'] * (float)$r['weight'] : null;
    $sum['weight'] += (float)$r['weight'];
    $sum['wscore'] += (float)$r['wscore'];
    $sum['total'] += $r['stats']['total'];
    $sum['sent'] += $r['stats']['done'] + $r['stats']['submitted'];
    $sum['overdue'] += $r['stats']['overdue'];
    $sum['soon'] += $r['stats']['soon'];
    foreach ($r['levels'] as $l) {
        foreach ($l['activities'] as $a) {
            if ($a['status'] === 'pending' && $a['due_date'] && $a['due_date'] <= date('Y-m-d', strtotime('+' . KPI_WARN_DAYS . ' days'))) {
                $due[] = $a + ['ind' => $r];
            }
        }
    }
}
unset($r);
usort($due, fn($a, $b) => $a['due_date'] <=> $b['due_date']);
$names = kpi_user_names();
$myId = (int)$me['id'];

$pageTitle = 'ภาพรวมตัวชี้วัด';
$active = 'dashboard';
$pageActions = kpi_can('controller') ? '<a href="indicator_form?' . h(http_build_query(['fy' => $fy, 'r' => $round])) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มตัวชี้วัด</a>' : '';
require __DIR__ . '/header.php';
?>
<div class="card mb-3">
  <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
    <form class="d-flex align-items-center gap-2">
      <label class="small text-muted">ปีงบประมาณ</label>
      <select name="fy" class="form-select form-select-sm w-auto" onchange="this.form.submit()"><?php foreach (kpi_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select>
      <input type="hidden" name="r" value="<?= $round ?>"><?php if ($src): ?><input type="hidden" name="src" value="<?= h($src) ?>"><?php endif; ?>
    </form>
    <ul class="nav nav-pills">
      <?php foreach ([1, 2] as $r): ?><li class="nav-item"><a class="nav-link py-1<?= $r === $round ? ' active' : '' ?>" href="?<?= h(http_build_query(array_filter(['fy' => $fy, 'r' => $r, 'src' => $src]))) ?>"><?= h(kpi_round_text($fy, $r)) ?></a></li><?php endforeach; ?>
    </ul>
    <?php if ($hasOffice): ?>
    <div class="btn-group btn-group-sm ms-auto">
      <?php foreach (['' => 'ทั้งหมด', 'office' => 'จากสำนักงาน', 'other' => 'ตัวชี้วัดอื่น'] as $k => $lb): ?>
        <a href="?<?= h(http_build_query(array_filter(['fy' => $fy, 'r' => $round, 'src' => $k]))) ?>" class="btn btn-outline-secondary<?= $src === $k ? ' active' : '' ?>"><?= $lb ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-3" data-reveal>
  <div class="col-sm-6 col-xl-3 reveal-item"><div class="stat-card h-100"><span class="stat-icon text-bg-primary"><i class="bi bi-bullseye"></i></span>
    <div><div class="stat-num"><?= count($rows) ?></div><div class="stat-label">ตัวชี้วัด · น้ำหนักรวม <?= kpi_num($sum['weight']) ?>%</div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal-item" style="--d:70ms"><div class="stat-card h-100"><span class="stat-icon text-bg-info"><i class="bi bi-award"></i></span>
    <div><div class="stat-num"><?= kpi_num($sum['wscore']) ?></div><div class="stat-label">คะแนนถ่วงน้ำหนักรวม (ร้อยละ · ตรวจแล้ว)</div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal-item" style="--d:140ms"><div class="stat-card h-100"><span class="stat-icon text-bg-success"><i class="bi bi-check2-circle"></i></span>
    <div class="flex-grow-1"><div class="stat-num"><?= $sum['sent'] ?> <small class="fs-6">/ <?= $sum['total'] ?></small></div><div class="stat-label">กิจกรรม/หลักฐานที่ส่งแล้ว</div>
      <div class="kpi-bar mt-1"><span class="bar-fill" style="width:<?= $sum['total'] ? round($sum['sent'] / $sum['total'] * 100) : 0 ?>%"></span></div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal-item" style="--d:210ms"><div class="stat-card h-100"><span class="stat-icon text-bg-<?= $sum['overdue'] ? 'danger' : 'warning' ?>"><i class="bi bi-alarm"></i></span>
    <div><div class="stat-num"><?= $sum['overdue'] ?> <small class="fs-6">· ใกล้ครบ <?= $sum['soon'] ?></small></div><div class="stat-label">เกินกำหนด · ครบกำหนดภายใน <?= KPI_WARN_DAYS ?> วัน</div></div></div></div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong><i class="bi bi-list-check"></i> ตัวชี้วัด <?= h(kpi_round_text($fy, $round, false)) ?></strong></div>
  <div class="table-responsive">
    <table class="table align-middle mb-0 kpi-table" data-reveal>
      <thead><tr><th style="width:3.5rem">ที่</th><th>ตัวชี้วัด</th><th class="text-end">น้ำหนัก</th><th style="min-width:180px">ความก้าวหน้า (ส่งแล้ว)</th><th class="text-end">คะแนน</th><th class="text-end">ถ่วงน้ำหนัก</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): $s = $r['stats']; $p = $s['total'] ? round(($s['done'] + $s['submitted']) / $s['total'] * 100) : 0; $mine = in_array($myId, $indUsers[(int)$r['id']] ?? [], true); ?>
        <tr class="<?= $mine ? 'kpi-mine' : '' ?>">
          <td class="fw-semibold"><?= h($r['code'] ?: (string)($i + 1)) ?></td>
          <td><a href="indicator?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none"><?= h($r['name']) ?></a>
            <?= !empty($r['is_office']) ? ' <span class="badge kpi-badge-office" title="ตัวชี้วัดจากสำนักงาน"><i class="bi bi-building-check"></i> สำนักงาน</span>' : '' ?>
            <div class="small text-muted"><?= h($r['work_group_name'] ?: '-') ?><?= $r['controller_name'] ? ' · ผู้ควบคุม: ' . h(preg_replace('/\s+/u', ' ', $r['controller_name'])) : '' ?>
              <?= $mine ? ' · <span class="badge kpi-badge-mine">ผู้ปฏิบัติ</span>' : '' ?>
              <?= $s['overdue'] ? ' · <span class="badge text-bg-danger">เกินกำหนด ' . $s['overdue'] . '</span>' : ($s['soon'] ? ' · <span class="badge text-bg-warning">ใกล้ครบกำหนด ' . $s['soon'] . '</span>' : '') ?></div></td>
          <td class="text-end"><?= $r['weight'] !== null ? kpi_num($r['weight']) . '%' : '-' ?></td>
          <td><div class="d-flex align-items-center gap-2"><div class="kpi-bar flex-grow-1"><span class="bar-fill" style="width:<?= $p ?>%"></span></div>
            <span class="small text-nowrap"><?= $s['done'] + $s['submitted'] ?>/<?= $s['total'] ?></span></div></td>
          <td class="text-end text-nowrap"><?= kpi_num($r['got']) ?> / <?= kpi_num($r['max']) ?></td>
          <td class="text-end fw-semibold"><?= $r['wscore'] !== null ? kpi_num($r['wscore']) : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-bullseye fs-2 d-block mb-1"></i>ยังไม่มีตัวชี้วัดใน<?= h(kpi_round_text($fy, $round, false)) ?></td></tr><?php endif; ?>
      </tbody>
      <?php if ($rows): ?><tfoot><tr class="fw-bold"><td colspan="2" class="text-end">รวม</td><td class="text-end"><?= kpi_num($sum['weight']) ?>%</td><td></td><td></td><td class="text-end"><?= kpi_num($sum['wscore']) ?></td></tr></tfoot><?php endif; ?>
    </table>
  </div>
</div>

<?php if ($due): ?>
<div class="card mb-3">
  <div class="card-header"><strong><i class="bi bi-alarm"></i> กิจกรรมที่ยังไม่ส่ง — เกินกำหนด / ครบกำหนดภายใน <?= KPI_WARN_DAYS ?> วัน</strong></div>
  <div class="list-group list-group-flush">
    <?php foreach ($due as $a): ?>
      <a href="indicator?id=<?= (int)$a['indicator_id'] ?>#act-<?= (int)$a['id'] ?>" class="list-group-item list-group-item-action d-flex gap-3 align-items-center">
        <div class="text-nowrap"><?= kpi_status_badge($a) ?><div class="small text-muted mt-1"><?= h(thai_date($a['due_date'])) ?></div></div>
        <div class="min-w-0"><div class="fw-semibold"><?= h(mb_strimwidth(preg_replace('/\s+/u', ' ', $a['title']), 0, 160, '…')) ?></div>
          <div class="small text-muted">ตัวชี้วัด<?= $a['ind']['code'] ? 'ที่ ' . h($a['ind']['code']) : '' ?> <?= h(mb_strimwidth($a['ind']['name'], 0, 90, '…')) ?> · <?= h(implode(', ', array_filter(array_map(fn($u) => $names[$u] ?? null, $a['users']))) ?: '-') ?></div></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
