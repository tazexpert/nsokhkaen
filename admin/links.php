<?php
/**
 * ลิงก์และเครื่องมือ (ผู้ดูแลระบบกลาง): เพิ่ม / แก้ไข / ลบ / เรียงลำดับ / ซ่อนลิงก์ที่แสดงในหน้าแรก (core_links)
 * - "เฉพาะผู้ใช้ในระบบ" = ผู้เยี่ยมชม (ไม่ได้ login) ไม่เห็นลิงก์นั้น
 */
require_once __DIR__ . '/../core/bootstrap.php';
require_superadmin();
$pdo = db();

$findLink = function (int $id) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT * FROM core_links WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};
$errors = [];
$editId = int_param('edit');
$form = ['label' => '', 'url' => 'https://', 'icon' => 'bi-link-45deg', 'members_only' => 0, 'is_active' => 1];
if ($editId && ($row = $findLink($editId))) {
    $form = $row;
} else {
    $editId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $id = (int)post('id');
    if ($action === 'save') {
        $form = ['label' => trim(post('label')), 'url' => trim(post('url')), 'icon' => post('icon'),
                 'members_only' => post('members_only') ? 1 : 0, 'is_active' => post('is_active') ? 1 : 0];
        if ($form['label'] === '') {
            $errors[] = 'กรุณาระบุชื่อลิงก์';
        } elseif (mb_strlen($form['label']) > 150) {
            $errors[] = 'ชื่อลิงก์ยาวเกิน 150 ตัวอักษร';
        }
        if ($e = link_url_error($form['url'])) {
            $errors[] = $e;
        } elseif (strlen($form['url']) > 500) {
            $errors[] = 'ลิงก์ยาวเกิน 500 ตัวอักษร';
        }
        if (!isset(LINK_ICONS[$form['icon']])) {
            $form['icon'] = 'bi-link-45deg';
        }
        $editId = $id && $findLink($id) ? $id : 0;
        if (!$errors) {
            if ($editId) {
                $pdo->prepare('UPDATE core_links SET label = ?, url = ?, icon = ?, members_only = ?, is_active = ? WHERE id = ?')
                    ->execute([$form['label'], $form['url'], $form['icon'], $form['members_only'], $form['is_active'], $editId]);
                audit_log('link_update', $form['label'] . ' → ' . $form['url']);
                flash('แก้ไขลิงก์ "' . $form['label'] . '" แล้ว');
            } else {
                $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM core_links')->fetchColumn();
                $pdo->prepare('INSERT INTO core_links (label, url, icon, sort_order, members_only, is_active) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$form['label'], $form['url'], $form['icon'], $next, $form['members_only'], $form['is_active']]);
                audit_log('link_create', $form['label'] . ' → ' . $form['url']);
                flash('เพิ่มลิงก์ "' . $form['label'] . '" แล้ว');
            }
            redirect(url('admin/links'));
        }
    } elseif ($action === 'delete' && ($row = $findLink($id))) {
        $pdo->prepare('DELETE FROM core_links WHERE id = ?')->execute([$id]);
        audit_log('link_delete', $row['label'] . ' → ' . $row['url']);
        flash('ลบลิงก์ "' . $row['label'] . '" แล้ว');
        redirect(url('admin/links'));
    } elseif ($action === 'toggle' && ($row = $findLink($id))) {
        $pdo->prepare('UPDATE core_links SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        audit_log('link_update', ($row['is_active'] ? 'ซ่อน ' : 'แสดง ') . $row['label']);
        redirect(url('admin/links'));
    } elseif (in_array($action, ['up', 'down'], true) && $findLink($id)) {
        // สลับลำดับกับรายการข้างเคียง แล้วจัดเลขลำดับใหม่ (10, 20, 30, …)
        $ids = $pdo->query('SELECT id FROM core_links ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
        $i = array_search($id, array_map('intval', $ids), true);
        $j = $action === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            $up = $pdo->prepare('UPDATE core_links SET sort_order = ? WHERE id = ?');
            foreach (array_values($ids) as $k => $lid) {
                $up->execute([($k + 1) * 10, $lid]);
            }
        }
        redirect(url('admin/links'));
    }
}

$links = $pdo->query('SELECT * FROM core_links ORDER BY sort_order, id')->fetchAll();

$pageTitle = 'ลิงก์และเครื่องมือ';
$active = 'links';
$pageActions = '<a href="' . h(url('')) . '" class="btn btn-outline-secondary"><i class="bi bi-house"></i> ดูหน้าแรก</a>';
require APP_ROOT . '/core/layout/header.php';
?>
<div class="row g-3 align-items-start">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header d-flex align-items-center">
        <strong><i class="bi bi-globe2"></i> ลิงก์ที่เกี่ยวข้องในหน้าแรก</strong>
        <span class="ms-auto small text-muted"><?= count($links) ?> ลิงก์</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th style="width:84px" class="text-center">ลำดับ</th><th>ชื่อลิงก์</th><th>การแสดง</th><th class="text-end" style="width:150px"></th></tr></thead>
          <tbody>
          <?php foreach ($links as $i => $l): ?>
            <tr class="<?= $l['is_active'] ? '' : 'link-off' ?><?= (int)$l['id'] === $editId ? ' table-primary' : '' ?>">
              <td class="text-center text-nowrap">
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                  <button name="action" value="up" class="btn btn-sm btn-link px-1"<?= $i === 0 ? ' disabled' : '' ?> aria-label="เลื่อนขึ้น"><i class="bi bi-arrow-up"></i></button><button
                          name="action" value="down" class="btn btn-sm btn-link px-1"<?= $i === count($links) - 1 ? ' disabled' : '' ?> aria-label="เลื่อนลง"><i class="bi bi-arrow-down"></i></button>
                </form>
              </td>
              <td class="min-w-0">
                <div class="fw-semibold"><i class="bi <?= h($l['icon']) ?> me-1"></i><?= h($l['label']) ?></div>
                <a href="<?= h(link_href($l['url'])) ?>" target="_blank" rel="noopener" class="small text-break"><?= h($l['url']) ?></a>
              </td>
              <td class="small text-nowrap">
                <?php if (!$l['is_active']): ?><span class="badge text-bg-secondary">ซ่อน</span>
                <?php elseif ($l['members_only']): ?><span class="badge text-bg-info"><i class="bi bi-person-lock"></i> เฉพาะผู้ใช้ในระบบ</span>
                <?php else: ?><span class="badge text-bg-success"><i class="bi bi-people"></i> ทุกคน</span><?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a href="<?= h(url('admin/links?edit=' . (int)$l['id'])) ?>" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                  <button name="action" value="toggle" class="btn btn-sm btn-outline-secondary" data-bs-toggle="tooltip" title="<?= $l['is_active'] ? 'ซ่อนจากหน้าแรก' : 'แสดงในหน้าแรก' ?>"><i class="bi <?= $l['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm(<?= js_attr('ลบลิงก์ "' . $l['label'] . '"?') ?>)"><?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                  <button name="action" value="delete" class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" title="ลบ"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$links): ?><tr><td colspan="4" class="text-center text-muted py-5">ยังไม่มีลิงก์ — เพิ่มลิงก์ได้จากแบบฟอร์มด้านขวา</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted"><i class="bi bi-info-circle"></i> ลิงก์ทั้งหมดเปิดในแท็บใหม่ · "เฉพาะผู้ใช้ในระบบ" = ผู้เยี่ยมชมที่ไม่ได้เข้าสู่ระบบจะไม่เห็น</div>
    </div>
  </div>

  <div class="col-xl-4">
    <form method="post" class="card" id="linkForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
      <div class="card-header"><strong><i class="bi <?= $editId ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i> <?= $editId ? 'แก้ไขลิงก์' : 'เพิ่มลิงก์' ?></strong></div>
      <div class="card-body">
        <?php if ($errors): ?><div class="alert alert-danger py-2 small"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="linkLabel">ชื่อลิงก์ <span class="text-danger">*</span></label>
          <input name="label" id="linkLabel" class="form-control" maxlength="150" required value="<?= h($form['label']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="linkUrl">ลิงก์ (URL) <span class="text-danger">*</span></label>
          <input name="url" id="linkUrl" class="form-control" maxlength="500" required value="<?= h($form['url']) ?>" placeholder="https://">
          <div class="form-text">เว็บภายนอก: <code>https://…</code> · หน้าในระบบนี้: path เช่น <code>modules/digitalcc/public/</code></div>
        </div>
        <div class="mb-3">
          <label class="form-label">ไอคอน</label>
          <div class="icon-pick">
            <?php foreach (LINK_ICONS as $ic => $name): ?>
              <input type="radio" class="btn-check" name="icon" id="ic-<?= h($ic) ?>" value="<?= h($ic) ?>"<?= $form['icon'] === $ic ? ' checked' : '' ?>>
              <label class="btn btn-outline-secondary" for="ic-<?= h($ic) ?>" title="<?= h($name) ?>" data-bs-toggle="tooltip"><i class="bi <?= h($ic) ?>"></i></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" name="members_only" value="1" id="linkMembers"<?= $form['members_only'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="linkMembers">แสดงเฉพาะผู้ใช้ที่เข้าสู่ระบบ</label>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="linkActive"<?= $form['is_active'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="linkActive">แสดงในหน้าแรก</label>
        </div>
        <div class="mt-3 small text-muted">ตัวอย่าง:</div>
        <span class="btn btn-outline-secondary btn-sm mt-1" id="linkPreview"><i class="bi <?= h($form['icon']) ?>"></i> <span><?= h($form['label'] ?: 'ชื่อลิงก์') ?></span></span>
      </div>
      <div class="card-footer d-flex gap-2">
        <?php if ($editId): ?><a href="<?= h(url('admin/links')) ?>" class="btn btn-outline-secondary">ยกเลิก</a><?php endif; ?>
        <button class="btn btn-primary ms-auto"><i class="bi bi-save"></i> <?= $editId ? 'บันทึกการแก้ไข' : 'เพิ่มลิงก์' ?></button>
      </div>
    </form>
  </div>
</div>
<style>
  .icon-pick { display: grid; grid-template-columns: repeat(auto-fill, minmax(40px, 1fr)); gap: 4px; }
  .icon-pick .btn { padding: .35rem 0; font-size: 1.05rem; }
  .link-off td:nth-child(-n+2) { opacity: .5; }
  .min-w-0 { min-width: 0; }
</style>
<script>
  // ตัวอย่างปุ่มลิงก์ตามชื่อ/ไอคอนที่เลือก
  (() => {
    const f = document.getElementById('linkForm'), pv = document.getElementById('linkPreview');
    const sync = () => {
      pv.querySelector('i').className = 'bi ' + (f.querySelector('[name=icon]:checked')?.value || 'bi-link-45deg');
      pv.querySelector('span').textContent = f.label.value.trim() || 'ชื่อลิงก์';
    };
    f.addEventListener('input', sync); f.addEventListener('change', sync);
  })();
</script>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
