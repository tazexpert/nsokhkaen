<?php
/** พิมพ์ "บันทึกข้อความ ขออนุมัติยืมพัสดุ" (หน้า 1) + การรับคืนและข้อกำหนดการยืม-คืน (หน้า 2) ตามแบบฟอร์มสำนักงาน */
require_once __DIR__ . '/bootstrap.php';
require_module(INV_MODULE);
$pdo = db();
$id = int_param('id');
$st = $pdo->prepare('SELECT * FROM inv_loans WHERE id = ?');
$st->execute([$id]);
$loan = $st->fetch();
if (!$loan || !can_view_loan($loan)) {
    http_response_code(404);
    exit('ไม่พบใบยืม หรือไม่มีสิทธิ์');
}
$st = $pdo->prepare('SELECT * FROM inv_loan_items WHERE loan_id = ? ORDER BY sort_order, id');
$st->execute([$id]);
$items = $st->fetchAll();
$ls = loan_settings();
$approverName = $loan['approver_name'] ?? ($ls['loan_approver_name'] ?: null);
$approverPos = $loan['approver_position'] ?? ($ls['loan_approver_position'] ?: null);
if ($loan['status'] === 'pending') {
    $approverName = $ls['loan_approver_name'] ?: null;
    $approverPos = $ls['loan_approver_position'] ?: null;
}
$lent = in_array($loan['status'], ['borrowed', 'returned'], true);
$returned = $loan['status'] === 'returned';

