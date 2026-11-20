<?php
// Printable catalog of materials, each item showing its photo, a scannable
// QR code, code and name only (no other details) - large cards, 2x3 (6)
// per A4 portrait page, for printing and keeping as a physical reference
// binder/board.
require_once __DIR__ . '/bootstrap.php';
requireAdmin();

$pdo = getDbConnection();

// ลำดับในแคตตาล็อก: รายการในชุดที่พิมพ์แล้ว (id <= inv_catalog_base_id) เรียงตามรหัสวัสดุเหมือนเดิม
// แล้วต่อด้วยรายการที่เพิ่มใหม่ตามลำดับที่เพิ่ม → หน้าที่พิมพ์ไว้แล้วไม่เลื่อน พิมพ์ใหม่เฉพาะหน้าท้าย
// ยังไม่ตั้งชุดที่พิมพ์แล้ว = เรียงตามรหัสทั้งหมด (แบบเดิม)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    save_setting('inv_catalog_base_id', (string)max(0, (int)($_POST['base_id'] ?? 0)));
    header('Location: catalog_print');
    exit;
}
$baseId = (int)setting('inv_catalog_base_id', '0');
$cut = $baseId > 0 ? $baseId : PHP_INT_MAX;
$st = $pdo->prepare("
    SELECT id, material_code AS code, qr_code, name, image_path, created_at
    FROM inv_materials
    ORDER BY id > :c1, CASE WHEN id > :c2 THEN id END, material_code ASC
");
$st->execute(['c1' => $cut, 'c2' => $cut]);
$rows = $st->fetchAll();
$title = 'แคตตาล็อกวัสดุสิ้นเปลือง';

// 6 cards (2 columns x 3 rows) per A4 page.
$pages = array_chunk($rows, 6);
$firstNew = null;                                   // ลำดับแรกที่เป็นรายการใหม่ (หลังชุดที่พิมพ์แล้ว)
foreach ($rows as $i => $r) {
    if ((int)$r['id'] > $cut) {
        $firstNew = $i;
        break;
    }
}
$newCount = $firstNew === null ? 0 : count($rows) - $firstNew;
$newPage = $firstNew === null ? null : intdiv($firstNew, 6) + 1;   // หน้าแรกที่ต้องพิมพ์ใหม่
$onlyNew = isset($_GET['new']) && $newPage !== null;             // แสดงเฉพาะหน้าที่มีรายการใหม่ (สำหรับพิมพ์)
$recent = $pdo->query('SELECT id, material_code, name, created_at FROM inv_materials ORDER BY id DESC LIMIT 60')->fetchAll();
$maxId = (int)($recent[0]['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        /* Embeds the font file from this server instead of relying on it
           being installed locally or on an external CDN - see
           public/requisition_print.php for why. */
        @font-face {
            font-family: 'TH Sarabun New';
            src: url('<?= BASE_URL ?>assets/fonts/THSarabunNew.ttf') format('truetype');
            font-weight: normal; font-style: normal;
        }
        @font-face {
            font-family: 'TH Sarabun New';
            src: url('<?= BASE_URL ?>assets/fonts/THSarabunNew-Bold.ttf') format('truetype');
            font-weight: bold; font-style: normal;
        }
        @page { size: A4 portrait; margin: 12mm; }
        body { font-family: 'TH Sarabun New', 'Tahoma', sans-serif; margin: 20px; color: #000; }
        .toolbar { margin-bottom: 20px; }
        h2 { text-align: center; margin-bottom: 2px; }
        .subtitle { text-align: center; color: #555; margin-top: 0; margin-bottom: 24px; }
        .page {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(3, 1fr);
            gap: 8mm;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }
        .card {
            border: 1px solid #999;
            border-radius: 8px;
            padding: 14px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: auto auto auto;
            gap: 8px 14px;
            page-break-inside: avoid;
        }
        .card .photo,
        .card .photo-placeholder,
        .card .qr {
            grid-row: 1;
            width: 100%;
            aspect-ratio: 1 / 1;
        }
        .card .photo, .card .photo-placeholder { grid-column: 1; }
        .card .qr { grid-column: 2; }
        .card .photo {
            object-fit: cover;
            border: 1px solid #ccc;
            border-radius: 6px;
        }
        .card .photo-placeholder {
            border: 1px dashed #ccc;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #bbb;
            font-size: 13px;
        }
        .card .code { grid-column: 1; grid-row: 2; font-size: 14px; color: #555; }
        .card .name { grid-column: 1 / -1; grid-row: 3; font-weight: bold; font-size: 24px; line-height: 1.25; }
        .toolbar .box { margin-top: 8px; padding: 8px 12px; border: 1px solid #c34397; border-radius: 8px; background: #fdf2f8; font-size: 18px; }
        .toolbar .base-form { margin-top: 6px; }
        .toolbar select { max-width: 520px; font-family: inherit; font-size: 16px; }
        @media print {
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()"><?= $onlyNew ? 'พิมพ์เฉพาะหน้าที่มีรายการใหม่' : 'พิมพ์แคตตาล็อก' ?></button>
    <span>ทั้งหมด <?= count($rows) ?> รายการ · <?= count($pages) ?> หน้า</span>
    <?php if ($onlyNew): ?> · <a href="catalog_print">แสดงทั้งหมด</a><?php endif; ?>
    <div class="box">
        <?php if ($baseId <= 0): ?>
            ยังไม่ได้ระบุชุดที่พิมพ์แล้ว — ตอนนี้เรียงตามรหัสวัสดุทั้งหมด · เลือกรายการสุดท้ายที่อยู่ในแคตตาล็อกที่พิมพ์ไว้
            แล้วรายการที่เพิ่มหลังจากนั้นจะไปอยู่ท้ายแคตตาล็อก
        <?php elseif ($newCount): ?>
            <b>รายการใหม่ <?= $newCount ?> รายการ</b> อยู่ท้ายแคตตาล็อก เริ่มที่<b>หน้า <?= $newPage ?></b>
            → พิมพ์ใหม่หน้า <?= $newPage ?><?= $newPage < count($pages) ? '–' . count($pages) : '' ?>
            <?php if (!$onlyNew): ?> · <a href="catalog_print?new=1">แสดงเฉพาะหน้า <?= $newPage ?><?= $newPage < count($pages) ? '–' . count($pages) : '' ?> เพื่อพิมพ์</a><?php endif; ?>
        <?php else: ?>
            ไม่มีรายการใหม่หลังชุดที่พิมพ์แล้ว
        <?php endif; ?>
        <form method="post" class="base-form">
            <?= csrf_field() ?>
            <label>ชุดที่พิมพ์แล้วถึงรายการ:
                <select name="base_id">
                    <option value="<?= $maxId ?>">ทุกรายการตอนนี้ (พิมพ์ครบแล้ว)</option>
                    <?php foreach ($recent as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === $baseId ? 'selected' : '' ?>><?= htmlspecialchars($r['material_code'] . ' ' . $r['name'] . ' · เพิ่มเมื่อ ' . thai_date($r['created_at'])) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <button>บันทึก</button>
            <small>(เรียงจากรายการที่เพิ่มล่าสุด · พิมพ์หน้าใหม่แล้ว กด "ทุกรายการตอนนี้" เพื่อเริ่มนับรายการใหม่รอบต่อไป)</small>
        </form>
    </div>
</div>

<?php if (!$onlyNew): ?>
<h2><?= htmlspecialchars($title) ?></h2>
<p class="subtitle">สำนักงานสถิติจังหวัดขอนแก่น — พิมพ์เมื่อ <?= date('d/m/') . ((int) date('Y') + 543) ?></p>
<?php endif; ?>

<?php foreach ($pages as $pi => $items): if ($onlyNew && $pi + 1 < $newPage) continue; ?>
    <div class="page">
        <?php foreach ($items as $item): ?>
            <div class="card">
                <?php if ($item['image_path']): ?>
                    <img class="photo" src="<?= BASE_URL ?><?= htmlspecialchars($item['image_path']) ?>" alt="">
                <?php else: ?>
                    <div class="photo-placeholder">ไม่มีรูป</div>
                <?php endif; ?>
                <img class="qr" src="qr_image?data=<?= urlencode(buildQrScanUrl($item['qr_code'])) ?>" alt="QR">
                <div class="code"><?= htmlspecialchars($item['code']) ?></div>
                <div class="name"><?= htmlspecialchars($item['name']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php if (!$rows): ?>
    <p class="text-muted">ยังไม่มีข้อมูล</p>
<?php endif; ?>

</body>
</html>
