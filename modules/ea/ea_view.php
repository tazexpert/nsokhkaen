<?php
/** รายละเอียด EA + โครงการ/รอบที่ใช้ EA นี้ · ผู้ดูแลระบบ EA แก้ไขข้อมูลเบื้องต้นได้ */
require_once __DIR__ . '/bootstrap.php';
require_module(EA_MODULE, 'viewer');
$pdo = db();
$id = int_param('id');
$st = $pdo->prepare('SELECT * FROM ea_areas WHERE id = ?');
$st->execute([$id]);
$ea = $st->fetch();
if (!$ea) {
    http_response_code(404);
    flash('ไม่พบ EA', 'warning');
    redirect('eas.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    check_csrf();
    ea_require('admin');
    $num = fn(string $k) => max(0, (int)str_replace(',', '', post($k)));
    $vcode = mb_substr(trim(post('village_code')), 0, 3);
    $lcode = preg_match('/^\d{1,3}$/', trim(post('local_code'))) ? str_pad(trim(post('local_code')), 3, '0', STR_PAD_LEFT) : '';
    // รหัส EA 15 หลักเปลี่ยนตามรหัสเทศบาล/อบต.
    $newCode = ea_make_code($ea['region_code'], $ea['province_code'], $ea['district_code'], $ea['tambon_code'], $ea['area_type'], $lcode, $ea['ea_no']);
    $dup = $pdo->prepare('SELECT COUNT(*) FROM ea_areas WHERE ea_code = ? AND id <> ?');
    $dup->execute([$newCode, $id]);
    if ($dup->fetchColumn()) {
        flash('รหัส EA ' . $newCode . ' ซ้ำกับ EA อื่น — ตรวจรหัสเทศบาล/อบต.', 'danger');
        redirect('ea_view.php?id=' . $id . '&edit=1');
    }
    $pdo->prepare('UPDATE ea_areas SET ea_code = ?, local_code = ?, local_name = ?, village_code = ?, village_name = ?, advance_ea = ?, houses = ?, households = ?, vacant_houses = ?, population = ?, note = ?, is_active = ? WHERE id = ?')
        ->execute([$newCode, $lcode, mb_substr(trim(post('local_name')), 0, 150), $vcode, mb_substr(trim(post('village_name')), 0, 150), mb_substr(trim(post('advance_ea')), 0, 20),
                   $num('houses'), $num('households'), $num('vacant_houses'), $num('population'), mb_substr(trim(post('note')), 0, 2000), post('is_active') === '1' ? 1 : 0, $id]);
    audit_log('ea_edit', 'แก้ไขข้อมูล EA ' . $ea['ea_code'], EA_MODULE);
    flash('บันทึกข้อมูล EA ' . $ea['ea_code'] . ' แล้ว');
    redirect('ea_view.php?id=' . $id);
}

$st = $pdo->prepare('SELECT pe.*, p.code, p.name, p.color, u.full_name AS assignee FROM ea_project_eas pe
                     JOIN core_projects p ON p.id = pe.project_id LEFT JOIN core_users u ON u.id = pe.assigned_user_id
                     WHERE pe.ea_id = ? ORDER BY p.sort_order, p.id, ' . EA_ROUND_ORDER . '');
$st->execute([$id]);
$uses = $st->fetchAll();
$edit = ea_can('admin') && isset($_GET['edit']);

$pageTitle = 'EA ' . $ea['ea_code'];
$active = 'eas';
$pageActions = '<a href="eas.php?district=' . h($ea['district_code']) . '&amp;tambon=' . h($ea['tambon_code']) . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> EA ในตำบลเดียวกัน</a>'
    . (ea_can('admin') && !$edit ? ' <a href="?id=' . $id . '&amp;edit=1" class="btn btn-primary"><i class="bi bi-pencil"></i> แก้ไข</a>' : '');
require __DIR__ . '/header.php';
$c = $ea['ea_code'];
?>
<div class="row g-3" data-reveal>
  <div class="col-lg-6 reveal-item">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center">
        <strong class="ea-code fs-5"><?= h($c) ?></strong>
        <span class="ea-code small text-muted ms-2" title="ภาค-จังหวัด-อำเภอ-ตำบล-เขตการปกครอง-เทศบาล/อบต.-EA"><?php
          $parts = []; $pos = 0; foreach ([1, 2, 2, 2, 1, 3, 4] as $len) { $parts[] = substr($c, $pos, $len); $pos += $len; }
          echo h(implode('-', $parts)); ?></span>
        <?php if (!$ea['is_active']): ?><span class="ms-2 text-muted">(เลิกใช้)</span><?php endif; ?>
      </div>
      <?php if ($edit): ?>
      <form method="post" class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save">
        <p class="small text-muted"><?= h(ea_place($ea)) ?> · <?= h(EA_AREA_TYPES[(int)$ea['area_type']] ?? '') ?> — รหัสและพื้นที่แก้ได้โดยนำเข้าบัญชีใหม่</p>
        <div class="row g-2">
          <div class="col-3"><label class="form-label small">รหัสเทศบาล/อบต.</label><input name="local_code" class="form-control" maxlength="3" pattern="\d{1,3}" value="<?= h($ea['local_code']) ?>"></div>
          <div class="col-9"><label class="form-label small">เทศบาล / อบต.</label><input name="local_name" class="form-control" maxlength="150" value="<?= h($ea['local_name']) ?>"></div>
          <div class="col-3"><label class="form-label small">รหัสหมู่บ้าน</label><input name="village_code" class="form-control" maxlength="3" value="<?= h($ea['village_code']) ?>"></div>
          <div class="col-9"><label class="form-label small">หมู่บ้าน/ชุมชน</label><input name="village_name" class="form-control" maxlength="150" value="<?= h($ea['village_name']) ?>"></div>
          <div class="col-md-3 col-6"><label class="form-label small">EA เสนอผลล่วงหน้า</label><input name="advance_ea" class="form-control" maxlength="20" value="<?= h($ea['advance_ea']) ?>"></div>
          <div class="col-md-3 col-6"><label class="form-label small">จำนวนบ้าน</label><input name="houses" type="number" min="0" class="form-control" value="<?= (int)$ea['houses'] ?>"></div>
          <div class="col-md-3 col-6"><label class="form-label small">บ้านว่าง</label><input name="vacant_houses" type="number" min="0" class="form-control" value="<?= (int)$ea['vacant_houses'] ?>"></div>
          <div class="col-md-3 col-6"><label class="form-label small">ครัวเรือน</label><input name="households" type="number" min="0" class="form-control" value="<?= (int)$ea['households'] ?>"></div>
          <div class="col-md-3 col-6"><label class="form-label small">ประชากร</label><input name="population" type="number" min="0" class="form-control" value="<?= (int)$ea['population'] ?>"></div>
          <div class="col-12"><label class="form-label small">หมายเหตุ</label><textarea name="note" class="form-control" rows="3" maxlength="2000"><?= h($ea['note']) ?></textarea></div>
          <div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="act" <?= $ea['is_active'] ? 'checked' : '' ?>>
            <label for="act" class="form-check-label">ใช้งาน (ไม่เลือก = เลิกใช้ ไม่แสดงในการเพิ่ม EA ให้โครงการ)</label></div></div>
        </div>
        <div class="mt-3 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button><a href="?id=<?= $id ?>" class="btn btn-light">ยกเลิก</a></div>
      </form>
      <?php else: ?>
      <div class="card-body">
        <dl class="row mb-0 ea-dl">
          <dt class="col-5">ภาค</dt><dd class="col-7"><?= h($ea['region_code'] . ' ' . $ea['region_name']) ?></dd>
          <dt class="col-5">จังหวัด</dt><dd class="col-7"><?= h($ea['province_code'] . ' ' . $ea['province_name']) ?></dd>
          <dt class="col-5">อำเภอ/เขต</dt><dd class="col-7"><?= h($ea['district_code'] . ' ' . $ea['district_name']) ?></dd>
          <dt class="col-5">ตำบล/แขวง</dt><dd class="col-7"><?= h($ea['tambon_code'] . ' ' . $ea['tambon_name']) ?></dd>
          <dt class="col-5">เขตการปกครอง</dt><dd class="col-7"><?= (int)$ea['area_type'] ?> <?= h(EA_AREA_TYPES[(int)$ea['area_type']] ?? '') ?></dd>
          <dt class="col-5">เทศบาล / อบต.</dt><dd class="col-7"><?= h(trim($ea['local_code'] . ' ' . $ea['local_name']) ?: '-') ?></dd>
          <dt class="col-5">EA</dt><dd class="col-7 ea-code"><?= h($ea['ea_no']) ?></dd>
          <?php if ($ea['advance_ea'] !== ''): ?><dt class="col-5">EA สำหรับเสนอผลล่วงหน้า</dt><dd class="col-7"><?= h($ea['advance_ea']) ?></dd><?php endif; ?>
          <dt class="col-5">หมู่บ้าน/ชุมชน</dt><dd class="col-7"><?= $ea['village_name'] !== '' ? h(($ea['village_code'] !== '' ? $ea['village_code'] . ' ' : '') . $ea['village_name']) : '-' ?></dd>
          <dt class="col-5">จำนวนบ้าน</dt><dd class="col-7"><?= number_format((int)$ea['houses']) ?></dd>
          <dt class="col-5">จำนวนครัวเรือน</dt><dd class="col-7"><?= number_format((int)$ea['households']) ?></dd>
          <dt class="col-5">บ้านว่าง</dt><dd class="col-7"><?= number_format((int)$ea['vacant_houses']) ?></dd>
          <dt class="col-5">จำนวนประชากร</dt><dd class="col-7"><?= number_format((int)$ea['population']) ?> คน</dd>
          <dt class="col-5">หมายเหตุ</dt><dd class="col-7"><?= nl2br(h($ea['note'] ?: '-')) ?></dd>
          <dt class="col-5">ที่มาของบัญชี</dt><dd class="col-7 small"><?= h($ea['source'] ?: '-') ?></dd>
        </dl>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6 reveal-item">
    <div class="card h-100">
      <div class="card-header"><strong><i class="bi bi-diagram-3"></i> โครงการที่ใช้ EA นี้สำรวจ</strong></div>
      <div class="table-responsive">
        <table class="table mb-0 ea-table">
          <thead><tr><th>โครงการ</th><th>รอบ/งวด</th><th>PSU</th><th>ผู้รับผิดชอบ</th><th>สถานะ</th></tr></thead>
          <tbody>
          <?php foreach ($uses as $u): ?>
            <tr>
              <td><a href="project_eas.php?id=<?= (int)$u['project_id'] ?>&amp;round=<?= urlencode($u['round_label']) ?>" class="text-decoration-none">
                <span class="badge" style="background:<?= h($u['color']) ?>"><?= h($u['code']) ?></span> <?= h($u['name']) ?></a></td>
              <td class="text-nowrap"><?= h($u['round_label'] ?: '-') ?><?= $u['enum_type'] !== '' ? ' <span class="small text-muted">' . h($u['enum_type']) . '</span>' : '' ?></td>
              <td class="ea-code small"><?= h($u['psu_no'] ?: '-') ?></td>
              <td><?= h($u['assignee'] ?: '-') ?></td>
              <td><?= ea_status_badge($u['status']) ?><?= $u['note'] !== '' ? '<div class="small text-muted">' . h($u['note']) . '</div>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$uses): ?><tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีโครงการใช้ EA นี้</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
