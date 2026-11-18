<?php
/**
 * แปลงไฟล์ SQL ของระบบให้ใช้ชื่อฐานข้อมูลจริง (DB_NAME ใน config.local.php) แทน "nsokhkaen"
 *   ใช้เมื่อฐานข้อมูลบนเซิร์ฟเวอร์ไม่ได้ชื่อ nsokhkaen (เช่น db_khkaen) — ไฟล์ใน database/ เขียน USE nsokhkaen; ไว้
 *
 *   php tools/sql_use_db.php database/schema.sql database/seed.sql
 *   php tools/sql_use_db.php database/migrations            (ทุกไฟล์ .sql ในโฟลเดอร์)
 *
 * ผลลัพธ์: storage/sql/<ชื่อไฟล์เดิม> (UTF-8 ไม่มี BOM) — นำไปรันด้วย mysql หรือ Import ใน phpMyAdmin/HeidiSQL ได้เลย
 * ไม่แก้ไฟล์ต้นฉบับ · ชื่อที่ต่อด้วย _ เช่น nsokhkaen_inventory (ฐานข้อมูลระบบเดิม) ไม่ถูกเปลี่ยน
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../config.php';

$args = array_slice($argv, 1);
if (!$args) {
    fwrite(STDERR, "วิธีใช้: php tools/sql_use_db.php <ไฟล์.sql | โฟลเดอร์> ...\n");
    exit(1);
}
$files = [];
foreach ($args as $a) {
    if (is_dir($a)) {
        $list = glob(rtrim($a, '/\\') . '/*.sql');
        sort($list);
        $files = array_merge($files, $list);
    } elseif (is_file($a)) {
        $files[] = $a;
    } else {
        fwrite(STDERR, "ไม่พบ: $a\n");
        exit(1);
    }
}
if (DB_NAME === 'nsokhkaen') {
    echo "DB_NAME ยังเป็น nsokhkaen — ไม่ต้องแปลง (ตั้ง DB_NAME ใน config.local.php ก่อน ถ้าฐานข้อมูลชื่ออื่น)\n";
    exit(0);
}
if (!preg_match('/^[A-Za-z0-9_]+$/', DB_NAME)) {
    fwrite(STDERR, "ชื่อฐานข้อมูลมีอักขระที่ไม่รองรับ: " . DB_NAME . "\n");
    exit(1);
}
$outDir = __DIR__ . '/../storage/sql';
if (!is_dir($outDir) && !mkdir($outDir, 0750, true)) {
    fwrite(STDERR, "สร้างโฟลเดอร์ $outDir ไม่ได้\n");
    exit(1);
}
foreach ($files as $f) {
    $sql = file_get_contents($f);
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    $n = 0;
    $sql = preg_replace('/(?<![A-Za-z0-9_])nsokhkaen(?![A-Za-z0-9_])/', DB_NAME, $sql, -1, $n);
    $out = $outDir . '/' . basename($f);
    file_put_contents($out, $sql);
    printf("%-55s → storage/sql/%s (แทนที่ %d จุด)\n", $f, basename($f), $n);
}
echo "\nเสร็จ — รันไฟล์ใน storage/sql/ ตามลำดับเดิม (ฐานข้อมูล: " . DB_NAME . ")\n";
