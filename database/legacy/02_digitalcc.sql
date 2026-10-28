-- ============================================================================
-- ย้ายข้อมูลจากระบบศูนย์ดิจิทัลชุมชนเดิม (ฐานข้อมูล `digitalcc_khonkaen`) เข้าสู่ระบบใหม่ (`nsokhkaen`)
-- รันหลัง 01_inventory.sql (หรือหลังติดตั้งใหม่ถ้าไม่มีระบบวัสดุเดิม) — รายละเอียด: docs/MIGRATION.md
--
-- เงื่อนไข: ฐานข้อมูลเดิมรัน database/migrate_visits.sql ของระบบเดิมแล้ว (มีตาราง visits และคอลัมน์ code, house_no, moo)
-- ถ้าชื่อฐานข้อมูลเดิมไม่ใช่ digitalcc_khonkaen ให้แทนที่ `digitalcc_khonkaen` ทั้งไฟล์
--
-- ผู้ใช้: ถ้าชื่อผู้ใช้ซ้ำกับบัญชีที่มีอยู่แล้ว (เช่น admin จากระบบวัสดุ) ถือว่าเป็นคนเดียวกัน
--         ใช้รหัสผ่านเดิมของบัญชีที่มีอยู่ แล้วเพิ่มสิทธิ์ระบบศูนย์ฯ ให้
-- รูปภาพ: path ในฐานข้อมูลเติมหน้า digitalcc/ (ไฟล์ต้องคัดลอกจาก uploads/ เดิม → uploads/digitalcc/)
-- ============================================================================

SET NAMES utf8mb4;
USE nsokhkaen;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ---------- ผู้ใช้ ----------
INSERT INTO core_users (username, password_hash, full_name, created_at)
SELECT u.username, u.password_hash, u.fullname, u.created_at
FROM `digitalcc_khonkaen`.`users` u
WHERE NOT EXISTS (SELECT 1 FROM core_users c WHERE c.username = u.username COLLATE utf8mb4_unicode_ci);

-- ผู้ใช้ระบบเดิมทุกคนมีสิทธิ์เต็ม → ผู้ดูแลระบบศูนย์ฯ
INSERT INTO core_user_roles (user_id, module_key, role)
SELECT c.id, 'digitalcc', 'admin'
FROM `digitalcc_khonkaen`.`users` u
JOIN core_users c ON c.username = u.username COLLATE utf8mb4_unicode_ci
ON DUPLICATE KEY UPDATE role = VALUES(role);

-- ---------- ศูนย์ (คง id เดิม เพื่อให้ลิงก์ center.html?id= เดิมยังใช้ได้) ----------
INSERT INTO dcc_centers (id, code, name, center_type, house_no, moo, address, village, subdistrict, district, province,
                         postcode, lat, lng, phone, email, facebook, open_hours, services, description, cover_image,
                         is_active, created_at, updated_at)
SELECT id, code, name,
       CASE WHEN center_type = 'กศน.' THEN 'สกร.' ELSE center_type END,
       house_no, moo, address, village, subdistrict, district, province, postcode, lat, lng, phone, email, facebook,
       open_hours, services, description,
       CASE WHEN cover_image <> '' THEN CONCAT('digitalcc/', cover_image) ELSE '' END,
       is_active, created_at, updated_at
FROM `digitalcc_khonkaen`.`centers`;

INSERT INTO dcc_center_images (id, center_id, file, caption, created_at)
SELECT id, center_id, CONCAT('digitalcc/', file), caption, created_at FROM `digitalcc_khonkaen`.`center_images`;

INSERT INTO dcc_center_staff (id, center_id, name, position, phone, email, line_id, bio, photo, sort_order, created_at)
SELECT id, center_id, name, position, phone, email, line_id, bio,
       CASE WHEN photo <> '' THEN CONCAT('digitalcc/', photo) ELSE '' END, sort_order, created_at
FROM `digitalcc_khonkaen`.`center_staff`;

INSERT INTO dcc_activities (id, center_id, title, activity_date, organizer, participants, description, cover_image, created_at, updated_at)
SELECT id, center_id, title, activity_date, organizer, participants, description,
       CASE WHEN cover_image <> '' THEN CONCAT('digitalcc/', cover_image) ELSE '' END, created_at, updated_at
FROM `digitalcc_khonkaen`.`activities`;

INSERT INTO dcc_activity_images (id, activity_id, file, caption, created_at)
SELECT id, activity_id, CONCAT('digitalcc/', file), caption, created_at FROM `digitalcc_khonkaen`.`activity_images`;

-- ---------- การตรวจเยี่ยม (created_by เดิม → id ผู้ใช้ใหม่ โดยจับคู่ด้วยชื่อผู้ใช้) ----------
INSERT INTO dcc_visits (id, center_id, activity_id, visit_date, visit_time, data, problems, suggestions,
                        informant_name, informant_position, inspector_name, inspector_position, lat, lng,
                        created_by, created_at, updated_at)
SELECT v.id, v.center_id, v.activity_id, v.visit_date, v.visit_time, v.data, v.problems, v.suggestions,
       v.informant_name, v.informant_position, v.inspector_name, v.inspector_position, v.lat, v.lng,
       c.id, v.created_at, v.updated_at
FROM `digitalcc_khonkaen`.`visits` v
LEFT JOIN `digitalcc_khonkaen`.`users` lu ON lu.id = v.created_by
LEFT JOIN core_users c ON c.username = lu.username COLLATE utf8mb4_unicode_ci;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'centers' AS tbl, (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`centers`) AS legacy, (SELECT COUNT(*) FROM dcc_centers) AS migrated
UNION ALL SELECT 'center_staff', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`center_staff`), (SELECT COUNT(*) FROM dcc_center_staff)
UNION ALL SELECT 'center_images', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`center_images`), (SELECT COUNT(*) FROM dcc_center_images)
UNION ALL SELECT 'activities', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`activities`), (SELECT COUNT(*) FROM dcc_activities)
UNION ALL SELECT 'activity_images', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`activity_images`), (SELECT COUNT(*) FROM dcc_activity_images)
UNION ALL SELECT 'visits', (SELECT COUNT(*) FROM `digitalcc_khonkaen`.`visits`), (SELECT COUNT(*) FROM dcc_visits);
