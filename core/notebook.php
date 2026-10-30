<?php
/**
 * สมุดบันทึกส่วนตัว: เข้ารหัส/ถอดรหัสเนื้อหา (AES-256-GCM)
 * - กุญแจหลัก: ค่าคงที่ NOTEBOOK_KEY (base64 ของ 32 ไบต์) ใน config.local.php ถ้ามี
 *   ไม่เช่นนั้นสร้างอัตโนมัติครั้งแรกที่ storage/notebook_key.php (ไม่อยู่ใน git · เป็นไฟล์ .php จึงเปิดผ่านเว็บไม่เห็นค่า)
 * - แต่ละคนใช้กุญแจย่อยของตัวเอง (HMAC จากกุญแจหลัก + id ผู้ใช้) → ข้อความในฐานข้อมูลอ่านไม่ออก และสลับแถวข้ามคนไม่ได้
 * - ถ้ากุญแจหลักหาย จะเปิดบันทึกเดิมไม่ได้อีก → ต้องสำรองไฟล์กุญแจไว้คู่กับการสำรองฐานข้อมูล (แต่เก็บแยกที่กัน)
 */

const NOTEBOOK_KEY_FILE = APP_ROOT . '/storage/notebook_key.php';

function notebook_master_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('เซิร์ฟเวอร์ไม่ได้เปิดใช้ PHP openssl (php.ini: extension=openssl)');
    }
    if (defined('NOTEBOOK_KEY')) {
        $raw = base64_decode((string)NOTEBOOK_KEY, true);
    } else {
        if (!is_file(NOTEBOOK_KEY_FILE)) {
            if (!is_dir(dirname(NOTEBOOK_KEY_FILE))) {
                mkdir(dirname(NOTEBOOK_KEY_FILE), 0700, true);
            }
            $fh = @fopen(NOTEBOOK_KEY_FILE, 'x');      // 'x' = สร้างใหม่เท่านั้น (กันสองคำขอสร้างกุญแจทับกัน)
            if ($fh) {
                fwrite($fh, "<?php\n// กุญแจเข้ารหัสสมุดบันทึก — ห้ามลบ/แก้ไข และต้องสำรองไว้ (ถ้าหาย บันทึกเดิมจะเปิดไม่ได้)\nreturn '"
                    . base64_encode(random_bytes(32)) . "';\n");
                fclose($fh);
            }
        }
        $raw = base64_decode((string)(include NOTEBOOK_KEY_FILE), true);
    }
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('กุญแจเข้ารหัสสมุดบันทึกไม่ถูกต้อง');
    }
    return $key = $raw;
}

function notebook_user_key(int $userId): string
{
    return hash_hmac('sha256', 'notebook-user:' . $userId, notebook_master_key(), true);
}

function notebook_encrypt(string $plain, int $userId): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', notebook_user_key($userId), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('เข้ารหัสไม่สำเร็จ');
    }
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

/** คืน null ถ้าถอดรหัสไม่ได้ (กุญแจไม่ตรง / ข้อมูลเสียหาย) */
function notebook_decrypt(string $stored, int $userId): ?string
{
    if (strncmp($stored, 'v1:', 3) !== 0 || ($bin = base64_decode(substr($stored, 3), true)) === false || strlen($bin) < 28) {
        return null;
    }
    $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', notebook_user_key($userId), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
    return $plain === false ? null : $plain;
}
