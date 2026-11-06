<?php
/** ภาพรวมระบบบริหารจัดการ EA — จำนวน EA ครัวเรือน ประชากร · EA ตามอำเภอ · ความก้าวหน้าการสำรวจของโครงการ */
require_once __DIR__ . '/bootstrap.php';
require_module(EA_MODULE, 'viewer');
$pdo = db();
$s = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(area_type = 1), 0) muni, COALESCE(SUM(area_type = 2), 0) rural,
                         COALESCE(SUM(houses), 0) houses, COALESCE(SUM(households), 0) households, COALESCE(SUM(population), 0) population,
                         COUNT(DISTINCT district_code) districts, COUNT(DISTINCT district_code, tambon_code) tambons, MAX(source) source
                  FROM ea_areas WHERE is_active = 1')->fetch();
$byDistrict = $pdo->query('SELECT district_code, MAX(district_name) name, SUM(area_type = 1) muni, SUM(area_type = 2) rural, COUNT(*) n,
                                  SUM(households) households, SUM(population) population
                           FROM ea_areas WHERE is_active = 1 GROUP BY district_code ORDER BY district_code')->fetchAll();
$prog = [];
foreach ($pdo->query("SELECT project_id, COUNT(*) n, SUM(status = 'done') done, SUM(status = 'in_progress') doing, COUNT(DISTINCT round_label) rounds
                      FROM ea_project_eas GROUP BY project_id") as $r) {
    $prog[(int)$r['project_id']] = $r;
}
$projects = array_filter(projects_all(), fn($p) => isset($prog[(int)$p['id']]));
// EA ที่ฉันรับผิดชอบ (ยังไม่เสร็จ)
$st = $pdo->prepare("SELECT pe.project_id, pe.round_label, COUNT(*) n, SUM(pe.status = 'done') done FROM ea_project_eas pe
                     WHERE pe.assigned_user_id = ? GROUP BY pe.project_id, pe.round_label HAVING n > done ORDER BY pe.project_id, pe.round_label");
$st->execute([(int)current_user()['id']]);
$mine = $st->fetchAll();

$pageTitle = 'ภาพรวม EA จังหวัดขอนแก่น';
$active = 'dashboard';
require __DIR__ . '/header.php';
?>
<?php if (!(int)$s['n']): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle"></i> ยังไม่มีบัญชี EA<?= ea_can('admin') ? ' — <a href="import.php" class="alert-link">นำเข้าจากไฟล์ Excel "บัญชีเขตแจงนับ"</a>' : ' (รอผู้ดูแลระบบ EA นำเข้า)' ?></div>
<?php endif; ?>
<div class="row g-3 mb-4" data-reveal>
  <?php foreach ([
      ['EA ทั้งหมด', (int)$s['n'], 'bi-map', 'primary', $s['districts'] . ' อำเภอ · ' . $s['tambons'] . ' ตำบล'],
      ['EA ในเขตเทศบาล', (int)$s['muni'], 'bi-buildings', 'info', ''],
      ['EA นอกเขตเทศบาล', (int)$s['rural'], 'bi-tree', 'success', ''],
      ['จำนวนบ้าน', (int)$s['houses'], 'bi-house-door', 'secondary', ''],
      ['ครัวเรือน', (int)$s['households'], 'bi-house-heart', 'warning', ''],
      ['ประชากร (คน)', (int)$s['population'], 'bi-people', 'danger', ''],
  ] as [$label, $v, $icon, $color, $sub]): ?>
  <div class="col-6 col-md-4 col-xl-2 reveal-item">
    <div class="stat-card h-100"><span class="stat-icon text-bg-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
      <div><div class="stat-num"><?= number_format($v) ?></div><div class="stat-label"><?= h($label) ?></div><?php if ($sub !== ''): ?><div class="small text-muted"><?= h($sub) ?></div><?php endif; ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3" data-reveal>
  <div class="col-xl-8 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center flex-wrap gap-2"><strong>จำนวน EA ตามอำเภอ</strong>
        <span class="ea-legend small ms-auto"><i style="background:#1d4ed8"></i>ในเขตเทศบาล <i style="background:#0f766e" class="ms-2"></i>นอกเขตเทศบาล</span></div>
      <div class="card-body">
        <?php $max = max(1, ...array_map(fn($r) => (int)$r['n'], $byDistrict ?: [['n' => 1]])); $step = $byDistrict ? 600 / count($byDistrict) : 0; ?>
        <div class="ea-vbars">
          <?php foreach ($byDistrict as $i => $r): ?>
          <a class="col text-decoration-none" href="eas.php?district=<?= h($r['district_code']) ?>"
             title="<?= h($r['district_code'] . ' ' . $r['name'] . ': ' . number_format($r['n']) . ' EA (ในเขตเทศบาล ' . $r['muni'] . ' · นอกเขตเทศบาล ' . $r['rural'] . ') · ครัวเรือน ' . number_format($r['households']) . ' · ประชากร ' . number_format($r['population'])) ?>">
            <div class="ea-stack ea-anim" style="height:<?= $r['n'] / $max * 100 ?>%;--d:<?= round($i * $step) ?>ms">
              <span class="bar" style="height:<?= $r['rural'] / $r['n'] * 100 ?>%;border-radius:0"></span>
              <span class="bar muni" style="height:<?= $r['muni'] / $r['n'] * 100 ?>%"></span>
              <span class="val"><?= (int)$r['n'] ?></span>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
        <div class="ea-vlabels"><?php foreach ($byDistrict as $r): ?><span><?= h($r['name']) ?></span><?php endforeach; ?></div>
        <?php if ($s['source']): ?><div class="small text-muted mt-2">ที่มา: <?= h($s['source']) ?></div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-4 reveal-item">
    <div class="card h-100">
      <div class="card-header"><strong>ครัวเรือนตามอำเภอ</strong></div>
      <div class="card-body ea-pop" style="max-height:380px;overflow:auto">
        <?php $hmax = max(1, ...array_map(fn($r) => (int)$r['households'], $byDistrict ?: [['households' => 1]]));
              $byHh = $byDistrict; usort($byHh, fn($a, $b) => $b['households'] <=> $a['households']); ?>
        <?php foreach ($byHh as $r): ?>
          <div class="bar-row">
            <span class="bar-label"><?= h($r['name']) ?></span>
            <span class="bar-track"><span class="bar-fill" style="width:<?= round($r['households'] / $hmax * 100, 1) ?>%"></span></span>
            <span class="bar-val text-nowrap"><?= number_format((int)$r['households']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$byHh): ?><p class="text-muted mb-0">ยังไม่มีข้อมูล</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1" data-reveal>
  <div class="col-lg-<?= $mine ? '8' : '12' ?> reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center"><strong>ความก้าวหน้าการสำรวจของโครงการ</strong>
        <a href="projects.php" class="btn btn-sm btn-light ms-auto">ทุกโครงการ <i class="bi bi-chevron-right"></i></a></div>
      <div class="card-body">
        <?php foreach ($projects as $p): $r = $prog[(int)$p['id']]; ?>
          <a href="project_eas.php?id=<?= (int)$p['id'] ?>" class="d-block text-decoration-none text-body mb-3">
            <div class="d-flex small"><span><strong style="color:<?= h($p['color']) ?>"><?= h($p['code']) ?></strong> <?= h($p['name'] !== $p['code'] ? $p['name'] : '') ?></span>
              <span class="ms-auto text-nowrap ps-2"><?= number_format((int)$r['done']) ?>/<?= number_format((int)$r['n']) ?> EA<?= $r['rounds'] > 1 ? ' · ' . (int)$r['rounds'] . ' รอบ' : '' ?></span></div>
            <div class="ea-progress"><span class="done" style="width:<?= $r['done'] / $r['n'] * 100 ?>%"></span><span class="doing" style="width:<?= $r['doing'] / $r['n'] * 100 ?>%"></span></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$projects): ?><p class="text-muted mb-0">ยังไม่มีโครงการที่กำหนด EA — <a href="projects.php">เลือกโครงการเพื่อเพิ่ม EA</a></p><?php endif; ?>
        <?php if ($projects): ?><div class="small text-muted ea-legend"><i style="background:#22c55e"></i>สำรวจเสร็จ <i style="background:#f59e0b" class="ms-2"></i>กำลังสำรวจ</div><?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($mine): $all = projects_all(); ?>
  <div class="col-lg-4 reveal-item">
    <div class="card h-100">
      <div class="card-header"><strong><i class="bi bi-person-check"></i> EA ที่ฉันรับผิดชอบ (ยังไม่เสร็จ)</strong></div>
      <div class="list-group list-group-flush">
        <?php foreach ($mine as $m): $p = $all[(int)$m['project_id']] ?? null; if (!$p) { continue; } ?>
          <a class="list-group-item list-group-item-action d-flex" href="project_eas.php?<?= h(http_build_query(['id' => $p['id'], 'round' => $m['round_label'], 'user' => current_user()['id']])) ?>">
            <span><strong style="color:<?= h($p['color']) ?>"><?= h($p['code']) ?></strong> <?= h($m['round_label']) ?></span>
            <span class="ms-auto small"><?= (int)$m['done'] ?>/<?= (int)$m['n'] ?> EA</span></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
