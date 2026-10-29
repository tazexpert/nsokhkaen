# แนวทางการย้ายระบบ (Migration) สู่ระบบบริหารจัดการใหม่

เอกสารนี้อธิบายการย้าย **ระบบบริหารจัดการวัสดุ (nsokhkaen_inventory) ที่ใช้งานอยู่จริง**
และ **ระบบศูนย์ดิจิทัลชุมชน (nsokhkaen_digitalcc)** เข้าสู่ระบบใหม่ `nsokhkaen` ที่ใช้ login เดียว

> ระยะเวลาที่ควรเผื่อ: ประมาณ 30–60 นาที ควรทำนอกเวลาที่มีการเบิกวัสดุ (เช่น หลังเลิกงาน)
> ระหว่างย้ายให้ "ปิดรับการเบิก" ชั่วคราว เพื่อไม่ให้มีข้อมูลใหม่เข้าระบบเดิมหลังสำรองข้อมูล

---

## สรุปสิ่งที่เปลี่ยน

| เรื่อง | ระบบเดิม | ระบบใหม่ |
|---|---|---|
| ฐานข้อมูล | `nsokhkaen_inventory`, `digitalcc_khonkaen` (แยกกัน) | `nsokhkaen` ฐานข้อมูลเดียว แยกตารางด้วย prefix |
| ตารางผู้ใช้ | `users` แยกแต่ละระบบ | `core_users` ใช้ร่วมกัน + `core_user_roles` สิทธิ์รายระบบ |
| ตารางระบบวัสดุ | `materials`, `requisitions`, … | `inv_materials`, `inv_requisitions`, … |
| ตารางศูนย์ฯ | `centers`, `visits`, … | `dcc_centers`, `dcc_visits`, … |
| URL ระบบวัสดุ | `/nsokhkaen_inventory/public/scan.php` | `/nsokhkaen/modules/inventory/scan.php` |
| URL เว็บศูนย์ฯ | `/digital.html`, `/center.html?id=` | `/nsokhkaen/modules/digitalcc/public/` |
| รูปวัสดุ | `uploads/materials/` | `uploads/inventory/materials/` |
| รูปศูนย์ฯ | `uploads/centers/` ฯลฯ | `uploads/digitalcc/centers/` ฯลฯ |

**สิ่งที่คงเดิม (ผู้ใช้ไม่ต้องทำอะไร):**
- ชื่อผู้ใช้ / รหัสผ่าน / PIN เดิมใช้ได้ทันที (ย้าย hash เดิมมาทั้งหมด)
- `id` ของทุกตาราง, เลขที่ใบเบิก และ **เลขที่ใบเบิกถัดไปต่อจากเดิม** (เช่น เดิมล่าสุด 57/2569 → ใบถัดไป 58/2569)
- ค่า `qr_code` ของวัสดุทุกรายการ → **สติ๊กเกอร์ QR Code ที่พิมพ์แปะไว้แล้วใช้ต่อได้** (ผ่านไฟล์ redirect ในขั้นที่ 6)
- ประวัติการเบิก การอนุมัติ ยอดคงเหลือ รูปภาพ และชื่อผู้จ่าย/ผู้อนุมัติในใบเบิก
- `id` ของศูนย์ดิจิทัลชุมชน → ลิงก์ `center.html?id=` เดิมยังพาไปศูนย์เดียวกัน

**สิทธิ์หลังย้าย (ข้อมูลจริงของสำนักงาน):** `admin` และ `zianso` → ผู้ดูแลระบบกลาง, ผู้ใช้อื่น 18 คน → เจ้าหน้าที่ระบบวัสดุ
· บัญชี `admin` ของระบบศูนย์ฯ รวมกับ `admin` ของระบบวัสดุ (ใช้รหัสผ่านของระบบวัสดุ)

**หลักการกำหนดสิทธิ์:**
- ผู้ใช้ role `admin` ของระบบวัสดุเดิม → **ผู้ดูแลระบบกลาง** (จัดการผู้ใช้ทุกระบบ + สิทธิ์สูงสุดทุกระบบ) และผู้ดูแลระบบวัสดุ
- ผู้ใช้ role `staff` → เจ้าหน้าที่ระบบวัสดุ (เหมือนเดิม)
- ผู้ใช้ระบบศูนย์ฯ เดิม → ผู้ดูแลระบบศูนย์ฯ
- ชื่อผู้ใช้ที่ซ้ำกันระหว่างสองระบบ (เช่น `admin`) ถือเป็นคนเดียวกัน: ใช้รหัสผ่านของ **ระบบวัสดุ** และได้สิทธิ์ทั้งสองระบบ
- ปรับสิทธิ์ภายหลังได้ที่เมนู **ผู้ดูแลระบบกลาง → ผู้ใช้งานและสิทธิ์**

