<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();
$currentLoginUser = currentUser();

$pdo = getDbConnection();
$id = (int) ($_GET['id'] ?? 0);

// ผู้เบิก (requester) on the printout is the logged-in account that actually
// submitted the slip (u.full_name/u.position), not the free-text
// requester_name/requester_position fields - those can be filled in for
// someone else (e.g. a staff member recording a request on a colleague's
// behalf) and aren't necessarily the person who should sign as ผู้เบิก here.
$stmt = $pdo->prepare('SELECT r.*, u.full_name AS creator_name, u.position AS creator_position
    FROM inv_requisitions r JOIN core_users u ON u.id = r.created_by WHERE r.id = :id');
$stmt->execute(['id' => $id]);
$requisition = $stmt->fetch();

if (!$requisition) {
    http_response_code(404);
    die('ไม่พบใบเบิกที่ร้องขอ');
}

// Staff only print their own requisitions; admin can print any.
if ($currentLoginUser['role'] !== 'admin' && (int) $requisition['created_by'] !== (int) $currentLoginUser['id']) {
    http_response_code(403);
    die('คุณไม่มีสิทธิ์เข้าถึงใบเบิกนี้');
}

// ผู้จ่าย (issuer) and the approver signing under "อนุญาติให้เบิกได้" are
// normally the same one or two people on every slip, so they're configured
// once in "ตั้งค่าระบบ" instead of being typed by hand on each printout.
$settingsStmt = $pdo->query("SELECT `key`, `value` FROM inv_settings
    WHERE `key` IN ('issuer_name', 'issuer_position', 'approver_name', 'approver_position')");
$signatorySettings = array_column($settingsStmt->fetchAll(), 'value', 'key');

$itemsStmt = $pdo->prepare('SELECT * FROM inv_requisition_items WHERE requisition_id = :id ORDER BY id');
$itemsStmt->execute(['id' => $id]);
$items = $itemsStmt->fetchAll();

$minRows = 11;
$blankRows = max(0, $minRows - count($items));

// Keeps the paper-form look of a blank line to sign/write on when a value
// isn't set, instead of a label with nothing after it.
function blankIfEmpty(?string $value, int $dots = 66): string
{
    $value = trim($value ?? '');
    return $value !== '' ? htmlspecialchars($value) : str_repeat('.', $dots);
}

// The "ลงชื่อ..." line: the dots ARE the signature line, so when the name is
// known it replaces the dots directly instead of being printed separately
// above a second line.
function signatureLine(?string $name, int $dots = 43): string
{
    $name = trim($name ?? '');
    return $name !== '' ? htmlspecialchars($name) : str_repeat('.', $dots);
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ใบเบิกวัสดุ เลขที่ <?= htmlspecialchars($requisition['requisition_no']) ?></title>
    <style>
        /* Embeds the font file from this server instead of relying on it
           being installed on whatever machine opens the page - a PC without
           TH Sarabun New installed would otherwise silently fall back to
           Tahoma, which renders Thai vowels/tone marks differently. Serving
           the real font bundled with the project (not a Google Fonts
           substitute) keeps this exact look and the page's measurements. */
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
        @font-face {
            font-family: 'TH Sarabun New';
            src: url('<?= BASE_URL ?>assets/fonts/THSarabunNew-Italic.ttf') format('truetype');
            font-weight: normal; font-style: italic;
        }
        @font-face {
            font-family: 'TH Sarabun New';
            src: url('<?= BASE_URL ?>assets/fonts/THSarabunNew-BoldItalic.ttf') format('truetype');
            font-weight: bold; font-style: italic;
        }
        /* mm is a physical unit, so .page's min-height matches the real A4
           printable area consistently, unlike 100vh (which some print/PDF
           engines size against an on-screen viewport instead of the actual
           paper size). */
        @page { size: A4; margin: 12mm 15mm; }
        html, body { height: 100%; margin: 0; }
        body { font-family: 'TH Sarabun New', 'Tahoma', sans-serif; font-size: 16pt; line-height: 1.3; color: #000; }
        .page { min-height: calc(297mm - 24mm); box-sizing: border-box; padding: 0; display: flex; flex-direction: column; }
        @media screen {
            .page { min-height: 100vh; padding: 12px 20px; }
        }
        .toolbar { margin-bottom: 15px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        h2 { text-align: center; margin: 0 0 2px; font-size: 18pt; }
        .subtitle { text-align: center; margin: 0 0 8px; }
        .intro { margin: 8px 0; }
        /* border-collapse centers the table's own outer border on the
           table's bounding-box edge, so most of it gets clipped off when
           rasterized for print - measured on a real export, the right
           edge comes out visibly thinner than the interior column
           dividers. Only the right edge sits flush against the page's
           printable-area boundary (top/left/bottom don't), so only it
           needs the extra width to compensate; boosting all four sides
           would make the whole frame thicker than the interior lines. */
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; border: 1px solid #000; border-right-width: 2px; }
        th, td { border: 1px solid #000; padding: 3px 6px; }
        th { background: #f0f0f0; }
        .col-no { width: 8%; text-align: center; white-space: nowrap; }
        .col-unit { width: 10%; text-align: center; }
        .col-qty { width: 12%; text-align: center; }
        .col-note { width: 16%; }
        .sign-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 6px; }
        /* Pushes the approval box to the bottom of the page instead of
           leaving a big gap below it when the table doesn't fill the page
           on its own - .signatures.sign-grid beats the plain .sign-grid
           margin-top above on specificity. */
        .signatures.sign-grid { margin-top: auto; }
        .sign-block { text-align: center; }
        /* Blank room to physically pen-sign above the "ลงชื่อ ...." line,
           instead of having to sign on top of the printed dots/name. Sized
           down from a naive 15mm to fit a single A4 page after the table
           above was trimmed to make room (see $minRows) - not meant to be
           generous, just enough for an actual signature. */
        .sign-space { height: 9mm; }
        .approve-title { text-align: center; font-weight: bold; font-size: 18pt; margin-top: 8px; }
        @media print {
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">พิมพ์ใบเบิก</button>
    <a href="requisitions.php">&larr; กลับไปหน้ารายการใบเบิก</a>
</div>

<div class="page">
<div class="text-right">เลขที่ <?= htmlspecialchars($requisition['requisition_no']) ?></div>
<h2>ใบเบิกวัสดุ</h2>
<p class="subtitle">สำนักงานสถิติจังหวัดขอนแก่น</p>

<p class="intro">ข้าพเจ้าขอเบิกวัสดุเพื่อใช้งาน<?= $requisition['purpose'] ? ' ' . htmlspecialchars($requisition['purpose']) . ' ' : '.............................................................................' ?>ตามรายการข้างล่างนี้</p>

<table>
    <thead>
    <tr>
        <th class="col-no">ลำดับที่</th>
        <th>รายการสิ่งของ</th>
        <th class="col-unit">หน่วยนับ</th>
        <th class="col-qty">จำนวนที่ขอเบิก</th>
        <th class="col-qty">จำนวนที่จ่าย</th>
        <th class="col-note">หมายเหตุ</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $i => $item): ?>
        <tr>
            <td class="col-no"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($item['item_name']) ?></td>
            <td class="col-unit"><?= htmlspecialchars($item['unit'] ?? '-') ?></td>
            <td class="col-qty"><?= (int) $item['quantity_requested'] ?></td>
            <td class="col-qty"><?= (int) $item['quantity_issued'] ?></td>
            <td class="col-note"><?= htmlspecialchars($item['note'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php for ($i = 0; $i < $blankRows; $i++): ?>
        <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>
    <?php endfor; ?>
    </tbody>
</table>

<div class="sign-grid">
    <div class="sign-block">
        <div class="sign-space"></div>
        <div>ลงชื่อ <?= signatureLine($requisition['creator_name']) ?> ผู้เบิก</div>
        <div>ตำแหน่ง <?= blankIfEmpty($requisition['creator_position']) ?></div>
        <div>วันที่ <?= formatThaiDate($requisition['created_at']) ?></div>
    </div>
    <div class="sign-block">
        <div class="sign-space"></div>
        <div>ลงชื่อ <?= signatureLine($signatorySettings['issuer_name'] ?? null) ?> ผู้จ่ายวัสดุ</div>
        <div>ตำแหน่ง <?= blankIfEmpty($signatorySettings['issuer_position'] ?? null) ?></div>
        <div>วันที่ <?= formatThaiDate($requisition['created_at']) ?></div>
    </div>
</div>

<div class="signatures sign-grid">
    <div>
        <div class="approve-title">อนุญาตให้เบิกได้</div>
        <div class="sign-block">
            <div class="sign-space"></div>
            <div>ลงชื่อ <?= signatureLine($signatorySettings['approver_name'] ?? null) ?></div>
            <div>ตำแหน่ง <?= blankIfEmpty($signatorySettings['approver_position'] ?? null) ?></div>
            <div>วันที่ <?= formatThaiDate($requisition['created_at']) ?></div>
        </div>
    </div>
    <div></div>
</div>

</div>
</body>
</html>
