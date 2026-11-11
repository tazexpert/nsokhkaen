<?php
/**
 * รายชื่อการจัดเวรกิจกรรม (ผู้ดูแลปฏิทิน): ลำดับข้าราชการ และสมาชิกกลุ่มกิจกรรม — พิมพ์ 1 ชื่อต่อบรรทัด
 *   จับคู่บัญชีจากชื่อ-สกุลอัตโนมัติ (ไม่มีบัญชี = ชื่ออย่างเดียว ไม่ได้รับแจ้งเตือน)
 *   เปลี่ยนรายชื่อไม่กระทบเวรที่จัดแล้ว · คิวคำนวณจากรายชื่อปัจจุบัน
 *   คิวล่าสุด (ก่อนใช้ระบบ): ข้าราชการคนใดและกลุ่มใดได้คิวไปแล้วล่าสุด → คิวเริ่มที่ลำดับถัดไป
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/rota_lib.php';
$me = require_module(CAL_MODULE, 'admin');
$pdo = db();

$lines = fn($v) => array_values(array_filter(array_map(fn($s) => trim(preg_replace('/\s+/u', ' ', $s)), preg_split('/\R/u', (string)$v)), 'strlen'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $rows = [];
    foreach ($lines(post('officials')) as $i => $n) {
        $rows[] = ['official', 0, $n, $i + 1];
    }
    $g = 0;
    foreach ((array)($_POST['groups'] ?? []) as $text) {
        $names = $lines($text);
        if ($names) {
            $g++;                                          // เลขกลุ่มเรียงใหม่ 1, 2, 3 … ตามลำดับช่อง
            foreach ($names as $i => $n) {
                $rows[] = ['group', $g, $n, $i + 1];
            }
        }
    }
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM cal_rota_members');
    $ins = $pdo->prepare('INSERT INTO cal_rota_members (kind, group_no, user_id, name, sort_order) VALUES (?, ?, ?, ?, ?)');
    foreach ($rows as [$kind, $gno, $name, $sort]) {
        $ins->execute([$kind, $gno, rota_find_user($name), mb_substr($name, 0, 150), $sort]);
    }
    $pdo->commit();
    $lastO = trim((string)post('last_official'));
    $lastG = (int)post('last_group');
    save_setting('cal_rota_last_official', in_array($lastO, array_column($rows, 2), true) ? $lastO : '');
    save_setting('cal_rota_last_group', $lastG >= 1 && $lastG <= $g ? (string)$lastG : '');
    audit_log('cal_rota_members', count($rows) . ' รายชื่อ · ' . $g . ' กลุ่ม', CAL_MODULE);
    flash('บันทึกรายชื่อและคิวล่าสุดของเวรกิจกรรมแล้ว');
    redirect('rota_members');
}

$members = rota_members();
$groups = $members['groups'] + [];
$active = 'rota';
$pageTitle = 'รายชื่อเวรกิจกรรม';
$pageActions = '<a href="rota" class="btn btn-light"><i class="bi bi-arrow-left"></i> เวรกิจกรรม</a>';
require __DIR__ . '/header.php';
$text = fn(array $list) => implode("\n", array_column($list, 'name'));
$status = function (array $list) {
    $out = '';
    foreach ($list as $m) {
        $out .= '<span class="rota-chip">' . ($m['user_id'] ? '<i class="bi bi-check-circle-fill text-success"></i> ' : '<i class="bi bi-person-dash text-warning" title="ไม่มีบัญชีในระบบ"></i> ') . h($m['name']) . '</span>';
    }
    return $out;
};
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="alert alert-info small"><i class="bi bi-info-circle"></i> พิมพ์ 1 ชื่อต่อบรรทัด ตามลำดับคิว · ระบบจับคู่บัญชีจากชื่อ-สกุลให้อัตโนมัติ
    (<i class="bi bi-check-circle-fill text-success"></i> มีบัญชี ได้รับแจ้งเตือน · <i class="bi bi-person-dash text-warning"></i> ไม่มีบัญชี แสดงชื่อในกิจกรรมอย่างเดียว)
    · ล้างทุกชื่อในช่องกลุ่ม = เอากลุ่มนั้นออก · เวรที่จัดแล้วไม่เปลี่ยน</div>
  <?php $lastO = (string)setting('cal_rota_last_official', ''); $lastG = (int)setting('cal_rota_last_group', '0');
        $state = rota_state($members); ?>
  <div class="card mb-3">
    <div class="card-header"><strong><i class="bi bi-skip-forward"></i> คิวล่าสุด</strong>
      <span class="small text-muted ms-2">ข้าราชการคนใดและกลุ่มใดได้คิวไปแล้วล่าสุด (ก่อนเริ่มใช้ระบบ) — คิวจะเริ่มที่ลำดับถัดไป</span></div>
    <div class="card-body row g-3 align-items-end">
      <div class="col-md-5"><label class="form-label">ข้าราชการคนล่าสุดที่ได้คิวแล้ว</label>
        <select name="last_official" class="form-select">
          <option value="">— ยังไม่มี (เริ่มลำดับที่ 1) —</option>
          <?php foreach ($members['officials'] as $i => $m): ?><option value="<?= h($m['name']) ?>" <?= rota_key(null, $m['name']) === rota_key(null, $lastO) ? 'selected' : '' ?>><?= $i + 1 ?>. <?= h($m['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label">กลุ่มล่าสุดที่ได้คิวแล้ว</label>
        <select name="last_group" class="form-select">
          <option value="">— ยังไม่มี (เริ่มกลุ่มที่ 1) —</option>
          <?php foreach (array_keys($members['groups']) as $g): ?><option value="<?= (int)$g ?>" <?= $lastG === (int)$g ? 'selected' : '' ?>>กลุ่มที่ <?= (int)$g ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4 small">ถึงคิวตอนนี้: <strong><?= h($state['officials'][0]['name'] ?? '-') ?></strong>
        · <strong><?= isset($state['groups'][0]) ? 'กลุ่มที่ ' . (int)$state['groups'][0] : '-' ?></strong>
        <div class="text-muted">(รวมเวรที่จัดในระบบแล้ว)</div></div>
    </div>
  </div>
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card h-100"><div class="card-header"><strong>ลำดับการเข้าร่วมกิจกรรมข้าราชการ</strong></div>
        <div class="card-body"><textarea name="officials" class="form-control" rows="9"><?= h($text($members['officials'])) ?></textarea>
          <div class="mt-2 small"><?= $status($members['officials']) ?></div></div></div>
    </div>
    <?php $n = 0; foreach ($groups as $g => $list): $n++; ?>
    <div class="col-md-6 col-lg-4">
      <div class="card h-100"><div class="card-header"><strong>กลุ่มกิจกรรมที่ <?= $n ?></strong></div>
        <div class="card-body"><textarea name="groups[]" class="form-control" rows="7"><?= h($text($list)) ?></textarea>
          <div class="mt-2 small"><?= $status($list) ?></div></div></div>
    </div>
    <?php endforeach; ?>
    <div class="col-md-6 col-lg-4">
      <div class="card h-100 border-dashed"><div class="card-header"><strong class="text-muted">เพิ่มกลุ่มใหม่ (กลุ่มที่ <?= $n + 1 ?>)</strong></div>
        <div class="card-body"><textarea name="groups[]" class="form-control" rows="7" placeholder="พิมพ์รายชื่อสมาชิก 1 ชื่อต่อบรรทัด"></textarea></div></div>
    </div>
  </div>
  <div class="sticky-actions mt-3"><button class="btn btn-primary"><i class="bi bi-save"></i> บันทึกรายชื่อ</button>
    <a href="rota" class="btn btn-light">ยกเลิก</a></div>
</form>
<style>.rota-chip{display:inline-block;border:1px solid var(--bs-border-color);border-radius:1rem;padding:0 .55rem;margin:0 .25rem .25rem 0}.border-dashed{border-style:dashed!important}</style>
<?php require __DIR__ . '/footer.php'; ?>
