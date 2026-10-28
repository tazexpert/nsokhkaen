<?php
/** ฟังก์ชันกลางที่ใช้ร่วมกันทุกระบบ */

/* ---------- ทั่วไป ---------- */

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void   // ไม่คืนค่า (จบการทำงานเสมอ) — ใช้ void เพื่อรองรับ PHP 8.0
{
    header('Location: ' . $url);
    exit;
}

/** URL ภายในระบบ (path จาก root ของโปรเจกต์) เช่น url('admin/users.php') */
function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function int_param(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? $_POST[$key] ?? $default;
    return is_numeric($v) ? (int)$v : $default;
}

/** แปลงค่าพิกัดจากฟอร์ม คืนค่า null ถ้าว่างหรืออยู่นอกช่วง */
function coord(string $v, float $min, float $max): ?float
{
    $v = trim($v);
    if ($v === '' || !is_numeric($v)) {
        return null;
    }
    $f = (float)$v;
    return ($f >= $min && $f <= $max) ? $f : null;
}

/** วันที่แบบย่อ เช่น 9 ต.ค. 2569 */
function thai_date(?string $date): string
{
    if (!$date || ($ts = strtotime($date)) === false) {
        return '-';
    }
    $m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return (int)date('j', $ts) . ' ' . $m[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
}

function json_out($data, int $code = 200): void   // ไม่คืนค่า (จบการทำงานเสมอ) — ใช้ void เพื่อรองรับ PHP 8.0
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** query string กันแคชไฟล์ CSS/JS ตามเวลาแก้ไขล่าสุด — path จาก root ของโปรเจกต์ */
function asset_v(string $path): string
{
    $full = APP_ROOT . '/' . ltrim($path, '/');
    return url($path) . '?v=' . (is_file($full) ? filemtime($full) : '0');
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** บันทึกการใช้งาน (ไม่ทำให้ request ล้มถ้าบันทึกไม่ได้) */
function audit_log(string $action, string $detail = '', string $module = 'core', ?int $userId = null): void
{
    try {
        $userId ??= $_SESSION['uid'] ?? null;
        db()->prepare('INSERT INTO core_audit_log (user_id, module_key, action, detail, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $module, $action, mb_substr($detail, 0, 500), client_ip()]);
    } catch (Throwable $e) {
        error_log('[nsokhkaen] audit_log failed: ' . $e->getMessage());
    }
}

/* ---------- Flash message ---------- */

function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

/** ตรวจ CSRF ของฟอร์ม (POST) — หยุดทำงานถ้าไม่ผ่าน */
function check_csrf(): void
{
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        exit('ขนาดข้อมูลรวมใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้ (post_max_size = ' . ini_get('post_max_size') . ') '
            . 'กรุณาลดจำนวนภาพต่อครั้ง แล้วกดย้อนกลับเพื่อลองอีกครั้ง');
    }
    if (!csrf_valid()) {
        http_response_code(400);
        exit('คำขอไม่ถูกต้อง (CSRF token ไม่ตรงกัน) กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
    }
}

/* ---------- อัปโหลดรูปภาพ (เก็บใต้ uploads/) ---------- */

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;
const PASSWORD_MIN_LENGTH = 6;            // ความยาวรหัสผ่านขั้นต่ำ
const IMAGE_MAX_SIDE = 1600;

/** แปลง $_FILES['x'] แบบ multiple เป็น array ของไฟล์เดี่ยว */
function files_array(string $field): array
{
    if (empty($_FILES[$field])) {
        return [];
    }
    $f = $_FILES[$field];
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
                  'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/**
 * บันทึกรูปที่อัปโหลดไว้ใต้ uploads/$subdir (เช่น digitalcc/centers)
 * คืนค่า path สัมพัทธ์จาก uploads/ (เช่น digitalcc/centers/abc.jpg) หรือ null ถ้าไม่มีไฟล์
 * โยน RuntimeException ถ้าไฟล์ไม่ถูกต้อง
 */
function save_upload(array $file, string $subdir): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ "' . $file['name'] . '" ไม่สำเร็จ (รหัส ' . $file['error'] . ')');
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('ไฟล์ "' . $file['name'] . '" มีขนาดใหญ่เกิน ' . (UPLOAD_MAX_BYTES / 1048576) . ' MB');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('ไฟล์ไม่ถูกต้อง');
    }
    $info = @getimagesize($file['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('ไฟล์ "' . $file['name'] . '" ไม่ใช่รูปภาพที่รองรับ (JPG, PNG, WEBP, GIF)');
    }
    $subdir = trim($subdir, '/');
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ ' . $subdir);
    }
    $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$info[2]];
    $dest = $dir . '/' . $name;
    if (!resize_image($file['tmp_name'], $dest, $info, IMAGE_MAX_SIDE) && !move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ ตรวจสอบสิทธิ์การเขียนโฟลเดอร์ uploads');
    }
    return $subdir . '/' . $name;
}

/** ย่อรูปให้ด้านยาวไม่เกิน $max (ต้องมี GD) คืน false ถ้าไม่ได้ย่อ */
function resize_image(string $src, string $dest, array $info, int $max): bool
{
    [$w, $h, $type] = $info;
    if (!function_exists('imagecreatetruecolor') || ($w <= $max && $h <= $max) || $type === IMAGETYPE_GIF) {
        return false;
    }
    $img = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default        => false,
    };
    if (!$img) {
        return false;
    }
    $r = min($max / $w, $max / $h);
    $out = imagecreatetruecolor((int)round($w * $r), (int)round($h * $r));
    if ($type !== IMAGETYPE_JPEG) {
        imagealphablending($out, false);
        imagesavealpha($out, true);
    }
    imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($out, $dest, 85),
        IMAGETYPE_PNG  => imagepng($out, $dest, 6),
        IMAGETYPE_WEBP => imagewebp($out, $dest, 85),
    };
    imagedestroy($img);
    imagedestroy($out);
    return $ok;
}

/** ลบไฟล์ใต้ uploads/ (path สัมพัทธ์จาก uploads/) */
function delete_upload(?string $rel): void
{
    if (!$rel || str_contains($rel, '..')) {
        return;
    }
    $path = UPLOAD_DIR . '/' . ltrim($rel, '/');
    if (is_file($path)) {
        @unlink($path);
    }
}

/** URL ของไฟล์ใต้ uploads/ */
function upload_url(?string $rel): string
{
    return $rel ? url('uploads/' . ltrim($rel, '/')) : '';
}

/** กลุ่มงานทั้งหมด [id => name] เรียงตามลำดับ */
function work_groups(): array
{
    static $groups = null;
    if ($groups === null) {
        $groups = db()->query('SELECT id, name FROM core_work_groups ORDER BY sort_order, id')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $groups;
}
