<?php
require_once __DIR__ . '/bootstrap.php';
requireAdmin();

$pdo = getDbConnection();
$items = $pdo->query('SELECT material_code AS code, qr_code, name FROM inv_materials ORDER BY id')->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>พิมพ์ QR Code</title>
    <style>
        body { font-family: 'Tahoma', sans-serif; margin: 20px; }
        .toolbar { margin-bottom: 15px; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
        .sticker {
            border: 1px dashed #999;
            text-align: center;
            padding: 8px;
            page-break-inside: avoid;
        }
        .sticker img { width: 120px; height: 120px; }
        .sticker .code { font-size: 12px; font-weight: bold; margin-top: 4px; }
        .sticker .name { font-size: 11px; }
        @media print {
            .toolbar { display: none; }
            .sticker { border: 1px solid #ccc; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">พิมพ์ QR Code</button>
    <span>ทั้งหมด <?= count($items) ?> รายการ</span>
</div>
<div class="grid">
    <?php foreach ($items as $item): ?>
        <div class="sticker">
            <img src="qr_image.php?data=<?= urlencode(buildQrScanUrl($item['qr_code'])) ?>" alt="QR">
            <div class="code"><?= htmlspecialchars($item['code']) ?></div>
            <div class="name"><?= htmlspecialchars($item['name']) ?></div>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
