# โครงสร้างระบบ และการเพิ่มระบบใหม่

## ภาพรวม

```
nsokhkaen/
├── index.php             หน้าหลัก (เลือกระบบตามสิทธิ์)
├── login.php / pin.php   เข้าสู่ระบบด้วยรหัสผ่าน / PIN 6 หลัก (ใช้ร่วมทุกระบบ)
├── account.php           บัญชีของฉัน (ข้อมูลส่วนตัว รหัสผ่าน PIN)
├── admin/                ผู้ดูแลระบบกลาง: ผู้ใช้งานและสิทธิ์ ตั้งค่าระบบ บันทึกการใช้งาน
├── core/                 โค้ดกลาง (ห้ามเข้าถึงจากเว็บ)
│   ├── bootstrap.php     จุดเริ่มต้นทุกหน้า: config, db, session, BASE_URL/APP_URL
│   ├── auth.php          login/logout, current_user(), สิทธิ์รายระบบ (require_module ฯลฯ)
│   ├── modules.php       อ่านทะเบียนระบบจาก modules/*/module.php
│   ├── helpers.php       h(), url(), CSRF, flash, อัปโหลดรูป, audit_log()
│   └── layout/           header/footer + เมนูด้านข้างที่ใช้ร่วมกัน
├── modules/
│   ├── inventory/        ระบบบริหารจัดการวัสดุ      (ตาราง inv_*)
│   └── digitalcc/        ระบบศูนย์ดิจิทัลชุมชน       (ตาราง dcc_*)
│       └── public/       เว็บไซต์สาธารณะ (ไม่ต้อง login)
├── assets/               CSS/JS/ฟอนต์ ที่ใช้ร่วมกัน
├── libs/                 phpqrcode, SimpleXLSX (ไม่ต้องใช้ Composer)
├── uploads/<module>/     ไฟล์อัปโหลดแยกตามระบบ
├── database/
│   ├── schema.sql        โครงสร้างทุกตาราง
│   ├── seed.sql          ข้อมูลเริ่มต้น (ติดตั้งใหม่เท่านั้น)
│   ├── migrations/       ไฟล์ปรับโครงสร้างในอนาคต (ตั้งชื่อ YYYYMMDD_<module>_<เรื่อง>.sql)
│   └── legacy/           สคริปต์ย้ายข้อมูลจากระบบเดิม
├── tools/migrate_legacy.php   เครื่องมือย้ายข้อมูล (command line)
└── legacy-redirects/     ไฟล์ redirect สำหรับ URL/QR ของระบบเดิม
```

## ฐานข้อมูล: แยกตารางด้วย prefix

| prefix | ระบบ | หมายเหตุ |
|---|---|---|
| `core_` | ระบบกลาง | `core_users`, `core_user_roles`, `core_settings`, `core_audit_log` |
| `inv_`  | วัสดุ | อ้างอิงผู้ใช้ที่ `core_users.id` |
| `dcc_`  | ศูนย์ดิจิทัลชุมชน | อ้างอิงผู้ใช้ที่ `core_users.id` |

ระบบใหม่ใช้ prefix ของตัวเอง (สั้น 3–4 ตัวอักษร) และ **ห้ามแก้ตารางของระบบอื่นโดยตรง**
ยกเว้นอ่าน `core_users` เพื่อแสดงชื่อผู้ใช้

## สิทธิ์ (Permissions)

- ผู้ใช้ 1 คน = 1 บัญชีใน `core_users` (ชื่อผู้ใช้+รหัสผ่าน และ/หรือ PIN)
- สิทธิ์แต่ละระบบอยู่ใน `core_user_roles (user_id, module_key, role)` — ไม่มีแถว = เข้าระบบนั้นไม่ได้
- `core_users.is_superadmin = 1` = ผู้ดูแลระบบกลาง: จัดการผู้ใช้/สิทธิ์ได้ และถือ role สูงสุดของทุกระบบ
- role ของแต่ละระบบประกาศใน `module.php` เรียงจากต่ำ → สูง ใช้เปรียบเทียบระดับได้

ฟังก์ชันที่ใช้ในโค้ด:

```php
require_module('inventory');            // หน้า: ต้องมีสิทธิ์ใดก็ได้ในระบบวัสดุ (ไม่ login → หน้า login)
require_module('digitalcc', 'editor');  // หน้า: ต้องเป็น editor ขึ้นไป (ไม่มีสิทธิ์ → 403)
require_module_api('inventory', 'admin'); // API (JSON): 401/403 และตรวจ CSRF เมื่อ POST
user_can('digitalcc', 'admin');         // true/false สำหรับซ่อน/แสดงปุ่ม
module_role('inventory');               // role ปัจจุบัน หรือ null
```

