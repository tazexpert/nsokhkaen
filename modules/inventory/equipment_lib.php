<?php
/**
 * ครุภัณฑ์และการยืม-คืน: ค่าคงที่และฟังก์ชันที่ใช้ร่วมกัน
 *   inv_equipment.status = สภาพการใช้งาน (normal / repair / dispose)
 *   การถูกยืม = มีรายการใน inv_loan_items ของใบยืมที่สถานะ pending (จองไว้) หรือ borrowed (ยืมอยู่)
 */

const EQ_STATUS = [
    'normal'  => ['ใช้งานตามปกติ', 'success', 'bi-check-circle'],
    'repair'  => ['เสียรอซ่อม', 'warning', 'bi-tools'],
    'dispose' => ['รอจำหน่าย', 'secondary', 'bi-archive'],
];

const LOAN_STATUS = [
    'pending'   => ['รออนุมัติ', 'warning'],
    'borrowed'  => ['กำลังยืม', 'primary'],
    'returned'  => ['คืนแล้ว', 'success'],
    'rejected'  => ['ไม่อนุมัติ', 'danger'],
    'cancelled' => ['ยกเลิก', 'secondary'],
];

/** ผู้รับบันทึกข้อความขออนุมัติยืมพัสดุ (คงที่) */
const LOAN_TO = 'ผู้ว่าราชการจังหวัดขอนแก่น';

/**
 * ค่าตั้งค่าใบยืม (inv_settings) พร้อมค่าเริ่มต้น
 *   ผู้ให้ยืม/ผู้อนุมัติ: เลือกจากผู้ใช้งานในหน้าตั้งค่า (ตำแหน่งแก้ไขได้) — ผู้ให้ยืมใช้เป็นผู้รับคืนด้วย
 */
const LOAN_SETTING_DEFAULTS = [
    'loan_org_phone'         => '0-4324-6639',
    'loan_org_fax'           => '0-4324-6183',
    'loan_lender_name'       => '',
    'loan_lender_position'   => '',
    'loan_approver_name'     => '',
    'loan_approver_position' => '',
];

function loan_settings(): array
{
    $s = LOAN_SETTING_DEFAULTS;
    $st = db()->query("SELECT `key`, `value` FROM inv_settings WHERE `key` IN ('" . implode("','", array_keys(LOAN_SETTING_DEFAULTS)) . "')");
    foreach ($st as $r) {
        $s[$r['key']] = $r['value'];
    }
    return $s;
}

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
            WHERE li.equipment_id IS NOT NULL AND l.status IN ('pending', 'borrowed')";
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

/** ผู้ให้ยืม/ผู้รับคืน: ตามที่ตั้งค่าไว้ ถ้าไม่ได้ตั้ง ใช้ผู้ดูแลที่ทำรายการ [ชื่อ, ตำแหน่ง] */
function loan_lender(array $me): array
{
    $ls = loan_settings();
    return $ls['loan_lender_name'] !== ''
        ? [$ls['loan_lender_name'], $ls['loan_lender_position'] ?: null]
        : [$me['full_name'], $me['position']];
}

/** ใบยืมนี้ผู้ใช้ปัจจุบันดูได้หรือไม่ (ผู้ดูแล = ทุกใบ, เจ้าหน้าที่ = ที่ตนเป็นผู้ยืม/ผู้บันทึก) */
function can_view_loan(array $loan): bool
{
    $me = current_user();
    return user_can(INV_MODULE, 'admin') || (int)$loan['borrower_id'] === $me['id'] || (int)$loan['created_by'] === $me['id'];
}
