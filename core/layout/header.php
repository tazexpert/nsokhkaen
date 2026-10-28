<?php
/**
 * ส่วนหัว + เมนูด้านบนที่ใช้ร่วมกันทุกระบบ
 * ตัวแปรที่หน้าเรียกใช้กำหนดได้ก่อน require:
 *   $pageTitle  หัวข้อหน้า
 *   $module     key ของระบบปัจจุบัน (null = หน้าระบบกลาง)
 *   $active     id ของเมนูที่เลือก
 *   $useLeaflet true เพื่อโหลดไลบรารีแผนที่
 *   $headExtra  HTML เพิ่มใน <head>
 *   $pageActions HTML ปุ่มด้านขวาของหัวข้อหน้า (ไม่บังคับ)
 */
$user = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$module = $module ?? null;
$active = $active ?? '';
$navMods = $user ? accessible_modules($user) : [];   // ชื่อเฉพาะ — กันชนกับตัวแปรของหน้าที่เรียก (เช่น $mods ในหน้าผู้ใช้งาน)
$cur = $module ? module_def($module) : null;
$notifs = $user ? user_notifications($user) : [];
$unread = $user ? unread_notification_count($notifs, $user) : 0;
$showNotifs = !empty($_SESSION['show_notifications']) && $notifs;
unset($_SESSION['show_notifications']);
$navActive = fn(bool $on) => $on ? ' active' : '';
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title><?= h($pageTitle) ?> · <?= h($cur['short'] ?? ORG_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= h(url('assets/img/favicon.png')) ?>">
<link rel="apple-touch-icon" href="<?= h(url('assets/img/apple-touch-icon.png')) ?>">
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
<nav class="navbar navbar-dark navbar-expand-xl app-nav sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand app-brand" href="<?= h(url('index.php')) ?>">
      <img src="<?= h(url('assets/img/logo.png')) ?>" alt="" class="brand-logo">
      <span class="brand-text"><small>ระบบบริหารจัดการ</small><strong>สำนักงานสถิติจังหวัดขอนแก่น</strong></span>
    </a>

    <?php if ($user): ?>
    <div class="d-flex align-items-center gap-1 order-xl-last ms-auto">
      <!-- การแจ้งเตือน -->
      <div class="dropdown">
        <button class="btn nav-icon-btn position-relative" type="button" id="notifBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="การแจ้งเตือน">
          <i class="bi bi-bell<?= $unread ? '-fill' : '' ?>"></i>
          <?php if ($unread): ?><span class="notif-badge" id="notifBadge"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notif-menu shadow" aria-labelledby="notifBtn">
          <div class="notif-head"><strong><i class="bi bi-bell"></i> การแจ้งเตือน</strong>
            <?php if ($notifs): ?><span class="small text-muted"><?= count($notifs) ?> รายการ</span><?php endif; ?></div>
          <div class="notif-list">
            <?php foreach ($notifs as $n): $isNew = !$user['notif_seen_at'] || $n['time'] > $user['notif_seen_at']; ?>
              <a class="notif-item<?= $isNew ? ' is-new' : '' ?>" href="<?= h($n['href']) ?>">
                <span class="notif-icon text-bg-<?= h($n['color']) ?>"><i class="bi <?= h($n['icon']) ?>"></i></span>
                <span class="min-w-0">
                  <span class="d-block fw-semibold"><?= h($n['title']) ?></span>
                  <?php if ($n['text'] !== ''): ?><span class="d-block small text-muted"><?= h($n['text']) ?></span><?php endif; ?>
                  <span class="d-block notif-time"><?= h(time_ago($n['time'])) ?></span>
                </span>
              </a>
            <?php endforeach; ?>
            <?php if (!$notifs): ?><div class="text-center text-muted py-4 small"><i class="bi bi-check2-circle fs-3 d-block"></i>ไม่มีการแจ้งเตือน</div><?php endif; ?>
          </div>
        </div>
      </div>
      <!-- บัญชีผู้ใช้ -->
      <div class="dropdown">
        <button class="btn nav-icon-btn d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="บัญชีผู้ใช้">
          <i class="bi bi-person-circle"></i>
          <span class="text-start lh-sm user-chip">
            <span class="d-block"><?= h($user['full_name']) ?></span>
            <small><?= $user['via_pin'] ? 'ยืนยันตัวตนด้วย PIN' : h($user['work_group'] ?: ($user['position'] ?: '')) ?></small>
          </span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li class="dropdown-header"><?= h($user['full_name']) ?><?php if ($user['username']): ?><br><small class="font-monospace"><?= h($user['username']) ?></small><?php endif; ?></li>
          <li><a class="dropdown-item<?= $navActive(!$module && $active === 'account') ?>" href="<?= h(url('account.php')) ?>"><i class="bi bi-person-gear"></i> บัญชีของฉัน</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= h(url('logout.php')) ?>"><i class="bi bi-box-arrow-right"></i> <?= $user['via_pin'] ? 'เปลี่ยนผู้ใช้' : 'ออกจากระบบ' ?></a></li>
        </ul>
      </div>
      <button class="navbar-toggler ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="เมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
    </div>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link<?= $navActive(!$module && $active === 'home') ?>" href="<?= h(url('index.php')) ?>"><i class="bi bi-house-door"></i> หน้าหลัก</a></li>
        <?php foreach ($navMods as $m): $isCur = $module === $m['key']; ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $navActive($isCur) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <?= module_logo($m, 22, 'me-1') ?> <?= h($m['short']) ?></a>
            <ul class="dropdown-menu shadow">
              <li class="dropdown-header"><?= h($m['name']) ?></li>
              <?php foreach ($m['menu'] as $item): if (!user_can($m['key'], $item['role'] ?? '', $user)) continue; ?>
                <li><a class="dropdown-item<?= $navActive($isCur && $active === $item['id']) ?>" href="<?= h(module_url($m['key'], $item['href'])) ?>"
                   <?= !empty($item['target']) ? 'target="' . h($item['target']) . '"' : '' ?>><i class="bi <?= h($item['icon']) ?>"></i> <?= h($item['label']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </li>
        <?php endforeach; ?>
        <?php if ($user['is_superadmin']): $isAdm = !$module && in_array($active, ['users', 'settings', 'audit'], true); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $navActive($isAdm) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-shield-lock"></i> ผู้ดูแลระบบ</a>
            <ul class="dropdown-menu shadow">
              <li><a class="dropdown-item<?= $navActive(!$module && $active === 'users') ?>" href="<?= h(url('admin/users.php')) ?>"><i class="bi bi-people"></i> ผู้ใช้งานและสิทธิ์</a></li>
              <li><a class="dropdown-item<?= $navActive(!$module && $active === 'settings') ?>" href="<?= h(url('admin/settings.php')) ?>"><i class="bi bi-sliders"></i> ตั้งค่าระบบ</a></li>
              <li><a class="dropdown-item<?= $navActive(!$module && $active === 'audit') ?>" href="<?= h(url('admin/audit.php')) ?>"><i class="bi bi-journal-text"></i> บันทึกการใช้งาน</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</nav>

<?php if ($cur): /* แถบเมนูย่อยของระบบปัจจุบัน */ ?>
<div class="mod-bar no-print">
  <div class="container-fluid">
    <span class="mod-bar-name" style="--mod-color:<?= h($cur['color']) ?>"><?= module_logo($cur, 24) ?> <span class="d-none d-sm-inline"><?= h($cur['short']) ?></span></span>
    <nav class="mod-bar-links">
      <?php foreach ($cur['menu'] as $item): if (!user_can($cur['key'], $item['role'] ?? '', $user)) continue; ?>
        <a href="<?= h(module_url($cur['key'], $item['href'])) ?>" class="<?= $active === $item['id'] ? 'active' : '' ?>"
           <?= !empty($item['target']) ? 'target="' . h($item['target']) . '"' : '' ?>><i class="bi <?= h($item['icon']) ?>"></i> <?= h($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>
<?php endif; ?>

<main class="content">
  <header class="page-head">
    <div class="min-w-0">
      <h1 class="h4 mb-0 text-truncate"><?= h($pageTitle) ?></h1>
      <?php if ($cur && $user): ?><div class="small text-muted"><?= h($cur['name']) ?> · <?= $user['via_pin'] ? 'ยืนยันตัวตนด้วย PIN' : h(role_short($cur['key'], module_role($cur['key'], $user))) ?></div><?php endif; ?>
    </div>
    <?php if (!empty($pageActions)): ?><div class="ms-auto d-flex gap-2 flex-wrap"><?= $pageActions ?></div><?php endif; ?>
  </header>
  <div class="content-body">
  <?php foreach (take_flash() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?> alert-dismissible fade show"><?= h($f['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endforeach; ?>
<?php if ($user): ?>
<script>
  // การแจ้งเตือน: เปิดดูแล้ว → บันทึกเวลา (ตัวเลขบนกระดิ่งหายไป); หลัง login เปิดรายการให้เห็นทันที
  document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('notifBtn');
    if (!btn) return;
    btn.addEventListener('shown.bs.dropdown', () => {
      const badge = document.getElementById('notifBadge');
      if (!badge) return;
      $.post(BASE_URL_JS + 'api/notifications.php', { action: 'seen' });
      badge.remove();
      btn.querySelector('.bi').className = 'bi bi-bell';
    });
    <?php if ($showNotifs): ?>bootstrap.Dropdown.getOrCreateInstance(btn).show();<?php endif; ?>
  });
</script>
<?php endif; ?>
