<?php
/**
 * ส่วนหัว + เมนูด้านข้างที่ใช้ร่วมกันทุกระบบ
 * ตัวแปรที่หน้าเรียกใช้กำหนดได้ก่อน require:
 *   $pageTitle  หัวข้อหน้า
 *   $module     key ของระบบปัจจุบัน (null = หน้าระบบกลาง)
 *   $active     id ของเมนูที่เลือก
 *   $useLeaflet true เพื่อโหลดไลบรารีแผนที่
 *   $headExtra  HTML เพิ่มใน <head>
 */
$user = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$module = $module ?? null;
$active = $active ?? '';
$mods = $user ? accessible_modules($user) : [];
$cur = $module ? module_def($module) : null;
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title><?= h($pageTitle) ?> · <?= h($cur['short'] ?? ORG_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= h(url('assets/img/favicon.svg')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php if (!empty($useLeaflet)): ?><link href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" rel="stylesheet"><?php endif; ?>
<link href="<?= h(asset_v('assets/css/app.css')) ?>" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const BASE_URL_JS = <?= json_encode(BASE_URL) ?>;
  const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
  // ส่ง CSRF token ไปกับทุกคำขอ AJAX ของ jQuery
  $.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });
</script>
<?= $headExtra ?? '' ?>
</head>
<body class="<?= h($bodyClass ?? '') ?>">
<div class="app-wrap">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= h(url('index.php')) ?>">
      <span class="brand-mark"><i class="bi bi-bar-chart-line-fill"></i></span>
      <span><strong>สสจ.ขอนแก่น</strong><small>ระบบบริหารจัดการ</small></span>
    </a>
    <nav>
      <a href="<?= h(url('index.php')) ?>" class="<?= !$module && $active === 'home' ? 'active' : '' ?>"><i class="bi bi-grid-1x2"></i>หน้าหลัก</a>
      <?php foreach ($mods as $m): $isCur = $module === $m['key']; ?>
        <details class="nav-mod" <?= $isCur ? 'open' : '' ?>>
          <summary style="--mod-color:<?= h($m['color']) ?>"><i class="bi <?= h($m['icon']) ?>"></i><span><?= h($m['short']) ?></span><i class="bi bi-chevron-down chev"></i></summary>
          <?php foreach ($m['menu'] as $item): if (!user_can($m['key'], $item['role'] ?? '', $user)) continue; ?>
            <a href="<?= h(module_url($m['key'], $item['href'])) ?>" class="sub <?= $isCur && $active === $item['id'] ? 'active' : '' ?>"
               <?= !empty($item['target']) ? 'target="' . h($item['target']) . '"' : '' ?>><i class="bi <?= h($item['icon']) ?>"></i><?= h($item['label']) ?></a>
          <?php endforeach; ?>
        </details>
      <?php endforeach; ?>
      <?php if ($user && $user['is_superadmin']): ?>
        <div class="nav-label">ผู้ดูแลระบบกลาง</div>
        <a href="<?= h(url('admin/users.php')) ?>" class="<?= !$module && $active === 'users' ? 'active' : '' ?>"><i class="bi bi-people"></i>ผู้ใช้งานและสิทธิ์</a>
        <a href="<?= h(url('admin/settings.php')) ?>" class="<?= !$module && $active === 'settings' ? 'active' : '' ?>"><i class="bi bi-sliders"></i>ตั้งค่าระบบ</a>
        <a href="<?= h(url('admin/audit.php')) ?>" class="<?= !$module && $active === 'audit' ? 'active' : '' ?>"><i class="bi bi-journal-text"></i>บันทึกการใช้งาน</a>
      <?php endif; ?>
      <hr>
      <?php if ($user): ?>
        <a href="<?= h(url('account.php')) ?>" class="<?= !$module && $active === 'account' ? 'active' : '' ?>"><i class="bi bi-person-gear"></i>บัญชีของฉัน</a>
        <a href="<?= h(url('logout.php')) ?>"><i class="bi bi-box-arrow-left"></i><?= $user['via_pin'] ? 'เปลี่ยนผู้ใช้' : 'ออกจากระบบ' ?></a>
      <?php endif; ?>
    </nav>
  </aside>
  <div class="sidebar-backdrop" onclick="document.body.classList.remove('nav-open')"></div>
  <main class="content">
    <header class="topbar">
      <button class="btn btn-light d-lg-none" type="button" onclick="document.body.classList.toggle('nav-open')" aria-label="เมนู"><i class="bi bi-list"></i></button>
      <div class="min-w-0">
        <?php if ($cur): ?><div class="topbar-mod" style="color:<?= h($cur['color']) ?>"><i class="bi <?= h($cur['icon']) ?>"></i> <?= h($cur['name']) ?></div><?php endif; ?>
        <h1 class="h5 mb-0 text-truncate"><?= h($pageTitle) ?></h1>
      </div>
      <?php if ($user): ?>
        <div class="ms-auto small text-muted text-end d-none d-sm-block">
          <i class="bi bi-person-circle"></i> <?= h($user['full_name']) ?>
          <div class="text-truncate" style="max-width:260px">
            <?= $user['via_pin'] ? 'ยืนยันตัวตนด้วย PIN' : ($cur ? h(role_short($cur['key'], module_role($cur['key'], $user))) : ($user['is_superadmin'] ? 'ผู้ดูแลระบบกลาง' : '')) ?>
          </div>
        </div>
      <?php endif; ?>
    </header>
    <div class="content-body">
    <?php foreach (take_flash() as $f): ?>
      <div class="alert alert-<?= h($f['type']) ?> alert-dismissible fade show"><?= h($f['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; ?>
