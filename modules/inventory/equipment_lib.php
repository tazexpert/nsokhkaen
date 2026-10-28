<?php
/**
 * ครุภัณฑ์และการยืม-คืน: ค่าคงที่และฟังก์ชันที่ใช้ร่วมกัน
 *   inv_equipment.status = สภาพการใช้งาน (normal / repair / dispose)
 *   การถูกยืม = มีรายการใน inv_loan_items ของใบยืมสถานะ borrowed (การขออนุมัติยืมทำในระบบ e-office
 *   ระบบนี้บันทึกผลการยืม แนบไฟล์ใบยืม และอัปเดตสถานะเมื่อคืน)
 */

const EQ_STATUS = [
    'normal'  => ['ใช้งานตามปกติ', 'success', 'bi-check-circle'],
    'repair'  => ['เสียรอซ่อม', 'warning', 'bi-tools'],
    'dispose' => ['รอจำหน่าย', 'secondary', 'bi-archive'],
];

const LOAN_STATUS = [
    'borrowed'  => ['กำลังยืม', 'primary'],
    'returned'  => ['คืนแล้ว', 'success'],
    'pending'   => ['รออนุมัติ', 'warning'],      // สถานะเดิม (ไม่ใช้แล้ว — อนุมัติผ่าน e-office)
    'rejected'  => ['ไม่อนุมัติ', 'danger'],
    'cancelled' => ['ยกเลิก', 'secondary'],
];

/** ไฟล์ใบยืมที่แนบ: ชนิดที่รับ [mime => นามสกุล] และขนาดสูงสุด */
const LOAN_FILE_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const LOAN_FILE_MAX = 10 * 1024 * 1024;
const LOAN_FILE_DIR = 'inventory/loans';      // ภายใต้ uploads/ (ปิดการเข้าถึงตรง — เปิดผ่าน loan_file.php)

function eq_status_badge(string $status): string
{
    [$label, $color, $icon] = EQ_STATUS[$status] ?? [$status, 'light', 'bi-question'];
    return '<span class="badge text-bg-' . $color . '"><i class="bi ' . $icon . '"></i> ' . h($label) . '</span>';
}

function loan_status_badge(string $status): string
{
    [$label, $color] = LOAN_STATUS[$status] ?? [$status, 'light'];
    return '<span class="badge text-bg-' . $color . '">' . h($label) . '</span>';
}

/** อายุการใช้งาน (ปี) = ปี พ.ศ. ปัจจุบัน - ปีงบที่ได้มา */
function equipment_age(?int $fiscalYear): ?int
{
    return $fiscalYear ? max(0, (int)date('Y') + 543 - $fiscalYear) : null;
}