---

## ขั้นที่ 0 — เตรียมความพร้อม

1. **ระบบเดิมต้องเป็นเวอร์ชันล่าสุด**
   - ระบบวัสดุ: รัน migration ของระบบเดิมครบถึง `009_add_requisition_approval.sql`
     (ตรวจได้: ตาราง `requisitions` ต้องมีคอลัมน์ `approved_at`)
   - ระบบศูนย์ฯ (ถ้าใช้): รัน `database/migrate_visits.sql` ของระบบเดิมแล้ว
2. PHP 8.0+ (`pdo_mysql`, `gd`, `zip`, `simplexml`), MySQL 5.7+ / MariaDB 10.3+
3. ฐานข้อมูลเดิมและใหม่ต้องอยู่บน **เซิร์ฟเวอร์ MySQL เดียวกัน** (สคริปต์ใช้ `INSERT … SELECT` ข้ามฐานข้อมูล)

## ขั้นที่ 1 — สำรองข้อมูล (สำคัญที่สุด)

```bash
mysqldump -u root -p --routines nsokhkaen_inventory > backup_inventory_YYYYMMDD.sql
mysqldump -u root -p --routines digitalcc_khonkaen  > backup_digitalcc_YYYYMMDD.sql
```

และคัดลอกโฟลเดอร์โปรเจกต์เดิมทั้งโฟลเดอร์เก็บไว้ (โดยเฉพาะ `uploads/`) เช่น
`C:\xampp\htdocs\nsokhkaen_inventory` → `C:\backup\nsokhkaen_inventory_YYYYMMDD`

> ใช้ phpMyAdmin ก็ได้: เลือกฐานข้อมูล → Export → Quick → SQL

## ขั้นที่ 2 — ติดตั้งโค้ดระบบใหม่

```bash
cd C:\xampp\htdocs          # หรือโฟลเดอร์เว็บของเซิร์ฟเวอร์
git clone https://github.com/tazexpert/nsokhkaen.git
```

สร้างไฟล์ `nsokhkaen/config.local.php` (ไม่ถูกเก็บใน git):

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'nsokhkaen');
define('DB_USER', 'root');
define('DB_PASS', '');
```

ให้เว็บเซิร์ฟเวอร์เขียนโฟลเดอร์ `uploads/` และ `libs/phpqrcode/cache/` ได้

## ขั้นที่ 3 — สร้างฐานข้อมูลใหม่ (โครงสร้างอย่างเดียว)

```bash
mysql -u root -p < database/schema.sql
```

> **ห้ามรัน `database/seed.sql`** ในกรณี migrate — ไฟล์นั้นสร้างผู้ใช้ `admin` ใหม่ ซึ่งจะชนกับ id ของผู้ใช้เดิม

## ขั้นที่ 4 — ปิดรับการเบิกในระบบเดิม

แจ้งเจ้าหน้าที่ให้หยุดเบิกชั่วคราว แล้ว (ถ้าข้อมูลเปลี่ยนหลังขั้นที่ 1) สำรองข้อมูลซ้ำอีกครั้ง

## ขั้นที่ 5 — ย้ายข้อมูล

### วิธี ก. ใช้เครื่องมือ (แนะนำ) — ตรวจความพร้อม ย้ายข้อมูล คัดลอกรูป และตรวจผลให้อัตโนมัติ

เปิด Command Prompt ที่โฟลเดอร์ `nsokhkaen` (XAMPP: `C:\xampp\php\php.exe` แทน `php`)

```bash
# 1) ตรวจความพร้อม (ไม่เขียนข้อมูล)
php tools/migrate_legacy.php --check ^
    --inventory-db=nsokhkaen_inventory --inventory-dir=C:\xampp\htdocs\nsokhkaen_inventory ^
    --digitalcc-db=digitalcc_khonkaen  --digitalcc-dir=C:\xampp\htdocs\nsokhkaen_digitalcc

