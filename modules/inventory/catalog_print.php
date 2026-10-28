<?php
// Printable catalog of materials, each item showing its photo, a scannable
// QR code, code and name only (no other details) - large cards, 2x3 (6)
// per A4 portrait page, for printing and keeping as a physical reference
// binder/board.
require_once __DIR__ . '/bootstrap.php';
requireAdmin();

$pdo = getDbConnection();
$rows = $pdo->query("
    SELECT material_code AS code, qr_code, name, image_path
    FROM inv_materials
    ORDER BY material_code ASC
")->fetchAll();
$title = 'แคตตาล็อกวัสดุสิ้นเปลือง';

// 6 cards (2 columns x 3 rows) per A4 page.
$pages = array_chunk($rows, 6);
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
        @media print {
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">พิมพ์แคตตาล็อก</button>
    <span>ทั้งหมด <?= count($rows) ?> รายการ</span>
</div>

<h2><?= htmlspecialchars($title) ?></h2>
<p class="subtitle">สำนักงานสถิติจังหวัดขอนแก่น — พิมพ์เมื่อ <?= date('d/m/') . ((int) date('Y') + 543) ?></p>

<?php foreach ($pages as $items): ?>
    <div class="page">
        <?php foreach ($items as $item): ?>
            <div class="card">
                <?php if ($item['image_path']): ?>
                    <img class="photo" src="<?= BASE_URL ?><?= htmlspecialchars($item['image_path']) ?>" alt="">
                <?php else: ?>
                    <div class="photo-placeholder">ไม่มีรูป</div>
                <?php endif; ?>
                <img class="qr" src="qr_image.php?data=<?= urlencode(buildQrScanUrl($item['qr_code'])) ?>" alt="QR">
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
