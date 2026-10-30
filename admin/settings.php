<?php
/**
 * ตั้งค่าระบบกลาง (ผู้ดูแลระบบกลาง)
 * - ล้างข้อมูลระบบ (reset) ใช้ตอนติดตั้งจริงหลังพัฒนา/ทดสอบเสร็จ — เลือกได้:
 *   · ล้างบันทึกการใช้งาน (core_audit_log): ทั้งหมด หรือเก็บไว้เฉพาะช่วงล่าสุด
 *   · ล้างการแจ้งเตือน: ซ่อนการแจ้งเตือนเดิมของทุกคน (core_settings.notif_reset_at) + ล้างตัวเลขที่กระดิ่ง
 *     เรื่องที่ยังรอดำเนินการ ('sticky' เช่น รออนุมัติ เกินกำหนดคืน วัสดุใกล้หมด) ยังแสดงจนกว่าจะดำเนินการ
 *   ยืนยันด้วย PIN ของตนเอง (ผู้ดูแลที่ยังไม่มี PIN ต้องตั้งที่บัญชีของฉันก่อน) · ดาวน์โหลด CSV เก็บไว้ก่อนได้ (?export=audit)
 */
require_once __DIR__ . '/../core/bootstrap.php';
require_superadmin();

$resetKeep = ['0' => 'ล้างทั้งหมด (เริ่มนับใหม่)', '30' => 'เก็บไว้ 30 วันล่าสุด', '90' => 'เก็บไว้ 90 วันล่าสุด', '365' => 'เก็บไว้ 1 ปีล่าสุด'];

// ดาวน์โหลดบันทึกการใช้งานทั้งหมดเป็น CSV (เปิดด้วย Excel ได้ภาษาไทย)
if (($_GET['export'] ?? '') === 'audit') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['id', 'เวลา', 'ชื่อผู้ใช้', 'ชื่อ-สกุล', 'ระบบ', 'การกระทำ', 'รายละเอียด', 'IP']);
    $st = db()->query('SELECT a.id, a.created_at, u.username, u.full_name, a.module_key, a.action, a.detail, a.ip
                       FROM core_audit_log a LEFT JOIN core_users u ON u.id = a.user_id ORDER BY a.id');
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

$errors = [];
$resetErrors = [];
$appName = APP_NAME;
$orgName = ORG_NAME;
$appUrl = APP_URL;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'reset_audit') {
    check_csrf();
    $keep = post('keep', '0');
    $me = current_user();
    $st = db()->prepare('SELECT pin_hash FROM core_users WHERE id = ?');
    $st->execute([$me['id']]);
    $hash = (string)$st->fetchColumn();
    $doAudit = (bool)post('do_audit');
    $doNotif = (bool)post('do_notif');
    if (!$doAudit && !$doNotif) {
        $resetErrors[] = 'กรุณาเลือกอย่างน้อย 1 รายการที่ต้องการล้าง';
    } elseif ($doAudit && !isset($resetKeep[$keep])) {
        $resetErrors[] = 'กรุณาเลือกขอบเขตการล้างบันทึกการใช้งาน';
    }
    $lock = $_SESSION['audit_reset_lock'] ?? ['n' => 0, 'until' => 0];   // ใส่ PIN ผิด 5 ครั้ง → พัก 15 นาที
    if ($lock['until'] > time()) {
        $resetErrors[] = 'ใส่ PIN ผิดหลายครั้ง กรุณารอ ' . ceil(($lock['until'] - time()) / 60) . ' นาทีแล้วลองใหม่';
    } elseif ($hash === '') {
        $resetErrors[] = 'บัญชีของคุณยังไม่ได้ตั้ง PIN — กรุณาตั้ง PIN ที่ "บัญชีของฉัน" ก่อน';
    } elseif (!preg_match('/^\d{6}$/', post('pin')) || !password_verify(post('pin'), $hash)) {
        $lock['n']++;
        if ($lock['n'] >= 5) {
            $lock = ['n' => 0, 'until' => time() + 900];
        }
        $_SESSION['audit_reset_lock'] = $lock;
        $resetErrors[] = 'PIN ไม่ถูกต้อง';
    } else {
        unset($_SESSION['audit_reset_lock']);
    }
    if (!$resetErrors) {
        $pdo = db();
        $done = [];
        if ($doAudit) {
            if ($keep === '0') {
                $n = (int)$pdo->query('SELECT COUNT(*) FROM core_audit_log')->fetchColumn();
                $pdo->exec('TRUNCATE TABLE core_audit_log');                  // ล้างทั้งหมด + เลขลำดับเริ่มใหม่
                if (post('reset_last_login')) {
                    $pdo->exec('UPDATE core_users SET last_login_at = NULL');   // สถิติ "เข้าระบบล่าสุด" เริ่มใหม่
                }
            } else {
                $st = $pdo->prepare('DELETE FROM core_audit_log WHERE created_at < ?');
                $st->execute([date('Y-m-d 00:00:00', strtotime('-' . ((int)$keep - 1) . ' days'))]);
                $n = $st->rowCount();
            }
            $done[] = 'บันทึกการใช้งาน (' . $resetKeep[$keep] . ') ' . number_format($n) . ' รายการ';
        }
        if ($doNotif) {
            $now = date('Y-m-d H:i:s');
            save_setting('notif_reset_at', $now);                            // ซ่อนการแจ้งเตือนก่อนเวลานี้
            $pdo->prepare('UPDATE core_users SET notif_seen_at = ?')->execute([$now]);   // ตัวเลขที่กระดิ่งเป็น 0
            $done[] = 'การแจ้งเตือนของผู้ใช้ทุกคน';
        }
        audit_log('audit_reset', 'ล้าง' . implode(' และ ', $done));
        flash('ล้าง' . implode(' และ ', $done) . ' เรียบร้อยแล้ว');
        redirect(url('admin/settings.php'));
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $appName = post('app_name');
    $orgName = post('org_name');
    $appUrl = post('app_url');
    if ($appName === '' || $orgName === '') {
        $errors[] = 'กรุณากรอกชื่อระบบและชื่อหน่วยงาน';
    }
    if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $appUrl)) {
        $errors[] = 'รูปแบบ URL ไม่ถูกต้อง (ต้องขึ้นต้นด้วย http:// หรือ https://)';
    }
    if (!$errors) {
        save_setting('app_name', $appName);
        save_setting('org_name', $orgName);
        save_setting('app_url', rtrim($appUrl, '/') . '/');
        audit_log('settings_update', 'app_url: ' . $appUrl);
        flash('บันทึกค่าระบบเรียบร้อยแล้ว');
        redirect(url('admin/settings.php'));
    }
}

