<?php
/**
 * กล่องจดหมาย: ข้อความระหว่างบุคลากร (core_messages) + ไฟล์แนบ (core_message_files)
 * - เปิดอ่านได้เฉพาะผู้ส่งและผู้รับ · ลบ = ซ่อนจากกล่องของฝ่ายตัวเอง (ลบไฟล์จริงเมื่อทั้งสองฝ่ายลบแล้ว)
 * - ไฟล์แนบเก็บใน uploads/messages (ปิดการเข้าถึงตรง) เปิดผ่าน message_file.php ที่ตรวจสิทธิ์ก่อน
 * - ผู้รับได้รับการแจ้งเตือนที่กระดิ่ง (msg_notifications) จนกว่าจะเปิดอ่าน
 */

const MSG_MAX_FILES = 5;
const MSG_FILE_MAX = 10 * 1024 * 1024;   // ต่อไฟล์
const MSG_FILE_DIR = 'messages';         // ภายใต้ uploads/
/** นามสกุลไฟล์ที่แนบได้ => Content-Type ตอนเปิดไฟล์ */
const MSG_FILE_TYPES = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
];
/** เปิดดูในเบราว์เซอร์ได้ (อื่น ๆ ดาวน์โหลดเสมอ) */
const MSG_INLINE_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

/** จำนวนข้อความที่ยังไม่อ่าน */
function msg_unread_count(int $uid): int
{
    static $cache = [];
    if (!isset($cache[$uid])) {
        try {
            $st = db()->prepare('SELECT COUNT(*) FROM core_messages WHERE recipient_id = ? AND recipient_deleted = 0 AND read_at IS NULL');
            $st->execute([$uid]);
            $cache[$uid] = (int)$st->fetchColumn();
        } catch (PDOException $e) {       // ยังไม่ได้รัน migration
            $cache[$uid] = 0;
        }
    }
    return $cache[$uid];
}

/** ผู้รับที่เลือกได้: เจ้าหน้าที่ (ชื่อผู้ใช้เป็นตัวเลข) ที่เปิดใช้งาน ยกเว้นตัวเอง — เรียงตามลำดับบุคลากร */
function msg_recipients(int $exceptId): array
{
    $st = db()->prepare("SELECT id, username, sort_order, full_name, nickname, position, photo FROM core_users
                         WHERE is_active = 1 AND username REGEXP '^[0-9]+$' AND id <> ?");
    $st->execute([$exceptId]);
    return array_values(sort_staff($st->fetchAll()));
}

/** ข้อความที่ผู้ใช้มีสิทธิ์เปิด (เป็นผู้ส่งหรือผู้รับ และยังไม่ได้ลบจากกล่องของตน) พร้อมข้อมูลผู้ส่ง/ผู้รับ */
function msg_get(int $id, int $uid): ?array
{
    $st = db()->prepare('SELECT m.*, s.full_name AS sender_name, s.position AS sender_position, s.photo AS sender_photo,
                                r.full_name AS recipient_name, r.position AS recipient_position, r.photo AS recipient_photo
                         FROM core_messages m JOIN core_users s ON s.id = m.sender_id JOIN core_users r ON r.id = m.recipient_id
                         WHERE m.id = ? AND ((m.recipient_id = ? AND m.recipient_deleted = 0) OR (m.sender_id = ? AND m.sender_deleted = 0))');
    $st->execute([$id, $uid, $uid]);
    return $st->fetch() ?: null;
}

function msg_files(int $messageId): array
{
    $st = db()->prepare('SELECT id, file_name, file_size FROM core_message_files WHERE message_id = ? ORDER BY id');
    $st->execute([$messageId]);
    return $st->fetchAll();
}

/** ตรวจไฟล์แนบก่อนส่ง — คืนรายการไฟล์ที่เลือกจริง หรือโยน RuntimeException */
function msg_check_files(array $files): array
{
    $files = array_values(array_filter($files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (count($files) > MSG_MAX_FILES) {
        throw new RuntimeException('แนบไฟล์ได้ไม่เกิน ' . MSG_MAX_FILES . ' ไฟล์');
    }
    foreach ($files as $f) {
        $name = (string)$f['name'];
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > MSG_FILE_MAX) {
            throw new RuntimeException('ไฟล์ "' . $name . '" ใหญ่เกิน ' . (MSG_FILE_MAX / 1048576) . ' MB');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('อัปโหลดไฟล์ "' . $name . '" ไม่สำเร็จ (รหัส ' . $f['error'] . ')');
        }
        if (!isset(MSG_FILE_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
            throw new RuntimeException('ไม่รองรับไฟล์ "' . $name . '" (แนบได้: ' . implode(', ', array_keys(MSG_FILE_TYPES)) . ')');
        }
    }
    return $files;
}

/** บันทึกไฟล์แนบ (ตรวจแล้วด้วย msg_check_files) — ชื่อไฟล์บนเซิร์ฟเวอร์สุ่มใหม่ เก็บชื่อเดิมไว้แสดง */
function msg_save_files(int $messageId, array $files): void
{
    $sub = MSG_FILE_DIR . '/' . date('Ym');
    $dir = UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ uploads/' . $sub);
    }
    if (!is_file(UPLOAD_DIR . '/' . MSG_FILE_DIR . '/.htaccess')) {      // ห้ามเปิดไฟล์ตรงจาก URL
        @file_put_contents(UPLOAD_DIR . '/' . MSG_FILE_DIR . '/.htaccess', "Require all denied\n");
    }
    $ins = db()->prepare('INSERT INTO core_message_files (message_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?)');
    foreach ($files as $f) {
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ ตรวจสอบสิทธิ์การเขียนโฟลเดอร์ uploads');
        }
        $orig = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]/u', '_', basename((string)$f['name'])), 0, 200);
        $ins->execute([$messageId, $sub . '/' . $name, $orig ?: $name, (int)$f['size']]);
    }
}

/** ลบไฟล์แนบจริง (เรียกเมื่อทั้งผู้ส่งและผู้รับลบข้อความแล้ว) */
function msg_purge(int $messageId): void
{
    $st = db()->prepare('SELECT file_path FROM core_message_files WHERE message_id = ?');
    $st->execute([$messageId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
        delete_upload($p);
    }
    db()->prepare('DELETE FROM core_messages WHERE id = ?')->execute([$messageId]);
}

/** เนื้อความ: ป้องกัน HTML + ลิงก์กดได้ + ขึ้นบรรทัดใหม่ */
function msg_body_html(string $text): string
{
    $html = preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)\]\'"])~u', '<a href="$1" target="_blank" rel="noopener">$1</a>', h($text));
    return nl2br($html);
}

