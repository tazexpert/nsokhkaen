-- ============================================================================
-- ดึงข้อมูลเบิกวัสดุ "รอบสุดท้าย" จากระบบวัสดุเดิม (`nsokhkaen_inventory`) มาแทนที่ในระบบใหม่ (`nsokhkaen`)
-- ใช้เมื่อ: ระบบใหม่ติดตั้ง/ย้ายข้อมูลไปแล้วและมีข้อมูลใหม่ (สลิป ปฏิทิน ครุภัณฑ์ การยืม สิทธิ์ผู้ใช้ ฯลฯ)
--           แต่ระบบเบิกวัสดุเดิมยังเปิดใช้งานอยู่ → ก่อนเปิดใช้ระบบใหม่ ให้ปิดระบบเดิมแล้วรันไฟล์นี้ครั้งเดียว
-- รายละเอียดขั้นตอน: docs/MIGRATION.md หัวข้อ "ระบบใหม่ใช้งานอยู่แล้ว แต่ระบบเบิกวัสดุเดิมยังเปิดอยู่"
--
-- แทนที่ทั้งหมดด้วยข้อมูลจากระบบเดิม (ข้อมูลส่วนนี้ที่บันทึกในระบบใหม่ระหว่างทดลองใช้จะถูกลบ):
--   หมวดหมู่ ที่เก็บ วัสดุ (ยอดคงเหลือ/รูป/qr_code) ใบเบิก รายการเบิก ประวัติรับ-จ่าย และครุภัณฑ์แบบเดิม (inv_assets)
-- ไม่แตะ: ผู้ใช้/สิทธิ์ที่มีอยู่แล้ว ระบบครุภัณฑ์ใหม่ (inv_equipment) การยืม-คืน ผู้ลงนาม/ตั้งค่า
--         สลิปเงินเดือน ปฏิทิน ศูนย์ดิจิทัลชุมชน
-- ผู้ใช้: จับคู่ด้วยชื่อผู้ใช้ (บัญชี PIN อย่างเดียว: id + ชื่อ) — มีอยู่แล้วใช้บัญชีในระบบใหม่ (ไม่เปลี่ยนรหัสผ่าน/PIN/สิทธิ์)
--         ผู้ใช้ที่เพิ่มในระบบเดิมภายหลัง → สร้างบัญชีใหม่ (รหัสผ่าน/PIN เดิม + สิทธิ์ระบบวัสดุตาม role เดิม)
-- id ของวัสดุ/ใบเบิกคงเดิม → qr_code และสติ๊กเกอร์เดิมใช้ต่อได้ เลขที่ใบเบิกถัดไปต่อจากระบบเดิม
-- ถ้าชื่อฐานข้อมูลเดิมไม่ใช่ nsokhkaen_inventory ให้แทนที่ `nsokhkaen_inventory` ทั้งไฟล์
-- (หรือใช้ php tools/migrate_legacy.php --resync-inventory --inventory-db=ชื่อจริง ซึ่งแทนที่ให้อัตโนมัติ)
-- ============================================================================

SET NAMES utf8mb4;
USE nsokhkaen;

-- ---------- ผู้ใช้: จับคู่บัญชีเดิม แล้วเพิ่มเฉพาะผู้ใช้ที่ยังไม่มีในระบบใหม่ ----------
-- จับคู่: ชื่อผู้ใช้ตรงกัน · บัญชีที่ไม่มีชื่อผู้ใช้ (ใช้ PIN อย่างเดียว) = id เดิม + ชื่อ-นามสกุลตรงกัน หรือ PIN (hash) เดียวกัน
DROP TABLE IF EXISTS _resync_user_map;
CREATE TABLE _resync_user_map (old_id INT UNSIGNED PRIMARY KEY, new_id INT UNSIGNED NOT NULL);
INSERT INTO _resync_user_map (old_id, new_id)
SELECT o.id, c.id FROM `nsokhkaen_inventory`.`users` o
JOIN core_users c ON c.username = o.username COLLATE utf8mb4_unicode_ci
WHERE o.username IS NOT NULL AND o.username <> '';
INSERT IGNORE INTO _resync_user_map (old_id, new_id)
SELECT o.id, c.id FROM `nsokhkaen_inventory`.`users` o
JOIN core_users c ON (c.id = o.id AND c.full_name = o.full_name COLLATE utf8mb4_unicode_ci)
                   OR (o.pin_hash IS NOT NULL AND c.pin_hash = o.pin_hash COLLATE utf8mb4_unicode_ci)
