<?php
require_once __DIR__ . '/bootstrap.php';
require_module(DCC_MODULE, 'inspector');
$pageTitle = 'ภาพรวม';
$active = 'dashboard';
$pdo = db();
$stats = $pdo->query(
    "SELECT (SELECT COUNT(*) FROM dcc_centers) AS centers,
            (SELECT COUNT(DISTINCT district) FROM dcc_centers WHERE district <> '') AS districts,
            (SELECT COUNT(*) FROM dcc_activities) AS activities,
            (SELECT COUNT(*) FROM dcc_centers WHERE lat IS NULL OR lng IS NULL) AS no_location"
)->fetch();
$recent = $pdo->query(
    "SELECT a.id, a.title, a.activity_date, a.participants, c.name AS center_name
     FROM dcc_activities a JOIN dcc_centers c ON c.id = a.center_id
     ORDER BY a.activity_date IS NULL, a.activity_date DESC, a.id DESC LIMIT 8"
)->fetchAll();
$byDistrict = $pdo->query(
    "SELECT district, COUNT(*) n FROM dcc_centers WHERE district <> '' GROUP BY district ORDER BY n DESC, district"
)->fetchAll();
// รายชื่อศูนย์ในแต่ละอำเภอ (แสดงเมื่อคลิกที่อำเภอ)
$centersByDistrict = [];
foreach ($pdo->query(
    "SELECT c.id, c.code, c.name, c.subdistrict, c.district, c.lat, c.lng,
            (SELECT COUNT(*) FROM dcc_visits v WHERE v.center_id = c.id) AS visit_count
     FROM dcc_centers c WHERE c.district <> '' ORDER BY c.name"
) as $r) {
    $centersByDistrict[$r['district']][] = $r;
}
require __DIR__ . '/header.php';
?>
<div class="row g-3 mb-4" data-reveal>
  <?php foreach ([
      ['centers', 'bi-building', 'ศูนย์ทั้งหมด', 'primary'],
      ['districts', 'bi-geo-alt', 'อำเภอที่มีศูนย์', 'success'],
      ['activities', 'bi-calendar-check', 'กิจกรรม', 'warning'],
  ] as [$k, $icon, $label, $color]): ?>
  <div class="col-12 col-sm-4 reveal-item">
    <div class="stat-card">
      <span class="stat-icon text-bg-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
      <div><div class="stat-num"><?= number_format((int)$stats[$k]) ?></div><div class="stat-label"><?= $label ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ((int)$stats['no_location'] > 0): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i>
    มี <?= (int)$stats['no_location'] ?> ศูนย์ที่ยังไม่ได้ระบุพิกัด lat/long จึงยังไม่แสดงบนแผนที่ — <a href="centers.php?nolocation=1" class="alert-link">ดูรายการ</a></div>
<?php endif; ?>

<div class="row g-3" data-reveal>
  <div class="col-lg-7 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center">
        <strong>กิจกรรมล่าสุด</strong>
        <?php if (dcc_can('editor')): ?><a href="activity_form.php" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg"></i> เพิ่มกิจกรรม</a><?php endif; ?>
      </div>
      <div class="list-group list-group-flush">
        <?php foreach ($recent as $r): ?>
          <a class="list-group-item list-group-item-action" href="<?= dcc_can('editor') ? 'activity_form.php?id=' . (int)$r['id'] : '#' ?>">
            <div class="d-flex"><strong class="me-2"><?= h($r['title']) ?></strong>
              <span class="ms-auto small text-muted text-nowrap"><?= thai_date($r['activity_date']) ?></span></div>
            <div class="small text-muted"><?= h($r['center_name']) ?><?= $r['participants'] > 0 ? ' · ' . number_format((int)$r['participants']) . ' คน' : '' ?></div>
          </a>
        <?php endforeach; ?>
        <?php if (!$recent): ?><div class="list-group-item text-muted">ยังไม่มีกิจกรรม</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center">
        <strong>จำนวนศูนย์ตามอำเภอ</strong>
        <?php if (dcc_can('editor')): ?><a href="center_form.php" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg"></i> เพิ่มศูนย์</a><?php endif; ?>
      </div>
      <div class="card-body">
        <?php $max = max(1, ...array_map(fn($r) => (int)$r['n'], $byDistrict ?: [['n' => 1]])); ?>
        <p class="small text-muted mb-2"><i class="bi bi-hand-index"></i> คลิกที่อำเภอเพื่อดูรายชื่อศูนย์</p>
        <div class="district-list">
        <?php foreach ($byDistrict as $r): ?>
          <details class="district">
            <summary class="bar-row">
              <i class="bi bi-chevron-right chev"></i>
              <span class="bar-label"><?= h($r['district']) ?></span>
              <span class="bar-track"><span class="bar-fill" style="width:<?= round($r['n'] / $max * 100) ?>%"></span></span>
              <span class="bar-val"><?= (int)$r['n'] ?></span>
            </summary>
            <div class="district-centers">
              <?php foreach ($centersByDistrict[$r['district']] ?? [] as $c): ?>
                <div class="dc-item">
                  <a href="<?= dcc_can('editor') ? 'center_form.php?id=' . (int)$c['id'] : 'public/center.html?id=' . (int)$c['id'] ?>" class="dc-name"><?= h($c['name']) ?></a>
                  <span class="small text-muted"><?= h(($c['code'] ? $c['code'] . ' · ' : '') . 'ต.' . $c['subdistrict']) ?></span>
                  <span class="dc-tags">
                    <?= $c['lat'] !== null ? '<i class="bi bi-geo-alt-fill text-success" title="มีพิกัดแล้ว"></i>' : '<i class="bi bi-geo-alt text-danger" title="ยังไม่มีพิกัด"></i>' ?>
                    <?php if ($c['visit_count'] > 0): ?><span class="badge text-bg-success">ตรวจ <?= (int)$c['visit_count'] ?> ครั้ง</span>
                    <?php else: ?><a href="visit_form.php?center_id=<?= (int)$c['id'] ?>" class="badge text-bg-warning text-decoration-none">ยังไม่ตรวจเยี่ยม</a><?php endif; ?>
                  </span>
                </div>
              <?php endforeach; ?>
              <div class="d-flex gap-3 small pt-1">
                <a href="centers.php?district=<?= urlencode($r['district']) ?>"><i class="bi bi-building"></i> จัดการศูนย์ในอำเภอนี้</a>
                <a href="visits.php?district=<?= urlencode($r['district']) ?>"><i class="bi bi-clipboard-check"></i> ตรวจเยี่ยม</a>
              </div>
            </div>
          </details>
        <?php endforeach; ?>
        </div>
        <?php if (!$byDistrict): ?><p class="text-muted mb-0">ยังไม่มีข้อมูล</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