function msg_file_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
}

function msg_file_icon(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match (true) {
        $ext === 'pdf' => 'bi-file-earmark-pdf text-danger',
        in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => 'bi-file-earmark-image text-info',
        in_array($ext, ['doc', 'docx'], true) => 'bi-file-earmark-word text-primary',
        in_array($ext, ['xls', 'xlsx', 'csv'], true) => 'bi-file-earmark-excel text-success',
        in_array($ext, ['ppt', 'pptx'], true) => 'bi-file-earmark-ppt text-warning',
        in_array($ext, ['zip', 'rar', '7z'], true) => 'bi-file-earmark-zip text-secondary',
        default => 'bi-file-earmark-text text-secondary',
    };
}

/** การแจ้งเตือนที่กระดิ่ง: ข้อความที่ยังไม่อ่าน (เรียกจาก core/notifications.php) */
function msg_notifications(array $user): array
{
    $st = db()->prepare('SELECT m.id, m.subject, m.created_at, s.full_name,
                                (SELECT COUNT(*) FROM core_message_files f WHERE f.message_id = m.id) AS nfiles
                         FROM core_messages m JOIN core_users s ON s.id = m.sender_id
                         WHERE m.recipient_id = ? AND m.recipient_deleted = 0 AND m.read_at IS NULL
                         ORDER BY m.id DESC LIMIT 15');
    $st->execute([$user['id']]);
    $out = [];
    foreach ($st as $m) {
        $out[] = ['title' => 'ข้อความใหม่จาก ' . preg_replace('/\s+/u', ' ', $m['full_name']), 'icon' => 'bi-envelope-fill', 'color' => 'primary',
                  'text' => mb_strimwidth($m['subject'], 0, 80, '…') . ($m['nfiles'] ? ' · แนบ ' . $m['nfiles'] . ' ไฟล์' : ''),
                  'href' => url('message_view.php?id=' . $m['id']), 'time' => $m['created_at'], 'module' => 'messages'];
    }
    return $out;
}
