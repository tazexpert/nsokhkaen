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

/**
 * บันทึกรูปโปรไฟล์: ตัดเป็นสี่เหลี่ยมจัตุรัส (กึ่งกลาง) ขนาด 400px — คืน path สัมพัทธ์จาก uploads/
 * โยน RuntimeException ถ้าไฟล์ไม่ถูกต้อง
 */
function save_avatar(array $file): ?string
{
    $rel = save_upload($file, 'avatars');
    if (!$rel || !function_exists('imagecreatetruecolor')) {
        return $rel;
    }
    $path = UPLOAD_DIR . '/' . $rel;
    $info = @getimagesize($path);
    $img = match ($info[2] ?? 0) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default        => false,                       // GIF: ใช้ตามเดิม
    };
    if (!$img) {
        return $rel;
    }
    [$w, $h] = $info;
    $side = min($w, $h);
    $size = min(400, $side);
    $out = imagecreatetruecolor($size, $size);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
    $jpg = preg_replace('/\.\w+$/', '.jpg', $rel);
    if (imagejpeg($out, UPLOAD_DIR . '/' . $jpg, 88)) {
        if ($jpg !== $rel) {
            @unlink($path);
        }
        $rel = $jpg;
    }
    imagedestroy($img);
    imagedestroy($out);
    return $rel;
}

/** รูปโปรไฟล์ผู้ใช้ (วงกลม) — ไม่มีรูป = ไอคอนผู้ใช้ */
function user_avatar(array $u, int $size = 32, string $class = ''): string
{
    $style = 'width:' . $size . 'px;height:' . $size . 'px';
    if (!empty($u['photo'])) {
        return '<img src="' . h(upload_url($u['photo'])) . '" alt="" class="avatar ' . h($class) . '" style="' . $style . '">';
    }
    return '<span class="avatar avatar-empty ' . h($class) . '" style="' . $style . ';font-size:' . round($size * .55) . 'px"><i class="bi bi-person-fill"></i></span>';
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

/** ชื่อเดือนภาษาไทยแบบเต็ม (1–12) */
function thai_month_long(int $m): string
{
    return ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม',
            'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'][$m] ?? '';
}

/**
 * แปลงข้อความจากไฟล์ (CSV) เป็น UTF-8: ตัด BOM และแปลงจาก Windows-874/TIS-620 ถ้าไม่ใช่ UTF-8
 * (Excel ภาษาไทยบันทึก CSV แบบ Windows-874 — mbstring ไม่รองรับ จึงใช้ iconv)
 */
function text_to_utf8(string $raw): string
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (mb_check_encoding($raw, 'UTF-8')) {
        return $raw;
    }
    $out = function_exists('iconv') ? @iconv('CP874', 'UTF-8//IGNORE', $raw) : false;
    if ($out === false || $out === '') {
        $out = @iconv('TIS-620', 'UTF-8//IGNORE', $raw);
    }
    return $out !== false && $out !== '' ? $out : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
}