/** เลขที่ใบยืม: เลขรัน/ปี พ.ศ. เริ่ม 1 ใหม่ทุกปี (แบบเดียวกับใบเบิก) */
function generate_loan_no(PDO $pdo): string
{
    $suffix = '/' . ((int)date('Y') + 543);
    $st = $pdo->prepare('SELECT loan_no FROM inv_loans WHERE loan_no LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $st->execute(['%' . $suffix]);
    $last = $st->fetchColumn();
    return ($last ? (int)strtok($last, '/') + 1 : 1) . $suffix;
}

/**
 * ใบยืมที่ยังไม่ปิดของครุภัณฑ์แต่ละชิ้น [equipment_id => [loan_id, loan_no, status, borrower_name, due_date]]
 * ($ids ว่าง = ทั้งหมด)
 */
function active_loans_by_equipment(array $ids = []): array
{
    $sql = "SELECT li.equipment_id, l.id AS loan_id, l.loan_no, l.status, l.borrower_name, l.due_date
            FROM inv_loan_items li JOIN inv_loans l ON l.id = li.loan_id
            WHERE li.equipment_id IS NOT NULL AND l.status = 'borrowed'";
    if ($ids) {
        $sql .= ' AND li.equipment_id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
    $out = [];
    foreach (db()->query($sql . ' ORDER BY l.id') as $r) {
        $out[(int)$r['equipment_id']] = $r;
    }
    return $out;
}

/** ข้อความแสดงครุภัณฑ์ 1 ชิ้น เช่น "โน้ตบุ๊ค (Notebook) HP ProBook 450" */
function equipment_label(array $e): string
{
    return trim(($e['type_name'] ?? '') . ' ' . ($e['brand'] ?? '') . ' ' . ($e['model'] ?? ''));
}

/** วันที่แบบเต็ม เช่น 1 ตุลาคม 2569 */
function thai_date_full(?string $date): string
{
    if (!$date || ($ts = strtotime($date)) === false) {
        return '';
    }
    return (int)date('j', $ts) . ' ' . thai_month_name((int)date('n', $ts)) . ' ' . ((int)date('Y', $ts) + 543);
}

function thai_month_name(int $m): string
{
    return ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม',
            'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'][$m] ?? '';
}

/** แปลงตัวเลขอารบิกเป็นเลขไทย (ใช้ในเอกสารราชการ) */
function thai_digits(string|int|null $s): string
{
    return strtr((string)$s, ['0' => '๐', '1' => '๑', '2' => '๒', '3' => '๓', '4' => '๔',
                               '5' => '๕', '6' => '๖', '7' => '๗', '8' => '๘', '9' => '๙']);
}

/** ประเภทครุภัณฑ์ทั้งหมด [id => name] */
function equipment_types(): array
{
    return db()->query('SELECT id, name FROM inv_equipment_types ORDER BY sort_order, name')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** หา/สร้างประเภทครุภัณฑ์ตามชื่อ */
function equipment_type_id(PDO $pdo, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM inv_equipment_types WHERE name = ?');
    $st->execute([$name]);
    if ($id = $st->fetchColumn()) {
        return (int)$id;
    }
    $pdo->prepare('INSERT INTO inv_equipment_types (name, sort_order) SELECT ?, COALESCE(MAX(sort_order), 0) + 1 FROM inv_equipment_types')->execute([$name]);
    return (int)$pdo->lastInsertId();
}

/** ใบยืมนี้ผู้ใช้ปัจจุบันดูได้หรือไม่ (ผู้ดูแล = ทุกใบ, เจ้าหน้าที่ = ที่ตนเป็นผู้ยืม/ผู้บันทึก) */
function can_view_loan(array $loan): bool
{
    $me = current_user();
    return user_can(INV_MODULE, 'admin') || (int)$loan['borrower_id'] === $me['id'] || (int)$loan['created_by'] === $me['id'];
}

/**
 * บันทึกไฟล์ใบยืมที่อัปโหลด (PDF/JPG/PNG/WebP ไม่เกิน 10 MB) คืน [path สัมพัทธ์จาก uploads/, ชื่อไฟล์เดิม]
 * โยน RuntimeException ถ้าไฟล์ไม่ถูกต้อง; คืน null ถ้าไม่ได้เลือกไฟล์
 */
function save_loan_file(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('อัปโหลดไฟล์ใบยืมไม่สำเร็จ (รหัส ' . $file['error'] . ')');
    }
    if ($file['size'] > LOAN_FILE_MAX) {
        throw new RuntimeException('ไฟล์ใบยืมใหญ่เกิน ' . (LOAN_FILE_MAX / 1048576) . ' MB');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(LOAN_FILE_TYPES[$mime])) {
        throw new RuntimeException('ไฟล์ใบยืมต้องเป็น PDF หรือรูปภาพ (JPG, PNG, WebP)');
    }
    $dir = UPLOAD_DIR . '/' . LOAN_FILE_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ uploads/' . LOAN_FILE_DIR);
    }
    if (!is_file($dir . '/.htaccess')) {           // ห้ามเปิดไฟล์ตรงจาก URL (ต้องผ่านการตรวจสิทธิ์)
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . LOAN_FILE_TYPES[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ ตรวจสอบสิทธิ์การเขียนโฟลเดอร์ uploads');
    }
    $orig = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]/u', '_', basename((string)$file['name'])), 0, 200);
    return [LOAN_FILE_DIR . '/' . $name, $orig ?: $name];
}