# 2) ถ้าทุกข้อเป็น [OK] ให้ย้ายจริง พร้อมตั้ง URL ของระบบใหม่ (ใช้ใน QR Code)
php tools/migrate_legacy.php --run ^
    --inventory-db=nsokhkaen_inventory --inventory-dir=C:\xampp\htdocs\nsokhkaen_inventory ^
    --digitalcc-db=digitalcc_khonkaen  --digitalcc-dir=C:\xampp\htdocs\nsokhkaen_digitalcc ^
    --app-url=http://192.168.1.50/nsokhkaen/
```

(บน Linux ใช้ `\` แทน `^` ต่อบรรทัด) ถ้าไม่เคยใช้ระบบศูนย์ฯ ให้ตัดตัวเลือก `--digitalcc-*` ออก

ผลลัพธ์จะแสดงจำนวนแถวเดิม/ใหม่ของแต่ละตาราง (ต้องเป็น `OK` ทั้งหมด) และตรวจว่าไฟล์รูปที่อ้างถึงมีครบ
ถ้าย้ายไม่สำเร็จกลางทาง: ลบฐานข้อมูล `nsokhkaen` ทิ้ง รันขั้นที่ 3 ใหม่ แล้วลองอีกครั้ง (ฐานข้อมูลเดิมไม่ถูกแก้ไข)

### วิธี ข. ไฟล์ SQL ไฟล์เดียว (phpMyAdmin / Navicat) — ทำขั้นที่ 3 และ 5 ในครั้งเดียว

ไฟล์ `database/legacy/migrate_all_in_one.sql` สร้างฐานข้อมูล `nsokhkaen` พร้อมตาราง แล้วย้ายข้อมูลทั้งสองระบบ
และตั้ง URL ระบบใหม่เป็น `http://192.168.144.83/nsokhkaen/` ให้เลย (ข้ามขั้นที่ 3 ได้)

```bash
mysql -u root -p --default-character-set=utf8mb4 < database/legacy/migrate_all_in_one.sql
```

Navicat: คลิกขวาที่ connection → Execute SQL File… → เลือกไฟล์นี้ · phpMyAdmin: แท็บ Import (ไม่ต้องเลือกฐานข้อมูลก่อน)

ท้ายไฟล์แสดงตารางเทียบจำนวนแถวเดิม/ใหม่ และรายชื่อผู้ใช้พร้อมสิทธิ์หลังย้าย — ตรวจให้ครบ
ถ้าเจอ `Duplicate entry` แปลว่าฐานข้อมูล `nsokhkaen` มีข้อมูลแล้ว: `DROP DATABASE nsokhkaen;` แล้วรันใหม่
จากนั้นคัดลอกรูปตามข้อ 4 ของวิธี ค. ด้านล่าง

> ผลทดสอบกับฐานข้อมูลจริง (export วันที่ 28 ต.ค. 2569): ผู้ใช้ 20, วัสดุ 94 (มีรูปทุกรายการ), ใบเบิก 9,
> รายการเบิก 40, ประวัติ 41, ยอดคงเหลือรวม 3,449 · ศูนย์ 46, ผู้ดูแล 46 — ตรงกันทุกตาราง,
> hash รหัสผ่าน/PIN และ qr_code ตรงกันทุกแถว, ใบเบิกถัดไปออกเลข 12/2569

### วิธี ค. รันไฟล์ SQL แยกทีละระบบ (phpMyAdmin)

1. (ถ้าชื่อฐานข้อมูลเดิมไม่ตรง) แก้ชื่อ `` `nsokhkaen_inventory` `` / `` `digitalcc_khonkaen` `` ในไฟล์ให้ตรง
2. Import `database/legacy/01_inventory.sql` แล้ว `database/legacy/02_digitalcc.sql`
3. ตรวจตารางสรุปท้ายไฟล์: คอลัมน์ legacy กับ migrated ต้องเท่ากัน
4. คัดลอกรูปเอง (ระบบวัสดุปัจจุบันมีรูป 94 ไฟล์):
   - `nsokhkaen_inventory/uploads/materials/*` → `nsokhkaen/uploads/inventory/materials/`
   - `nsokhkaen_digitalcc/uploads/centers/*` → `nsokhkaen/uploads/digitalcc/centers/` (และ `staff`, `activities` เช่นเดียวกัน)