WHERE o.username IS NULL OR o.username = '';

-- ผู้ใช้ที่เพิ่มในระบบเดิมภายหลัง → สร้างบัญชีใหม่ (ใช้ชื่อผู้ใช้ชั่วคราวเพื่อจับคู่ แล้วคืนค่าเดิม)
INSERT INTO core_users (username, password_hash, full_name, position, pin_hash, is_superadmin, is_active, created_at, updated_at)
SELECT CONCAT('__resync_', o.id), o.password_hash, o.full_name, o.position, o.pin_hash, 0, o.is_active, o.created_at, o.updated_at
FROM `nsokhkaen_inventory`.`users` o
WHERE NOT EXISTS (SELECT 1 FROM _resync_user_map m WHERE m.old_id = o.id);
INSERT INTO _resync_user_map (old_id, new_id)
SELECT CAST(SUBSTRING(c.username, 10) AS UNSIGNED), c.id FROM core_users c WHERE c.username LIKE '\_\_resync\_%';
INSERT IGNORE INTO core_user_roles (user_id, module_key, role)
SELECT c.id, 'inventory', o.role FROM core_users c
JOIN `nsokhkaen_inventory`.`users` o ON o.id = CAST(SUBSTRING(c.username, 10) AS UNSIGNED)
WHERE c.username LIKE '\_\_resync\_%';
UPDATE core_users c JOIN `nsokhkaen_inventory`.`users` o ON o.id = CAST(SUBSTRING(c.username, 10) AS UNSIGNED)
SET c.username = NULLIF(o.username, '')
WHERE c.username LIKE '\_\_resync\_%';

-- ---------- ลบข้อมูลวัสดุ/ใบเบิกในระบบใหม่ แล้วคัดลอกจากระบบเดิม ----------
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

DELETE FROM inv_asset_transactions;
DELETE FROM inv_material_transactions;
DELETE FROM inv_requisition_items;
DELETE FROM inv_requisitions;
DELETE FROM inv_assets;
DELETE FROM inv_materials;
DELETE FROM inv_categories;
DELETE FROM inv_storage_locations;

INSERT INTO inv_storage_locations (id, name, created_at)
SELECT id, name, created_at FROM `nsokhkaen_inventory`.`storage_locations`;

INSERT INTO inv_categories (id, name, item_type, created_at)
SELECT id, name, item_type, created_at FROM `nsokhkaen_inventory`.`categories`;

-- รูปภาพ: uploads/materials/ → uploads/inventory/materials/
INSERT INTO inv_materials (id, material_code, qr_code, name, category_id, category_code, unit, unit_cost,
                           stock_qty, min_stock, storage_location, image_path, note, created_by, created_at, updated_at)
SELECT x.id, x.material_code, x.qr_code, x.name, x.category_id, x.category_code, x.unit, x.unit_cost,
       x.stock_qty, x.min_stock, x.storage_location,
       CASE WHEN x.image_path LIKE 'uploads/materials/%'
            THEN CONCAT('uploads/inventory/materials/', SUBSTRING(x.image_path, CHAR_LENGTH('uploads/materials/') + 1))
            ELSE x.image_path END,
       x.note, m.new_id, x.created_at, x.updated_at
FROM `nsokhkaen_inventory`.`materials` x LEFT JOIN _resync_user_map m ON m.old_id = x.created_by;

INSERT INTO inv_assets (id, asset_code, qr_code, name, category_id, brand_model, serial_number, status,
                        storage_location, acquired_date, image_path, note, created_by, created_at, updated_at)
