<?php
/**
 * เครื่องมือย้ายข้อมูลจากระบบเดิมเข้าสู่ระบบใหม่ (รันจาก command line เท่านั้น)
 *
 *   php tools/migrate_legacy.php --check  [ตัวเลือก]   ตรวจความพร้อมอย่างเดียว ไม่เขียนข้อมูล
 *   php tools/migrate_legacy.php --run    [ตัวเลือก]   ย้ายข้อมูลจริง
 *
 * ตัวเลือก:
 *   --inventory-db=NAME     ฐานข้อมูลระบบวัสดุเดิม (ค่าเริ่มต้น nsokhkaen_inventory; ใส่ --skip-inventory เพื่อข้าม)
 *   --inventory-dir=PATH    โฟลเดอร์โปรเจกต์ระบบวัสดุเดิม (เพื่อคัดลอกรูปจาก uploads/)
 *   --digitalcc-db=NAME     ฐานข้อมูลระบบศูนย์ดิจิทัลชุมชนเดิม (ไม่ใส่ = ข้าม)
 *   --digitalcc-dir=PATH    โฟลเดอร์โปรเจกต์ระบบศูนย์ฯ เดิม (เพื่อคัดลอกรูปจาก uploads/)
 *   --app-url=URL           URL ของระบบใหม่ เช่น http://192.168.1.50/nsokhkaen/ (ใช้กับ QR Code)
 *
 * ฐานข้อมูลปลายทางอ่านจาก config.php / config.local.php (DB_NAME) และต้องสร้างจาก database/schema.sql แล้ว
 * ผู้ใช้ฐานข้อมูลใน config ต้องมีสิทธิ์ SELECT บนฐานข้อมูลเดิมด้วย
 * รายละเอียด: docs/MIGRATION.md
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('command line only');
}
require __DIR__ . '/../core/bootstrap.php';

$opts = getopt('', ['check', 'run', 'skip-inventory', 'inventory-db:', 'inventory-dir:', 'digitalcc-db:', 'digitalcc-dir:', 'app-url:', 'help']);
if (isset($opts['help']) || (!isset($opts['check']) && !isset($opts['run']))) {
    echo preg_replace('/^\s*\* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__))[1])[0]), "\n";
    exit(isset($opts['help']) ? 0 : 1);
}
$run = isset($opts['run']);
$invDb = isset($opts['skip-inventory']) ? null : ($opts['inventory-db'] ?? 'nsokhkaen_inventory');
$dccDb = $opts['digitalcc-db'] ?? null;
$invDir = isset($opts['inventory-dir']) ? rtrim($opts['inventory-dir'], '/\\') : null;
$dccDir = isset($opts['digitalcc-dir']) ? rtrim($opts['digitalcc-dir'], '/\\') : null;
$appUrl = $opts['app-url'] ?? null;

$pdo = db();
$problems = [];
$ok = fn(string $m) => print("  [OK]   $m\n");
$bad = function (string $m) use (&$problems) { $problems[] = $m; print("  [ERR]  $m\n"); };
$warn = fn(string $m) => print("  [WARN] $m\n");

function db_exists(PDO $pdo, string $name): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
}

function missing_columns(PDO $pdo, string $db, array $need): array
{
    $missing = [];
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($need as $tc) {
        [$t, $c] = explode('.', $tc);
        $st->execute([$db, $t, $c]);
        if (!$st->fetchColumn()) {
            $missing[] = $tc;
        }
    }
    return $missing;
}

echo "\n== ตรวจฐานข้อมูลปลายทาง: " . DB_NAME . "\n";
try {
    $counts = [];
    foreach (['core_users', 'inv_materials', 'inv_requisitions', 'dcc_centers'] as $t) {
        $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }
    $ok('มีตารางของระบบใหม่ครบ (สร้างจาก schema.sql แล้ว)');
    if ($invDb && ($counts['core_users'] || $counts['inv_materials'] || $counts['inv_requisitions'])) {
        $bad('ฐานข้อมูลปลายทางมีข้อมูลผู้ใช้/วัสดุอยู่แล้ว — ต้องเริ่มจากฐานข้อมูลว่าง (รัน schema.sql ใหม่ และห้ามรัน seed.sql)');
    }
    if ($dccDb && $counts['dcc_centers']) {
        $bad('ฐานข้อมูลปลายทางมีข้อมูลศูนย์ดิจิทัลชุมชนอยู่แล้ว');
    }
} catch (PDOException $e) {
    $bad('ยังไม่มีตารางของระบบใหม่ — รัน database/schema.sql ก่อน (' . $e->getMessage() . ')');
}

if ($invDb) {
    echo "\n== ตรวจระบบวัสดุเดิม: $invDb\n";
    if (!db_exists($pdo, $invDb)) {
        $bad("ไม่พบฐานข้อมูล $invDb (หรือผู้ใช้ฐานข้อมูลไม่มีสิทธิ์ SELECT)");
    } else {
        $miss = missing_columns($pdo, $invDb, ['users.pin_hash', 'users.position', 'materials.unit_cost', 'materials.category_code',
            'materials.image_path', 'requisitions.approved_at', 'settings.key', 'storage_locations.name']);
        $miss ? $bad('ฐานข้อมูลเดิมยังรัน migration ของระบบเดิมไม่ครบ ขาด: ' . implode(', ', $miss) . ' (ดู README ของระบบเดิม)')
              : $ok('โครงสร้างเป็นเวอร์ชันล่าสุด (ผ่าน migration 001–009)');
        foreach (['users', 'materials', 'requisitions', 'material_transactions'] as $t) {
            $n = $pdo->query("SELECT COUNT(*) FROM `$invDb`.`$t`")->fetchColumn();
            echo "         $t: $n แถว\n";
        }
        $dups = $pdo->query("SELECT COUNT(*) FROM `$invDb`.`users` WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        $dups ? $ok("ผู้ดูแลระบบเดิม $dups คน จะเป็นผู้ดูแลระบบกลาง (is_superadmin)")
              : $warn('ไม่มีผู้ดูแลระบบ (admin) ที่ใช้งานอยู่ — หลังย้ายต้องตั้งผู้ดูแลระบบกลางเองในฐานข้อมูล');
    }
    if ($invDir) {
        is_dir("$invDir/uploads") ? $ok("พบโฟลเดอร์รูปภาพ $invDir/uploads") : $bad("ไม่พบโฟลเดอร์ $invDir/uploads");
    } else {
        $warn('ไม่ได้ระบุ --inventory-dir จะไม่คัดลอกรูปวัสดุ (คัดลอกเองได้ภายหลัง: uploads/materials → uploads/inventory/materials)');
    }
}

if ($dccDb) {
    echo "\n== ตรวจระบบศูนย์ดิจิทัลชุมชนเดิม: $dccDb\n";
    if (!db_exists($pdo, $dccDb)) {
        $bad("ไม่พบฐานข้อมูล $dccDb (หรือผู้ใช้ฐานข้อมูลไม่มีสิทธิ์ SELECT)");
    } else {
        $miss = missing_columns($pdo, $dccDb, ['centers.code', 'centers.house_no', 'centers.moo', 'visits.data', 'users.fullname']);
        $miss ? $bad('ฐานข้อมูลเดิมยังไม่ได้รัน database/migrate_visits.sql ของระบบเดิม ขาด: ' . implode(', ', $miss))
              : $ok('โครงสร้างเป็นเวอร์ชันล่าสุด');
        // ชื่อผู้ใช้ที่ซ้ำกับบัญชีในระบบใหม่ หรือในระบบวัสดุเดิมที่กำลังจะย้ายเข้ามา
        $sql = "SELECT u.username FROM `$dccDb`.`users` u WHERE EXISTS (SELECT 1 FROM core_users c WHERE c.username = u.username COLLATE utf8mb4_unicode_ci)";
        if ($invDb && db_exists($pdo, $invDb)) {
            $sql .= " OR EXISTS (SELECT 1 FROM `$invDb`.`users` i WHERE i.username = u.username COLLATE utf8mb4_unicode_ci)";
        }
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $warn("ชื่อผู้ใช้ \"$u\" มีอยู่แล้ว — จะรวมเป็นบัญชีเดียว (ใช้รหัสผ่านของบัญชีที่มีอยู่) และเพิ่มสิทธิ์ระบบศูนย์ฯ");
        }
    }
    if ($dccDir) {
        is_dir("$dccDir/uploads") ? $ok("พบโฟลเดอร์รูปภาพ $dccDir/uploads") : $bad("ไม่พบโฟลเดอร์ $dccDir/uploads");
    } else {
        $warn('ไม่ได้ระบุ --digitalcc-dir จะไม่คัดลอกรูป (คัดลอกเองได้ภายหลัง: uploads/{centers,staff,activities} → uploads/digitalcc/)');
    }
}

if (!is_writable(UPLOAD_DIR)) {
    $bad('โฟลเดอร์ uploads/ ของระบบใหม่เขียนไม่ได้');
}

if ($problems) {
    echo "\nพบปัญหา " . count($problems) . " รายการ — แก้ไขก่อนย้ายข้อมูล\n";
    exit(1);
}
if (!$run) {
    echo "\nพร้อมย้ายข้อมูล — สั่งจริงด้วย --run (สำรองฐานข้อมูลก่อนเสมอ)\n";
    exit(0);
}

/** รันไฟล์ SQL ทีละคำสั่ง แทนชื่อฐานข้อมูลตามตัวเลือก แล้วแสดงตารางตรวจนับท้ายไฟล์ */
function run_sql_file(PDO $pdo, string $file, array $replace): void
{
    $sql = strtr(file_get_contents($file), $replace);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))) as $stmt) {
        if (stripos($stmt, 'SELECT') === 0) {
            foreach ($pdo->query($stmt)->fetchAll() as $r) {
                $mark = (string)$r['legacy'] === (string)$r['migrated'] ? 'OK ' : '!! ';
                printf("  %s %-24s เดิม %-8s ใหม่ %s\n", $mark, $r['tbl'], $r['legacy'], $r['migrated']);
            }
            continue;
        }
        $pdo->exec($stmt);
    }
}

