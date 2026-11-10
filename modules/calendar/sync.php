<?php
/** ส่งออก / ซิงก์ "งานของฉัน" ไป Google Calendar และปฏิทิน iOS (ไฟล์ .ics หรือ ลิงก์สมัครรับ) */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ical_lib.php';
$me = require_module(CAL_MODULE);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'renew') {
    check_csrf();
    cal_ical_token((int)$me['id'], true);
    audit_log('cal_ical_renew', 'สร้างลิงก์สมัครรับปฏิทินใหม่', CAL_MODULE);
    flash('สร้างลิงก์ใหม่แล้ว — ลิงก์เดิมใช้ไม่ได้ ต้องสมัครรับในแอปปฏิทินใหม่');
    redirect('sync.php');
}
$token = cal_ical_token((int)$me['id']);
$https = cal_ical_feed_url($token);
$webcal = preg_replace('#^https?://#', 'webcal://', $https);
$google = 'https://calendar.google.com/calendar/r?cid=' . rawurlencode($webcal);
$st = db()->prepare('SELECT last_used_at FROM cal_ical_tokens WHERE user_id = ?');
$st->execute([(int)$me['id']]);
$lastUsed = $st->fetchColumn();
$count = count(cal_ical_events((int)$me['id']));
// ที่อยู่ระบบเข้าถึงจากอินเทอร์เน็ตได้หรือไม่ (Google ต้องดึงจากภายนอก)
$host = (string)parse_url(APP_URL, PHP_URL_HOST);
$internal = $host === 'localhost' || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host) || !str_contains($host, '.');

$pageTitle = 'ส่งออกไป Google Calendar / iOS';
$active = 'mine';
$pageActions = '<a href="index.php?mine=1" class="btn btn-light"><i class="bi bi-calendar3"></i> งานของฉัน</a>';
require __DIR__ . '/header.php';
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><strong><i class="bi bi-arrow-repeat text-primary"></i> สมัครรับ (ซิงก์อัตโนมัติ)</strong> <span class="badge text-bg-success">แนะนำ</span></div>
      <div class="card-body">
        <p class="small text-muted mb-2">ปฏิทินในโทรศัพท์/Google ดึง "งานของฉัน" ให้อัตโนมัติ (ทุก 1 ชั่วโมงขึ้นไป ขึ้นกับแอป) — งานใหม่/แก้ไข/ลบ ตามมาเอง</p>
        <?php if ($internal): ?>
          <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle"></i> ที่อยู่ระบบ <b><?= h($host) ?></b> เป็นที่อยู่ภายใน —
            Google Calendar ดึงข้อมูลไม่ได้ (iPhone ใช้ได้เมื่ออยู่ในเครือข่ายสำนักงาน) · ผู้ดูแลระบบตั้ง URL ของระบบที่เข้าถึงจากภายนอกได้ที่ <i>ตั้งค่าระบบ</i></div>
        <?php endif; ?>
        <div class="d-grid gap-2 mb-3">
          <a href="<?= h($webcal) ?>" class="btn btn-dark"><i class="bi bi-apple"></i> เพิ่มในปฏิทิน iPhone / iPad / Mac</a>
          <a href="<?= h($google) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="bi bi-google"></i> เพิ่มใน Google Calendar</a>
        </div>
        <label class="form-label small mb-1">ลิงก์สมัครรับ (สำหรับคัดลอกไปวางเอง)</label>
        <div class="input-group input-group-sm mb-2">
          <input type="text" class="form-control font-monospace" id="feedUrl" value="<?= h($https) ?>" readonly>
          <button type="button" class="btn btn-outline-secondary" id="copyFeed"><i class="bi bi-clipboard"></i> คัดลอก</button>
        </div>
        <div class="small text-muted mb-3"><?= number_format($count) ?> รายการ (ย้อนหลัง 6 เดือน – ล่วงหน้า 1 ปี) · ดึงข้อมูลล่าสุด: <?= $lastUsed ? h(thai_date(substr($lastUsed, 0, 10)) . ' ' . substr($lastUsed, 11, 5)) . ' น.' : 'ยังไม่เคย' ?></div>
        <div class="alert alert-secondary small py-2 mb-2"><i class="bi bi-shield-lock"></i> ลิงก์นี้เป็นของคุณคนเดียว ใครมีลิงก์จะเห็นงานของคุณ (รวมกิจกรรมส่วนตัว) — ถ้าลิงก์หลุด ให้สร้างใหม่</div>
        <form method="post" data-confirm="สร้างลิงก์ใหม่? ลิงก์เดิมจะใช้ไม่ได้ ต้องสมัครรับในแอปปฏิทินใหม่">
          <?= csrf_field() ?><input type="hidden" name="action" value="renew">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-clockwise"></i> สร้างลิงก์ใหม่ (ยกเลิกลิงก์เดิม)</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-download"></i> ดาวน์โหลดไฟล์ .ics (นำเข้าครั้งเดียว)</strong></div>
      <div class="card-body">
        <p class="small text-muted">ได้ข้อมูล ณ ตอนดาวน์โหลด — งานที่เพิ่ม/แก้ภายหลังจะไม่ตามไป (นำเข้าซ้ำจะไม่ซ้ำรายการเดิมใน Google)</p>
        <a href="ical.php?download=1" class="btn btn-primary"><i class="bi bi-calendar-plus"></i> ดาวน์โหลด my-calendar.ics</a>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><strong>วิธีตั้งค่า</strong></div>
      <div class="card-body small">
        <p class="mb-1"><b><i class="bi bi-apple"></i> iPhone / iPad</b></p>
        <ol class="mb-3">
          <li>เปิดหน้านี้ใน Safari บน iPhone แล้วกด <b>เพิ่มในปฏิทิน iPhone</b> → <b>สมัครรับ</b></li>
          <li>หรือ <i>การตั้งค่า → ปฏิทิน → บัญชี → เพิ่มบัญชี → อื่นๆ → เพิ่มปฏิทินที่สมัครรับ</i> แล้ววางลิงก์</li>
        </ol>
        <p class="mb-1"><b><i class="bi bi-google"></i> Google Calendar (คอมพิวเตอร์)</b></p>
        <ol class="mb-3">
          <li>กด <b>เพิ่มใน Google Calendar</b> แล้วยืนยัน หรือ</li>
          <li>calendar.google.com → ปฏิทินอื่นๆ <b>+</b> → <b>จาก URL</b> → วางลิงก์ → เพิ่มปฏิทิน</li>
          <li>Google อัปเดตลิงก์สมัครรับเองทุกไม่กี่ชั่วโมง (ไม่ทันที) · Android เห็นผ่านแอป Google Calendar</li>
        </ol>
        <p class="mb-1"><b><i class="bi bi-file-earmark-arrow-down"></i> ไฟล์ .ics</b></p>
        <ul class="mb-0">
          <li>Google: การตั้งค่า → นำเข้าและส่งออก → เลือกไฟล์ → นำเข้า</li>
          <li>iPhone: เปิดไฟล์ → เพิ่มทั้งหมด</li>
        </ul>
      </div>
    </div>
  </div>
</div>
<script>
document.getElementById('copyFeed').addEventListener('click', async e => {
  const inp = document.getElementById('feedUrl'); inp.select();
  try { await navigator.clipboard.writeText(inp.value); } catch (_) { document.execCommand('copy'); }
  e.currentTarget.innerHTML = '<i class="bi bi-check2"></i> คัดลอกแล้ว';
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
