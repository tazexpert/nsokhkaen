<?php
// รูป QR Code 1 รูป (PNG) จากข้อความ/ลิงก์ — ใช้ไลบรารี libs/phpqrcode (ไม่ต้องใช้ Composer)
// เครื่องที่ไม่ได้เปิด extension gd (เช่น ติดตั้ง PHP ใหม่บน IIS) → ส่งเป็นรูป SVG แทน ให้ QR ยังแสดงและพิมพ์ได้
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$data = $_GET['data'] ?? '';
if ($data === '') {
    http_response_code(400);
    exit('missing data');
}
while (ob_get_level() > 0) {
    ob_end_clean();                      // ข้อความหรือช่องว่างที่ค้างใน buffer ทำให้ไฟล์รูปเสีย
}
header('Cache-Control: private, max-age=86400');

if (function_exists('imagecreate')) {
    header('Content-Type: image/png');
    QRcode::png($data, false, QR_ECLEVEL_L, 6, 2);
    exit;
}

error_log('[nsokhkaen] qr_image: ไม่มี extension gd — ส่ง QR เป็น SVG (เปิด extension=gd ใน php.ini)');
$rows = QRcode::text($data, false, QR_ECLEVEL_L, 1, 0);
$n = count($rows);
$m = 2;                                  // ขอบขาว (โมดูล)
$path = '';
foreach ($rows as $y => $row) {
    for ($x = 0, $len = strlen($row); $x < $len; $x++) {
        if ($row[$x] === '1') {
            $path .= 'M' . ($x + $m) . ' ' . ($y + $m) . 'h1v1h-1z';
        }
    }
}
$size = $n + 2 * $m;
header('Content-Type: image/svg+xml');
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . ($size * 6) . '" height="' . ($size * 6) . '" shape-rendering="crispEdges">'
    . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
