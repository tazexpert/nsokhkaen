<?php
/**
 * นำเข้าเวรศาลากลางจากคำสั่งจังหวัด (ไฟล์ PDF ที่สแกน) — ผู้ดูแลปฏิทิน · แท็บที่ 2 ของ "นำเข้าเวรสนง./ศาลากลาง"
 *   1) เลือกไฟล์ PDF → เบราว์เซอร์อ่านตาราง "บัญชีรายละเอียดแนบท้ายคำสั่ง" (pdf.js + OCR tesseract.js — ไม่ส่งไฟล์ไปที่ใด)
 *      หาเส้นตาราง → อ่านทีละช่อง (วัน เดือน ปี · ผู้ปฏิบัติหน้าที่เวร · ผู้ตรวจเวร) → เลือกเฉพาะบุคลากรของสำนักงาน
 *   2) ตรวจ/แก้รายการ → บันทึก (แทนที่ทั้งเดือน) → ปฏิทิน "เวรศาลากลาง-กลางวัน/กลางคืน · ผู้ปฏิบัติหน้าที่เวร/ผู้ตรวจเวร"
 *      + แจ้งเตือนผู้รับผิดชอบ + เครื่องหมาย * ในแผนการปฏิบัติงานของเดือนนั้น (assets/js/guard-import.js)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/guard_lib.php';
require_once __DIR__ . '/plan_lib.php';
$me = require_module(CAL_MODULE, 'admin');
$errors = [];
$ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m', strtotime('first day of next month'));
[$y, $m] = array_map('intval', explode('-', $ym));
if ($m < 1 || $m > 12) {
    [$y, $m] = [(int)date('Y'), (int)date('n')];
}
$users = cal_users();
$hasTable = guard_has_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTable) {
    check_csrf();
    [$y, $m] = array_map('intval', explode('-', preg_match('/^\d{4}-\d{2}$/', (string)post('month')) ? post('month') : sprintf('%04d-%02d', $y, $m)));
    $ym = sprintf('%04d-%02d', $y, $m);
    if (post('action') === 'clear') {
        $from = $ym . '-01';
        db()->prepare('DELETE FROM cal_guard_shifts WHERE guard_date BETWEEN ? AND ?')->execute([$from, date('Y-m-t', strtotime($from))]);
        [$c, $u, $d] = plan_sync_guard($y, $m, (int)$me['id']);        // กลับไปใช้เวรศาลากลางจากแผนการปฏิบัติงาน (ถ้ามี)
        audit_log('cal_guard_import', 'ล้างเวรศาลากลางตามคำสั่งจังหวัด เดือน ' . $ym, CAL_MODULE);
        flash('ล้างเวรศาลากลางตามคำสั่งจังหวัดของเดือนนี้แล้ว' . ($c ? ' — ใช้เวรศาลากลางจากแผนการปฏิบัติงานแทน ' . $c . ' วัน' : ''));
        redirect('guard_import?m=' . $ym);
    }
    $rows = [];
    $raw = json_decode((string)post('rows'), true);
    $days = (int)date('t', strtotime($ym . '-01'));
    foreach (is_array($raw) ? $raw : [] as $i => $r) {
        $day = (int)($r['day'] ?? 0);
        $shift = (string)($r['shift'] ?? '');
        $role = (string)($r['role'] ?? '');
        $uid = (int)($r['user_id'] ?? 0);
        $name = trim(preg_replace('/\s+/u', ' ', (string)($r['name'] ?? '')));
        if ($uid && isset($users[$uid])) {
            $name = preg_replace('/\s+/u', ' ', trim($users[$uid]['full_name']));
        } else {
            $uid = 0;
        }
        if ($day < 1 || $day > $days || !isset(GUARD_SHIFTS[$shift]) || !isset(GUARD_ROLES[$role]) || $name === '') {
            $errors[] = 'รายการที่ ' . ($i + 1) . ' ข้อมูลไม่ครบ (วันที่ / ช่วง / หน้าที่ / ชื่อ)';
            continue;
        }
        $rows[] = ['date' => sprintf('%s-%02d', $ym, $day), 'shift' => $shift, 'role' => $role, 'user_id' => $uid ?: null, 'name' => $name];
    }
    if (!$rows && !$errors) {
        $errors[] = 'ยังไม่มีรายการเวร — อ่านไฟล์ PDF หรือเพิ่มรายการก่อนบันทึก';
    }
    if (!$errors) {
        usort($rows, fn($a, $b) => [$a['date'], $a['shift'] === 'night', $a['role'] === 'inspector'] <=> [$b['date'], $b['shift'] === 'night', $b['role'] === 'inspector']);
        $res = guard_save_month($y, $m, $rows, (int)$me['id']);
        [$c, $u, $d] = $res['events'];
        $label = thai_month_long($m) . ' ' . ($y + 543);
        audit_log('cal_guard_import', 'เวรศาลากลางตามคำสั่งจังหวัด ' . $label . ': ' . $res['rows'] . ' รายการ (กิจกรรมใหม่ ' . $c . ' · แก้ไข ' . $u . ' · ลบ ' . $d . ')', CAL_MODULE);
        flash('บันทึกเวรศาลากลาง ' . $label . ' แล้ว ' . $res['rows'] . ' รายการ — ปฏิทิน: เพิ่ม ' . $c . ' · แก้ไข ' . $u . ' · ลบ ' . $d
            . ($res['plan'] ? ' · ทำเครื่องหมาย * ในแผนการปฏิบัติงาน ' . $res['plan'] . ' วัน' : ''));
        redirect('guard_import?m=' . $ym);
    }
}

$saved = guard_month_rows($y, $m);
$init = array_map(fn($r) => ['day' => (int)substr($r['guard_date'], 8, 2), 'shift' => $r['shift'], 'role' => $r['role'],
    'user_id' => $r['user_id'] ? (int)$r['user_id'] : 0, 'name' => $r['name']], $saved);
if ($errors && isset($raw) && is_array($raw)) {
    $init = $raw;                                   // ข้อผิดพลาด → คงรายการที่แก้ไว้
}
// บุคลากรสำหรับจับคู่ชื่อที่อ่านได้ (ชื่อไม่มีคำนำหน้า) และช่องเลือก — เรียงตามลำดับบุคลากร
$staff = [];
foreach ($users as $u) {
    $full = preg_replace('/\s+/u', ' ', trim($u['full_name']));
    $staff[] = ['id' => (int)$u['id'], 'name' => $full, 'bare' => preg_replace('/^(นางสาว|นาง|นาย|ว่าที่ร้อยตรี|ว่าที่\s*ร\.ต\.|ดร\.)\s*/u', '', $full)];
}
$months = [];
for ($i = -2; $i <= 3; $i++) {
    $t = strtotime(date('Y-m-01') . " $i month");
    $months[date('Y-m', $t)] = thai_month_long((int)date('n', $t)) . ' ' . ((int)date('Y', $t) + 543);
}
$months[$ym] ??= thai_month_long($m) . ' ' . ($y + 543);
ksort($months);