5. เข้าสู่ระบบ แล้วตั้งค่า URL ที่ **ผู้ดูแลระบบกลาง → ตั้งค่าระบบ → URL ของระบบ**

## ขั้นที่ 6 — ให้ URL เดิมและสติ๊กเกอร์ QR เดิมใช้งานต่อได้

สติ๊กเกอร์ที่พิมพ์ไว้ฝังลิงก์เดิม เช่น `http://192.168.1.50/nsokhkaen_inventory/public/scan.php?qr=MAT-QR-0001`
ให้แทนที่โฟลเดอร์ระบบเดิมด้วยไฟล์ redirect:

1. ย้ายโฟลเดอร์เดิมไปเก็บ: `htdocs/nsokhkaen_inventory` → `htdocs/_old_nsokhkaen_inventory` (หรือเก็บในที่สำรอง)
2. คัดลอก `nsokhkaen/legacy-redirects/nsokhkaen_inventory` ไปเป็น `htdocs/nsokhkaen_inventory`
3. แก้ `htdocs/nsokhkaen_inventory/redirect-config.php` ให้เป็น URL ระบบใหม่:
   ```php
   const NEW_APP_URL = 'http://192.168.1.50/nsokhkaen/';
   ```
4. ทำเช่นเดียวกันกับเว็บศูนย์ฯ เดิม (ถ้ามี) ด้วย `legacy-redirects/digitalcc` และแก้ `redirect-config.js`

ผล: สแกนสติ๊กเกอร์เดิม → เปิด `…/nsokhkaen/modules/inventory/scan.php?qr=MAT-QR-0001` อัตโนมัติ
(ถ้ายังไม่ได้ยืนยันตัวตน จะไปหน้ากรอก PIN ก่อนแล้วกลับมาที่รายการนั้นเหมือนเดิม)

> สติ๊กเกอร์ที่พิมพ์ใหม่จากระบบใหม่จะใช้ URL ใหม่โดยตรง ไม่ต้องผ่าน redirect

## ขั้นที่ 7 — ตรวจสอบหลังย้าย (Checklist)

เข้าสู่ระบบที่ `http://<เซิร์ฟเวอร์>/nsokhkaen/` แล้วตรวจ:

- [ ] ผู้ดูแลระบบเดิม login ด้วยรหัสผ่านเดิมได้ และเห็นเมนู "ผู้ดูแลระบบกลาง"
- [ ] เจ้าหน้าที่ (staff) login ได้ และ **ไม่เห็น** รายงาน/ตั้งค่า/เมนูผู้ดูแลระบบกลาง
- [ ] หน้า "เข้าใช้ด้วย PIN" ใช้ PIN เดิมได้
- [ ] สแกนสติ๊กเกอร์เดิมด้วยมือถือ → เปิดรายการถูกต้อง → เพิ่มลงใบเบิก → บันทึก → ได้เลขที่ใบเบิกต่อจากเดิม
- [ ] เมนูวัสดุ: จำนวนรายการ ยอดคงเหลือ และรูปภาพตรงกับระบบเดิม
- [ ] ใบเบิกเก่าเปิดดู/พิมพ์ได้ ชื่อผู้จ่าย/ผู้อนุมัติขึ้นถูกต้อง สถานะอนุมัติถูกต้อง
- [ ] รายงานช่วงวันที่ย้อนหลังให้ยอดเท่ากับระบบเดิม
- [ ] พิมพ์ QR Code ใหม่ 1 ใบ แล้วสแกนจากมือถือได้ (ตรวจว่า APP_URL ถูกต้อง ไม่ใช่ localhost)
- [ ] (ศูนย์ฯ) เว็บไซต์สาธารณะแสดงศูนย์ แผนที่ และรูปครบ / หน้าตรวจเยี่ยมเปิดประวัติเดิมได้
- [ ] ผู้ดูแลระบบกลางตรวจ/ปรับสิทธิ์ผู้ใช้แต่ละคนที่ **ผู้ใช้งานและสิทธิ์**
- [ ] เปลี่ยนรหัสผ่านบัญชีที่ใช้รหัสผ่านเริ่มต้น (เช่น `password123`)

## ขั้นที่ 8 — เปิดใช้งาน และเก็บระบบเดิม