function copy_tree(string $from, string $to): int
{
    if (!is_dir($from)) {
        return 0;
    }
    $n = 0;
    @mkdir($to, 0755, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile() || $f->getFilename() === '.gitkeep' || $f->getFilename() === '.htaccess') {
            continue;
        }
        $dest = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
        @mkdir(dirname($dest), 0755, true);
        if (copy($f->getPathname(), $dest)) {
            $n++;
        }
    }
    return $n;
}

$replaceUse = ['USE nsokhkaen;' => 'USE `' . DB_NAME . '`;'];
try {
    if ($invDb) {
        echo "\n== ย้ายข้อมูลระบบวัสดุ\n";
        run_sql_file($pdo, APP_ROOT . '/database/legacy/01_inventory.sql', $replaceUse + ['`nsokhkaen_inventory`' => "`$invDb`"]);
        if ($invDir) {
            echo '  คัดลอกรูปวัสดุ: ' . copy_tree("$invDir/uploads/materials", UPLOAD_DIR . '/inventory/materials') . " ไฟล์\n";
            copy_tree("$invDir/uploads/assets", UPLOAD_DIR . '/inventory/assets');
        }
    }
    if ($dccDb) {
        echo "\n== ย้ายข้อมูลระบบศูนย์ดิจิทัลชุมชน\n";
        run_sql_file($pdo, APP_ROOT . '/database/legacy/02_digitalcc.sql', $replaceUse + ['`digitalcc_khonkaen`' => "`$dccDb`"]);
        if ($dccDir) {
            foreach (['centers', 'staff', 'activities'] as $d) {
                echo "  คัดลอกรูป $d: " . copy_tree("$dccDir/uploads/$d", UPLOAD_DIR . "/digitalcc/$d") . " ไฟล์\n";
            }
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n[ERR] ย้ายข้อมูลไม่สำเร็จ: " . $e->getMessage() . "\n";
    echo "      ฐานข้อมูลปลายทางอาจมีข้อมูลบางส่วนแล้ว — ลบฐานข้อมูลใหม่ทิ้ง รัน schema.sql ใหม่ แล้วลองอีกครั้ง\n";
    exit(1);
}

$pdo->exec("INSERT IGNORE INTO core_settings (`key`, `value`) VALUES
    ('app_name', 'ระบบบริหารจัดการ สำนักงานสถิติจังหวัดขอนแก่น'), ('org_name', 'สำนักงานสถิติจังหวัดขอนแก่น')");
if (!(int)$pdo->query('SELECT COUNT(*) FROM core_users WHERE is_superadmin = 1 AND is_active = 1')->fetchColumn()) {
    echo "\n  [WARN] ยังไม่มีผู้ดูแลระบบกลาง — กำหนดด้วยคำสั่ง SQL:\n"
        . "         UPDATE core_users SET is_superadmin = 1 WHERE username = 'ชื่อผู้ใช้';\n";
}

if ($appUrl) {
    save_setting('app_url', rtrim($appUrl, '/') . '/');
    echo "\n  ตั้ง APP_URL = " . rtrim($appUrl, '/') . "/\n";
}

// ตรวจว่าไฟล์รูปที่ฐานข้อมูลอ้างถึงมีอยู่จริง
echo "\n== ตรวจไฟล์รูปภาพที่อ้างถึงในฐานข้อมูล\n";
$missing = 0;
$checks = [
    "SELECT image_path FROM inv_materials WHERE image_path IS NOT NULL AND image_path <> ''" => APP_ROOT . '/',
    "SELECT cover_image FROM dcc_centers WHERE cover_image <> ''" => UPLOAD_DIR . '/',
    "SELECT file FROM dcc_center_images" => UPLOAD_DIR . '/',
    "SELECT photo FROM dcc_center_staff WHERE photo <> ''" => UPLOAD_DIR . '/',
    "SELECT cover_image FROM dcc_activities WHERE cover_image <> ''" => UPLOAD_DIR . '/',
    "SELECT file FROM dcc_activity_images" => UPLOAD_DIR . '/',
];
foreach ($checks as $sql => $base) {
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $p) {
        if (!is_file($base . $p)) {
            $missing++;
            if ($missing <= 10) {
                echo "  ไม่พบไฟล์: $p\n";
            }
        }
    }
}
echo $missing ? "  [WARN] ไม่พบไฟล์รูป $missing ไฟล์ — คัดลอกจากโฟลเดอร์ uploads/ ของระบบเดิมเพิ่ม\n" : "  [OK]   ไฟล์รูปครบ\n";

echo "\nย้ายข้อมูลเสร็จ — ขั้นต่อไป: ตั้ง redirect ของ URL เดิม และทดสอบตาม docs/MIGRATION.md\n";
