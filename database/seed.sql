-- ข้อมูลเริ่มต้นสำหรับติดตั้งใหม่ (ห้ามรันหลัง migrate ข้อมูลเดิม — ดู docs/MIGRATION.md)
-- mysql -u root -p < database/seed.sql

SET NAMES utf8mb4;
USE nsokhkaen;

-- ผู้ดูแลระบบกลาง: admin / admin1234  (เปลี่ยนรหัสผ่านทันทีหลังเข้าสู่ระบบ)
INSERT INTO core_users (username, password_hash, full_name, is_superadmin)
VALUES ('admin', '$2y$10$NVypn7ot0WH6TiHI/lFGAOsQy6E6TgNEf6LsBcMFlQbMcgi7rJhvu', 'ผู้ดูแลระบบ', 1)
ON DUPLICATE KEY UPDATE username = username;

INSERT IGNORE INTO core_settings (`key`, `value`) VALUES
('app_name', 'ระบบบริหารจัดการ สำนักงานสถิติจังหวัดขอนแก่น'),
('org_name', 'สำนักงานสถิติจังหวัดขอนแก่น'),
('app_url',  'http://localhost/nsokhkaen/');

INSERT IGNORE INTO inv_storage_locations (name) VALUES
('ห้องพัสดุ ชั้น 1'), ('ห้องธุรการ ชั้น 1');
