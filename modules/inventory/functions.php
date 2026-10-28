<?php
/** ฟังก์ชันของระบบวัสดุ (ย้ายมาจาก nsokhkaen_inventory/includes/functions.php) */

// Generate the next running code for materials/assets, e.g. MAT-0001, AST-0001
function generateNextCode(PDO $pdo, string $table, string $column, string $prefix): string
{
    $stmt = $pdo->prepare("SELECT $column FROM $table WHERE $column LIKE :prefix ORDER BY id DESC LIMIT 1");
    $stmt->execute(['prefix' => $prefix . '-%']);
    $last = $stmt->fetchColumn();

    $nextNumber = 1;
    if ($last) {
        $parts = explode('-', $last);
        $nextNumber = (int) end($parts) + 1;
    }

    return $prefix . '-' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
}

// Unique code stored in the qr_code column and used as the lookup key
function generateQrPayload(string $code): string
{
    return $code . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

// Requisition numbers follow Thai government document convention:
// running number / Buddhist-era year, resetting back to 1 each new year
// (e.g. 1/2569, 2/2569, ... then 1/2570 the following year).
function generateRequisitionNo(PDO $pdo): string
{
    $thaiYear = (int) date('Y') + 543;
    $suffix = '/' . $thaiYear;

    $stmt = $pdo->prepare("SELECT requisition_no FROM inv_requisitions
        WHERE requisition_no LIKE :suffix ORDER BY id DESC LIMIT 1");
    $stmt->execute(['suffix' => '%' . $suffix]);
    $last = $stmt->fetchColumn();

    $nextNumber = 1;
    if ($last) {
        $nextNumber = (int) strtok($last, '/') + 1;
    }

    return $nextNumber . $suffix;
}

// Finds (or creates) a category by its exact name, scoped to $itemType
// ('material' or 'asset'), returning its id.
function findOrCreateCategory(PDO $pdo, string $name, string $itemType): int
{
    $stmt = $pdo->prepare('SELECT id FROM inv_categories WHERE name = :name AND item_type = :item_type');
    $stmt->execute(['name' => $name, 'item_type' => $itemType]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $insert = $pdo->prepare('INSERT INTO inv_categories (name, item_type) VALUES (:name, :item_type)');
    $insert->execute(['name' => $name, 'item_type' => $itemType]);
    return (int) $pdo->lastInsertId();
}

// Resolves the category_id to save for a material from its รหัสหมวดวัสดุ
// (category_code, e.g. "14111500" - the office's own material classification
// code). Returns null only when no category_code was given (e.g. a legacy
// item added before this scheme, or imported from a format that doesn't
// carry one).
function resolveMaterialCategoryId(PDO $pdo, ?string $categoryCode): ?int
{
    $categoryCode = trim((string) $categoryCode);
    if ($categoryCode === '') {
        return null;
    }

    return findOrCreateCategory($pdo, $categoryCode, 'material');
}

// Generates the next material_code for $categoryCode: the category code
// itself followed by a 2-digit running number scoped to that category
// (e.g. "14111500" -> "1411150001", "1411150002", ...), per the office's
// own numbering convention. Falls back to the generic MAT-0001 scheme for
// materials with no category_code (manual entries predating this scheme,
// or imported from a format that doesn't carry one).
function generateNextMaterialCode(PDO $pdo, ?string $categoryCode): string
{
    $categoryCode = trim((string) $categoryCode);
    if ($categoryCode === '') {
        return generateNextCode($pdo, 'inv_materials', 'material_code', 'MAT');
    }

    $stmt = $pdo->prepare('SELECT material_code FROM inv_materials
        WHERE material_code LIKE :prefix AND CHAR_LENGTH(material_code) = :len
        ORDER BY material_code DESC LIMIT 1');
    $stmt->execute([
        'prefix' => $categoryCode . '%',
        'len' => strlen($categoryCode) + 2,
    ]);
    $last = $stmt->fetchColumn();

    $nextNumber = 1;
    if ($last !== false) {
        $seq = substr($last, strlen($categoryCode));
        if ($seq !== false && ctype_digit($seq)) {
            $nextNumber = (int) $seq + 1;
        }
    }

    return $categoryCode . str_pad((string) $nextNumber, 2, '0', STR_PAD_LEFT);
}

// Saves an uploaded photo (field $fieldName in $_FILES) under
// uploads/$subfolder/$code.<ext>, replacing any previous image for the
// same $code regardless of its old extension. Returns the path to store in
// the DB (relative to the project root), or null if no file was uploaded.
// Throws InvalidArgumentException on an invalid file.
function handleImageUpload(string $fieldName, string $subfolder, string $code): ?string
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('เกิดข้อผิดพลาดระหว่างอัปโหลดรูปภาพ');
    }

    $maxBytes = 5 * 1024 * 1024;
    if ($_FILES[$fieldName]['size'] > $maxBytes) {
        throw new InvalidArgumentException('ไฟล์รูปภาพต้องมีขนาดไม่เกิน 5MB');
    }

    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($_FILES[$fieldName]['tmp_name']);
    if (!isset($allowedMimes[$mime])) {
        throw new InvalidArgumentException('รองรับเฉพาะไฟล์รูปภาพ JPG, PNG, WEBP หรือ GIF เท่านั้น');
    }
    $ext = $allowedMimes[$mime];

    // เก็บใต้ uploads/inventory/<subfolder>/ ของระบบกลาง
    $dir = APP_ROOT . '/uploads/inventory/' . $subfolder;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    // Remove any previous image for this item (possibly a different extension)
    foreach (glob($dir . '/' . $code . '.*') ?: [] as $existing) {
        unlink($existing);
    }

    $filename = $code . '.' . $ext;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dir . '/' . $filename)) {
        throw new InvalidArgumentException('ไม่สามารถบันทึกไฟล์รูปภาพได้');
    }

    return 'uploads/inventory/' . $subfolder . '/' . $filename;
}

