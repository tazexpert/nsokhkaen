<?php
/**
 * ค้นหาพิกัด lat/long ของศูนย์อัตโนมัติ
 * การค้นหาทำในเบราว์เซอร์ของผู้ใช้ (OpenStreetMap Nominatim) แล้วส่งพิกัดที่ยืนยันแล้วมาบันทึกที่นี่
 */
require_once __DIR__ . '/bootstrap.php';
require_module(DCC_MODULE, 'editor');
$pdo = db();

// บันทึกพิกัด (เรียกจาก JavaScript)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    header('Content-Type: application/json; charset=utf-8');
    $id = int_param('id');
    $lat = coord(post('lat'), 5, 21);     // ขอบเขตประเทศไทยโดยประมาณ
    $lng = coord(post('lng'), 97, 106);
    if (!$id || $lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'พิกัดไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->prepare('UPDATE dcc_centers SET lat = ?, lng = ? WHERE id = ?')->execute([$lat, $lng, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

$all = !empty($_GET['all']);
$rows = $pdo->query(
    'SELECT id, code, name, address, subdistrict, district, lat, lng FROM dcc_centers' .
    ($all ? '' : ' WHERE lat IS NULL OR lng IS NULL') . ' ORDER BY district, name'
)->fetchAll();

$pageTitle = 'ค้นหาพิกัดศูนย์อัตโนมัติ';
$active = 'geocode';
require __DIR__ . '/header.php';
?>
<div class="alert alert-info small">
  <i class="bi bi-info-circle"></i>
  ระบบจะค้นหาชื่อศูนย์จากแผนที่ <strong>OpenStreetMap</strong> (ประมาณ 1 รายการต่อวินาที) และ<strong>เลือกให้อัตโนมัติเฉพาะผลที่อยู่ในอำเภอเดียวกัน</strong>
  ตรวจตำแหน่งบนแผนที่ก่อนกดบันทึก — ถ้าไม่พบ ใช้ปุ่ม <i class="bi bi-google"></i> เปิด Google Maps แล้วคัดลอกพิกัด (คลิกขวาที่หมุด) มาวางในช่องได้เลย
</div>

<div class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-2 align-items-center">
    <button class="btn btn-primary" id="btnRun"><i class="bi bi-search"></i> เริ่มค้นหาทั้งหมด</button>
    <button class="btn btn-success" id="btnSaveAll" disabled><i class="bi bi-save"></i> บันทึกทั้งหมดที่เลือก</button>
    <span id="progress" class="small text-muted ms-2"></span>
    <a class="ms-auto small" href="geocode.php<?= $all ? '' : '?all=1' ?>"><?= $all ? 'แสดงเฉพาะศูนย์ที่ยังไม่มีพิกัด' : 'แสดงทุกศูนย์ (รวมที่มีพิกัดแล้ว)' ?></a>
  </div>
  <div id="geoMap" style="height:380px;border-top:1px solid #e5e9f0"></div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table align-middle mb-0" id="geoTable">
      <thead><tr><th>ศูนย์</th><th style="width:34%">ผลการค้นหา</th><th style="width:230px">พิกัด (lat, lng)</th><th class="text-end" style="width:150px"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-id="<?= (int)$r['id'] ?>" data-name="<?= h($r['name']) ?>" data-sub="<?= h($r['subdistrict']) ?>" data-district="<?= h($r['district']) ?>">
          <td><div class="fw-semibold"><?= h($r['name']) ?></div>
            <div class="small text-muted"><?= $r['code'] ? h($r['code']) . ' · ' : '' ?>ต.<?= h($r['subdistrict']) ?> อ.<?= h($r['district']) ?></div></td>
          <td class="result small text-muted"><?= $r['lat'] !== null ? '<span class="text-success"><i class="bi bi-check-circle"></i> มีพิกัดแล้ว</span>' : '—' ?></td>
          <td><input class="form-control form-control-sm coord" placeholder="16.xxxx, 102.xxxx" value="<?= $r['lat'] !== null ? h((float)$r['lat'] . ', ' . (float)$r['lng']) : '' ?>"></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-light" target="_blank" rel="noopener" title="ค้นหาใน Google Maps"
               href="https://www.google.com/maps/search/<?= rawurlencode($r['name'] . ' ' . $r['subdistrict'] . ' ' . $r['district'] . ' ขอนแก่น') ?>"><i class="bi bi-google"></i></a>
            <button class="btn btn-sm btn-light one" title="ค้นหารายการนี้"><i class="bi bi-search"></i></button>
            <button class="btn btn-sm btn-outline-success save" title="บันทึก"><i class="bi bi-save"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="4" class="text-center text-success py-5"><i class="bi bi-check2-all"></i> ทุกศูนย์มีพิกัดแล้ว</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
window.GEO_CSRF = <?= json_encode(csrf_token()) ?>;
</script>
<?php require __DIR__ . '/footer.php'; ?>