- แจ้งเจ้าหน้าที่ URL ใหม่: `http://<เซิร์ฟเวอร์>/nsokhkaen/`
- เก็บฐานข้อมูลเดิมไว้อย่างน้อย 1–3 เดือน (อ่านอย่างเดียว) ก่อนลบ
- ห้ามใช้ระบบเดิมบันทึกข้อมูลต่อ เพราะข้อมูลจะไม่ถูกส่งต่อมาระบบใหม่

## การย้อนกลับ (Rollback)

ระบบใหม่ไม่แก้ไขฐานข้อมูลเดิม หากต้องย้อนกลับ:
1. นำโฟลเดอร์ระบบเดิม (ที่ย้ายเก็บไว้ในขั้นที่ 6) กลับมาแทนไฟล์ redirect
2. ใช้งานระบบเดิมต่อได้ทันที (ข้อมูลที่บันทึกในระบบใหม่หลังย้ายจะไม่อยู่ในระบบเดิม)

## อัปเดตฐานข้อมูลที่ย้ายไปแล้ว

- ถ้า **ยังไม่ได้ย้าย** หรือจะย้ายใหม่: ใช้ `migrate_all_in_one.sql` / `schema.sql` เวอร์ชันล่าสุดได้เลย (มีครบแล้ว)
- ถ้า **ย้ายไปแล้วด้วยไฟล์เวอร์ชันก่อนหน้า**: รันไฟล์ใน `database/migrations/` ที่ยังไม่เคยรัน ตามลำดับ (รันซ้ำได้ ไม่กระทบข้อมูลเดิม)

| ไฟล์ | เนื้อหา |
|---|---|
| `20261028_core_workgroup_inv_equipment.sql` | กลุ่มงานผู้ใช้ การแจ้งเตือน ทะเบียนครุภัณฑ์และตารางการยืม |
| `20261029_inv_loans_attachment.sql` | การยืมผ่าน e-office: แนบไฟล์ใบยืม + เลขที่หนังสือ (ตัดการอนุมัติในระบบ) |
| `20261030_inv_equipment_assigned_user.sql` | ผู้ใช้งานประจำของครุภัณฑ์ (คอลัมน์ "ผู้ยืม/ผู้ใช้งาน") |
| `20261031_payslip_init.sql` | ระบบสลิปเงินเดือนพนักงาน (ตาราง `pay_*`) |
| `20261101_payslip_signers.sql` | ผู้ลงนามในสลิปแยกตามช่วงเวลา (ย้ายผู้ลงนามเดิมเป็นคนแรกให้อัตโนมัติ) |
| `20261102_calendar_userprefs.sql` | ระบบปฏิทินการปฏิบัติงาน (`cal_*`) + ลำดับระบบที่ผู้ใช้จัดเอง (`core_user_prefs`) |
| `20261103_payslip_comments.sql` | สลิปเงินเดือน: ผู้อนุมัติแจ้งแก้ไขให้ผู้จัดการสลิป (`pay_slip_comments`) |
| `20261104_calendar_duty.sql` | ปฏิทิน: เวรสำนักงาน (`cal_events.kind`) |
| `20261105_core_user_photo.sql` | รูปโปรไฟล์ผู้ใช้ (`core_users.photo`) |

```bash
mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261028_core_workgroup_inv_equipment.sql
mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261029_inv_loans_attachment.sql
mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261030_inv_equipment_assigned_user.sql
mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261031_payslip_init.sql
mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261101_payslip_signers.sql
mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261102_calendar_userprefs.sql
mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261103_payslip_comments.sql
mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261104_calendar_duty.sql
mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261105_core_user_photo.sql
```

หลังอัปเดต:
1. ผู้ดูแลระบบกลาง → ผู้ใช้งานและสิทธิ์ → กำหนด **กลุ่มงาน** ให้ผู้ใช้แต่ละคน
2. วัสดุและครุภัณฑ์ → ครุภัณฑ์ → **นำเข้า CSV** (ไฟล์รายงานครุภัณฑ์คอมพิวเตอร์ที่บันทึกเป็น CSV)
3. ให้เว็บเซิร์ฟเวอร์เขียนโฟลเดอร์ `uploads/inventory/loans/` ได้ (ไฟล์ใบยืม — มี `.htaccess` ปิดการเปิดตรงจาก URL;
   ถ้าใช้ Nginx ให้ตั้ง `location /nsokhkaen/uploads/inventory/loans/ { deny all; }`)

