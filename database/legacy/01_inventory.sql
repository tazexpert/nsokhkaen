-- ============================================================================
-- ย้ายข้อมูลจากระบบวัสดุเดิม (ฐานข้อมูล `nsokhkaen_inventory`) เข้าสู่ระบบใหม่ (`nsokhkaen`)
-- รายละเอียดขั้นตอนทั้งหมด: docs/MIGRATION.md
--
-- เงื่อนไข:
--   1. ฐานข้อมูลเดิมผ่าน migration ของระบบเดิมครบถึง 009_add_requisition_approval.sql แล้ว
--   2. ฐานข้อมูลใหม่สร้างจาก database/schema.sql แล้ว และ "ยังว่าง" (ห้ามรัน seed.sql ก่อน)
--   3. ฐานข้อมูลทั้งสองอยู่บน MySQL/MariaDB เครื่องเดียวกัน
--   ถ้าชื่อฐานข้อมูลเดิมไม่ใช่ nsokhkaen_inventory ให้แทนที่ `nsokhkaen_inventory` ทั้งไฟล์
--   (หรือใช้ php tools/migrate_legacy.php --inventory-db=ชื่อจริง ซึ่งแทนที่ให้อัตโนมัติ)
--
-- สิ่งที่คงไว้เหมือนเดิม: id ทุกตาราง, ชื่อผู้ใช้/รหัสผ่าน/PIN (hash เดิม), qr_code ของวัสดุ
-- (สติ๊กเกอร์เดิมยังสแกนได้), เลขที่ใบเบิก และลำดับเลขที่ใบเบิกถัดไป
-- ============================================================================

SET NAMES utf8mb4;
USE nsokhkaen;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ---------- ผู้ใช้ → ระบบกลาง (คง id เดิม) ----------
-- admin ของระบบเดิม ("จัดการได้ทุกระบบ") = ผู้ดูแลระบบกลาง (is_superadmin) ในระบบใหม่
INSERT INTO core_users (id, username, password_hash, full_name, position, pin_hash, is_superadmin, is_active, created_at, updated_at)
SELECT id, username, password_hash, full_name, position, pin_hash, (role = 'admin'), is_active, created_at, updated_at
FROM `nsokhkaen_inventory`.`users`;

-- สิทธิ์ในระบบวัสดุตาม role เดิม (admin / staff)
INSERT INTO core_user_roles (user_id, module_key, role)
SELECT id, 'inventory', role FROM `nsokhkaen_inventory`.`users`;

-- ---------- ค่าตั้งค่า ----------
-- app_name / app_url เดิมเป็นของระบบวัสดุ ไม่ย้าย — ระบบใหม่ใช้ค่ากลางด้านล่าง (แก้ได้ที่เมนูตั้งค่าระบบ)
INSERT INTO inv_settings (`key`, `value`)
SELECT `key`, `value` FROM `nsokhkaen_inventory`.`settings`
WHERE `key` IN ('issuer_name', 'issuer_position', 'approver_name', 'approver_position');

INSERT IGNORE INTO core_settings (`key`, `value`) VALUES
('app_name', 'ระบบบริหารจัดการ สำนักงานสถิติจังหวัดขอนแก่น'),
('org_name', 'สำนักงานสถิติจังหวัดขอนแก่น');

-- ---------- ข้อมูลหลัก ----------
INSERT INTO inv_storage_locations (id, name, created_at)
SELECT id, name, created_at FROM `nsokhkaen_inventory`.`storage_locations`;

INSERT INTO inv_categories (id, name, item_type, created_at)
SELECT id, name, item_type, created_at FROM `nsokhkaen_inventory`.`categories`;

-- รูปภาพย้ายจาก uploads/materials/ → uploads/inventory/materials/
INSERT INTO inv_materials (id, material_code, qr_code, name, category_id, category_code, unit, unit_cost,
                           stock_qty, min_stock, storage_location, image_path, note, created_by, created_at, updated_at)
SELECT id, material_code, qr_code, name, category_id, category_code, unit, unit_cost,
       stock_qty, min_stock, storage_location,
       CASE WHEN image_path LIKE 'uploads/materials/%'
            THEN CONCAT('uploads/inventory/materials/', SUBSTRING(image_path, CHAR_LENGTH('uploads/materials/') + 1))
            ELSE image_path END,
       note, created_by, created_at, updated_at
FROM `nsokhkaen_inventory`.`materials`;

-- ครุภัณฑ์ (ไม่มีหน้าจอใช้งานแล้ว แต่ย้ายไว้เพื่อรักษาข้อมูลเดิม)
INSERT INTO inv_assets (id, asset_code, qr_code, name, category_id, brand_model, serial_number, status,
                        storage_location, acquired_date, image_path, note, created_by, created_at, updated_at)
SELECT id, asset_code, qr_code, name, category_id, brand_model, serial_number, status,
       storage_location, acquired_date,
       CASE WHEN image_path LIKE 'uploads/assets/%'
            THEN CONCAT('uploads/inventory/assets/', SUBSTRING(image_path, CHAR_LENGTH('uploads/assets/') + 1))
            ELSE image_path END,
       note, created_by, created_at, updated_at
FROM `nsokhkaen_inventory`.`assets`;

-- ---------- ใบเบิกและประวัติ ----------
INSERT INTO inv_requisitions (id, requisition_no, purpose, requester_name, requester_position,
                              created_by, created_at, approved_by, approved_at)
SELECT id, requisition_no, purpose, requester_name, requester_position,
       created_by, created_at, approved_by, approved_at
FROM `nsokhkaen_inventory`.`requisitions`;

INSERT INTO inv_requisition_items (id, requisition_id, item_type, material_id, asset_id, item_name, unit,
                                   quantity_requested, quantity_issued, note)
SELECT id, requisition_id, item_type, material_id, asset_id, item_name, unit,
       quantity_requested, quantity_issued, note
FROM `nsokhkaen_inventory`.`requisition_items`;

INSERT INTO inv_material_transactions (id, material_id, user_id, requisition_id, transaction_type,
                                       quantity, balance_after, note, created_at)
SELECT id, material_id, user_id, requisition_id, transaction_type, quantity, balance_after, note, created_at
FROM `nsokhkaen_inventory`.`material_transactions`;

INSERT INTO inv_asset_transactions (id, asset_id, user_id, requisition_id, action, borrower_name, note, created_at)
SELECT id, asset_id, user_id, requisition_id, action, borrower_name, note, created_at
FROM `nsokhkaen_inventory`.`asset_transactions`;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- ตรวจสอบจำนวนแถว (ควรเท่ากับฐานข้อมูลเดิม)
SELECT 'users' AS tbl, (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`users`) AS legacy, (SELECT COUNT(*) FROM core_users) AS migrated
UNION ALL SELECT 'materials', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`materials`), (SELECT COUNT(*) FROM inv_materials)
UNION ALL SELECT 'requisitions', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`requisitions`), (SELECT COUNT(*) FROM inv_requisitions)
UNION ALL SELECT 'requisition_items', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`requisition_items`), (SELECT COUNT(*) FROM inv_requisition_items)
UNION ALL SELECT 'material_transactions', (SELECT COUNT(*) FROM `nsokhkaen_inventory`.`material_transactions`), (SELECT COUNT(*) FROM inv_material_transactions)
UNION ALL SELECT 'stock_qty_total', (SELECT COALESCE(SUM(stock_qty),0) FROM `nsokhkaen_inventory`.`materials`), (SELECT COALESCE(SUM(stock_qty),0) FROM inv_materials);