// A user's PIN identifies them when borrowing an asset on the scan page
// without requiring a full system login - no separate "borrower" table,
// any row in `users` can optionally have a pin_hash set (in addition to,
// or instead of, username/password). Stored hashed exactly like
// passwords; since each hash is independently salted, uniqueness of the
// plaintext PIN has to be checked by trying every active user's hash
// rather than by a database constraint.

// Finds the active user whose PIN matches, or null if none does. Used
// both to identify the borrower when adding an asset to a requisition
// cart and to enforce PIN uniqueness when setting/changing a PIN.
function findUserByPin(PDO $pdo, string $pin, ?int $excludeId = null): ?array
{
    return find_user_by_pin($pin, $excludeId);   // ตาราง core_users (ระบบกลาง)
}

// Thai month names ("9 ตุลาคม 2569" / "9 ตุลาคม 2569 14:23 น.") used wherever
// a date needs to read in Thai convention (พ.ศ. year) instead of the raw SQL
// datetime string - printed documents, the dashboard's recent-requisitions
// table, the ใบเบิก list.
function formatThaiDate(string $datetime): string
{
    $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    $ts = strtotime($datetime);
    return (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . ((int) date('Y', $ts) + 543);
}

function formatThaiDateTime(string $datetime): string
{
    $ts = strtotime($datetime);
    return formatThaiDate($datetime) . ' ' . date('H:i', $ts) . ' น.';
}

// The actual content encoded into the printed QR image: a direct link to the
// scan page. Any phone camera app can open this - it does not need our own
// in-page scanner. If the user is not logged in, scan.php requires login
// first and returns here afterwards. APP_URL ตั้งได้ที่เมนูตั้งค่าระบบกลาง
// (สติ๊กเกอร์ที่พิมพ์จากระบบเดิมชี้ไปที่ .../public/scan.php — ดู legacy-redirects/)
function buildQrScanUrl(string $qrCode): string
{
    return APP_URL . 'modules/inventory/scan.php?qr=' . urlencode($qrCode);
}

// Click-to-pick date field used anywhere a date-range filter is needed
// (ใบเบิก, รายงาน) instead of the native <input type="date">, whose
// displayed field order depends on the browser/OS locale and isn't
// reliably วัน-เดือน-ปี, and instead of a day/month/year dropdown triple
// (too many clicks). assets/js/thai-date-select.js turns this input into a
// flatpickr calendar popup: the visible text always reads "9 ตุลาคม 2569"
// (Thai month name, พ.ศ. year) while the field's own .val() stays the
// underlying ISO date (Y-m-d) that the API filters expect - see
// thaiDateSelectGet()/Set() in that file.
function renderThaiDateSelect(string $prefix): void
{
    ?>
    <input type="text" class="form-control thai-datepicker" id="<?= $prefix ?>" placeholder="เลือกวันที่" autocomplete="off" readonly>
    <?php
}

function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeString(?string $value): string
{
    return trim($value ?? '');
}

// Cache-busting query string for a static asset (CSS/JS), based on its
// last-modified time, so browsers fetch the new version immediately after
// a deploy instead of serving a stale cached copy indefinitely.
function assetVersion(string $relativePath): string
{
    $fullPath = INV_DIR . '/' . ltrim($relativePath, '/');   // path สัมพัทธ์จากโฟลเดอร์ระบบวัสดุ
    $mtime = file_exists($fullPath) ? filemtime($fullPath) : time();
    return '?v=' . $mtime;
}
