#!/usr/bin/env python3
"""สร้าง database/legacy/migrate_all_in_one.sql จาก schema.sql + 01_inventory.sql + 02_digitalcc.sql
รันใหม่ทุกครั้งที่แก้ไฟล์ต้นทาง:  python3 tools/build_migrate_all.py"""
import os, re
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
read = lambda p: open(os.path.join(root, p), encoding='utf-8').read()

def body(sql):
    # ตัดหัวคอมเมนต์ / SET NAMES / USE ของแต่ละไฟล์ (ใส่ครั้งเดียวด้านบน)
    sql = re.sub(r'^SET NAMES utf8mb4;\n', '', sql, flags=re.M)
    sql = re.sub(r'^USE nsokhkaen;\n', '', sql, flags=re.M)
    return sql.strip() + '\n'

schema = read('database/schema.sql')
schema = schema[schema.index('-- ----------------------------------------------------------------------------\n-- core_'):]
out = f"""-- ============================================================================
-- ย้ายระบบทั้งหมดในไฟล์เดียว: สร้างฐานข้อมูล nsokhkaen + ย้ายข้อมูลระบบวัสดุ + ระบบศูนย์ดิจิทัลชุมชน
-- (ไฟล์นี้สร้างอัตโนมัติจาก tools/build_migrate_all.py — อย่าแก้โดยตรง)
--
-- ใช้เมื่อ: ฐานข้อมูลเดิม `nsokhkaen_inventory` และ `digitalcc_khonkaen` อยู่บนเซิร์ฟเวอร์เดียวกัน
-- วิธีใช้:  mysql -u root -p --default-character-set=utf8mb4 < database/legacy/migrate_all_in_one.sql
--          หรือ phpMyAdmin/Navicat: เปิดไฟล์นี้แล้ว Run ทั้งไฟล์ (ไม่ต้องเลือกฐานข้อมูลก่อน)
--
-- ปลอดภัย: อ่านจากฐานข้อมูลเดิมอย่างเดียว ไม่แก้ไข/ลบข้อมูลเดิม
-- ถ้าฐานข้อมูล nsokhkaen มีข้อมูลอยู่แล้ว สคริปต์จะหยุดที่ error "Duplicate entry" (ไม่เขียนทับ)
--   → ต้องการรันใหม่: DROP DATABASE nsokhkaen; แล้วรันไฟล์นี้อีกครั้ง
-- หลังรัน: คัดลอกรูปภาพ และตั้ง redirect ตาม docs/MIGRATION.md ขั้นที่ 5–6
-- ============================================================================

SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS nsokhkaen DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nsokhkaen;

-- ############################################################################
-- ส่วนที่ 1: โครงสร้างตาราง (database/schema.sql)
-- ############################################################################
{body(schema)}
-- ############################################################################
-- ส่วนที่ 2: ย้ายข้อมูลระบบวัสดุ (database/legacy/01_inventory.sql)
-- ############################################################################
{body(read('database/legacy/01_inventory.sql'))}
-- ############################################################################
-- ส่วนที่ 3: ย้ายข้อมูลระบบศูนย์ดิจิทัลชุมชน (database/legacy/02_digitalcc.sql)
-- ############################################################################
{body(read('database/legacy/02_digitalcc.sql'))}
-- ############################################################################
-- ส่วนที่ 4: ค่าตั้งค่าของสำนักงาน
-- ############################################################################
-- URL ของระบบใหม่ (ใช้ใน QR Code) — เครื่องเซิร์ฟเวอร์เดิมของระบบวัสดุคือ 192.168.144.83
-- ถ้าติดตั้งที่อื่น แก้ภายหลังได้ที่ ผู้ดูแลระบบกลาง → ตั้งค่าระบบ
INSERT INTO core_settings (`key`, `value`) VALUES ('app_url', 'http://192.168.144.83/nsokhkaen/')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- สรุปผู้ใช้และสิทธิ์หลังย้าย (ตรวจว่าถูกต้อง แล้วปรับได้ที่เมนู ผู้ใช้งานและสิทธิ์)
SELECT u.id, u.username, u.full_name, u.is_superadmin AS superadmin,
       GROUP_CONCAT(CONCAT(r.module_key, ':', r.role) ORDER BY r.module_key SEPARATOR ', ') AS roles,
       (u.pin_hash IS NOT NULL) AS has_pin
FROM core_users u LEFT JOIN core_user_roles r ON r.user_id = u.id
GROUP BY u.id ORDER BY u.id;
"""
open(os.path.join(root, 'database/legacy/migrate_all_in_one.sql'), 'w', encoding='utf-8').write(out)
print('written database/legacy/migrate_all_in_one.sql', len(out.splitlines()), 'lines')
