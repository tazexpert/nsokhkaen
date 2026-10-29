<?php
/**
 * ระบบสลิปเงินเดือนพนักงาน: ค่าคงที่และฟังก์ชันที่ใช้ร่วมกัน
 *   สิทธิ์แยกหน้าที่: employee (ดูสลิปของตนเอง) · manager (นำเข้า/จัดการสลิป) · approver (อนุมัติ) · admin (ทุกอย่าง)
 *   พนักงานเห็นเฉพาะสลิปที่ "อนุมัติแล้ว" ของรหัสพนักงานที่ผูกกับบัญชีตนเอง (pay_employees.user_id)
 */

const PAY_MODULE = 'payslip';

/** รายการในสลิป: คอลัมน์ => ชื่อที่แสดง */
const PAY_INCOME = ['salary' => 'อัตราเงินเดือน', 'arrears' => 'เงินตกเบิก', 'cola' => 'ค่าครองชีพชั่วคราว'];
const PAY_DEDUCT = ['tax' => 'ภาษี', 'social_security' => 'เงินประกันสังคม', 'loan_welfare' => 'เงินกู้สวัสดิการ',
                    'loan_ghb' => 'เงินกู้ ธอส.', 'loan_slf' => 'เงินกู้ กยศ.', 'loan_islamic' => 'เงินกู้ธนาคารอิสลาม'];
const PAY_TOTALS = ['total_income' => 'รวมรายได้', 'total_deduct' => 'รวมรายจ่าย', 'net_pay' => 'เงินเดือนคงเหลือ'];

/** รายการที่แสดงเสมอแม้เป็น 0 (แบบสลิปเดิม) — ค่าครองชีพแสดงเฉพาะเมื่อมีค่า */
const PAY_ALWAYS_SHOW = ['salary', 'arrears', 'tax', 'social_security', 'loan_welfare', 'loan_ghb', 'loan_slf', 'loan_islamic'];

const PAY_SETTING_DEFAULTS = [];
/** ผู้ลงนามเมื่อยังไม่ได้ตั้งค่า */
const PAY_DEFAULT_SIGNER = ['id' => 0, 'effective_ym' => 0, 'name' => 'นายสมศักดิ์  แก้วสอน', 'position' => 'สถิติจังหวัดขอนแก่น', 'signature_path' => null];
const PAY_PRIVATE_DIR = 'payslip';     // uploads/payslip (ปิดการเข้าถึงตรง — เปิดผ่าน signature.php)

function pay_can(string $role): bool
{
    return user_can(PAY_MODULE, $role);
}

/**
 * สิทธิ์แยกหน้าที่: ผู้ใช้มี role ใด role หนึ่งใน $roles หรือเป็นผู้ดูแลระบบสลิป (admin)
 *   รออนุมัติ (ดู/อนุมัติ)   = approver, admin
 *   จัดการสลิป / นำเข้าข้อมูล / รายชื่อพนักงาน = manager, admin
 *   ตั้งค่าสลิป              = admin
 */
function pay_is(string ...$roles): bool
{
    $r = module_role(PAY_MODULE);
    return $r === 'admin' || in_array($r, $roles, true);
}

/** หน้าที่ต้องมีสิทธิ์ตามหน้าที่ — ไม่มีสิทธิ์ = 403 */
function pay_require(string ...$roles): array
{
    $me = require_module(PAY_MODULE);
    if (!pay_is(...$roles)) {
        forbidden(PAY_MODULE, $roles[0] ?? 'admin');
    }
    return $me;
}

function pay_settings(): array
{
    $s = PAY_SETTING_DEFAULTS;
    foreach (db()->query('SELECT `key`, `value` FROM pay_settings') as $r) {
        $s[$r['key']] = $r['value'];
    }
    return $s;
}

function pay_save_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO pay_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute([$key, $value]);
}

/**
 * ผู้ลงนามของสลิปเดือนนั้น: ผู้ที่ "เริ่มตั้งแต่" ล่าสุดไม่เกินเดือนของสลิป
 * (สลิปเดือนเก่ายังแสดงผู้ลงนามเดิม แม้เปลี่ยนผู้ลงนามแล้ว)
 */