session เก็บเฉพาะ `uid` และโหลดสิทธิ์จากฐานข้อมูลทุก request → ปิดบัญชี/เปลี่ยนสิทธิ์มีผลทันที

## ความปลอดภัย

- ทุกฟอร์ม POST ใช้ `csrf_field()` + `check_csrf()`; AJAX ของ jQuery ส่ง header `X-CSRF-Token` ให้อัตโนมัติ (ตั้งใน layout)
- SQL ใช้ prepared statement ทั้งหมด
- รหัสผ่าน/PIN เก็บด้วย `password_hash()`; หน่วงเวลาเมื่อกรอกผิดหลายครั้ง; บันทึกการ login ใน `core_audit_log`
- `core/ database/ libs/ tools/ docs/` และ `config*.php` ถูกปิดด้วย `.htaccess` (Apache) — ถ้าใช้ Nginx ต้องตั้ง deny เอง
- `uploads/` ห้ามรันสคริปต์

## เพิ่มระบบใหม่ (ตัวอย่าง: ระบบจองห้องประชุม `room`)

1. สร้างโฟลเดอร์ `modules/room/` และไฟล์ `modules/room/module.php`

   ```php
   <?php
   return [
       'key'         => 'room',                       // ต้องตรงกับชื่อโฟลเดอร์
       'name'        => 'ระบบจองห้องประชุม',
       'short'       => 'จองห้องประชุม',
       'icon'        => 'bi-calendar2-week',           // Bootstrap Icons
       'color'       => '#7c3aed',
       'description' => 'จองและอนุมัติการใช้ห้องประชุม',
       'order'       => 30,
       'roles' => [                                    // ต่ำ → สูง
           'user'  => 'ผู้ใช้ — จองห้อง ดูการจองของตนเอง',
           'admin' => 'ผู้ดูแล — อนุมัติ/ยกเลิก จัดการห้อง',
       ],
       'menu' => [
           ['id' => 'calendar', 'label' => 'ปฏิทินห้อง', 'href' => 'index.php',  'icon' => 'bi-calendar3', 'role' => 'user'],
           ['id' => 'rooms',    'label' => 'จัดการห้อง', 'href' => 'rooms.php',  'icon' => 'bi-door-open', 'role' => 'admin'],
       ],
   ];
   ```

2. สร้างหน้าแรก `modules/room/index.php`

   ```php
   <?php
   require_once __DIR__ . '/../../core/bootstrap.php';
   $user = require_module('room');                 // ตรวจสิทธิ์ก่อนแสดงผล
   $module = 'room';
   $active = 'calendar';
   $pageTitle = 'ปฏิทินห้องประชุม';
   require APP_ROOT . '/core/layout/header.php';
   ?>
   <div class="card"><div class="card-body">…</div></div>
   <?php require APP_ROOT . '/core/layout/footer.php';
   ```

3. สร้างตาราง prefix `room_` ในไฟล์ `database/migrations/YYYYMMDD_room_init.sql` (และเพิ่มใน `schema.sql`)
   อ้างอิงผู้ใช้ด้วย `FOREIGN KEY (user_id) REFERENCES core_users(id)`
4. ไฟล์อัปโหลดใช้ `save_upload($file, 'room/xxx')` → เก็บที่ `uploads/room/xxx/`
5. เข้า **ผู้ดูแลระบบกลาง → ผู้ใช้งานและสิทธิ์** ระบบใหม่จะมีช่องกำหนดสิทธิ์ให้อัตโนมัติ

ไม่ต้องแก้โค้ดส่วนกลาง เมนูด้านข้าง หน้าหลัก และหน้าจัดการสิทธิ์จะแสดงระบบใหม่เองจาก `module.php`

## หมายเหตุเกี่ยวกับโค้ดระบบวัสดุ

โค้ดระบบวัสดุย้ายมาจาก `nsokhkaen_inventory` แทบทั้งหมด `modules/inventory/bootstrap.php` มีชั้นเชื่อมต่อ
ที่คงชื่อฟังก์ชันเดิม (`currentUser()`, `isAdmin()`, `requireLoginApi()`, `getDbConnection()` ฯลฯ)
แต่ทำงานบน login/สิทธิ์กลาง ทำให้ตรรกะเดิม (ใบเบิก การตัดสต๊อก รายงาน นำเข้า Excel) ไม่ต้องเขียนใหม่
