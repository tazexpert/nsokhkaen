<?php
/**
 * แชร์ข้อความ (core_shares / core_share_users / core_share_files)
 * - บุคลากรทุกคนโพสต์ข้อความ (+ ไฟล์แนบ) แล้วเลือกผู้เห็น: ทุกคน หรือเลือกรายบุคคล/กลุ่มงาน
 * - เห็นได้เฉพาะ ผู้แชร์ · ผู้รับที่เลือก · ทุกคน (audience = all) — ผู้รับดูอย่างเดียว
 * - ผู้แชร์แก้ไข/ลบโพสต์ของตนได้ · ไฟล์แนบเก็บใน uploads/shares (ปิดการเปิดตรง) เปิดผ่าน share_file.php
 * - ผู้รับได้รับการแจ้งเตือนที่กระดิ่ง 14 วัน (share_notifications)
 * ไฟล์แนบใช้ชนิด/ขนาด/การตรวจเดียวกับกล่องจดหมาย (core/messages.php)
 */

const SHARE_FILE_DIR = 'shares';         // ภายใต้ uploads/

/** เงื่อนไข SQL: โพสต์ที่ผู้ใช้เห็นได้ (ใช้กับ alias s) */
function share_visible_sql(int $uid): array
{
    return ['(s.user_id = ? OR s.audience = \'all\' OR EXISTS (SELECT 1 FROM core_share_users su WHERE su.share_id = s.id AND su.user_id = ?))', [$uid, $uid]];
}