function pay_signer_for(int $year, int $month): array
{
    static $all = null;
    $all ??= db()->query('SELECT * FROM pay_signers ORDER BY effective_ym DESC')->fetchAll();
    $ym = $year * 100 + $month;
    foreach ($all as $sg) {
        if ((int)$sg['effective_ym'] <= $ym) {
            return $sg;
        }
    }
    return $all ? end($all) : PAY_DEFAULT_SIGNER;      // ก่อนผู้ลงนามคนแรก → ใช้คนแรก
}

/** ข้อความช่วงที่ผู้ลงนามมีผล */
function pay_signer_from(int $ym): string
{
    return $ym === 0 ? 'ตั้งแต่เริ่มต้น' : 'ตั้งแต่ ' . pay_period(intdiv($ym, 100), $ym % 100);
}

/** รหัสพนักงานที่ผูกกับผู้ใช้ (null = ไม่ได้อยู่ในรายชื่อพนักงาน) */
function pay_employee_of(int $userId): ?array
{
    $st = db()->prepare('SELECT * FROM pay_employees WHERE user_id = ? AND is_active = 1');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

/** ผู้ใช้ปัจจุบันดูสลิปนี้ได้หรือไม่: ผู้จัดการ/ผู้อนุมัติ = ทุกใบ, พนักงาน = ของตนเองที่อนุมัติแล้ว */
function pay_can_view(array $slip): bool
{
    if (pay_is('approver', 'manager')) {    // ผู้อนุมัติ/ผู้จัดการสลิป/ผู้ดูแล ดูได้ทุกใบ
        return true;
    }
    $me = current_user();
    $emp = $me ? pay_employee_of($me['id']) : null;
    return $emp && (int)$emp['id'] === (int)$slip['employee_id'] && $slip['status'] === 'approved' && pay_can('employee');
}

function pay_month_name(int $m): string
{
    return ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม',
            'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'][$m] ?? (string)$m;
}

function pay_period(int $year, int $month): string
{
    return pay_month_name($month) . ' ' . $year;
}

/** แสดงจำนวนเงิน: 0/ว่าง = "-" */
function pay_money($v): string
{
    return ($v === null || (float)$v == 0.0) ? '-' : number_format((float)$v, 2);
}

/** แปลงค่าจากไฟล์เป็นตัวเลข: "-", "", "1,234.50" → null / 1234.5 */
function pay_num($v): ?float
{
    if ($v === null) {
        return null;
    }
    $v = trim(str_replace([',', ' ', '฿'], '', (string)$v));
    if ($v === '' || $v === '-' || !is_numeric($v)) {
        return null;
    }
    return round((float)$v, 2);
}

/** เติมยอดรวมที่ว่างด้วยการคำนวณ */
function pay_fill_totals(array $row): array
{
    $sum = fn(array $keys) => array_sum(array_map(fn($k) => (float)($row[$k] ?? 0), $keys));
    $row['total_income'] ??= $sum(array_keys(PAY_INCOME));
    $row['total_deduct'] ??= $sum(array_keys(PAY_DEDUCT));
    $row['net_pay'] ??= (float)$row['total_income'] - (float)$row['total_deduct'];
    return $row;
}

/** ชื่อสำหรับเทียบ (ตัดช่องว่างทั้งหมด) */
function pay_norm_name(string $s): string
{
    return preg_replace('/\s+/u', '', $s);
}

/**
 * ผูกรหัสพนักงานกับผู้ใช้ และให้สิทธิ์ "พนักงาน" ในระบบนี้ถ้ายังไม่มีสิทธิ์ใด
 * ($userId = null → ยกเลิกการผูก)
 */
function pay_link_user(int $employeeId, ?int $userId): void
{
    $pdo = db();
    if ($userId) {
        $pdo->prepare('UPDATE pay_employees SET user_id = NULL WHERE user_id = ? AND id <> ?')->execute([$userId, $employeeId]);
    }
    $pdo->prepare('UPDATE pay_employees SET user_id = ? WHERE id = ?')->execute([$userId, $employeeId]);
    if ($userId) {
        $pdo->prepare("INSERT IGNORE INTO core_user_roles (user_id, module_key, role) VALUES (?, ?, 'employee')")
            ->execute([$userId, PAY_MODULE]);
    }
}

