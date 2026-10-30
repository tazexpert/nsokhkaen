-- ============================================================================
-- ผู้ใช้งาน: ลำดับการแสดงภายในกลุ่มตำแหน่ง (รหัสผู้ใช้มาจากส่วนกลาง แก้ไม่ได้) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261108_core_user_sort_order.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE core_users ADD COLUMN IF NOT EXISTS sort_order SMALLINT NULL AFTER nickname;