SELECT x.id, x.asset_code, x.qr_code, x.name, x.category_id, x.brand_model, x.serial_number, x.status,
       x.storage_location, x.acquired_date,
       CASE WHEN x.image_path LIKE 'uploads/assets/%'
            THEN CONCAT('uploads/inventory/assets/', SUBSTRING(x.image_path, CHAR_LENGTH('uploads/assets/') + 1))
            ELSE x.image_path END,
       x.note, m.new_id, x.created_at, x.updated_at
FROM `nsokhkaen_inventory`.`assets` x LEFT JOIN _resync_user_map m ON m.old_id = x.created_by;

INSERT INTO inv_requisitions (id, requisition_no, purpose, requester_name, requester_position,
                              created_by, created_at, approved_by, approved_at)
SELECT x.id, x.requisition_no, x.purpose, x.requester_name, x.requester_position,
       m1.new_id, x.created_at, m2.new_id, x.approved_at
FROM `nsokhkaen_inventory`.`requisitions` x
LEFT JOIN _resync_user_map m1 ON m1.old_id = x.created_by
LEFT JOIN _resync_user_map m2 ON m2.old_id = x.approved_by;

INSERT INTO inv_requisition_items (id, requisition_id, item_type, material_id, asset_id, item_name, unit,
                                   quantity_requested, quantity_issued, note)
SELECT id, requisition_id, item_type, material_id, asset_id, item_name, unit,
       quantity_requested, quantity_issued, note
FROM `nsokhkaen_inventory`.`requisition_items`;

INSERT INTO inv_material_transactions (id, material_id, user_id, requisition_id, transaction_type,
                                       quantity, balance_after, note, created_at)
SELECT x.id, x.material_id, m.new_id, x.requisition_id, x.transaction_type, x.quantity, x.balance_after, x.note, x.created_at
FROM `nsokhkaen_inventory`.`material_transactions` x LEFT JOIN _resync_user_map m ON m.old_id = x.user_id;

INSERT INTO inv_asset_transactions (id, asset_id, user_id, requisition_id, action, borrower_name, note, created_at)
SELECT x.id, x.asset_id, m.new_id, x.requisition_id, x.action, x.borrower_name, x.note, x.created_at
FROM `nsokhkaen_inventory`.`asset_transactions` x LEFT JOIN _resync_user_map m ON m.old_id = x.user_id;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

DROP TABLE IF EXISTS _resync_user_map;

-- ตรวจสอบจำนวนแถว (เดิม = ใหม่ ทุกตาราง)
SELECT 'materials' AS tbl, (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`materials`) AS legacy, (SELECT COUNT(*) FROM inv_materials) AS migrated
UNION ALL SELECT 'stock_qty (รวม)', (SELECT COALESCE(SUM(stock_qty), 0) FROM `nsokhkaen_inventory`.`materials`), (SELECT COALESCE(SUM(stock_qty), 0) FROM inv_materials)
UNION ALL SELECT 'requisitions', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`requisitions`), (SELECT COUNT(*) FROM inv_requisitions)
UNION ALL SELECT 'requisition_items', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`requisition_items`), (SELECT COUNT(*) FROM inv_requisition_items)
UNION ALL SELECT 'material_transactions', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`material_transactions`), (SELECT COUNT(*) FROM inv_material_transactions)
UNION ALL SELECT 'categories', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`categories`), (SELECT COUNT(*) FROM inv_categories)
UNION ALL SELECT 'storage_locations', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`storage_locations`), (SELECT COUNT(*) FROM inv_storage_locations)
UNION ALL SELECT 'assets', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`assets`), (SELECT COUNT(*) FROM inv_assets)
UNION ALL SELECT 'requisitions ไม่มีผู้บันทึก', 0, (SELECT COUNT(*) FROM inv_requisitions WHERE created_by IS NULL OR created_by NOT IN (SELECT id FROM core_users));