/** จับคู่พนักงานที่ยังไม่ผูก กับผู้ใช้ที่ชื่อ-นามสกุลตรงกัน คืนจำนวนที่ผูกได้ */
function pay_auto_link(): int
{
    $pdo = db();
    $users = [];
    foreach ($pdo->query('SELECT id, full_name FROM core_users WHERE is_active = 1') as $u) {
        $users[pay_norm_name($u['full_name'])][] = (int)$u['id'];
    }
    $taken = array_flip($pdo->query('SELECT user_id FROM pay_employees WHERE user_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
    $n = 0;
    foreach ($pdo->query('SELECT id, full_name FROM pay_employees WHERE user_id IS NULL')->fetchAll() as $e) {
        $ids = $users[pay_norm_name($e['full_name'])] ?? [];
        if (count($ids) === 1 && !isset($taken[$ids[0]])) {      // ชื่อซ้ำหลายบัญชี = ไม่ผูกอัตโนมัติ
            pay_link_user((int)$e['id'], $ids[0]);
            $taken[$ids[0]] = true;
            $n++;
        }
    }
    return $n;
}

/** โฟลเดอร์ไฟล์ส่วนตัวของระบบ (ปิดการเข้าถึงตรงด้วย .htaccess) */
function pay_private_dir(): string
{
    $dir = UPLOAD_DIR . '/' . PAY_PRIVATE_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

/** สลิป 1 ใบ (HTML) ตามรูปแบบ "ใบแสดงรายการหักเงินเดือน" เดิม */
function pay_render_slip(array $s, string $code): string
{
    $sg = pay_signer_for((int)$s['year'], (int)$s['month']);
    $line = function (int $n, string $label, $value, bool $bold = false) {
        return '<tr' . ($bold ? ' class="fw-bold"' : '') . '><td>' . ($n ? $n . '. ' : '') . h($label) . '</td>'
            . '<td class="amt">' . pay_money($value) . '</td><td class="unit">บาท</td></tr>';
    };
    $rows = '';
    $n = 0;
    foreach (PAY_INCOME as $k => $label) {
        if (in_array($k, PAY_ALWAYS_SHOW, true) || (float)$s[$k] != 0.0) {
            $rows .= $line(++$n, $label, $s[$k]);
        }
    }
    $income = $rows . $line(0, 'รวมรายการรับ', $s['total_income'], true);
    $rows = '';
    $n = 0;
    foreach (PAY_DEDUCT as $k => $label) {
        if (in_array($k, PAY_ALWAYS_SHOW, true) || (float)$s[$k] != 0.0) {
            $rows .= $line(++$n, $label, $s[$k]);
        }
    }
    $deduct = $rows . $line(0, 'รวมรายจ่าย', $s['total_deduct'], true);
    $approved = $s['status'] === 'approved';
    $sig = $approved && !empty($sg['signature_path'])
        ? '<img src="' . h(module_url(PAY_MODULE, 'signature.php?id=' . (int)$sg['id'] . '&v=' . substr(md5($sg['signature_path']), 0, 8))) . '" alt="ลายมือชื่อ" class="sig-img">'
        : '<span class="sig-line"></span>';
    return '<div class="payslip">'
        . '<div class="ps-no">เลขที่ ' . h($code) . '</div>'
        . '<div class="ps-head"><div>' . h(ORG_NAME) . '</div><div class="ps-title">ใบแสดงรายการหักเงินเดือน</div>'
        . '<div>ประจำเดือน ' . h(pay_month_name((int)$s['month'])) . ' พ.ศ. ' . (int)$s['year'] . '</div>'
        . '<div>ชื่อ ' . h($s['full_name']) . '</div></div>'
        . '<table class="ps-table"><tr><th colspan="3">รายการรับ</th></tr>' . $income
        . '<tr><th colspan="3" class="pt-3">รายการหัก</th></tr>' . $deduct
        . '<tr class="ps-net"><td>คงเหลือเงินรับสุทธิ</td><td class="amt">' . pay_money($s['net_pay']) . '</td><td class="unit">บาท</td></tr></table>'
        . '<div class="ps-sign"><div class="d-flex align-items-end justify-content-center gap-2"><span>ลงชื่อ</span>' . $sig . '</div>'
        . '<div>(' . h($sg['name']) . ')</div><div>' . nl2br(h((string)$sg['position'])) . '</div></div>'
        . (!$approved ? '<div class="ps-draft">ยังไม่อนุมัติ</div>' : '')
        . '</div>';
}