/** ช่องกรอกแบบเส้นประ: มีค่า = แสดงค่า, ไม่มี = เส้นประว่าง */
function fill(?string $v, string $minWidth = '3cm', string $align = 'center'): string
{
    $v = trim((string)$v);
    return '<span class="fill" style="min-width:' . $minWidth . ';text-align:' . $align . '">' . ($v !== '' ? h($v) : '&nbsp;') . '</span>';
}
/** วันที่แยก วัน/เดือน/ปี สำหรับช่อง "วันที่ .. เดือน .. พ.ศ. .." */
function dmy(?string $date): array
{
    if (!$date) {
        return ['', '', ''];
    }
    $t = strtotime($date);
    return [thai_digits(date('j', $t)), thai_month_name((int)date('n', $t)), thai_digits((int)date('Y', $t) + 543)];
}
$td = fn(?string $d) => $d ? thai_digits(thai_date_full($d)) : '';
[$sd, $sm, $sy] = dmy($loan['start_date']);
[$ed, $em, $ey] = dmy($loan['due_date']);
[$rd, $rm, $ry] = dmy($loan['returned_at']);
$minRows = 6;
$font = BASE_URL . 'assets/fonts/';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>บันทึกขออนุมัติยืมพัสดุ <?= h($loan['loan_no']) ?></title>
<style>
  @font-face { font-family: 'TH Sarabun New'; src: url('<?= h($font) ?>THSarabunNew.ttf') format('truetype'); font-weight: normal; }
  @font-face { font-family: 'TH Sarabun New'; src: url('<?= h($font) ?>THSarabunNew-Bold.ttf') format('truetype'); font-weight: bold; }
  @page { size: A4; margin: 15mm 18mm 12mm 25mm; }
  body { font-family: 'TH Sarabun New', Tahoma, sans-serif; font-size: 16pt; line-height: 1.32; color: #000; margin: 0; }
  .sheet { position: relative; }
  .page2 { break-before: page; page-break-before: always; min-height: 266mm; display: flex; flex-direction: column; }
  @media screen {
    body { background: #e9edf2; }
    .sheet { background: #fff; width: 210mm; box-sizing: border-box; padding: 15mm 18mm 12mm 25mm; margin: 12px auto; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
    .page2 { min-height: 270mm; }
    .toolbar { text-align: center; padding: 10px; font-family: system-ui, sans-serif; font-size: 14px; }
    .toolbar button { font-size: 15px; padding: 6px 16px; }
  }
  @media print { .toolbar { display: none; } }
  .head { position: relative; text-align: center; height: 1.6cm; }
  .garuda { position: absolute; left: 0; top: 0; height: 1.5cm; }
  .head h1 { font-size: 29pt; margin: 0; padding-top: .3cm; line-height: 1; }
  .row { white-space: nowrap; overflow: hidden; }
  .lbl { font-weight: bold; font-size: 20pt; }
  .fill { display: inline-block; border-bottom: 1px dotted #000; line-height: 1.15; padding: 0 4px; white-space: nowrap; }
  .grow { display: flex; align-items: baseline; gap: 4px; }
  .grow .fill { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
  .grow > .fixed { flex: none; }
  .line { white-space: nowrap; }
  .indent { padding-left: 2.5cm; }
  table.items { width: 100%; border-collapse: collapse; margin: 6px 0 8px; font-size: 14pt; line-height: 1.2; }
  table.items th, table.items td { border: 1px solid #000; padding: 2px 5px; vertical-align: top; }
  table.items th { font-weight: normal; text-align: center; vertical-align: middle; }
  table.items td.c { text-align: center; }
  table.items tr.blank td { height: 0.62cm; }
  .para { text-indent: 2.5cm; text-align: justify; margin: 0; }
  .u { text-decoration: underline; }
  .sign { text-align: left; line-height: 1.45; }
  .sign .who { padding-left: 1.4cm; }
  .sign-right { margin-left: 50%; margin-top: 6px; }
  .sign-cols { display: grid; grid-template-columns: 1fr 1.2fr; gap: 10px; margin-top: 12px; }
  .pos { display: flex; gap: 4px; }
  .pos-lines { flex: 1; white-space: pre-line; border-bottom: 1px dotted #000; line-height: 1.25; }
  .rules { font-size: 15pt; }
  .rules h3 { text-align: center; text-decoration: underline; font-size: 16pt; margin: 14px 0 4px; }
  .rules p { margin: 0; text-indent: 1.6cm; text-align: justify; }
  .page-no { text-align: center; margin-bottom: 14px; }
  .print-footer { margin-top: auto; padding-top: 10px; }
  .print-footer img { height: 1.6cm; }
</style>
</head>
<body>
<div class="toolbar">
  <button onclick="window.print()">🖨 พิมพ์</button>
  <a href="loan_view.php?id=<?= (int)$loan['id'] ?>">← กลับไปหน้าใบยืม</a>
  <span style="color:#666;margin-left:10px">ใบยืมเลขที่ <?= h($loan['loan_no']) ?> · ตั้งค่ากระดาษ A4 / ระยะขอบ "ค่าเริ่มต้น" และปิด "ส่วนหัวและส่วนท้าย"</span>
</div>

<div class="sheet">
  <div class="head">
    <img src="<?= h(INV_URL . 'assets/img/garuda.png') ?>" class="garuda" alt="">
    <h1>บันทึกข้อความ</h1>
  </div>
  <div class="grow"><span class="lbl">ส่วนราชการ</span> <?= fill(ORG_NAME . '  โทรศัพท์ ' . thai_digits($ls['loan_org_phone']) . '  โทรสาร ' . thai_digits($ls['loan_org_fax']), '1cm', 'left') ?></div>
  <div class="grow"><span class="lbl">ที่</span> <?= fill('', '1cm') ?> <span class="lbl">วันที่</span> <span class="fill fixed" style="min-width:7cm"><?= h($td(substr($loan['created_at'], 0, 10))) ?></span></div>
  <div class="grow"><span class="lbl">เรื่อง</span> <?= fill('ขออนุมัติยืมพัสดุ', '1cm', 'left') ?></div>
  <div class="grow" style="margin-top:6px">เรียน <?= fill(LOAN_TO, '1cm', 'left') ?></div>
  <div class="line indent">ข้าพเจ้า <?= fill($loan['borrower_name'], '5cm') ?> ตำแหน่ง <?= fill($loan['borrower_position'], '5.6cm') ?></div>
  <div class="line">หน่วยงาน <?= fill($loan['borrower_org'], '6cm') ?> หมายเลขโทรศัพท์ <?= fill(thai_digits($loan['borrower_phone']), '4cm') ?></div>
  <div class="grow">มีความประสงค์จะขอยืมพัสดุ <?= fill('เพื่อ' . preg_replace('/^เพื่อ\s*/u', '', $loan['purpose']), '1cm', 'left') ?></div>
  <div class="line" style="margin-top:4px">ตั้งแต่ วันที่ <?= fill($sd, '1cm') ?> เดือน <?= fill($sm, '2.2cm') ?> พ.ศ. <?= fill($sy, '1.5cm') ?>
    ถึง วันที่ <?= fill($ed, '1cm') ?> เดือน <?= fill($em, '2.2cm') ?> พ.ศ. <?= fill($ey, '1.5cm') ?> ดังนี้</div>

  <table class="items">
    <thead><tr>
      <th style="width:1.3cm">ลำดับ</th><th>รายการ</th><th style="width:3.4cm">ยี่ห้อ/รุ่น</th>
      <th style="width:2.8cm">หมายเลขเครื่อง<br>(Serial – no.)</th><th style="width:3.4cm">หมายเลขพัสดุ</th><th style="width:1.3cm">จำนวน</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $i => $it): ?>
      <tr>
        <td class="c"><?= thai_digits($i + 1) ?></td>
        <td><?= h($it['item_name']) ?></td>
        <td><?= h($it['brand_model']) ?></td>
        <td class="c"><?= h($it['serial_number']) ?></td>
        <td class="c"><?= h($it['asset_no']) ?></td>
        <td class="c"><?= thai_digits((int)$it['quantity']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php for ($i = count($items); $i < $minRows; $i++): ?><tr class="blank"><td></td><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
    </tbody>
  </table>

  <p class="para">ตามรายการที่ยืมข้างต้นนี้ ข้าพเจ้าจะดูแลรักษาเป็นอย่างดี <span class="u">หากสิ่งของที่ส่งคืนเกิดการชำรุด เสียหาย
    ใช้การไม่ได้หรือสูญหายไป ข้าพเจ้ายินดีรับผิดชอบชดใช้หรือจัดการแก้ไขซ่อมแซมให้คงสภาพเดิม โดยจะรับผิดชอบในค่าใช้จ่ายที่เกิดขึ้น
    หรือชดใช้เป็นพัสดุประเภท ชนิด ขนาด ลักษณะ และคุณภาพอย่างเดียวกัน หรือชดใช้เป็นเงินตามราคาที่เป็นอยู่ในขณะยืมตามหลักเกณฑ์ที่กระทรวงการคลังกำหนด</span></p>
  <div class="indent">ทั้งนี้ ข้าพเจ้าจะส่งคืนพัสดุ ภายในวันที่ <?= fill($ed, '1cm') ?> เดือน <?= fill($em, '2.2cm') ?> พ.ศ. <?= fill($ey, '1.5cm') ?></div>

  <div class="sign sign-right">
    <div>(ลงชื่อ) <?= fill('', '5.2cm') ?> ผู้ยืม</div>
    <div class="who">( <?= fill($loan['borrower_name'], '4cm') ?> )</div>
    <div>ตำแหน่ง <?= fill($loan['borrower_position'], '5.5cm') ?></div>
    <div>วันที่ <?= fill($td(substr($loan['created_at'], 0, 10)), '5.9cm') ?></div>
  </div>

  <div class="sign-cols">
    <div class="sign">
      <div>(ลงชื่อ) <?= fill('', '4.6cm') ?> ผู้ให้ยืม</div>
      <div class="who">( <?= fill($lent ? $loan['lender_name'] : '', '3.8cm') ?> )</div>
      <div class="pos"><span>ตำแหน่ง</span><span class="pos-lines"><?= $lent && $loan['lender_position'] ? h($loan['lender_position']) : '&nbsp;' ?></span></div>
      <div>วันที่ <?= fill($lent ? $td(substr($loan['lent_at'], 0, 10)) : '', '5.3cm') ?></div>
    </div>
    <div class="sign">
      <div>(ลงชื่อ) <?= fill('', '5cm') ?> ผู้อนุมัติ</div>
      <div class="who">( <?= fill($approverName, '4.2cm') ?> )</div>
      <div class="pos"><span>ตำแหน่ง</span><span class="pos-lines"><?= $approverPos ? h($approverPos) : '&nbsp;' ?></span></div>
      <div>วันที่ <?= fill($lent ? $td(substr($loan['lent_at'], 0, 10)) : '', '5.6cm') ?></div>
    </div>
  </div>

  <div class="page2">
    <div class="page-no">-๒-</div>
    <div>ได้รับพัสดุคืนถูกต้อง ครบถ้วน สมบูรณ์เรียบร้อยแล้ว เมื่อวันที่ <?= fill($rd, '1cm') ?> เดือน <?= fill($rm, '2.2cm') ?> พ.ศ. <?= fill($ry, '1.5cm') ?></div>
    <div class="sign-cols" style="margin-top:22px">
      <div class="sign">
        <div>(ลงชื่อ) <?= fill('', '4.6cm') ?> ผู้ส่งคืน</div>
        <div class="who">( <?= fill($loan['borrower_name'], '3.8cm') ?> )</div>
        <div>ตำแหน่ง <?= fill($loan['borrower_position'], '4.9cm') ?></div>
        <div>วันที่ <?= fill($returned ? $td(substr($loan['returned_at'], 0, 10)) : '', '5.3cm') ?></div>
      </div>
      <div class="sign">
        <div>(ลงชื่อ) <?= fill('', '5cm') ?> ผู้รับคืน</div>
        <div class="who">( <?= fill($returned ? $loan['receiver_name'] : ($lent ? $loan['lender_name'] : ''), '4.2cm') ?> )</div>
        <?php $rp = $returned ? $loan['receiver_position'] : ($lent ? $loan['lender_position'] : ''); ?>
        <div class="pos"><span>ตำแหน่ง</span><span class="pos-lines"><?= $rp ? h($rp) : '&nbsp;' ?></span></div>
        <div>วันที่ <?= fill($returned ? $td(substr($loan['returned_at'], 0, 10)) : '', '5.6cm') ?></div>
      </div>
    </div>
    <p style="margin:18px 0 0"><strong>หมายเหตุ : โปรดอ่านข้อกำหนดและแนวปฏิบัติในการยืม-คืน</strong></p>
    <div class="rules">
      <h3>ข้อกำหนดการยืม-คืน</h3>
      <p>๑. การยืมควรระบุเหตุผลความจำเป็นที่ต้องการใช้งานทุกครั้ง และยืมเพื่อประโยชน์ในทางราชการเท่านั้น</p>
      <p>๒. ผู้ยืมมีหน้าที่รับผิดชอบต่อทรัพย์สินที่ได้ยืมไว้ใช้งาน เสมือนหนึ่งเป็นทรัพย์สินของผู้ใช้ยืมเอง ไม่ให้เกิดความเสียหาย หรือสูญหาย</p>
      <p>๓. ผู้ยืมมีหน้าที่ต้องชดใช้ความเสียหายในกรณีที่ทรัพย์สินชำรุด หรือสูญหาย ตามมูลค่าของทรัพย์สิน หากความเสียหายนั้นเกิดจากความประมาทของผู้ยืม</p>
      <p>๔. ผู้ยืมต้องไม่ให้ผู้อื่นยืมทรัพย์สินที่ตนเองได้ยืมมาไม่ว่ากรณีใดๆ เว้นแต่การยืมนั้นได้รับการอนุมัติเป็นลายลักษณ์อักษรจากผู้มีอำนาจอนุมัติแล้วเท่านั้น</p>
      <p>๕. ทรัพย์สินที่ผู้ยืมไปใช้งาน มีไว้ใช้เพื่อประโยชน์ของทางราชการเท่านั้น ห้ามมิให้ผู้ยืมนำทรัพย์สินไปใช้อย่างอื่นนอกเหนือจากที่หน่วยงานกำหนด หรือทำให้เกิดความเสียหายที่เกิดจากการละเมิดดังกล่าวให้ถือเป็นความผิดส่วนบุคคล โดยผู้ยืมต้องรับผิดต่อความเสียหายที่เกิดขึ้นนั้น</p>
      <p>๖. ผู้ยืมจะต้องกำหนดระยะเวลาการยืมให้ชัดเจน และจะต้องนำมาคืนภายในระยะเวลาที่กำหนด</p>
      <p>๗. กรณีมีความจำเป็นต้องใช้งานต่อ ให้ดำเนินการส่งคืนตามระยะเวลาที่กำหนดให้เรียบร้อยก่อน แล้วจัดทำบันทึกขอยืมและกำหนดระยะเวลาการยืมใหม่</p>
    </div>
    <div class="print-footer"><img src="<?= h(INV_URL . 'assets/img/print-footer.png') ?>" alt=""></div>
  </div>
</div>
</body>
</html>