$st = db()->prepare('SELECT pin_hash IS NOT NULL AND pin_hash <> \'\' FROM core_users WHERE id = ?');
$st->execute([current_user()['id']]);
$hasPin = (bool)$st->fetchColumn();
$auditInfo = db()->query('SELECT COUNT(*) n, MIN(created_at) first_at FROM core_audit_log')->fetch();

$pageTitle = 'ตั้งค่าระบบ';
$active = 'settings';
require APP_ROOT . '/core/layout/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<?php
// ข้อมูลสำหรับตรวจปัญหาการติดตั้ง (เช่น เพิ่มระบบใหม่แล้วไม่แสดง)
$opc = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$dirs = array_map('basename', glob(APP_ROOT . '/modules/*', GLOB_ONLYDIR) ?: []);
?>
<div class="row g-3 align-items-start">
  <div class="col-xl-6 d-flex flex-column gap-3">
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="card-body">
    <div class="mb-3"><label class="form-label">ชื่อระบบ</label><input name="app_name" class="form-control" required value="<?= h($appName) ?>"></div>
    <div class="mb-3"><label class="form-label">ชื่อหน่วยงาน</label><input name="org_name" class="form-control" required value="<?= h($orgName) ?>">
      <div class="form-text">แสดงในหน้าเข้าสู่ระบบ ท้ายหน้า และเอกสารที่พิมพ์</div></div>
    <div class="mb-3"><label class="form-label">URL ของระบบ (APP_URL)</label>
      <input name="app_url" class="form-control" required value="<?= h($appUrl) ?>" placeholder="http://192.168.1.50/nsokhkaen/">
      <div class="form-text">
        ใช้สร้างลิงก์ที่ต้องเปิดจาก<strong>อุปกรณ์อื่น</strong> เช่น QR Code สติ๊กเกอร์วัสดุ — ต้องเป็นที่อยู่ที่มือถือเข้าถึงได้จริง
        (ห้ามใช้ <code>localhost</code> ถ้าจะสแกนด้วยมือถือ) · ลิงก์ภายในหน้าเว็บไม่ขึ้นกับค่านี้ (ระบบตรวจ path ให้อัตโนมัติ: <code><?= h(BASE_URL) ?></code>)
      </div></div>
  </div>
  <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button></div>