/** วันที่จากไฟล์ → Y-m-d (รองรับ 28/10/2569, 28/10/2026, 2026-10-28, 28 ต.ค. 2569, 28 ตุลาคม 2569) */
function parse_date_flexible(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    $y = $m = $d = 0;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $x)) {
        [, $y, $m, $d] = $x;
    } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $v, $x)) {
        [, $d, $m, $y] = $x;
        if (strlen($y) === 2) {
            $y = (int)$y + ((int)$y > 50 ? 2500 : 2000);         // 69 → 2569, 26 → 2026
        }
    } elseif (preg_match('/^(\d{1,2})\s*(\S+?)\.?\s*(\d{2,4})$/u', $v, $x)) {
        $months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $short = ['ม.ค', 'ก.พ', 'มี.ค', 'เม.ย', 'พ.ค', 'มิ.ย', 'ก.ค', 'ส.ค', 'ก.ย', 'ต.ค', 'พ.ย', 'ธ.ค'];
        $name = rtrim($x[2], '.');
        $i = array_search($name, $months, true);
        $i = $i === false ? array_search($name, $short, true) : $i;
        if ($i === false) {
            return null;
        }
        [$d, $m, $y] = [$x[1], $i + 1, (int)$x[3] < 100 ? 2500 + (int)$x[3] : $x[3]];
    } elseif (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {      // เลขวันที่ของ Excel
        return date('Y-m-d', (int)(((float)$v - 25569) * 86400));
    } else {
        return null;
    }
    $y = (int)$y;
    if ($y > 2400) {
        $y -= 543;                                          // พ.ศ. → ค.ศ.
    }
    return checkdate((int)$m, (int)$d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
}

/* ---------- ลำดับบุคลากร (ใช้ทุกหน้าที่แสดงรายชื่อบุคลากร) ---------- */

/**
 * ประเภทบุคลากรจากตำแหน่ง:
 *   chief = สถิติจังหวัด · dir1/dir2/dir3 = ผู้อำนวยการกลุ่ม (วิชาการฯ / ปฏิบัติการฯ / ขับเคลื่อนงานด้านดิจิทัล) · dir = ผู้อำนวยการอื่น
 *   gov = ข้าราชการอื่น (นักวิชาการ… / เจ้าพนักงาน…) · emp = พนักงานสถิติ · asst = ผู้ช่วยพนักงานสถิติ
 *   driver = พนักงานขับรถ · contract = จ้างเหมาบริการ (ตำแหน่งอื่นทั้งหมด)
 */
function staff_category(?string $position): string
{
    $p = preg_replace('/\s+/u', '', (string)$position);
    return match (true) {
        str_contains($p, 'พนักงานขับรถ')                         => 'driver',
        (bool)preg_match('/^สถิติจังหวัด/u', $p)                 => 'chief',
        str_contains($p, 'ผู้อำนวยการกลุ่มวิชาการสถิติและวางแผน')    => 'dir1',
        str_contains($p, 'ผู้อำนวยการกลุ่มปฏิบัติการสถิติและดิจิทัล')   => 'dir2',
        str_contains($p, 'ผู้อำนวยการกลุ่มขับเคลื่อนงานด้านดิจิทัล')   => 'dir3',
        (bool)preg_match('/^ผู้อำนวยการ/u', $p)                  => 'dir',
        (bool)preg_match('/^ผู้ช่วยพนักงานสถิติ/u', $p)              => 'asst',
        (bool)preg_match('/^พนักงานสถิติ/u', $p)                  => 'emp',
        (bool)preg_match('/^(นักวิชาการ|เจ้าพนักงาน)/u', $p)        => 'gov',
        default                                                => 'contract',
    };
}

/**
 * ลำดับการแสดงบุคลากร: 1 สถิติจังหวัด · 2–4 ผู้อำนวยการกลุ่ม (วิชาการฯ, ปฏิบัติการฯ, ขับเคลื่อนฯ) · 5 ข้าราชการ
 * · 6 พนักงานสถิติ · 7 ผู้ช่วยพนักงานสถิติ · 8 จ้างเหมาบริการ · 9 พนักงานขับรถ — ภายในกลุ่มเรียงตามชื่อผู้ใช้ (ตัวเลข)
 * บัญชีที่ชื่อผู้ใช้ไม่ใช่ตัวเลข (ผู้ดูแลจากภายนอก) อยู่ท้ายสุด
 */
function staff_sort_key(array $u): array
{
    static $rank = ['chief' => 1, 'dir1' => 2, 'dir2' => 3, 'dir3' => 4, 'dir' => 4.5, 'gov' => 5,
                    'emp' => 6, 'asst' => 7, 'contract' => 8, 'driver' => 9];
    $username = (string)($u['username'] ?? '');
    $numeric = preg_match('/^\d+$/', $username) === 1;
    return [$numeric ? 0 : 1, $rank[staff_category($u['position'] ?? '')], $numeric ? (int)$username : PHP_INT_MAX,
            $username, (string)($u['full_name'] ?? '')];
}

/** เรียงรายชื่อบุคลากรตามลำดับตำแหน่ง (คงคีย์เดิมของ array) */
function sort_staff(array $users): array
{
    uasort($users, fn($a, $b) => staff_sort_key($a) <=> staff_sort_key($b));
    return $users;
}
