<?php
/**
 * นำเข้าเวลาปฏิบัติราชการจาก PDF "รายงานข้อมูลเวลาปฏิบัติราชการ" (ทุกคนในไฟล์เดียว) — ผู้ดูแลปฏิทิน · แนะนำนำเข้าทุกวันจันทร์
 * 1) เลือกไฟล์ → อ่านข้อความด้วย pdf.js ในเบราว์เซอร์ (ไม่ต้องติดตั้งโปรแกรมบนเซิร์ฟเวอร์) → ส่งข้อความ + ตำแหน่งมาแยกข้อมูล
 * 2) ตรวจการจับคู่ชื่อกับบัญชีผู้ใช้ → บันทึก (นำเข้าซ้ำได้: ปรับปรุงตามไฟล์ล่าสุด · การรับรองที่ผู้ดูแลแก้ไว้คงเดิม)
 * แสดงผลในแผนการปฏิบัติงาน (ดู att_lib.php)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/att_lib.php';
$me = require_module(CAL_MODULE, 'admin');
$pdo = db();
$sessKey = 'cal_att_import';
$tmpDir = APP_ROOT . '/storage/tmp';
$errors = [];
$tmpFile = fn(?string $t) => $t && preg_match('/^[a-f0-9]{32}$/', $t) ? $tmpDir . '/att_' . $t . '.json' : null;
$load = function () use ($sessKey, $tmpFile) {
    $f = $tmpFile($_SESSION[$sessKey]['token'] ?? null);
    return $f && is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
};
$clear = function () use ($sessKey, $tmpFile) {
    $f = $tmpFile($_SESSION[$sessKey]['token'] ?? null);
    if ($f && is_file($f)) {
        @unlink($f);
    }
    unset($_SESSION[$sessKey]);
};
$users = $pdo->query('SELECT id, username, sort_order, full_name, position FROM core_users WHERE is_active = 1')->fetchAll();
$staff = sort_staff(array_column($users, null, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'preview') {
        $json = (string)($_POST['items'] ?? '');
        $pages = strlen($json) <= 40 * 1024 * 1024 ? json_decode($json, true) : null;
        if (!is_array($pages) || !$pages) {
            $errors[] = 'อ่านไฟล์ PDF ไม่ได้ — กรุณาเลือกไฟล์ PDF ที่พิมพ์จากระบบลงเวลา แล้วลองใหม่';
        } else {
            $res = att_parse_pages($pages);
            $errors = $res['errors'];
            if ($res['people']) {
                foreach ($res['people'] as &$p) {
                    $p['user_id'] = att_match_user($p['name'], $users);
                }
                unset($p);
                $clear();
                if (!is_dir($tmpDir)) {
                    @mkdir($tmpDir, 0770, true);
                }
                $token = bin2hex(random_bytes(16));
                file_put_contents($tmpFile($token), json_encode($res, JSON_UNESCAPED_UNICODE));
                $_SESSION[$sessKey] = ['token' => $token, 'file' => mb_substr(post('file_name'), 0, 150)];
                if (!$errors) {
                    redirect('att_import.php?step=preview');
                }
            }
        }
    } elseif ($action === 'import') {
        $res = $load();
        if (!$res) {
            flash('ไม่พบข้อมูลที่อ่านไว้ กรุณาเลือกไฟล์อีกครั้ง', 'warning');
            redirect('att_import.php');
        }
        $up = $pdo->prepare('INSERT INTO cal_attendance (user_id, work_date, holiday, shift, time_in, time_out, late, certify, leave_code, absent_am, absent_pm, detail, report_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE holiday = VALUES(holiday), shift = VALUES(shift), time_in = VALUES(time_in), time_out = VALUES(time_out),
                               late = VALUES(late), certify = VALUES(certify), leave_code = VALUES(leave_code), absent_am = VALUES(absent_am),
                               absent_pm = VALUES(absent_pm), detail = VALUES(detail), report_at = VALUES(report_at), imported_at = NOW()');
        $n = ['people' => 0, 'rows' => 0, 'skipped' => 0];
        $used = [];
        $months = [];
        $pdo->beginTransaction();
        foreach ($res['people'] as $i => $p) {
            $v = (string)($_POST['map'][$i] ?? '');
            $uid = ctype_digit($v) && isset($staff[(int)$v]) ? (int)$v : null;
            if (!$uid || isset($used[$uid])) {
                $n['skipped']++;
                continue;
            }
            $used[$uid] = true;
            $n['people']++;
            foreach ($p['rows'] as $r) {
                $up->execute([$uid, $r['work_date'], $r['holiday'], $r['shift'], $r['time_in'], $r['time_out'], $r['late'], $r['certify'], $r['leave_code'],
                              $r['absent_am'], $r['absent_pm'], mb_substr($r['detail'], 0, 255), $res['report_at']]);
                $n['rows']++;
                $months[substr($r['work_date'], 0, 7)] = true;
            }
        }
        $pdo->commit();
        $file = $_SESSION[$sessKey]['file'] ?? '';
        $clear();
        $msg = 'นำเข้าเวลาปฏิบัติราชการแล้ว ' . $n['people'] . ' คน (' . $n['rows'] . ' วัน)' . ($n['skipped'] ? ' · ข้าม ' . $n['skipped'] . ' คน (ไม่ได้เลือกบัญชี)' : '')
             . ($res['report_at'] ? ' · ข้อมูล ณ ' . thai_date(substr($res['report_at'], 0, 10)) . ' ' . substr($res['report_at'], 11, 5) . ' น.' : '');
        audit_log('cal_att_import', $msg . ' (ไฟล์ ' . $file . ')', CAL_MODULE);
        flash($msg);
        ksort($months);
        redirect('plan.php?m=' . (array_key_last($months) ?? date('Y-m')));
    } elseif ($action === 'cancel') {
        $clear();
        redirect('att_import.php');
    }
}

$res = ($_GET['step'] ?? '') === 'preview' || ($errors && isset($_SESSION[$sessKey])) ? $load() : null;
$pageTitle = 'นำเข้าเวลาปฏิบัติราชการ';
$active = 'att';
$pageActions = '<a href="plan.php" class="btn btn-light"><i class="bi bi-grid-3x3"></i> แผนการปฏิบัติงาน</a>';
require __DIR__ . '/header.php';
?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <?= h($e) ?></div><?php endforeach; ?>

<?php if (!$res): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" class="card" id="attForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="preview">
      <input type="hidden" name="items" id="attItems"><input type="hidden" name="file_name" id="attName">
      <div class="card-header"><strong><i class="bi bi-file-earmark-pdf text-danger"></i> ไฟล์รายงานข้อมูลเวลาปฏิบัติราชการ (PDF)</strong></div>
      <div class="card-body">
        <input type="file" id="attFile" class="form-control mb-3" accept="application/pdf,.pdf" required>
        <button class="btn btn-primary" id="attBtn"><i class="bi bi-search"></i> อ่านไฟล์และตรวจสอบ</button>
        <span class="small text-muted ms-2" id="attStatus">ยังไม่บันทึกจนกว่าจะกดยืนยัน</span>
      </div>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><strong>วิธีใช้และการแสดงผลในแผนการปฏิบัติงาน</strong></div>
      <div class="card-body small">
        <ul class="mb-2">
          <li>นำเข้าทุกวันจันทร์: พิมพ์ "รายงานข้อมูลเวลาปฏิบัติราชการ" ของทุกคนเป็น PDF ไฟล์เดียวจากระบบลงเวลา แล้วเลือกไฟล์ที่นี่ (นำเข้าซ้ำได้ ข้อมูลปรับตามไฟล์ล่าสุด)</li>
          <li>ตัดสินเฉพาะวันทำงานก่อนวันที่พิมพ์รายงาน · วันหยุดราชการไม่แสดง</li>
        </ul>
        <table class="table table-sm mb-0">
          <tr><td class="text-nowrap"><span class="att-chip">—</span></td><td>เข้าไม่เกิน 08:30 และออกตั้งแต่ 16:30 — ไม่แสดง</td></tr>
          <tr><td><span class="att-chip att-late">ส</span></td><td>สาย (ช่อง "สาย" ในไฟล์มีค่า)</td></tr>
          <tr><td class="text-nowrap"><span class="att-chip att-half">ลาเช้า</span></td><td>เข้า 12:00–13:00 และออกหลัง 16:30</td></tr>
          <tr><td class="text-nowrap"><span class="att-chip att-half">ลาบ่าย</span></td><td>เข้าก่อน 08:30 และออก 12:00–13:00</td></tr>
          <tr><td><span class="att-chip att-pending">ล</span></td><td>ลงเวลาไม่ครบ (มีเข้าหรือออกอย่างเดียว) — รับรองการมาปฏิบัติราชการ + แจ้งเตือนเจ้าตัว</td></tr>
          <tr><td class="text-nowrap"><span class="att-chip">สรง</span></td><td>ไม่ลงเวลา แต่มีรหัสโครงการในแผน</td></tr>
          <tr><td><span class="att-chip att-trip">ร</span></td><td>ไม่ลงเวลา มีกิจกรรมในปฏิทินที่สถานที่ไม่ใช่ Zoom / ศาลากลางจังหวัดขอนแก่น / ห้องประชุมสำนักงานสถิติจังหวัดขอนแก่น</td></tr>
          <tr><td><span class="att-chip att-absent">ข</span></td><td>ขาดราชการ — ผู้ดูแลปฏิทินแก้เป็น <span class="att-chip att-cert">ล</span> รับรองการมาปฏิบัติราชการ ได้ (คลิกที่ ข ในแผน)</td></tr>
          <tr><td><span class="att-chip att-leave">ป</span></td><td>การลาตามไฟล์ (ก ป พ ...)</td></tr>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js" integrity="sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e" crossorigin="anonymous"></script>
<script>
(() => {
  // อ่านข้อความ + ตำแหน่งจาก PDF ด้วย pdf.js แล้วส่งไปแยกข้อมูลที่เซิร์ฟเวอร์
  const form = document.getElementById('attForm'), status = document.getElementById('attStatus'), btn = document.getElementById('attBtn');
  form.addEventListener('submit', async e => {
    if (document.getElementById('attItems').value) { return; }
    e.preventDefault();
    const file = document.getElementById('attFile').files[0];
    if (!file) { return; }
    if (!window.pdfjsLib) { status.textContent = 'โหลดตัวอ่าน PDF ไม่ได้ — ตรวจการเชื่อมต่ออินเทอร์เน็ต'; return; }
    btn.disabled = true;
    try {
      pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
      const doc = await pdfjsLib.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
      const pages = [];
      for (let i = 1; i <= doc.numPages; i++) {
        status.textContent = 'กำลังอ่านหน้า ' + i + ' / ' + doc.numPages + ' …';
        const pg = await doc.getPage(i), vp = pg.getViewport({ scale: 1 }), tc = await pg.getTextContent();
        pages.push(tc.items.filter(it => it.str && it.str.trim() !== '').map(it => [it.str, Math.round(it.transform[4] * 10) / 10,
          Math.round((vp.height - it.transform[5]) * 10) / 10, Math.round(it.width * 10) / 10]));
      }
      document.getElementById('attItems').value = JSON.stringify(pages);
      document.getElementById('attName').value = file.name;
      status.textContent = 'อ่านแล้ว ' + doc.numPages + ' หน้า — กำลังตรวจสอบ…';
      form.submit();
    } catch (err) {
      status.textContent = 'อ่านไฟล์ไม่ได้: ' + err.message;
      btn.disabled = false;
    }
  });
})();
</script>
<?php else:
  $judge = fn(array $r) => !$r['holiday'] && (!$res['report_at'] || $r['work_date'] < substr($res['report_at'], 0, 10)); ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="card mb-3">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center"><strong><i class="bi bi-person-lines-fill"></i> ผู้ปฏิบัติงานในไฟล์ (<?= count($res['people']) ?> คน)</strong>
      <span class="small text-muted">ไฟล์ <?= h($_SESSION[$sessKey]['file'] ?? '') ?><?= $res['report_at'] ? ' · ข้อมูล ณ ' . h(thai_date(substr($res['report_at'], 0, 10)) . ' ' . substr($res['report_at'], 11, 5)) . ' น. (ตัดสินถึงวันก่อนหน้า)' : '' ?></span></div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>ชื่อในไฟล์</th><th style="min-width:240px">บัญชีผู้ใช้</th><th class="text-center">วันทำงาน</th><th class="text-center">ครบ</th>
          <th class="text-center">สาย</th><th class="text-center">ลงไม่ครบ</th><th class="text-center">ไม่ลงเวลา</th><th class="text-center">ลา/รับรอง</th></tr></thead>
        <tbody>
        <?php foreach ($res['people'] as $i => $p):
          $rows = array_filter($p['rows'], $judge);
          $cnt = ['full' => 0, 'late' => 0, 'half' => 0, 'none' => 0, 'leave' => 0];
          foreach ($rows as $r) {
              if ($r['leave_code'] !== '' || $r['certify'] !== '') { $cnt['leave']++; }
              elseif ($r['time_in'] && $r['time_out']) { $cnt['full']++; if ($r['late'] !== '') { $cnt['late']++; } }
              elseif ($r['time_in'] || $r['time_out']) { $cnt['half']++; }
              else { $cnt['none']++; }
          } ?>
          <tr>
            <td><?= h($p['name']) ?><div class="small text-muted"><?= h($p['position']) ?></div></td>
            <td><select name="map[<?= $i ?>]" class="form-select form-select-sm<?= $p['user_id'] ? '' : ' is-invalid' ?>"><option value="">— ข้าม ไม่นำเข้า —</option>
              <?php foreach ($staff as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$p['user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h(preg_replace('/\s+/u', ' ', $u['full_name'])) ?></option><?php endforeach; ?>
            </select></td>
            <td class="text-center"><?= count($rows) ?></td>
            <td class="text-center"><?= $cnt['full'] ?></td>
            <td class="text-center<?= $cnt['late'] ? ' text-warning fw-semibold' : '' ?>"><?= $cnt['late'] ?></td>
            <td class="text-center<?= $cnt['half'] ? ' text-danger fw-semibold' : '' ?>"><?= $cnt['half'] ?></td>
            <td class="text-center<?= $cnt['none'] ? ' fw-semibold' : '' ?>"><?= $cnt['none'] ?></td>
            <td class="text-center"><?= $cnt['leave'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer small text-muted">"ไม่ลงเวลา" จะแสดงเป็นรหัสโครงการ (มีงานในแผน) / ร (มีกิจกรรมไปราชการ) / ข (ขาดราชการ) ในแผนการปฏิบัติงาน</div>
  </div>
  <div class="d-flex gap-2">
    <button name="action" value="import" class="btn btn-success"><i class="bi bi-check-lg"></i> ยืนยันนำเข้า</button>
    <button name="action" value="cancel" class="btn btn-light" formnovalidate>ยกเลิก</button>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
