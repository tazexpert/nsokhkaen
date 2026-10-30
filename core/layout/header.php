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
$navMods = accessible_modules($user);   // ไม่ได้ login = เฉพาะระบบที่เปิดให้ผู้เยี่ยมชม (guest_role) · ชื่อเฉพาะ — กันชนกับตัวแปรของหน้าที่เรียก (เช่น $mods ในหน้าผู้ใช้งาน)
// มีมากกว่า 5 ระบบ: แสดง 4 ระบบแรก (ตามลำดับที่ผู้ใช้จัด) ที่เหลือรวมในเมนู "ระบบอื่นๆ"
$navMore = count($navMods) > 5 ? array_slice($navMods, 4, null, true) : [];
$navMain = $navMore ? array_slice($navMods, 0, 4, true) : $navMods;
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
            <?php foreach ($notifs as $__n): $__isNew = !$user['notif_seen_at'] || $__n['time'] > $user['notif_seen_at']; ?>
              <a class="notif-item<?= $__isNew ? ' is-new' : '' ?>" href="<?= h($__n['href']) ?>">
                <span class="notif-icon text-bg-<?= h($__n['color']) ?>"><i class="bi <?= h($__n['icon']) ?>"></i></span>
                <span class="min-w-0">
                  <span class="d-block fw-semibold"><?= h($__n['title']) ?></span>
                  <?php if ($__n['text'] !== ''): ?><span class="d-block small text-muted"><?= h($__n['text']) ?></span><?php endif; ?>
                  <span class="d-block notif-time"><?= h(time_ago($__n['time'])) ?></span>
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
          <?= user_avatar($user, 32, 'nav-avatar') ?>
          <span class="text-start lh-sm user-chip">
            <span class="d-block"><?= h($user['full_name']) ?></span>
            <small><?= $user['via_pin'] ? 'ยืนยันตัวตนด้วย PIN' : h($user['work_group'] ?: ($user['position'] ?: '')) ?></small>
          </span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li class="dropdown-header d-flex align-items-center gap-2"><?= user_avatar($user, 40) ?><span><?= h($user['full_name']) ?><?php if ($user['username']): ?><br><small class="font-monospace"><?= h($user['username']) ?></small><?php endif; ?></span></li>
          <li><a class="dropdown-item<?= $navActive(!$module && $active === 'account') ?>" href="<?= h(url('account.php')) ?>"><i class="bi bi-person-gear"></i> บัญชีของฉัน</a></li>
          <li><a class="dropdown-item" href="<?= h(url('index.php#arrange')) ?>"><i class="bi bi-arrows-move"></i> จัดเรียงเมนูระบบ</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= h(url('logout.php')) ?>"><i class="bi bi-box-arrow-right"></i> <?= $user['via_pin'] ? 'เปลี่ยนผู้ใช้' : 'ออกจากระบบ' ?></a></li>
        </ul>
      </div>
      <button class="navbar-toggler ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="เมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
    </div>
    <?php else: /* ผู้เยี่ยมชม: ปุ่มเข้าสู่ระบบ */ ?>
    <div class="d-flex align-items-center gap-1 order-xl-last ms-auto">
      <a href="<?= h(url('login.php')) ?>" class="btn btn-light btn-login"><i class="bi bi-box-arrow-in-right"></i> <span class="d-none d-sm-inline">เข้าสู่ระบบ</span></a>
      <button class="navbar-toggler ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="เมนู">
        <span class="navbar-toggler-icon"></span>
      </button>
    </div>
    <?php endif; ?>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link<?= $navActive(!$module && $active === 'home') ?>" href="<?= h(url('index.php')) ?>"><i class="bi bi-house-door"></i> หน้าหลัก</a></li>
        <?php foreach ($navMain as $__m): $__isCur = $module === $__m['key']; ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $navActive($__isCur) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <?= module_logo($__m, 22, 'me-1') ?> <?= h($__m['short']) ?></a>
            <ul class="dropdown-menu shadow">
              <li class="dropdown-header"><?= h($__m['name']) ?></li>
              <?php foreach ($__m['menu'] as $__item): if (!menu_item_visible($__m['key'], $__item, $user)) continue; ?>
                <li><a class="dropdown-item<?= $navActive($__isCur && $active === $__item['id']) ?>" href="<?= h(module_url($__m['key'], $__item['href'])) ?>"
                   <?= !empty($__item['target']) ? 'target="' . h($__item['target']) . '"' : '' ?>><i class="bi <?= h($__item['icon']) ?>"></i> <?= h($__item['label']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </li>
        <?php endforeach; ?>
        <?php if ($navMore): $__isCurMore = isset($navMore[$module]); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $navActive($__isCurMore) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-grid-3x3-gap"></i> ระบบอื่นๆ<?= $__isCurMore ? ': ' . h($navMore[$module]['short']) : '' ?></a>
            <ul class="dropdown-menu shadow more-menu">
              <?php foreach ($navMore as $__m): $__isCur = $module === $__m['key']; ?>
                <li class="dropdown-header d-flex align-items-center gap-1"><?= module_logo($__m, 18) ?> <?= h($__m['name']) ?></li>
                <?php foreach ($__m['menu'] as $__item): if (!menu_item_visible($__m['key'], $__item, $user)) continue; ?>
                  <li><a class="dropdown-item<?= $navActive($__isCur && $active === $__item['id']) ?>" href="<?= h(module_url($__m['key'], $__item['href'])) ?>"
                     <?= !empty($__item['target']) ? 'target="' . h($__item['target']) . '"' : '' ?>><i class="bi <?= h($__item['icon']) ?>"></i> <?= h($__item['label']) ?></a></li>
                <?php endforeach; ?>
                <li><hr class="dropdown-divider"></li>
              <?php endforeach; ?>
              <?php if ($user): ?><li><a class="dropdown-item small text-muted" href="<?= h(url('index.php#arrange')) ?>"><i class="bi bi-arrows-move"></i> จัดเรียงระบบในเมนู</a></li><?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>
        <?php if (!empty($user['is_superadmin'])): $__isAdm = !$module && in_array($active, ['users', 'settings', 'audit'], true); ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= $navActive($__isAdm) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-shield-lock"></i> ผู้ดูแลระบบ</a>
            <ul class="dropdown-menu shadow">
              <li><a class="dropdown-item<?= $navActive(!$module && $active === 'users') ?>" href="<?= h(url('admin/users.php')) ?>"><i class="bi bi-people"></i> ผู้ใช้งานและสิทธิ์</a></li>
              <li><a class="dropdown-item<?= $navActive(!$module && $active === 'settings') ?>" href="<?= h(url('admin/settings.php')) ?>"><i class="bi bi-sliders"></i> ตั้งค่าระบบ</a></li>
              <li><a class="dropdown-item<?= $navActive(!$module && $active === 'audit') ?>" href="<?= h(url('admin/audit.php')) ?>"><i class="bi bi-journal-text"></i> บันทึกการใช้งาน</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<?php if ($cur): /* แถบเมนูย่อยของระบบปัจจุบัน */ ?>
<div class="mod-bar no-print">
  <div class="container-fluid">
    <span class="mod-bar-name" style="--mod-color:<?= h($cur['color']) ?>"><?= module_logo($cur, 24) ?> <span class="d-none d-sm-inline"><?= h($cur['short']) ?></span></span>
    <nav class="mod-bar-links">
      <?php foreach ($cur['menu'] as $__item): if (!menu_item_visible($cur['key'], $__item, $user)) continue; ?>
        <a href="<?= h(module_url($cur['key'], $__item['href'])) ?>" class="<?= $active === $__item['id'] ? 'active' : '' ?>"
           <?= !empty($__item['target']) ? 'target="' . h($__item['target']) . '"' : '' ?>><i class="bi <?= h($__item['icon']) ?>"></i> <?= h($__item['label']) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>
<?php endif; ?>

<main class="content">
  <?php if (empty($hidePageHead)): ?>
  <header class="page-head">
    <div class="min-w-0">
      <h1 class="h4 mb-0 text-truncate"><?= h($pageTitle) ?></h1>
      <?php if ($cur && $user): ?><div class="small text-muted"><?= h($cur['name']) ?> · <?= $user['via_pin'] ? 'ยืนยันตัวตนด้วย PIN' : h(role_short($cur['key'], module_role($cur['key'], $user))) ?></div><?php endif; ?>
    </div>
    <?php if (!empty($pageActions)): ?><div class="ms-auto d-flex gap-2 flex-wrap"><?= $pageActions ?></div><?php endif; ?>
  </header>
  <?php endif; ?>
  <div class="content-body">
  <?php foreach (take_flash() as $__f): ?>
    <div class="alert alert-<?= h($__f['type']) ?> alert-dismissible fade show"><?= h($__f['msg']) ?>
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
