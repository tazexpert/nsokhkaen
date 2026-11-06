<?php
/** EA ของโครงการ — รายการโครงการที่รับผิดชอบ (core_projects) พร้อมจำนวน EA และความก้าวหน้าการสำรวจ แยกตามรอบ/งวด */
require_once __DIR__ . '/bootstrap.php';
require_module(EA_MODULE, 'viewer');
$pdo = db();
$showAll = !empty($_GET['all']);
$stats = [];
foreach ($pdo->query("SELECT project_id, round_label, COUNT(*) n, SUM(status = 'done') done, SUM(status = 'in_progress') doing,
                             COUNT(assigned_user_id) assigned, MAX(updated_at) last
                      FROM ea_project_eas GROUP BY project_id, round_label
                      ORDER BY project_id, MIN(enum_year) IS NULL, MIN(enum_year), MIN(enum_month) IS NULL, MIN(enum_month), round_label") as $r) {
    $stats[(int)$r['project_id']][] = $r;
}
// สถานประกอบการของโครงการ (เช่น สำรวจยอดขาย)
$units = $pdo->query("SELECT project_id, COUNT(*) n, SUM(status = 'done') done FROM ea_project_units GROUP BY project_id")->fetchAll(PDO::FETCH_UNIQUE);
$projects = array_filter(projects_all(), fn($p) => $showAll || $p['is_active'] || isset($stats[(int)$p['id']]) || isset($units[(int)$p['id']]));

$pageTitle = 'EA ของโครงการ';
$active = 'projects';
$pageActions = (projects_can_manage() ? '<a href="' . h(module_url('ea', 'manage_projects.php')) . '" class="btn btn-light"><i class="bi bi-kanban"></i> จัดการโครงการ</a>' : '');
require __DIR__ . '/header.php';
?>
<p class="text-muted small">เลือกโครงการเพื่อกำหนด EA ที่ใช้สำรวจ ผู้รับผิดชอบ และติดตามสถานะ — แยกรอบ/งวดการสำรวจได้ (เช่น รายเดือน รายไตรมาส)
  <?php if (!$showAll): ?> · <a href="?all=1">แสดงโครงการที่ปิดใช้งานด้วย</a><?php endif; ?></p>
<div class="row g-3" data-reveal>
<?php foreach ($projects as $p): $rounds = $stats[(int)$p['id']] ?? [];
    $n = array_sum(array_column($rounds, 'n')); $done = array_sum(array_column($rounds, 'done')); $doing = array_sum(array_column($rounds, 'doing'));
    [$stLabel, $stColor] = project_status($p); ?>
  <div class="col-md-6 col-xl-4 reveal-item">
    <a href="project_eas.php?id=<?= (int)$p['id'] ?>" class="card h-100 text-decoration-none text-body" style="border-left:5px solid <?= h($p['color']) ?>">
      <div class="card-body">
        <div class="d-flex align-items-start gap-2">
          <div class="flex-grow-1"><div class="fw-semibold fs-5" style="color:<?= h($p['color']) ?>"><?= h($p['code']) ?></div>
            <div class="small"><?= h($p['name']) ?></div></div>
          <span class="badge text-bg-<?= $stColor ?>"><?= h($stLabel) ?></span>
        </div>
        <div class="d-flex align-items-baseline gap-2 mt-3">
          <span class="fs-3 fw-semibold"><?= number_format($n) ?></span><span class="text-muted">EA</span>
          <?php if ($n): ?><span class="ms-auto small text-muted">สำรวจเสร็จ <?= number_format($done) ?> (<?= round($done / $n * 100) ?>%)</span><?php endif; ?>
        </div>
        <?php if ($n): ?>
        <div class="ea-progress mt-1"><span class="done" style="width:<?= $done / $n * 100 ?>%"></span><span class="doing" style="width:<?= $doing / $n * 100 ?>%"></span></div>
        <?php if (count($rounds) > 1 || $rounds[0]['round_label'] !== ''): ?>
        <div class="small text-muted mt-2"><?= count($rounds) ?> รอบ: <?= h(implode(', ', array_map(fn($r) => ($r['round_label'] ?: 'ไม่ระบุรอบ') . ' (' . $r['n'] . ')', array_slice($rounds, 0, 6)))) ?><?= count($rounds) > 6 ? ' …' : '' ?></div>
        <?php endif; ?>
        <?php else: ?><div class="small text-muted mt-1">ยังไม่ได้กำหนด EA</div><?php endif; ?>
        <?php if (isset($units[(int)$p['id']])): $un = $units[(int)$p['id']]; ?>
        <div class="small mt-2"><span class="text-primary" data-href="project_units.php?id=<?= (int)$p['id'] ?>"><i class="bi bi-shop"></i> สถานประกอบการ <?= number_format((int)$un['n']) ?> แห่ง</span>
          <span class="text-muted">· สำรวจเสร็จ <?= number_format((int)$un['done']) ?></span></div>
        <?php endif; ?>
        <div class="small text-muted mt-2"><?php if (($p['survey_period'] ?? '') !== ''): ?><i class="bi bi-arrow-repeat"></i> <?= h(project_period_label($p['survey_period'])) ?> · <?php endif; ?><i class="bi bi-calendar3"></i> <?= h(project_period_text($p)) ?><?= $p['fiscal_year'] ? ' · ปีงบประมาณ ' . (int)$p['fiscal_year'] : '' ?></div>
      </div>
    </a>
  </div>
<?php endforeach; ?>
<?php if (!$projects): ?><div class="col-12 text-muted">ยังไม่มีโครงการ<?= projects_can_manage() ? ' — <a href="' . h(module_url('ea', 'manage_projects.php')) . '">เพิ่มโครงการ</a>' : '' ?></div><?php endif; ?>
</div>
<script>
// ลิงก์สถานประกอบการในการ์ด (การ์ดทั้งใบเป็นลิงก์ไป EA ของโครงการ)
document.querySelectorAll('[data-href]').forEach(el => el.addEventListener('click', e => { e.preventDefault(); location.href = el.dataset.href; }));
</script>
<?php require __DIR__ . '/footer.php'; ?>
