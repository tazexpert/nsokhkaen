<?php
/** แสดง/พิมพ์ แบบฟอร์มการตรวจเยี่ยมศูนย์ดิจิทัลชุมชน (รูปแบบเดียวกับแบบฟอร์มกระดาษ) */
require_once __DIR__ . '/bootstrap.php';
require_module(DCC_MODULE, 'inspector');
$pdo = db();
$id = int_param('id');
$st = $pdo->prepare('SELECT v.*, c.name AS cur_name, c.house_no, c.village, c.moo, c.address, c.subdistrict, c.district, c.center_type
                     FROM dcc_visits v JOIN dcc_centers c ON c.id = v.center_id WHERE v.id = ?');
$st->execute([$id]);
$v = $st->fetch();
if (!$v) {
    flash('ไม่พบบันทึกการตรวจเยี่ยม', 'danger');
    redirect('visits.php');
}
$d = (json_decode((string)$v['data'], true) ?: []) + ['groups' => [], 'purposes' => [], 'safety' => []];
// ข้อมูลทั่วไป ณ วันที่ตรวจ (ถ้าไม่มีสำเนา ใช้ข้อมูลศูนย์ปัจจุบัน)
$s = ($d['snapshot'] ?? []) + [
    'name' => $v['cur_name'], 'house_no' => $v['house_no'], 'village' => $v['village'], 'moo' => $v['moo'], 'address' => $v['address'],
    'subdistrict' => $v['subdistrict'], 'district' => $v['district'], 'center_type' => $v['center_type'],
    'staff_name' => '', 'staff_phone' => '', 'staff_line' => '',
];
$st = $pdo->prepare('SELECT COUNT(*) FROM dcc_visits WHERE center_id = ? AND (visit_date < ? OR (visit_date = ? AND id <= ?))');
$st->execute([$v['center_id'], $v['visit_date'], $v['visit_date'], $v['id']]);
$visitNo = (int)$st->fetchColumn();
$photos = [];
if ($v['activity_id']) {
    $st = $pdo->prepare('SELECT file FROM dcc_activity_images WHERE activity_id = ? ORDER BY id');
    $st->execute([$v['activity_id']]);
    $photos = $st->fetchAll(PDO::FETCH_COLUMN);
}

$box = fn(bool $on) => '<span class="bx">' . ($on ? '☑' : '☐') . '</span>';
$fill = fn($text, string $w = '') => '<span class="fill"' . ($w ? " style=\"min-width:$w\"" : '') . '>' . (trim((string)$text) !== '' ? h($text) : '&nbsp;') . '</span>';
$opt = fn(string $k, string $o) => $box(($d[$k] ?? '') === $o) . ' ' . h($o);
$typeSel = visit_type_option((string)$s['center_type']);
[$y, $m, $dd] = array_map('intval', explode('-', $v['visit_date']));
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>แบบฟอร์มการตรวจเยี่ยม · <?= h($s['name']) ?></title>
<link rel="icon" type="image/png" href="assets/img/favicon.png">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root{--line:#333}
  *{box-sizing:border-box}
  body{margin:0;background:#e9edf2;font-family:'Sarabun',sans-serif;font-size:15px;color:#111;line-height:1.75}
  .toolbar{position:sticky;top:0;display:flex;gap:8px;flex-wrap:wrap;padding:10px 16px;background:linear-gradient(to right,#4099ff,#73b4ff)}
  .toolbar a,.toolbar button{display:inline-flex;gap:6px;align-items:center;padding:8px 14px;border-radius:8px;border:0;background:#fff;color:#1a66c2;text-decoration:none;font:inherit;cursor:pointer}
  .toolbar .primary{background:#0f6cbd;color:#fff}
  .page{background:#fff;max-width:210mm;margin:16px auto;padding:14mm 16mm;box-shadow:0 4px 20px rgba(0,0,0,.12)}
  h1{font-size:19px;text-align:center;margin:0}
  .sub{text-align:center;margin-bottom:6px;font-weight:600}
  .right{text-align:right}
  .sec{font-weight:700;margin:12px 0 2px;background:#eef2f7;padding:2px 8px;border-left:4px solid #0f6cbd}
  .q{font-weight:600;margin-top:4px}
  .ind{padding-left:22px}
  .fill{display:inline-block;border-bottom:1px dotted var(--line);min-width:60px;padding:0 6px;text-align:center}
  .bx{font-size:17px;line-height:1}
  .row{display:flex;flex-wrap:wrap;column-gap:22px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;column-gap:20px}
  .dev{display:grid;grid-template-columns:240px 1fr 1fr 1fr}
  .text{border-bottom:1px dotted var(--line);min-height:28px;white-space:pre-line;padding:0 4px}
  .sign{display:grid;grid-template-columns:1fr 1fr;gap:20px;text-align:center;margin-top:26px}
  .sign .ln{border-bottom:1px dotted var(--line);display:inline-block;min-width:200px}
  .photos{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:8px}
  .photos img{width:100%;aspect-ratio:4/3;object-fit:cover;border:1px solid #ccc}
  .meta{font-size:12px;color:#555;margin-top:10px}
  .pb{break-before:page}
  @media (max-width:700px){.page{padding:16px;margin:0}.dev{grid-template-columns:1fr 1fr 1fr}.dev .n{grid-column:1/-1;font-weight:600}.grid2{grid-template-columns:1fr}.photos{grid-template-columns:1fr 1fr}}
  @media print{
    @page{size:A4;margin:12mm}
    body{background:#fff;font-size:14px}
    .toolbar{display:none}
    .page{box-shadow:none;margin:0;padding:0;max-width:none}
    .sec{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  }
</style>
</head>
<body>
<div class="toolbar">
  <a href="visits.php?center_id=<?= (int)$v['center_id'] ?>#history"><i class="bi bi-arrow-left"></i> กลับ</a>
  <a href="visit_form.php?center_id=<?= (int)$v['center_id'] ?>&amp;copy=1"><i class="bi bi-clipboard-plus"></i> ตรวจเยี่ยมครั้งถัดไป</a>
  <a href="visit_form.php?id=<?= (int)$v['id'] ?>"><i class="bi bi-pencil"></i> แก้ไข</a>
  <a href="public/center.html?id=<?= (int)$v['center_id'] ?>#act-<?= (int)$v['activity_id'] ?>" target="_blank"><i class="bi bi-eye"></i> ดูบนเว็บไซต์</a>
  <button class="primary" onclick="print()"><i class="bi bi-printer"></i> พิมพ์ / บันทึก PDF</button>
</div>

<div class="page">
  <img src="assets/img/logo.png" alt="" style="display:block;height:54px;margin:0 auto 6px">
  <h1>แบบฟอร์มการตรวจเยี่ยมศูนย์ดิจิทัลชุมชน</h1>
  <div class="sub">จังหวัด<?= h(PROVINCE) ?></div>
  <div class="right">ครั้งที่ <?= $fill($visitNo, '30px') ?> &nbsp; วันที่ตรวจเยี่ยม <?= $fill(sprintf('%d/%d/%d', $dd, $m, $y + 543)) ?> เวลา <?= $fill($v['visit_time'] ? substr($v['visit_time'], 0, 5) : '') ?> น.</div>

  <div class="sec">ข้อมูลทั่วไปของศูนย์ดิจิทัลชุมชน</div>
  <div>ชื่อศูนย์ดิจิทัลชุมชน <?= $fill($s['name'], '60%') ?></div>
  <div>ที่ตั้งศูนย์ เลขที่ <?= $fill($s['house_no']) ?> หมู่บ้าน <?= $fill($s['village'], '110px') ?> หมู่ที่ <?= $fill($s['moo']) ?>
    ตำบล <?= $fill($s['subdistrict'], '100px') ?> อำเภอ <?= $fill($s['district'], '100px') ?></div>
  <?php if (trim((string)$s['address']) !== ''): ?><div>ที่อยู่เพิ่มเติม <?= $fill($s['address'], '60%') ?></div><?php endif; ?>
  <div>สถานที่ตั้งอยู่ใน
    <?php foreach (VISIT_LOCATION_TYPES as $val => $label): ?><?= $box($typeSel === $val) ?> <?= h($label) ?>&nbsp; <?php endforeach; ?>
    <?= $box($typeSel === '' && $s['center_type'] !== '') ?> อื่น ๆ <?= $fill($typeSel === '' ? $s['center_type'] : '') ?></div>
  <div>เจ้าหน้าที่ประจำศูนย์ดิจิทัลชุมชน ชื่อ-นามสกุล <?= $fill($s['staff_name'], '45%') ?></div>
  <div>เบอร์โทรศัพท์ <?= $fill($s['staff_phone'], '160px') ?> Line ID <?= $fill($s['staff_line'], '160px') ?>
    <?php if ($v['lat'] !== null): ?>&nbsp; พิกัด <?= $fill(sprintf('%.6f, %.6f', $v['lat'], $v['lng'])) ?><?php endif; ?></div>

  <div class="sec">ส่วนที่ 1 ด้านโครงสร้างพื้นฐานและอุปกรณ์</div>
  <div class="q">1.1 สภาพเครื่องคอมพิวเตอร์</div>
  <div class="ind">จำนวนเครื่องคอมพิวเตอร์ <?= $fill($d['pc_total'] ?? '') ?> เครื่อง &nbsp; ใช้งานได้ <?= $fill($d['pc_ok'] ?? '') ?> เครื่อง &nbsp; ชำรุด/รอซ่อม <?= $fill($d['pc_broken'] ?? '') ?> เครื่อง</div>
  <div class="ind row"><span>ความพร้อมใช้งานของเครื่องคอมพิวเตอร์</span><?php foreach (VISIT_PC_STATUS as $o): ?><span><?= $opt('pc_status', $o) ?></span><?php endforeach; ?></div>
  <div class="q">1.2 ระบบเครือข่ายอินเทอร์เน็ต</div>
  <div class="ind"><?= $box(($d['net_status'] ?? '') === 'ok') ?> ใช้งานได้ปกติ Download <?= $fill($d['net_down'] ?? '') ?> Mbps Upload <?= $fill($d['net_up'] ?? '') ?> Mbps</div>
  <div class="ind"><?= $box(($d['net_status'] ?? '') === 'problem') ?> มีปัญหา (ช้า/หลุดบ่อย/ใช้ไม่ได้) สาเหตุ <?= $fill($d['net_problem'] ?? '', '45%') ?></div>
  <div class="ind"><?= $box(!empty($d['free_wifi'])) ?> มีการปล่อยสัญญาณ Free Wi-Fi ภายในศูนย์</div>
  <?php foreach (VISIT_DEVICES as $title => $items): ?>
    <div class="q"><?= h($title) ?></div>
    <?php foreach ($items as $key => [$label, $opts]): ?>
      <div class="ind dev"><span class="n"><?= h($label) ?></span><?php foreach ($opts as $o): ?><span><?= $opt('dev_' . $key, $o) ?></span><?php endforeach; ?></div>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <div class="sec">ส่วนที่ 2 ด้านการบริหารจัดการและการให้บริการ</div>
  <div class="q">2.1 การบริหารจัดการศูนย์</div>
  <div class="ind"><?= $box(!empty($d['sign'])) ?> มีป้ายชื่อศูนย์ดิจิทัลชุมชนติดตั้งชัดเจน</div>
  <div class="ind"><?= $box(!empty($d['schedule'])) ?> มีตารางเวลาเปิด-ปิดให้บริการชัดเจน เวลาเปิด <?= $fill($d['open_time'] ?? '') ?> น. เวลาปิด <?= $fill($d['close_time'] ?? '') ?> น.</div>
  <div class="ind"><?= $box(!empty($d['logbook'])) ?> มีสมุดบันทึกผู้ใช้บริการ (Logbook) หรือระบบสถิติออนไลน์</div>
  <div class="ind">จำนวนผู้เข้าใช้บริการเฉลี่ย <?= $fill($d['avg_users'] ?? '') ?> คน ต่อ <?php foreach (VISIT_AVG_PER as $o): ?><?= $opt('avg_per', $o) ?>&nbsp; <?php endforeach; ?></div>
  <div class="q">2.2 กลุ่มผู้ใช้บริการหลัก (เลือกได้มากกว่า 1 ข้อ)</div>
  <div class="ind grid2">
    <?php foreach (VISIT_USER_GROUPS as $k => $label): ?><span><?= $box(in_array($k, $d['groups'], true)) ?> <?= h($label) ?></span><?php endforeach; ?>
    <span><?= $box(trim($d['groups_other'] ?? '') !== '') ?> อื่น ๆ <?= $fill($d['groups_other'] ?? '') ?></span>
  </div>
  <div class="q">2.3 วัตถุประสงค์หลักในการเข้าใช้บริการ (เลือกได้มากกว่า 1 ข้อ)</div>
  <div class="ind grid2">
    <?php foreach (VISIT_PURPOSES as $k => $label): ?><span><?= $box(in_array($k, $d['purposes'], true)) ?> <?= h($label) ?></span><?php endforeach; ?>
    <span><?= $box(trim($d['purposes_other'] ?? '') !== '') ?> อื่น ๆ <?= $fill($d['purposes_other'] ?? '') ?></span>
  </div>

  <div class="sec">ส่วนที่ 3 ด้านความปลอดภัยและสภาพแวดล้อม</div>
  <div class="ind grid2"><?php foreach (VISIT_SAFETY as $k => $label): ?><span><?= $box(in_array($k, $d['safety'], true)) ?> <?= h($label) ?></span><?php endforeach; ?></div>

  <div class="sec">ส่วนที่ 4 สรุปปัญหา อุปสรรค และข้อเสนอแนะ</div>
  <div class="q">4.1 ปัญหาหรืออุปสรรคที่พบในการดำเนินงานของศูนย์</div>
  <div class="text"><?= h($v['problems']) ?: '&nbsp;' ?></div>
  <div class="q">4.2 ความต้องการหรือข้อเสนอแนะที่ต้องการให้ส่วนกลางสนับสนุน</div>
  <div class="text"><?= h($v['suggestions']) ?: '&nbsp;' ?></div>

  <div class="sign">
    <div>ลงชื่อ <span class="ln">&nbsp;</span><br>( <?= $fill($v['informant_name'], '180px') ?> )<br>ตำแหน่ง <?= $fill($v['informant_position'], '160px') ?><br>ผู้ให้ข้อมูล</div>
    <div>ลงชื่อ <span class="ln">&nbsp;</span><br>( <?= $fill($v['inspector_name'], '180px') ?> )<br>ตำแหน่ง <?= $fill($v['inspector_position'], '160px') ?><br>ผู้ตรวจเยี่ยม</div>
  </div>

  <?php if ($photos): ?>
    <div class="pb"></div>
    <div class="sec">ภาพประกอบการตรวจเยี่ยม — <?= h($s['name']) ?> (<?= thai_date($v['visit_date']) ?>)</div>
    <div class="photos"><?php foreach ($photos as $p): ?><img src="<?= h(upload_url($p)) ?>" alt=""><?php endforeach; ?></div>
  <?php endif; ?>
  <div class="meta">บันทึกในระบบเมื่อ <?= h($v['created_at']) ?><?= $v['updated_at'] !== $v['created_at'] ? ' · แก้ไขล่าสุด ' . h($v['updated_at']) : '' ?></div>
</div>
</body>
</html>