</form>
<form method="post" class="card border-danger" id="resetAuditForm"
      onsubmit="return confirm('ยืนยันล้างข้อมูลที่เลือก? ข้อมูลที่ลบแล้วกู้คืนไม่ได้')">
  <?= csrf_field() ?><input type="hidden" name="action" value="reset_audit">
  <div class="card-header text-danger"><strong><i class="bi bi-arrow-counterclockwise"></i> ล้างข้อมูลระบบ (reset)</strong></div>
  <div class="card-body">
    <?php if ($resetErrors): ?><div class="alert alert-danger py-2"><?= implode('<br>', array_map('h', $resetErrors)) ?></div><?php endif; ?>
    <p class="small text-muted mb-3">ใช้ตอนเริ่มใช้งานจริงหลังพัฒนา/ทดสอบเสร็จ — เลือกสิ่งที่ต้องการล้าง ไม่กระทบผู้ใช้ สิทธิ์ และข้อมูลของระบบงานต่าง ๆ
      · ระบบจะบันทึกไว้ 1 รายการว่าใครล้างอะไร เมื่อไร</p>
    <?php $posted = $_SERVER['REQUEST_METHOD'] === 'POST'; ?>

    <div class="reset-opt">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="do_audit" value="1" id="doAudit"<?= !$posted || post('do_audit') ? ' checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="doAudit"><i class="bi bi-journal-text"></i> ล้างบันทึกการใช้งาน (log)</label>
      </div>
      <div class="small text-muted ms-4 mb-2">มี <?= number_format((int)$auditInfo['n']) ?> รายการ<?= $auditInfo['first_at'] ? ' ตั้งแต่ ' . h(thai_date($auditInfo['first_at'])) : '' ?>
        · <a href="<?= h(url('admin/settings.php?export=audit')) ?>"><i class="bi bi-download"></i> ดาวน์โหลดเก็บไว้ก่อน (CSV)</a></div>
      <div class="ms-4" id="auditOpts">
        <select name="keep" id="resetKeep" class="form-select form-select-sm" style="max-width:320px" aria-label="ขอบเขต">
          <?php foreach ($resetKeep as $k => $label): ?><option value="<?= $k ?>"<?= post('keep', '0') === (string)$k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
        </select>
        <div class="form-check mt-2" id="resetLastLoginWrap">
          <input class="form-check-input" type="checkbox" name="reset_last_login" value="1" id="resetLastLogin"<?= !$posted || post('reset_last_login') ? ' checked' : '' ?>>
          <label class="form-check-label small" for="resetLastLogin">ล้าง "เวลาเข้าระบบล่าสุด" ของผู้ใช้ทุกคนด้วย (สถิติเริ่มนับใหม่)</label>
        </div>
      </div>
    </div>

    <div class="reset-opt">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="do_notif" value="1" id="doNotif"<?= !$posted || post('do_notif') ? ' checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="doNotif"><i class="bi bi-bell-slash"></i> ล้างการแจ้งเตือน</label>
      </div>
      <div class="small text-muted ms-4">ซ่อนการแจ้งเตือนเดิมของผู้ใช้ทุกคน และล้างตัวเลขที่กระดิ่ง
        — เรื่องที่ยังรอดำเนินการ (รออนุมัติ เกินกำหนดคืน วัสดุใกล้หมด กิจกรรมวันนี้/พรุ่งนี้) ยังแสดงจนกว่าจะดำเนินการเสร็จ
        <?php if ($nr = setting('notif_reset_at')): ?><br>ล้างครั้งล่าสุด: <?= h(thai_date($nr)) ?> <?= h(substr($nr, 11, 5)) ?> น.<?php endif; ?></div>
    </div>

    <div class="mt-3" style="max-width:320px">
      <label class="form-label" for="resetPin">PIN ของคุณ (6 หลัก) เพื่อยืนยัน</label>
      <?php if ($hasPin): ?>
        <input name="pin" id="resetPin" type="password" class="form-control font-monospace" inputmode="numeric" pattern="\d{6}" maxlength="6"
               autocomplete="off" required placeholder="ใส่ PIN 6 หลัก">
      <?php else: ?>
        <div class="alert alert-warning py-2 small mb-0">ยังไม่ได้ตั้ง PIN — <a href="<?= h(url('account.php?setup_pin=1')) ?>">ตั้ง PIN ที่บัญชีของฉัน</a> ก่อนจึงจะล้างข้อมูลได้</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-footer"><button class="btn btn-danger" id="resetBtn"<?= $hasPin ? '' : ' disabled' ?>><i class="bi bi-arrow-counterclockwise"></i> ล้างข้อมูลที่เลือก</button></div>
</form>
<style>
  .reset-opt { padding: .75rem 0; border-top: 1px solid var(--bs-border-color-translucent); }
</style>
<script>
  // ตัวเลือกย่อยของการล้าง log · ปุ่มล้างใช้ได้เมื่อเลือกอย่างน้อย 1 รายการ
  (() => {
    const sel = document.getElementById('resetKeep'), wrap = document.getElementById('resetLastLoginWrap');
    const doAudit = document.getElementById('doAudit'), doNotif = document.getElementById('doNotif');
    const opts = document.getElementById('auditOpts'), btn = document.getElementById('resetBtn');
    const hasPin = <?= $hasPin ? 'true' : 'false' ?>;
    const sync = () => {
      wrap.classList.toggle('d-none', sel.value !== '0');
      opts.classList.toggle('d-none', !doAudit.checked);
      btn.disabled = !hasPin || (!doAudit.checked && !doNotif.checked);
    };
    [sel, doAudit, doNotif].forEach(el => el.addEventListener('change', sync)); sync();
  })();