## ย้ายระบบสลิปเงินเดือน (Google Apps Script + Google Sheet)

1. ผู้ดูแลระบบกลาง → ผู้ใช้งานและสิทธิ์: ให้สิทธิ์ระบบสลิปเงินเดือน
   - สถิติจังหวัด (ผู้อนุมัติเดิม กลุ่ม 9) → **ผู้อนุมัติ** · ผู้ที่ดูแล Google Sheet → **ผู้จัดการสลิป**
   - ผู้ดูแลระบบ (ตั้งค่าสลิป/ลายมือชื่อ และนำเข้าพร้อมสถานะอนุมัติเดิม) → **ผู้ดูแลระบบสลิป**
   - ผู้ดูแลระบบกลางไม่ได้สิทธิ์อัตโนมัติ (ข้อมูลส่วนบุคคล)
2. เปิด Google Sheet → ไฟล์ → ดาวน์โหลด → Microsoft Excel (.xlsx)
3. ผู้ดูแลระบบสลิปเข้า สลิปเงินเดือน → นำเข้าข้อมูล → เลือกไฟล์ → ติ๊ก **"ใช้สถานะจากคอลัมน์ อนุมัติ"** (สลิปเดิมที่อนุมัติแล้วจะไม่ต้องอนุมัติซ้ำ) → ยืนยัน
4. สลิปเงินเดือน → รายชื่อพนักงาน: ตรวจว่าทุกเลขที่ผูกกับบัญชีผู้ใช้แล้ว (ระบบผูกให้อัตโนมัติเมื่อชื่อ-นามสกุลตรงกัน)
   — พนักงานที่ยังไม่มีบัญชี ให้ผู้ดูแลระบบกลางสร้างบัญชีก่อน แล้วกลับมาเลือกผูก
5. ตั้งค่าสลิป: ผู้ลงนาม "ตั้งแต่เริ่มต้น" + รูปลายมือชื่อ (PNG พื้นโปร่งใส)
   — เมื่อเปลี่ยนผู้ลงนาม ให้ "เพิ่มผู้ลงนาม" พร้อมเดือนที่เริ่มใช้ สลิปเดือนก่อนหน้ายังแสดงผู้ลงนามเดิม
6. แจ้งพนักงานให้เข้าใช้ด้วยบัญชีของระบบบริหารจัดการ — **รหัสผ่านเดิมของระบบสลิป (ชีต UserData) ไม่ได้ย้ายมา**
7. เดือนถัดไป: ผู้จัดการสลิปนำเข้าไฟล์ของเดือนนั้น (หรือเพิ่มรายใบ) → ผู้อนุมัติกดอนุมัติ → พนักงานเห็นสลิปและได้รับแจ้งเตือน

## ปัญหาที่พบบ่อย

| อาการ | สาเหตุ / วิธีแก้ |
|---|---|
| `--check` ขาดคอลัมน์ `requisitions.approved_at` | รัน migration ของระบบเดิมให้ครบก่อน (`009_add_requisition_approval.sql`) |
| `ฐานข้อมูลปลายทางมีข้อมูล…อยู่แล้ว` | เคยรัน seed.sql หรือย้ายไปแล้ว — ลบฐานข้อมูล `nsokhkaen` แล้วรัน schema.sql ใหม่ |
| `ไม่พบฐานข้อมูล …` | ชื่อฐานข้อมูลไม่ตรง หรือผู้ใช้ใน config.local.php ไม่มีสิทธิ์ SELECT ฐานข้อมูลเดิม |
| รูปวัสดุไม่ขึ้น | ยังไม่ได้คัดลอก `uploads/materials` → `uploads/inventory/materials` |
| สแกน QR แล้วเปิด localhost | ตั้ง APP_URL ที่ผู้ดูแลระบบกลาง → ตั้งค่าระบบ ให้เป็น IP/โดเมนที่มือถือเข้าถึงได้ |
| สติ๊กเกอร์เดิม 404 | ยังไม่ได้ทำขั้นที่ 6 หรือ `NEW_APP_URL` ใน redirect-config.php ไม่ถูกต้อง |
| ภาษาไทยเพี้ยนหลังนำเข้า SQL | นำเข้าด้วย `mysql --default-character-set=utf8mb4` (ไฟล์ SQL มี `SET NAMES utf8mb4` แล้ว) |
