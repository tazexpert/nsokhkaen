<?php
/**
 * รหัสงานในแผนการปฏิบัติงาน (ผู้ดูแลปฏิทิน): เพิ่ม / แก้ไขชื่อเต็ม สี ลำดับ / ปิดใช้งาน / ลบ (เฉพาะที่ยังไม่ถูกใช้)
 * รหัส "*" (เวรรักษาการณ์ศาลากลางฯ) แก้ชื่อ/สีได้ แต่ลบหรือเปลี่ยนรหัสไม่ได้
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/plan_lib.php';
$me = require_module(CAL_MODULE, 'admin');
$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $id = (int)post('id');
    $st = $pdo->prepare('SELECT * FROM cal_plan_codes WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch() ?: null;
    if ($action === 'save') {
        $code = trim(post('code'));
        $name = trim(post('name'));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', post('color')) ? strtolower(post('color')) : '#0d6efd';
        $order = (int)post('sort_order');
        if ($row && $row['kind'] === 'guard') {
            $code = '*';                             // รหัสเวรรักษาการณ์คงที่
        } elseif ($code === '' || mb_strlen($code) > 20 || preg_match('/[\s,+\/*]/u', $code) || $code === PLAN_DUTY_WORD) {
            $errors[] = 'รหัสต้องไม่ว่าง ไม่เกิน 20 ตัวอักษร ไม่มีช่องว่างหรือเครื่องหมาย , + / * และห้ามใช้คำว่า "' . PLAN_DUTY_WORD . '" (สงวนไว้สำหรับเวรสำนักงาน)';
        }
        if (!$errors) {
            $dup = $pdo->prepare('SELECT id FROM cal_plan_codes WHERE code = ? AND id <> ?');
            $dup->execute([$code, $id]);
            if ($dup->fetchColumn()) {
                $errors[] = 'มีรหัส "' . $code . '" อยู่แล้ว';
            }
        }
        if (!$errors) {
            $name = $name !== '' ? mb_substr($name, 0, 200) : $code;
            if ($row) {
                $pdo->prepare('UPDATE cal_plan_codes SET code = ?, name = ?, color = ?, sort_order = ?, is_active = ? WHERE id = ?')
                    ->execute([$code, $name, $color, $order, $row['kind'] === 'guard' ? 1 : (post('is_active') ? 1 : 0), $id]);
                flash('บันทึกรหัส "' . $code . '" แล้ว');
            } else {
                $pdo->prepare("INSERT INTO cal_plan_codes (code, name, kind, color, sort_order) VALUES (?, ?, 'field', ?, ?)")
                    ->execute([$code, $name, $color, $order ?: (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM cal_plan_codes WHERE kind = 'field'")->fetchColumn()]);
                flash('เพิ่มรหัส "' . $code . '" แล้ว');
            }
            audit_log('cal_plan_code', 'รหัสงาน ' . $code . ' — ' . $name, CAL_MODULE);
            redirect('plan_codes.php');
        }
    } elseif ($action === 'delete' && $row && $row['kind'] !== 'guard') {
        $n = $pdo->prepare('SELECT COUNT(*) FROM cal_plan_cells WHERE code_id = ?');
        $n->execute([$id]);
        if ($n->fetchColumn()) {
            flash('รหัส "' . $row['code'] . '" ถูกใช้ในแผนแล้ว ลบไม่ได้ — ปิดใช้งานแทน', 'warning');
        } else {
            $pdo->prepare('DELETE FROM cal_plan_codes WHERE id = ?')->execute([$id]);
            audit_log('cal_plan_code', 'ลบรหัสงาน ' . $row['code'], CAL_MODULE);
            flash('ลบรหัส "' . $row['code'] . '" แล้ว');
        }
        redirect('plan_codes.php');
    }
}

$codes = plan_codes();
$usage = array_column($pdo->query('SELECT code_id, COUNT(*) n FROM cal_plan_cells GROUP BY code_id')->fetchAll(), 'n', 'code_id');

$pageTitle = 'รหัสงานในแผนการปฏิบัติงาน';
$active = 'plan';
$pageActions = '<a href="plan.php" class="btn btn-light"><i class="bi bi-grid-3x3"></i> แผนการปฏิบัติงาน</a>';
require __DIR__ . '/header.php';
$rowForm = function (?array $c) use ($usage) {
    $guard = $c && $c['kind'] === 'guard';
    $fid = 'f' . ($c['id'] ?? 'new'); ?>
    <tr>
      <td><form method="post" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($c['id'] ?? 0) ?>"></form>
        <input name="code" form="<?= $fid ?>" class="form-control form-control-sm fw-semibold" style="width:90px" maxlength="20" value="<?= h($c['code'] ?? '') ?>"<?= $guard ? ' readonly' : ' required' ?> placeholder="รหัส"></td>
      <td><input name="name" form="<?= $fid ?>" class="form-control form-control-sm" maxlength="200" value="<?= h($c['name'] ?? '') ?>" placeholder="ชื่อเต็มโครงการ"></td>
      <td><input type="color" name="color" form="<?= $fid ?>" class="form-control form-control-sm form-control-color" value="<?= h($c['color'] ?? '#0d6efd') ?>" title="สี"></td>
      <td><input type="number" name="sort_order" form="<?= $fid ?>" class="form-control form-control-sm" style="width:80px" value="<?= (int)($c['sort_order'] ?? 0) ?>" title="ลำดับ"></td>
      <td class="text-center"><?php if ($c && !$guard): ?><input type="checkbox" name="is_active" value="1" form="<?= $fid ?>" class="form-check-input"<?= $c['is_active'] ? ' checked' : '' ?> title="เปิดใช้งาน"><?php elseif ($guard): ?><span class="badge text-bg-danger">เวรรักษาการณ์</span><?php endif; ?></td>
      <td class="text-center small text-muted"><?= $c ? number_format((int)($usage[$c['id']] ?? 0)) : '' ?></td>
      <td class="text-end text-nowrap">
        <button form="<?= $fid ?>" class="btn btn-sm btn-<?= $c ? 'outline-primary' : 'primary' ?>"><i class="bi <?= $c ? 'bi-save' : 'bi-plus-lg' ?>"></i> <?= $c ? 'บันทึก' : 'เพิ่ม' ?></button>
        <?php if ($c && !$guard && empty($usage[$c['id']])): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('ลบรหัส <?= h($c['code']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button></form>
        <?php endif; ?>
      </td>
    </tr>
<?php };
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<div class="card" style="max-width:980px">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>รหัส</th><th>ชื่อเต็ม</th><th>สี</th><th>ลำดับ</th><th class="text-center">ใช้งาน</th><th class="text-center">ใช้ในแผน (ช่อง)</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($codes as $c) { $rowForm($c); } ?>
        <tr class="table-light"><td colspan="7" class="small fw-semibold pt-3"><i class="bi bi-plus-circle"></i> เพิ่มรหัสโครงการใหม่</td></tr>
        <?php $rowForm(null); ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    รหัสที่ถูกใช้ในแผนแล้วลบไม่ได้ (ปิดใช้งานแทน — แผนเดิมยังแสดงรหัสนั้น) · คำว่า "เวร" สงวนไว้สำหรับเวรสำนักงาน (ดึงจากปฏิทิน) · "*" = เวรรักษาการณ์ศาลากลางฯ (ส่งเข้าปฏิทินและแจ้งเตือนผู้รับผิดชอบ)
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