</script>
  </div>
  <div class="col-xl-6 d-flex flex-column gap-3">
<div class="card">
  <div class="card-header"><strong><i class="bi bi-grid"></i> ระบบที่ติดตั้ง</strong></div>
  <ul class="list-group list-group-flush">
    <?php foreach (modules() as $m): ?>
      <li class="list-group-item">
        <div class="d-flex"><strong><?= module_logo($m, 22) ?> <?= h($m['name']) ?></strong>
          <code class="ms-auto small">modules/<?= h($m['key']) ?>/</code></div>
        <div class="small text-muted">บทบาท: <?= h(implode(' · ', $m['roles'])) ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php
  // โฟลเดอร์ระบบที่อ่านไม่ได้ (ไม่มี module.php หรือไฟล์ไม่ถูกต้อง) — ช่วยตรวจเมื่ออัปเดตโค้ดไม่ครบ
  $broken = array_filter(glob(APP_ROOT . '/modules/*', GLOB_ONLYDIR) ?: [], fn($d) => !isset(modules()[basename($d)]));
  // เวอร์ชันโค้ด (commit ของ git) — ใช้ตรวจว่าเซิร์ฟเวอร์อัปเดตโค้ดล่าสุดแล้วหรือยัง
  $ver = null;
  $head = @file_get_contents(APP_ROOT . '/.git/HEAD');
  if ($head !== false) {
      $head = trim($head);
      if (str_starts_with($head, 'ref: ')) {
          $ref = substr($head, 5);
          $ver = @file_get_contents(APP_ROOT . '/.git/' . $ref);
          if ($ver === false && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', (string)@file_get_contents(APP_ROOT . '/.git/packed-refs'), $mm)) {
              $ver = $mm[1];
          }
      } else {
          $ver = $head;
      }
  }
  ?>
  <?php foreach ($broken as $d): ?>
    <div class="list-group-item list-group-item-warning small"><i class="bi bi-exclamation-triangle"></i> โฟลเดอร์ <code>modules/<?= h(basename($d)) ?>/</code>
      ไม่มีไฟล์ module.php หรือไฟล์ไม่ถูกต้อง — ระบบนี้จึงไม่แสดง (ตรวจว่าอัปโหลดไฟล์ครบ)</div>
  <?php endforeach; ?>
  <div class="card-footer small text-muted d-flex flex-wrap gap-2">
    <span>เพิ่มระบบใหม่: สร้างโฟลเดอร์ใน <code>modules/</code> พร้อมไฟล์ <code>module.php</code> (ดู docs/ARCHITECTURE.md)</span>
    <span class="ms-auto">เวอร์ชันโค้ด: <code><?= $ver ? h(substr(trim($ver), 0, 7)) : 'ไม่ทราบ (ไม่ได้ติดตั้งด้วย git)' ?></code></span>
  </div>
</div>
<div class="card">
  <div class="card-header"><strong><i class="bi bi-wrench"></i> ข้อมูลตรวจสอบการติดตั้ง</strong></div>
  <div class="card-body small">
    <dl class="row mb-0">
      <dt class="col-sm-4">โฟลเดอร์โปรแกรม</dt><dd class="col-sm-8"><code><?= h(APP_ROOT) ?></code></dd>
      <dt class="col-sm-4">โฟลเดอร์ใน modules/</dt><dd class="col-sm-8"><code><?= h(implode(', ', $dirs) ?: '-') ?></code></dd>
      <dt class="col-sm-4">ระบบที่โหลดได้</dt><dd class="col-sm-8"><code><?= h(implode(', ', array_keys(modules()))) ?></code></dd>
      <dt class="col-sm-4">PHP</dt><dd class="col-sm-8"><?= h(PHP_VERSION) ?> · <?= h(PHP_OS_FAMILY) ?></dd>
      <dt class="col-sm-4">OPcache</dt><dd class="col-sm-8"><?= $opc && !empty($opc['opcache_enabled'])
          ? 'เปิด · ตรวจไฟล์ใหม่ ' . (ini_get('opcache.validate_timestamps') ? 'ทุก ' . (int)ini_get('opcache.revalidate_freq') . ' วินาที' : '<strong class="text-danger">ปิด — ต้องรีสตาร์ต Apache หลังอัปเดตโค้ด</strong>')
          : 'ปิด' ?></dd>
    </dl>
  </div>
</div>
  </div>
</div>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
