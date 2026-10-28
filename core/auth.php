<?php
/**
 * การยืนยันตัวตน (login เดียวทุกระบบ) และสิทธิ์รายระบบ
 *
 * session เก็บเฉพาะ uid — ข้อมูลผู้ใช้และสิทธิ์โหลดจากฐานข้อมูลทุก request
 * ดังนั้นการปิดบัญชี/เปลี่ยนสิทธิ์มีผลทันทีโดยไม่ต้องให้ผู้ใช้ออกจากระบบ
 */

function start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE || PHP_SAPI === 'cli') {
        return;
    }
    session_name('NSOKKSESS');
    session_set_cookie_params([
        'path'     => BASE_URL,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

/** ผู้ใช้ที่ login อยู่ (พร้อมสิทธิ์รายระบบ) หรือ null */
function current_user(bool $reload = false): ?array
{
    static $user = false;
    if ($reload) {
        $user = false;
    }
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) {
        return null;
    }
    $st = db()->prepare('SELECT u.id, u.username, u.full_name, u.position, u.email, u.phone, u.is_superadmin, u.is_active,
                                u.work_group_id, g.name AS work_group, u.notif_seen_at,
                                (u.pin_hash IS NOT NULL) AS has_pin, (u.password_hash IS NOT NULL) AS has_password
                         FROM core_users u LEFT JOIN core_work_groups g ON g.id = u.work_group_id WHERE u.id = ?');
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row || !$row['is_active']) {
        logout_user();            // บัญชีถูกปิด/ลบระหว่างใช้งาน
        return null;
    }
    $st = db()->prepare('SELECT module_key, role FROM core_user_roles WHERE user_id = ?');
    $st->execute([$uid]);
    $row['roles'] = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $row['id'] = (int)$row['id'];
    $row['is_superadmin'] = (bool)$row['is_superadmin'];
    $row['has_pin'] = (bool)$row['has_pin'];
    $row['has_password'] = (bool)$row['has_password'];
    $row['via_pin'] = !empty($_SESSION['via_pin']);
    return $user = $row;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_superadmin(): bool
{
    return (bool)(current_user()['is_superadmin'] ?? false);
}

/** role ของผู้ใช้ในระบบ $module (superadmin = role สูงสุดของทุกระบบ) หรือ null ถ้าไม่มีสิทธิ์ */
function module_role(string $module, ?array $user = null): ?string
{
    $user ??= current_user();
    if (!$user || !module_def($module)) {
        return null;
    }
    if ($user['is_superadmin'] && module_def($module)['superadmin_access']) {
        return top_role($module);
    }
    $role = $user['roles'][$module] ?? null;
    return role_level($module, $role) > 0 ? $role : null;
}

/** มีสิทธิ์ในระบบ $module ตั้งแต่ระดับ $minRole ขึ้นไปหรือไม่ ($minRole ว่าง = มีสิทธิ์ใดก็ได้) */
function user_can(string $module, string $minRole = '', ?array $user = null): bool
{
    $role = module_role($module, $user);
    if ($role === null) {
        return false;
    }
    return $minRole === '' || role_level($module, $role) >= role_level($module, $minRole);
}

/** ระบบที่ผู้ใช้เข้าได้ */
function accessible_modules(?array $user = null): array
{
    return array_filter(modules(), fn($m) => module_role($m['key'], $user) !== null);
}

function login_user(int $userId, bool $viaPin = false): void
{
    session_regenerate_id(true);
    $redirect = $_SESSION['redirect_after_login'] ?? null;
    $_SESSION = ['uid' => $userId, 'via_pin' => $viaPin, 'redirect_after_login' => $redirect,
                 'show_notifications' => !$viaPin];   // เปิดรายการแจ้งเตือนให้เห็นทันทีหลัง login
    db()->prepare('UPDATE core_users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
    current_user(true);
    audit_log($viaPin ? 'login_pin' : 'login', '', 'core', $userId);
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/** จำหน้าที่ผู้ใช้ตั้งใจจะเข้า เพื่อพากลับหลัง login */
function remember_target(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_SERVER['REQUEST_URI'])) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    }
}

/** ดูหน้าเป้าหมายหลัง login โดยไม่ลบออก */
function peek_login_target(string $fallback): string
{
    $t = $_SESSION['redirect_after_login'] ?? '';
    return (is_string($t) && str_starts_with($t, BASE_URL) && !str_starts_with($t, '//')) ? $t : $fallback;
}

/** หน้าที่ควรไปหลัง login สำเร็จ (เฉพาะ URL ภายในระบบ) แล้วล้างค่า */
function take_login_target(string $fallback): string
{
    $t = peek_login_target($fallback);
    unset($_SESSION['redirect_after_login']);
    return $t;
}

/** ต้อง login ก่อน — $loginPage: 'login.php' (รหัสผ่าน) หรือ 'pin.php' (PIN) */
function require_login(string $loginPage = 'login.php'): array
{
    $u = current_user();
    if (!$u) {
        remember_target();
        redirect(url($loginPage));
    }
    return $u;
}

/** ต้องมีสิทธิ์ในระบบ $module ตั้งแต่ระดับ $minRole */
function require_module(string $module, string $minRole = '', string $loginPage = 'login.php'): array
{
    $u = require_login($loginPage);
    if (!user_can($module, $minRole, $u)) {
        forbidden($module, $minRole);
    }
    return $u;
}

/** สำหรับ API (JSON): ต้อง login + มีสิทธิ์ และตรวจ CSRF เมื่อเป็น POST */
function require_module_api(string $module, string $minRole = ''): array
{
    $u = current_user();
    if (!$u) {
        json_out(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน'], 401);
    }
    if (!user_can($module, $minRole, $u)) {
        json_out(['success' => false, 'message' => 'คุณไม่มีสิทธิ์ทำรายการนี้'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_valid()) {
        json_out(['success' => false, 'message' => 'คำขอไม่ถูกต้อง (CSRF) กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง'], 400);
    }
    return $u;
}

function require_superadmin(): array
{
    $u = require_login();
    if (!$u['is_superadmin']) {
        forbidden(null, '');
    }
    return $u;
}

function forbidden(?string $module, string $minRole): never
{
    http_response_code(403);
    $pageTitle = 'ไม่มีสิทธิ์เข้าถึง';
    $module = null;
    require APP_ROOT . '/core/layout/header.php';
    echo '<div class="card" style="max-width:640px"><div class="card-body text-center py-5">'
        . '<i class="bi bi-shield-lock display-4 text-danger"></i>'
        . '<h2 class="h5 mt-3">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h2>'
        . '<p class="text-muted mb-4">หากต้องการใช้งาน กรุณาติดต่อผู้ดูแลระบบเพื่อขอสิทธิ์</p>'
        . '<a href="' . h(url('index.php')) . '" class="btn btn-primary">กลับหน้าหลัก</a></div></div>';
    require APP_ROOT . '/core/layout/footer.php';
    exit;
}

/**
 * หาผู้ใช้ที่ใช้งานอยู่จาก PIN (PIN เก็บแบบ hash แยก salt จึงต้องตรวจทีละคน)
 * ใช้ทั้งตอน login ด้วย PIN และตรวจ PIN ซ้ำตอนตั้ง/เปลี่ยน PIN
 */
function find_user_by_pin(string $pin, ?int $excludeId = null): ?array
{
    $sql = 'SELECT * FROM core_users WHERE is_active = 1 AND pin_hash IS NOT NULL';
    $params = [];
    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll() as $u) {
        if (password_verify($pin, $u['pin_hash'])) {
            return $u;
        }
    }
    return null;
}

/** หน่วงเวลาเมื่อกรอกรหัสผ่าน/PIN ผิดหลายครั้งในเซสชันเดียว ลดการเดารหัส */
function throttle_failed_logins(): void
{
    $fails = $_SESSION['login_fails'] ?? 0;
    if ($fails >= 5) {
        sleep(min(10, $fails - 4));
    }
}