$active = 'duty';
$pageTitle = 'นำเข้าเวรสนง./ศาลากลาง';
require __DIR__ . '/header.php';
?>
<?= cal_duty_tabs('guard') ?>
<?php if (!$hasTable): ?>
  <div class="alert alert-warning">ต้องรัน migration <code>database/migrations/20261215_cal_guard_shifts.sql</code> ก่อนใช้งาน</div>
<?php else: ?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<div class="row g-3" data-reveal>
  <div class="col-xl-4 reveal-item">
    <div class="card h-100">
      <div class="card-header"><strong><i class="bi bi-file-earmark-pdf"></i> 1. อ่านไฟล์คำสั่งจังหวัด</strong></div>
      <div class="card-body">
        <label class="form-label">ไฟล์ PDF ที่สแกน (หรือภาพหน้าตาราง)</label>
        <input type="file" class="form-control" id="giFile" accept="application/pdf,image/*" multiple>
        <div class="small text-muted mt-2">ระบบอ่านตาราง "บัญชีรายละเอียดแนบท้ายคำสั่ง" ในเบราว์เซอร์ (ไม่ส่งไฟล์ไปที่ใด) ใช้เวลาราว 1–3 นาที
          · เลือกเฉพาะบุคลากรของสำนักงานสถิติจังหวัดขอนแก่น ในช่อง <b>ผู้ปฏิบัติหน้าที่เวร</b> และ <b>ผู้ตรวจเวร</b></div>
        <div class="progress mt-3 d-none" id="giProg" role="progressbar" aria-label="ความคืบหน้า"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>
        <div class="small mt-2" id="giMsg"></div>
        <details class="mt-2 small d-none" id="giRawBox"><summary>ข้อความที่อ่านได้จากตาราง (ไว้ตรวจสอบ)</summary><pre class="gi-raw" id="giRaw"></pre></details>
      </div>
    </div>
  </div>
  <div class="col-xl-8 reveal-item" style="--d:70ms">
    <form method="post" class="card h-100" id="giForm">
      <?= csrf_field() ?>
      <input type="hidden" name="rows" id="giRows">
      <div class="card-header d-flex flex-wrap align-items-center gap-2"><strong><i class="bi bi-list-check"></i> 2. ตรวจสอบรายการ แล้วบันทึก</strong>
        <select name="month" id="giMonth" class="form-select form-select-sm w-auto ms-auto" aria-label="เดือน" onchange="if(!document.getElementById('giBody').dataset.dirty)location='guard_import?m='+this.value">
          <?php foreach ($months as $k => $lb): ?><option value="<?= $k ?>" <?= $k === $ym ? 'selected' : '' ?>><?= h($lb) ?></option><?php endforeach; ?>
        </select></div>
      <div class="card-body p-0">
        <div class="px-3 pt-2 small text-muted"><?= $saved ? '<i class="bi bi-check2-circle text-success"></i> บันทึกไว้แล้ว ' . count($saved) . ' รายการ — แก้ไขแล้วบันทึกซ้ำได้' : 'ยังไม่มีเวรศาลากลางตามคำสั่งจังหวัดของเดือนนี้' ?>
          · ปฏิทินแสดงเป็น "เวรศาลากลาง-กลางวัน / กลางคืน" แยกผู้ปฏิบัติหน้าที่เวร / ผู้ตรวจเวร</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 gi-table">
            <thead><tr><th style="width:6.5rem">วันที่</th><th style="width:7rem">ช่วง</th><th style="width:11rem">หน้าที่</th><th>บุคลากร</th><th style="width:2.5rem"></th></tr></thead>
            <tbody id="giBody"></tbody>
          </table>
        </div>
        <div class="p-3 pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" id="giAdd"><i class="bi bi-plus-lg"></i> เพิ่มรายการ</button></div>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2">
        <?php if ($saved): ?><button type="submit" name="action" value="clear" class="btn btn-outline-danger btn-sm" formnovalidate onclick="return confirm('ล้างเวรศาลากลางตามคำสั่งจังหวัดของเดือนนี้ทั้งหมด?')"><i class="bi bi-trash"></i> ล้างเดือนนี้</button><?php endif; ?>
        <button type="submit" name="action" value="save" class="btn btn-primary ms-auto"><i class="bi bi-check2-circle"></i> บันทึกและลงปฏิทิน</button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="giData"><?= json_encode(['staff' => $staff, 'rows' => $init, 'shifts' => GUARD_SHIFTS, 'roles' => GUARD_ROLES], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<style>
.gi-raw { max-height: 320px; overflow: auto; white-space: pre-wrap; font-size: .78rem; background: var(--bs-tertiary-bg); padding: .5rem; border-radius: .4rem; }
.gi-table tr.gi-warn td { background: var(--bs-warning-bg-subtle); }
.gi-table tr.gi-new td { animation: giIn .5s ease-out; }
@keyframes giIn { from { background: var(--bs-success-bg-subtle); } }
@media (prefers-reduced-motion: reduce) { .gi-table tr.gi-new td { animation: none; } }
</style>
<script src="<?= h(asset_v('modules/calendar/assets/js/guard-import.js')) ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