/** โพสต์ 1 รายการที่ผู้ใช้เห็นได้ (พร้อมข้อมูลผู้แชร์) */
function share_get(int $id, int $uid): ?array
{
    [$vis, $p] = share_visible_sql($uid);
    $st = db()->prepare("SELECT s.*, u.full_name, u.nickname, u.position, u.photo FROM core_shares s JOIN core_users u ON u.id = s.user_id
                         WHERE s.id = ? AND $vis");
    $st->execute([$id, ...$p]);
    return $st->fetch() ?: null;
}

/** บุคลากรที่เลือกเป็นผู้รับได้ (ชื่อผู้ใช้เป็นตัวเลข เปิดใช้งาน) พร้อมกลุ่มงาน — เรียงตามลำดับบุคลากร */
function share_staff(int $exceptId): array
{
    $st = db()->prepare("SELECT u.id, u.username, u.sort_order, u.full_name, u.nickname, u.position, u.photo, u.work_group_id, g.name AS work_group
                         FROM core_users u LEFT JOIN core_work_groups g ON g.id = u.work_group_id
                         WHERE u.is_active = 1 AND u.username REGEXP '^[0-9]+$' AND u.id <> ?");
    $st->execute([$exceptId]);
    return array_values(sort_staff($st->fetchAll()));
}

/** ผู้รับของโพสต์ [user_id, ...] */
function share_user_ids(int $shareId): array
{
    $st = db()->prepare('SELECT user_id FROM core_share_users WHERE share_id = ?');
    $st->execute([$shareId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** ไฟล์แนบของหลายโพสต์ [share_id => [file, ...]] */
function share_files(array $shareIds): array
{
    $out = [];
    if (!$shareIds) {
        return $out;
    }
    $in = implode(',', array_map('intval', $shareIds));
    foreach (db()->query("SELECT id, share_id, file_name, file_size FROM core_share_files WHERE share_id IN ($in) ORDER BY id") as $f) {
        $out[(int)$f['share_id']][] = $f;
    }
    return $out;
}

/** บันทึกไฟล์แนบ (ตรวจแล้วด้วย msg_check_files) — ชื่อไฟล์บนเซิร์ฟเวอร์สุ่มใหม่ เก็บชื่อเดิมไว้แสดง */
function share_save_files(int $shareId, array $files): void
{
    $sub = SHARE_FILE_DIR . '/' . date('Ym');
    $dir = UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์ uploads/' . $sub);
    }
    if (!is_file(UPLOAD_DIR . '/' . SHARE_FILE_DIR . '/.htaccess')) {    // ห้ามเปิดไฟล์ตรงจาก URL (Apache — IIS ใช้ web.config ในโฟลเดอร์)
        @file_put_contents(UPLOAD_DIR . '/' . SHARE_FILE_DIR . '/.htaccess', "Require all denied\n");
    }
    $ins = db()->prepare('INSERT INTO core_share_files (share_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?)');
    foreach ($files as $f) {
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ ตรวจสอบสิทธิ์การเขียนโฟลเดอร์ uploads');
        }
        $orig = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]/u', '_', basename((string)$f['name'])), 0, 200);
        $ins->execute([$shareId, $sub . '/' . $name, $orig ?: $name, (int)$f['size']]);
    }
}

/** ลบไฟล์แนบที่เลือก (เฉพาะของโพสต์นี้) */
function share_delete_files(int $shareId, array $fileIds): void
{
    if (!$fileIds) {
        return;
    }
    $in = implode(',', array_map('intval', $fileIds));
    $st = db()->prepare("SELECT id, file_path FROM core_share_files WHERE share_id = ? AND id IN ($in)");
    $st->execute([$shareId]);
    foreach ($st->fetchAll() as $f) {
        delete_upload($f['file_path']);
        db()->prepare('DELETE FROM core_share_files WHERE id = ?')->execute([$f['id']]);
    }
}

/** ลบโพสต์ทั้งหมด (ไฟล์จริง + ข้อมูล) */
function share_delete(int $shareId): void
{
    $st = db()->prepare('SELECT file_path FROM core_share_files WHERE share_id = ?');
    $st->execute([$shareId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
        delete_upload($p);
    }
    db()->prepare('DELETE FROM core_shares WHERE id = ?')->execute([$shareId]);
}

/**
 * ข้อความสรุปผู้รับจากรายชื่อที่เลือก: กลุ่มงานที่เลือกครบทั้งกลุ่มแสดงเป็นชื่อกลุ่ม ที่เหลือแสดงชื่อคน
 * เช่น "กลุ่มวิชาการสถิติและวางแผน, นายธีรชัย หนูจิต"
 */
function share_audience_text(array $userIds, array $staff): string
{
    $sel = array_flip($userIds);
    $byGroup = [];
    foreach ($staff as $u) {
        if ($u['work_group_id']) {
            $byGroup[(int)$u['work_group_id']]['name'] = $u['work_group'];
            $byGroup[(int)$u['work_group_id']]['ids'][] = (int)$u['id'];
        }
    }
    $parts = [];
    $covered = [];
    foreach ($byGroup as $g) {
        if (count($g['ids']) > 1 && !array_diff($g['ids'], $userIds)) {
            $parts[] = $g['name'];
            $covered += array_flip($g['ids']);
        }
    }
    foreach ($staff as $u) {
        if (isset($sel[(int)$u['id']]) && !isset($covered[(int)$u['id']])) {
            $parts[] = preg_replace('/\s+/u', ' ', $u['full_name']);
        }
    }
    return mb_strimwidth(implode(', ', $parts), 0, 500, '…');
}

/** การแจ้งเตือนที่กระดิ่ง: โพสต์ที่แชร์ถึงผู้ใช้ใน 14 วันล่าสุด (เรียกจาก core/notifications.php) */
function share_notifications(array $user): array
{
    $st = db()->prepare("SELECT s.id, s.body, u.full_name, (SELECT COUNT(*) FROM core_share_files f WHERE f.share_id = s.id) AS nfiles,
                                CASE WHEN s.audience = 'all' THEN s.created_at ELSE su.added_at END AS t
                         FROM core_shares s JOIN core_users u ON u.id = s.user_id
                         LEFT JOIN core_share_users su ON su.share_id = s.id AND su.user_id = ?
                         WHERE s.user_id <> ? AND (s.audience = 'all' OR su.user_id IS NOT NULL)
                         HAVING t >= NOW() - INTERVAL 14 DAY
                         ORDER BY t DESC LIMIT 15");
    $st->execute([$user['id'], $user['id']]);
    $out = [];
    foreach ($st as $s) {
        $out[] = ['title' => 'แชร์ข้อความจาก ' . preg_replace('/\s+/u', ' ', $s['full_name']), 'icon' => 'bi-megaphone-fill', 'color' => 'info',
                  'text' => msg_snippet($s['body'], 80) . ($s['nfiles'] ? ' · แนบ ' . $s['nfiles'] . ' ไฟล์' : ''),
                  'href' => url('shares#share-' . $s['id']), 'time' => $s['t'], 'module' => 'shares'];
    }
    return $out;
}
