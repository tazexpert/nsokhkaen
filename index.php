<?php
/** หน้าหลัก: เลือกระบบที่มีสิทธิ์ใช้งาน */
require_once __DIR__ . '/core/bootstrap.php';
$user = require_login();
$mods = accessible_modules($user);

$pageTitle = 'หน้าหลัก';
$active = 'home';
require APP_ROOT . '/core/layout/header.php';
?>
<div class="mb-4">
  <h2 class="h4 mb-1">สวัสดี <?= h($user['full_name']) ?></h2>
  <p class="text-muted mb-0">เลือกระบบที่ต้องการใช้งาน</p>
</div>

<div class="row g-3 mb-4">
  <?php foreach ($mods as $m): ?>
    <div class="col-md-6 col-xl-4">
      <a class="mod-card" href="<?= h(module_url($m['key'], $m['entry'] ?? 'index.php')) ?>">
        <span class="mod-icon" style="background:<?= h($m['color']) ?>"><i class="bi <?= h($m['icon']) ?>"></i></span>
        <span class="min-w-0">
          <h3><?= h($m['name']) ?></h3>
          <span class="d-block small text-muted mb-2"><?= h($m['description']) ?></span>
          <span class="badge text-bg-light border"><i class="bi bi-person-badge"></i> <?= h(role_short($m['key'], module_role($m['key'], $user))) ?></span>
        </span>
      </a>
    </div>
  <?php endforeach; ?>
  <?php if (!$mods): ?>
    <div class="col-12"><div class="alert alert-warning mb-0"><i class="bi bi-info-circle"></i>
      บัญชีของคุณยังไม่ได้รับสิทธิ์ใช้งานระบบใด กรุณาติดต่อผู้ดูแลระบบ</div></div>
  <?php endif; ?>
</div>

<?php
$links = [];
foreach (modules() as $m) {
    foreach ($m['links'] as $l) {
        $links[] = $l + ['module' => $m['key']];
    }
}
if ($links): ?>
  <h3 class="h6 text-muted">ลิงก์ที่เกี่ยวข้อง</h3>
  <div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($links as $l): ?>
      <a href="<?= h(module_url($l['module'], $l['href'])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
        <i class="bi <?= h($l['icon'] ?? 'bi-box-arrow-up-right') ?>"></i> <?= h($l['label']) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($user['is_superadmin']): ?>
  <h3 class="h6 text-muted">ผู้ดูแลระบบกลาง</h3>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= h(url('admin/users.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-people"></i> ผู้ใช้งานและสิทธิ์</a>
    <a href="<?= h(url('admin/settings.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-sliders"></i> ตั้งค่าระบบ</a>
    <a href="<?= h(url('admin/audit.php')) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-journal-text"></i> บันทึกการใช้งาน</a>
  </div>
<?php endif; ?>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
