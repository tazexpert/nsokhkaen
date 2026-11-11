-- ============================================================================
-- ปฏิทินการปฏิบัติงาน: การจัดเวรกิจกรรม — รันซ้ำได้
--   แต่ละกิจกรรม = ข้าราชการ 1 คน (ตามลำดับ ข้ามได้ 1–3 คิว) + กลุ่มกิจกรรม 1 กลุ่ม (ตามลำดับ)
--   ยืนยันแล้วเพิ่มลงปฏิทิน (ผู้รับผิดชอบ = ข้าราชการ + สมาชิกกลุ่ม → ได้รับการแจ้งเตือน)
--   ลบกิจกรรมในปฏิทิน = คืนคิว (cal_rota_turns ลบตาม)
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261207_cal_rota.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

-- รายชื่อในการหมุนเวียน: official = ลำดับการเข้าร่วมกิจกรรมข้าราชการ · group = สมาชิกกลุ่มกิจกรรม (group_no)
CREATE TABLE IF NOT EXISTS cal_rota_members (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind        ENUM('official', 'group') NOT NULL,
  group_no    TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- official = 0
  user_id     INT UNSIGNED NULL,                     -- ผู้มีบัญชี (ได้รับแจ้งเตือน) · ว่าง = ชื่ออย่างเดียว
  name        VARCHAR(150) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_cal_rota_members (kind, group_no, sort_order),
  CONSTRAINT fk_cal_rota_members_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เวรที่จัดแล้ว (1 กิจกรรม = 1 แถว) — ใช้คำนวณว่าถึงคิวใคร/กลุ่มใด
CREATE TABLE IF NOT EXISTS cal_rota_turns (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id          INT UNSIGNED NOT NULL,
  official_user_id  INT UNSIGNED NULL,
  official_name     VARCHAR(150) NOT NULL,
  skipped_names     VARCHAR(500) NULL,                 -- ข้าราชการที่ถูกข้าม (ยังอยู่ในคิวถัดไป)
  dress             VARCHAR(255) NULL,                 -- การแต่งกาย
  group_no          TINYINT UNSIGNED NOT NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cal_rota_turns_event (event_id),
  CONSTRAINT fk_cal_rota_turns_event FOREIGN KEY (event_id) REFERENCES cal_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_cal_rota_turns_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายชื่อเริ่มต้น (เฉพาะเมื่อยังไม่มีรายชื่อ) — จับคู่บัญชีจากชื่อ-สกุล (ไม่สนช่องว่าง) · ไม่พบ = ชื่ออย่างเดียว
INSERT INTO cal_rota_members (kind, group_no, user_id, name, sort_order)
SELECT x.kind, x.group_no,
       (SELECT u.id FROM core_users u WHERE REPLACE(u.full_name, ' ', '') = REPLACE(x.name, ' ', '') AND u.is_active = 1
        ORDER BY u.username REGEXP '^[0-9]+$' DESC, u.id LIMIT 1),
       x.name, x.sort_order
FROM (
  SELECT 'official' AS kind, 0 AS group_no, 'นางเยาวภา มณีฉาย' AS name, 1 AS sort_order
  UNION ALL SELECT 'official', 0, 'นายประเทือง ภูสะทด', 2
  UNION ALL SELECT 'official', 0, 'นางสาวจงกล ยงค์สถิตย์สุข', 3
  UNION ALL SELECT 'official', 0, 'นายณชพัฒน์ ไชยคำ', 4
  UNION ALL SELECT 'official', 0, 'นางรุ่งกาล บุราณรักษ์', 5
  UNION ALL SELECT 'official', 0, 'นายโสภณ เพ็งค่ำ', 6
  UNION ALL SELECT 'group', 1, 'นายธีรชัย หนูจิต', 1
  UNION ALL SELECT 'group', 1, 'นางสาวศิรินันท์ นามพรหม', 2
  UNION ALL SELECT 'group', 1, 'นางสาววิชุดา ซาฮาด', 3
  UNION ALL SELECT 'group', 1, 'นายนครินทร์ เอี่ยมปาน', 4
  UNION ALL SELECT 'group', 1, 'นางสาวพิมพ์ยี่ษา หัสพิมพ์', 5
  UNION ALL SELECT 'group', 2, 'นางปิ่นอนงค์ นาพิลา', 1
  UNION ALL SELECT 'group', 2, 'นายชวัลโชติ ยศธสาร', 2
  UNION ALL SELECT 'group', 2, 'นางสาวณัฐกมล ขาวหนู', 3
  UNION ALL SELECT 'group', 2, 'นางสาวธัญญาภรณ์ ง้าวกลาง', 4
  UNION ALL SELECT 'group', 2, 'นายภาณุภัท อัตถาชน', 5
  UNION ALL SELECT 'group', 3, 'นางมยุวรรน ศรีโย', 1
  UNION ALL SELECT 'group', 3, 'นางสาวนันฐริกา จันทร์ทาดี', 2
  UNION ALL SELECT 'group', 3, 'นายรังสรรค์ สิทธิจันทร์เสน', 3
  UNION ALL SELECT 'group', 3, 'นายสุวิทย์ นาโพธิ์', 4
  UNION ALL SELECT 'group', 3, 'นางสาวทัชรียา วรสาร', 5
) x
WHERE NOT EXISTS (SELECT 1 FROM cal_rota_members);
