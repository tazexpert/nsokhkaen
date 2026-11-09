<?php
/**
 * พิมพ์ชุดเอกสารใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ (A4) ตามแบบฟอร์มของสำนักงาน
 *   หน้า 1   แบบ 8708 ส่วนที่ 1 · หน้า "- 2 -" ตรวจสอบ/อนุมัติ/รับเงิน
 *   2..n    แบบประกอบใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ (5 วัน/หน้า)
 *   n+1..   ใบรับรองแทนใบเสร็จรับเงิน แบบ บก.111 (7 รายการ/หน้า ยอดยกไป/ยกมา)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/claims_lib.php';
require_module(EA_MODULE, 'viewer');
$pdo = db();
$st = $pdo->prepare('SELECT * FROM ea_claims WHERE id = ?');
$st->execute([int_param('id')]);
$c = $st->fetch();
if (!$c || !claim_can_view($c)) {
    flash('ไม่พบใบเบิก', 'warning');
    redirect('claims.php');
}
$st = $pdo->prepare('SELECT * FROM ea_claim_days WHERE claim_id = ? ORDER BY seq, trip_date, id');
$st->execute([$c['id']]);
$days = $st->fetchAll();
$annex = claim_annex_pages($c, $days);
$receipt = claim_receipt_pages($c, $days);
$totalPages = 1 + count($annex) + count($receipt);
$posFull = trim($c['position'] . $c['level']);
$fill = fn($v, string $cls = '') => '<span class="fill ' . $cls . '">' . ($v === '' || $v === null ? '&nbsp;' : h((string)$v)) . '</span>';
$box = fn(bool $on) => '<span class="box">' . ($on ? '&#10003;' : '') . '</span>';
$first = $days[0] ?? null;
$last = $days ? end($days) : null;
[$d1, $m1, $y1] = claim_date_parts($first['trip_date'] ?? null);
[$d2, $m2, $y2] = claim_date_parts($last ? ($last['return_date'] ?: $last['trip_date']) : null);
[$cd, $cm, $cy] = claim_date_parts($c['claim_date']);
$tripDays = $days ? (int)round((strtotime(($last['return_date'] ?: $last['trip_date'])) - strtotime($first['trip_date'])) / 86400) + 1 : 0;
$purpose = trim(preg_replace('/\s+/u', ' ', (string)$c['purpose']));
$vehicleText = claim_vehicle_text($c, $days);
$receiptTotal = array_sum(array_map(fn($b) => (float)$b['day']['amount'], array_merge([], ...$receipt)));
$split = fn(float $n) => [number_format(floor($n)), str_pad((string)(int)round(($n - floor($n)) * 100), 2, '0', STR_PAD_LEFT)];
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ใบเบิกค่าเดินทาง <?= h($c['full_name']) ?> <?= h(thai_date($c['claim_date'])) ?></title>
<style>
  /* ฟอนต์ TH Sarabun New (ไฟล์ในโปรเจกต์ assets/fonts — ชุดเดียวกับใบเบิกวัสดุ) ไม่ต้องโหลดจากอินเทอร์เน็ต
     ขนาดตัวอักษรตั้งให้ใกล้เคียงแบบฟอร์ม (TH Sarabun New ตัวเล็กกว่าฟอนต์ทั่วไปประมาณ 1.45 เท่า) */
  @font-face { font-family: 'TH Sarabun New'; src: url('<?= BASE_URL ?>assets/fonts/THSarabunNew.ttf') format('truetype'); font-weight: normal; font-style: normal; }
  @font-face { font-family: 'TH Sarabun New'; src: url('<?= BASE_URL ?>assets/fonts/THSarabunNew-Bold.ttf') format('truetype'); font-weight: bold; font-style: normal; }
  :root { --line: #000; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e5e7eb; font-family: 'TH Sarabun New', 'Tahoma', sans-serif; font-size: 21px; color: #000; }
  .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: 8px; align-items: center; padding: 10px 16px; background: #1f2937; color: #fff; font-family: system-ui, 'Segoe UI', Tahoma, sans-serif; font-size: 14px; }
  .toolbar a, .toolbar button { color: #fff; background: #374151; border: 0; border-radius: 6px; padding: 6px 12px; text-decoration: none; font: inherit; cursor: pointer; }
  .toolbar .primary { background: #16a34a; }
  .page { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 14mm 15mm 12mm 20mm; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); position: relative; }
  .pno { text-align: right; font-size: 19px; margin-bottom: 2px; }
  .center { text-align: center; } .right { text-align: right; } .b { font-weight: 700; }
  .title { text-align: center; font-weight: 700; font-size: 25px; line-height: 1.1; margin: 6px 0 10px; }
  .fill { display: inline-block; border-bottom: 1px dotted #000; min-width: 40px; padding: 0 4px; text-align: center; line-height: 1.15; vertical-align: bottom; }
  .fill.l { text-align: left; } .w40 { min-width: 38px; } .w60 { min-width: 60px; } .w70 { min-width: 70px; } .w90 { min-width: 90px; } .w120 { min-width: 120px; } .w160 { min-width: 160px; } .w200 { min-width: 200px; } .w260 { min-width: 260px; } .wfull { display: block; min-width: 0; text-align: left; }
  .box { display: inline-block; width: 13px; height: 13px; border: 1px solid #000; line-height: 11px; font-size: 17px; text-align: center; margin-right: 3px; vertical-align: -1px; }
  .line { margin: 3px 0; line-height: 1.17; }
  .indent { padding-left: 36px; }
  .tight { white-space: nowrap; letter-spacing: -.1px; }
  .row2 { display: flex; justify-content: space-between; gap: 10px; }
  table.form { width: 100%; border-collapse: collapse; }
  table.form td, table.form th { border: 1px solid #000; padding: 1px 4px; vertical-align: top; line-height: 1; }
  table.form th { font-weight: 400; text-align: center; vertical-align: middle; }
  /* ตารางแบบบรรทัด (เหมือนแบบฟอร์ม): 1 แถว = 1 บรรทัด ไม่ตัดคำ */
  table.lines { table-layout: fixed; }
  table.lines td { height: 21px; padding: 0 3px; font-size: 19px; line-height: 20px; white-space: nowrap; overflow: hidden; text-overflow: clip; }
  .annex td { border-top: 0; border-bottom: 0; }
  .annex tr.top td { border-top: 1px solid #000; }
  .annex tr.blank td { border-top: 1px solid #000; }
  .annex tr:last-child td { border-bottom: 1px solid #000; }
  .annex td.c { text-align: center; padding: 0 1px; }
  .receipt td.n { text-align: right; }
  .sig { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid #000; border-bottom: 1px solid #000; }
  .sig > div { padding: 6px 8px 10px; }
  .sig > div + div { border-left: 1px solid #000; }
  .dots { border-bottom: 1px dotted #000; height: 26px; }
  .explain td { vertical-align: top; padding: 1px 4px; font-size: 20px; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .page { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 0; page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    @page { size: A4; margin: 13mm 14mm 11mm 18mm; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <strong>ใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ</strong>
  <span><?= h($c['full_name']) ?> · <?= number_format((float)$c['total_amount'], 2) ?> บาท · <?= $totalPages ?> หน้า · <?= $c['status'] === 'issued' ? 'ออกใบสำคัญแล้ว' : 'ร่าง' ?></span>
  <span style="margin-left:auto"></span>
  <?php if (claim_can_edit($c)): ?><a href="claim_form.php?id=<?= (int)$c['id'] ?>">แก้ไข</a><?php endif; ?>
  <a href="claims.php">ใบเบิกทั้งหมด</a>
  <button type="button" class="primary" onclick="window.print()">พิมพ์ / บันทึก PDF</button>
</div>

<!-- ===================== แบบ 8708 ส่วนที่ 1 ===================== -->
<div class="page">
  <div class="row2">
    <div>
      <div class="line">สัญญายืมเงินเลขที่ <?= $fill($c['loan_no'], 'w160') ?> วันที่ <?= $fill(claim_date_long($c['loan_date']), 'w160') ?></div>
      <div class="line">ชื่อผู้ยืม <?= $fill($c['loan_no'] !== '' ? $c['full_name'] : '', 'w200') ?> จำนวนเงิน <?= $fill((float)$c['loan_amount'] ? number_format((float)$c['loan_amount'], 2) : '', 'w120') ?> บาท</div>
    </div>
    <div class="right"><div class="line">ส่วนที่ 1</div><div class="line">แบบ 8708</div></div>
  </div>
  <div class="title">ใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ</div>
  <div style="margin-left:52%">
    <div class="line">ที่ทำการ <?= $fill($c['office'], 'w200 l') ?></div>
    <div class="line">วันที่ <?= $fill($cd, 'w60') ?> เดือน <?= $fill($cm, 'w90') ?> พ.ศ. <?= $fill($cy, 'w60') ?></div>
  </div>
  <div class="line" style="margin-top:8px">เรื่อง&nbsp;&nbsp; ขออนุมัติเบิกค่าใช้จ่ายในการเดินทางไปราชการ</div>
  <div class="line">เรียน&nbsp;&nbsp; <?= h($c['addressee']) ?></div>
  <div class="line indent" style="margin-top:8px">ตามคำสั่ง/บันทึก ที่ <?= $fill($c['order_no'], 'w120') ?> ลงวันที่ <?= $fill(claim_date_long($c['order_date']), 'w160') ?> ได้อนุมัติให้</div>
  <div class="line">ข้าพเจ้า <?= $fill($c['full_name'], 'w200') ?> ตำแหน่ง <?= $fill($c['position'], 'w200') ?> ระดับ <?= $fill($c['level'], 'w90') ?></div>
  <div class="line">สังกัด <?= $fill($c['department'], 'w200') ?> พร้อมด้วย <?= $fill($c['companions'], 'w260 l') ?></div>
  <div class="dots"></div>
  <div class="line">เดินทางไปปฏิบัติราชการ <span class="fill l" style="display:inline">&nbsp;<?= h($purpose) ?>&nbsp;</span> โดยออกเดินทางจาก</div>
  <div class="line tight"><?php foreach (CLAIM_PLACES as $k => $l): ?><?= $box($c['depart_from'] === $k) ?><?= h($l) ?>&nbsp; <?php endforeach; ?>
    ตั้งแต่วันที่ <?= $fill($d1, 'w40') ?> เดือน <?= $fill($m1, 'w70') ?> พ.ศ. <?= $fill($y1, 'w40') ?> เวลา <?= $fill($first ? str_replace(' น.', '', claim_time($first['depart_time'])) : '', 'w40') ?> น.และกลับถึง</div>
  <div class="line tight"><?php foreach (CLAIM_PLACES as $k => $l): ?><?= $box($c['return_to'] === $k) ?><?= h($l) ?>&nbsp; <?php endforeach; ?>
    &nbsp;วันที่ <?= $fill($d2, 'w40') ?> เดือน <?= $fill($m2, 'w70') ?> พ.ศ. <?= $fill($y2, 'w40') ?> เวลา <?= $fill($last ? str_replace(' น.', '', claim_time($last['return_time'])) : '', 'w40') ?> น.</div>
  <div class="line">รวมไปราชการครั้งนี้ <?= $fill($days ? count($days) : '', 'w60') ?> วัน <?= $fill('', 'w60') ?> ชั่วโมง</div>
  <div class="line indent">ข้าพเจ้าขอเบิกค่าใช้จ่ายในการเดินทางไปราชการสำหรับ <?= $box($c['claim_for'] !== 'group') ?>ข้าพเจ้า <?= $box($c['claim_for'] === 'group') ?>คณะเดินทาง ดังนี้</div>
  <table style="width:100%;border-collapse:collapse">
    <?php
      $lines = [
          ['ค่าเบี้ยเลี้ยงเดินทางประเภท', trim($c['per_diem_type'] . ' ' . $c['per_diem_note']), (float)$c['per_diem_days'] ? claim_num((float)$c['per_diem_days']) : '', (float)$c['per_diem_amount']],
          ['ค่าเช่าที่พักประเภท', $c['lodging_type'], (float)$c['lodging_days'] ? claim_num((float)$c['lodging_days']) : '', (float)$c['lodging_amount']],
          ['ค่าพาหนะ', $vehicleText, null, (float)$c['vehicle_amount']],
          ['ค่าใช้จ่ายอื่น', $c['other_desc'], null, (float)$c['other_amount']],
      ];
      foreach ($lines as [$label, $desc, $nd, $amt]): ?>
    <tr>
      <td style="white-space:nowrap;width:1%"><?= h($label) ?></td>
      <td<?= $nd === null ? ' colspan="2"' : '' ?>><span class="fill wfull"><?= h((string)$desc) ?: '&nbsp;' ?></span></td>
      <?php if ($nd !== null): ?><td style="white-space:nowrap;width:1%">&nbsp;จำนวน <?= $fill($nd, 'w40') ?> วัน&nbsp;</td><?php endif; ?>
      <td style="white-space:nowrap;width:1%">รวม <?= $fill(claim_money($amt), 'w120') ?> บาท</td>
    </tr>
    <?php endforeach; ?>
    <tr><td colspan="3" class="right">รวมเงินทั้งสิ้น&nbsp;</td><td style="white-space:nowrap">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?= $fill(number_format((float)$c['total_amount'], 2), 'w120') ?> บาท</td></tr>
  </table>
  <div class="line">จำนวนเงิน (ตัวอักษร) <?= $fill((float)$c['total_amount'] > 0 ? '=' . baht_text((float)$c['total_amount']) . ' =' : '', 'w260 l') ?></div>
  <div class="line indent" style="margin-top:8px">ข้าพเจ้าขอรับรองว่ารายการที่กล่าวมาข้างต้นเป็นความจริง และหลักฐานการจ่ายที่ส่งมาด้วย จำนวน <?= $fill($totalPages, 'w40') ?> ฉบับ</div>
  <div class="line">รวมทั้งจำนวนเงินที่ขอเบิกถูกต้องตามกฎหมายทุกประการ</div>
  <div class="row2" style="margin-top:18px">
    <div class="indent" style="padding-top:2px">ข้าพเจ้าขอรับเงินเพียง <?= number_format((float)$c['receive_amount']) ?> บาท</div>
    <div style="width:52%">
      <div class="line">ลงชื่อ <?= $fill('', 'w200') ?> ผู้ขอรับเงิน</div>
      <div class="line center">( <?= $fill($c['full_name'], 'w200') ?> )</div>
      <div class="line">ตำแหน่ง <?= $fill($posFull, 'w200') ?></div>
    </div>
  </div>
</div>

<!-- ===================== แบบ 8708 หน้า 2 ===================== -->
<div class="page">
  <div class="center" style="margin-bottom:6px">- 2 -</div>
  <div class="sig">
    <div>
      <div>ได้ตรวจสอบหลักฐานการเบิกจ่ายเงินที่แนบถูกต้องแล้ว</div>
      <div>เห็นควรอนุมัติให้เบิกจ่ายได้</div>
      <div class="line" style="margin-top:26px">ลงชื่อ <?= $fill('', 'w200') ?></div>
      <div class="line center">( <?= $fill('', 'w200') ?> )</div>
      <div class="line">ตำแหน่ง <?= $fill('', 'w200') ?></div>
      <div class="line center">เจ้าหน้าที่ตรวจสอบใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ</div>
      <div class="line">วันที่ <?= $fill('', 'w200') ?></div>
    </div>
    <div>
      <div>อนุมัติให้จ่ายได้</div>
      <div>&nbsp;</div>
      <div class="line" style="margin-top:26px">ลงชื่อ <?= $fill('', 'w200') ?></div>
      <div class="line center">( <?= $fill('', 'w200') ?> )</div>
      <div class="line">ตำแหน่ง <?= $fill('', 'w200') ?></div>
      <div class="line">&nbsp;</div>
      <div class="line">วันที่ <?= $fill('', 'w200') ?></div>
    </div>
  </div>
  <div style="padding:14px 0 8px;border-bottom:1px solid #000">
    <div class="line indent" style="padding-left:90px">ได้รับเงินค่าใช้จ่ายในการเดินทางไปราชการ &nbsp;&nbsp; จำนวน <?= $fill(number_format((float)$c['receive_amount']), 'w160') ?> บาท</div>
    <div class="line">( <?= $fill((float)$c['receive_amount'] > 0 ? baht_text((float)$c['receive_amount']) : '', 'w260') ?> ) ไว้เป็นการถูกต้องแล้ว</div>
    <div class="row2" style="margin-top:20px">
      <div style="width:48%">
        <div class="line">ลงชื่อ <?= $fill('', 'w200') ?> ผู้รับเงิน</div>
        <div class="line center">( <?= $fill('', 'w200') ?> )</div>
        <div class="line">ตำแหน่ง <?= $fill('', 'w200') ?></div>
        <div class="line">วันที่ <?= $fill('', 'w200') ?></div>
      </div>
      <div style="width:48%">
        <div class="line">ลงชื่อ <?= $fill('', 'w200') ?> ผู้จ่ายเงิน</div>
        <div class="line center">( <?= $fill('', 'w200') ?> )</div>
        <div class="line">ตำแหน่ง <?= $fill('', 'w200') ?></div>
        <div class="line">วันที่ <?= $fill('', 'w200') ?></div>
      </div>
    </div>
    <div class="line" style="margin-top:10px">จากเงินยืมตามสัญญาเลขที่ <?= $fill($c['loan_no'] !== '' ? $c['loan_no'] : '-', 'w200') ?> วันที่ <?= $fill($c['loan_date'] ? claim_date_long($c['loan_date']) : '-', 'w200') ?></div>
  </div>
  <div style="padding:8px 0 10px;border-bottom:1px solid #000">
    <div class="line"><u>หมายเหตุ</u> <?= $fill((string)$c['note'], 'w260 l') ?></div>
    <?php for ($i = 0; $i < 4; $i++): ?><div class="dots"></div><?php endfor; ?>
  </div>
  <table class="explain" style="margin-top:8px">
    <tr><td class="b" style="white-space:nowrap"><u>คำชี้แจง</u></td><td>1.</td><td>กรณีเดินทางเป็นหมู่คณะและจัดทำใบเบิกค่าใช้จ่ายรวมฉบับเดียวกัน หากระยะเวลาในการเริ่มต้นและสิ้นสุดการเดินทางของแต่ละบุคคลแตกต่างกัน ให้แสดงรายละเอียดของวันเวลาที่แตกต่างกันของบุคคลนั้นในช่องหมายเหตุ</td></tr>
    <tr><td></td><td>2.</td><td>กรณียื่นขอเบิกค่าใช้จ่ายรายบุคคล ให้ผู้ขอรับเงินเป็นผู้ลงลายมือชื่อผู้รับเงินและวันเดือนปีที่รับเงิน กรณีที่มีการยืมเงิน ให้ระบุวันที่ที่ได้รับเงินยืม เลขสัญญายืมและวันที่อนุมัติเงินยืมด้วย</td></tr>
    <tr><td></td><td>3.</td><td>กรณียื่นขอเบิกค่าใช้จ่ายรวมเป็นหมู่คณะ ผู้ขอรับเงินมิต้องลงลายมือชื่อในช่องผู้รับเงิน ทั้งนี้ ให้ผู้มีสิทธิแต่ละคนลงลายมือชื่อผู้รับเงินในหลักฐานการจ่ายเงิน (ส่วนที่ 2)</td></tr>
  </table>
</div>

<!-- ===================== แบบประกอบใบเบิกค่าใช้จ่าย (บรรทัดละแถว) ===================== -->
<?php
$pageNo = 1;
foreach ($annex as $blocks): $pageNo++; $used = 0; ?>
<div class="page">
  <div class="pno"><?= $pageNo ?>/<?= $totalPages ?></div>
  <div class="title" style="margin:0 0 2px">แบบประกอบใบเบิกค่าใช้จ่ายในการเดินทางไปราชการ</div>
  <div class="center b">ของ <?= $fill($c['full_name'], 'w200') ?></div>
  <div class="center b" style="margin-bottom:8px"><?= h($c['department']) ?></div>
  <table class="form lines annex">
    <colgroup><col style="width:6%"><col style="width:15%"><col style="width:9%"><col style="width:15%"><col style="width:9%"><col style="width:18%"><col style="width:28%"></colgroup>
    <tr class="top"><th rowspan="2">ลำดับ<br>ที่</th><th rowspan="2">ออกจาก</th><th>วัน เดือน ปี</th><th rowspan="2">ถึง</th><th>วัน เดือน ปี</th><th>สถานที่</th><th>รายการค่าใช้จ่ายที่ขอเบิก นอกเหนือจาก</th></tr>
    <tr><th>เวลา</th><th>เวลา</th><th>ที่ไปปฏิบัติงาน</th><th>เบี้ยเลี้ยง ที่พัก และค่าพาหนะไปกลับ</th></tr>
    <?php foreach ($blocks as $b): $d = $b['day']; $used += $b['rows'];
      $dcol = [claim_date_short($d['trip_date']), claim_time($d['depart_time'])];
      $rcol = [claim_date_short($d['return_date'] ?: $d['trip_date']), claim_time($d['return_time'])];
      for ($r = 0; $r < $b['rows']; $r++): ?>
    <tr<?= $r === 0 ? ' class="top"' : '' ?>>
      <td class="c"><?= $r === 0 ? $b['seq'] : '' ?></td>
      <td><?= h($b['origin'][$r] ?? '') ?></td>
      <td class="c"><?= h($dcol[$r] ?? '') ?></td>
      <td><?= h($b['origin'][$r] ?? '') ?></td>
      <td class="c"><?= h($rcol[$r] ?? '') ?></td>
      <td class="c"><?= h($b['places'][$r] ?? '') ?></td>
      <td class="c"><?= h($b['extra'][$r] ?? '') ?></td>
    </tr>
    <?php endfor; endforeach;
      for ($i = $used; $i < CLAIM_ANNEX_LINES; $i++): ?>
    <tr class="blank"><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
    <?php endfor; ?>
  </table>
</div>
<?php endforeach; ?>

<!-- ===================== ใบรับรองแทนใบเสร็จรับเงิน (บก.111) ===================== -->
<?php
$carry = 0.0;
foreach ($receipt as $pi => $blocks): $pageNo++; $isLast = $pi === count($receipt) - 1; $used = 0; ?>
<div class="page">
  <div class="pno"><?= $pageNo ?>/<?= $totalPages ?></div>
  <div class="title" style="margin:0">ใบรับรองแทนใบเสร็จรับเงิน</div>
  <div class="right" style="margin-bottom:4px">แบบ บก. 111</div>
  <div class="center b" style="margin-bottom:6px">ส่วนราชการ &nbsp; <?= h($c['office']) ?></div>
  <table class="form lines receipt">
    <colgroup><col style="width:12%"><col><col style="width:8%"><col style="width:5%"><col style="width:9%"></colgroup>
    <tr><th rowspan="2">วัน เดือน ปี</th><th rowspan="2">รายละเอียดรายจ่าย</th><th colspan="2">จำนวนเงิน</th><th rowspan="2">หมายเหตุ</th></tr>
    <tr><th>บาท</th><th>ส.ต.</th></tr>
    <?php if ($pi > 0): [$bb, $ss] = $split($carry); $used++; ?>
    <tr><td></td><td class="right b">ยอดยกมา</td><td class="n"><?= $bb ?></td><td class="n"><?= $ss ?></td><td></td></tr>
    <?php endif;
      foreach ($blocks as $b): $d = $b['day']; $used += $b['rows']; $carry += (float)$d['amount']; [$bb, $ss] = $split((float)$d['amount']);
        foreach ($b['lines'] as $li => $line): ?>
    <tr><td class="center"><?= $li === 0 ? h(claim_date_short($d['trip_date'])) : '' ?></td><td><?= h($line) ?></td><td></td><td></td><td></td></tr>
    <?php endforeach; ?>
    <tr><td></td><td class="right">เป็นเงิน</td><td class="n"><?= $bb ?></td><td class="n"><?= $ss ?></td><td></td></tr>
    <?php endforeach;
      for ($i = $used; $i < ($isLast ? CLAIM_RECEIPT_LAST_LINES : CLAIM_RECEIPT_LINES); $i++): ?>
    <tr><td></td><td></td><td></td><td></td><td></td></tr>
    <?php endfor;
      [$bb, $ss] = $split($isLast ? $receiptTotal : $carry); ?>
    <tr><td></td><td class="center b"><?= $isLast ? 'รวมเป็นเงินทั้งสิ้น' : 'ยอดยกไป' ?></td><td class="n b"><?= $bb ?></td><td class="n b"><?= $ss ?></td><td></td></tr>
  </table>
  <?php if ($isLast): ?>
  <div class="line" style="margin-top:12px">รวมทั้งสิ้น (ตัวอักษร) <?= $fill(baht_text($receiptTotal), 'w260 l') ?></div>
  <div class="line indent" style="margin-top:10px">ข้าพเจ้า <?= $fill($c['full_name'], 'w200') ?> ตำแหน่ง <?= $fill($posFull, 'w200') ?></div>
  <div class="line">กอง <?= $fill($c['department'], 'w200') ?> ขอรับรองว่ารายจ่ายข้างต้นนี้ไม่อาจเรียกใบเสร็จรับเงินจากผู้รับได้</div>
  <div class="line">และข้าพเจ้าได้จ่ายไปในงานของราชการโดยแท้</div>
  <div style="margin-left:48%;margin-top:16px">
    <div class="line center">(ลงชื่อ) <?= $fill('', 'w200') ?></div>
    <div class="line center">วันที่ <?= $fill($cd, 'w60') ?> เดือน <?= $fill($cm, 'w90') ?> พ.ศ. <?= $fill($cy, 'w60') ?></div>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<script>
// ย่อขนาดตัวอักษรเมื่อข้อความยาวเกินช่อง (แต่ละแถว = 1 บรรทัดตามแบบฟอร์ม)
// ถ้าช่องใดต้องย่อ ให้ทุกช่องของตารางชนิดเดียวกัน (ทุกหน้า) ใช้ขนาดเดียวกัน = ขนาดเล็กที่สุดที่ทุกช่องพอดี
(document.fonts ? document.fonts.ready : Promise.resolve()).then(() => {
  const groups = {};
  document.querySelectorAll('table.lines').forEach(t => { (groups[t.className] ??= []).push(...t.querySelectorAll('td')); });
  Object.values(groups).forEach(tds => {
    const base = Math.max(...tds.map(td => parseFloat(getComputedStyle(td).fontSize)));
    let min = base;   // ย่อได้ต่ำสุด 13px (TH Sarabun New)
    tds.forEach(td => {
      let fs = base;
      td.style.fontSize = fs + 'px';
      while (td.scrollWidth > td.clientWidth + 1 && fs > 13) { fs -= 0.5; td.style.fontSize = fs + 'px'; }
      min = Math.min(min, fs);
    });
    tds.forEach(td => { td.style.fontSize = min + 'px'; });
  });
});
</script>
</body>
</html>
