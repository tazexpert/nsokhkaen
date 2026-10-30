<?php
/**
 * เข้าสู่ระบบด้วยชื่อผู้ใช้ + รหัสผ่าน (ใช้ได้กับทุกระบบ)
 *   ปกติใช้ผ่าน popup (modal) ในแถบเมนู: ส่งแบบ AJAX (ajax=1) คืน JSON {ok, redirect | error}
 *   เปิดหน้านี้ตรง ๆ (GET) → ไปหน้าหลักและเปิด popup ให้อัตโนมัติ · หน้าเต็มเดิมใช้เมื่อส่งฟอร์มแบบไม่มี JavaScript
 */
require_once __DIR__ . '/core/bootstrap.php';
$ajax = !empty($_POST['ajax']);

/** หน้าที่ไปหลัง login: ตั้ง PIN ก่อน (ถ้าบทบาทกำหนด) → หน้าที่ตั้งใจจะเข้า → หน้าที่กด login (return) → หน้าหลัก */
function login_target(array $u): string
{
    if (!$u['has_pin']) {
        foreach (accessible_modules($u) as $m) {
            if (in_array(module_role($m['key'], $u), $m['prompt_pin_roles'] ?? [], true)) {
                return url('account.php?setup_pin=1');
            }
        }
    }
    $return = (string)($_POST['return'] ?? '');
    $fallback = (str_starts_with($return, BASE_URL) && !str_starts_with($return, '//') && !preg_match('#/(login|logout|pin)\.php#', $return))
        ? preg_replace('/([?&])login=1(&|$)/', '$1', $return) : url('index.php');
    return take_login_target(rtrim($fallback, '?&'));
}

if ($u = current_user()) {
    $ajax ? json_out(['ok' => true, 'redirect' => login_target($u)]) : redirect(login_target($u));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('index.php?login=1'));            // หน้า login = popup บนหน้าหลัก
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = post('username');
    $password = (string)($_POST['password'] ?? '');
    throttle_failed_logins();
    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM core_users WHERE username = ? AND is_active = 1');
        $st->execute([$username]);
        $row = $st->fetch();
        if ($row && $row['password_hash'] && password_verify($password, $row['password_hash'])) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE core_users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            }
            login_user((int)$row['id']);
            $to = login_target(current_user());
            $ajax ? json_out(['ok' => true, 'redirect' => $to]) : redirect($to);
        }
        $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
        audit_log('login_failed', 'username: ' . $username, 'core', null);
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
    if ($ajax) {
        json_out(['ok' => false, 'error' => $error]);
    }
}
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เข้าสู่ระบบ · <?= h(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= h(url('assets/img/favicon.png')) ?>">
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600&family=Sarabun:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= h(asset_v('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="auth-page">
  <form method="post" class="auth-card">
    <div class="text-center mb-4">
      <img src="<?= h(url('assets/img/logo.png')) ?>" alt="" class="auth-logo">
      <h1 class="h5 mt-3 mb-1">ระบบบริหารจัดการ</h1>
      <p class="h6 fw-normal text-muted mb-0"><?= h(ORG_NAME) ?></p>
    </div>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label">ชื่อผู้ใช้</label>
      <input name="username" class="form-control form-control-lg" required autofocus autocomplete="username" value="<?= h(post('username')) ?>">
    </div>
    <div class="mb-4">
      <label class="form-label">รหัสผ่าน</label>
      <input name="password" type="password" class="form-control form-control-lg" required autocomplete="current-password">
    </div>
    <button class="btn btn-primary btn-lg w-100">เข้าสู่ระบบ</button>
    <div class="text-center mt-3 small">
      <a href="<?= h(url('pin.php')) ?>"><i class="bi bi-grid-3x3-gap"></i> เข้าใช้ด้วย PIN 6 หลัก</a>
      <span class="text-muted mx-2">·</span>
      <a href="<?= h(url('index.php')) ?>"><i class="bi bi-house-door"></i> กลับหน้าหลัก</a>
    </div>
  </form>
</body>
</html>
