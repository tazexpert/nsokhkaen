<?php
/**
 * แชร์ข้อความ: เขียนใหม่ / แก้ไข / ลบ (เฉพาะโพสต์ของตนเอง)
 *   ผู้รับ: ทุกคน หรือเลือกเอง (รายบุคคล · เลือกทั้งกลุ่มงาน) — ผู้รับใหม่ได้รับการแจ้งเตือนที่กระดิ่ง
 *   ไฟล์แนบ: ชนิด/ขนาดเดียวกับกล่องจดหมาย (MSG_FILE_TYPES, MSG_MAX_FILES, MSG_FILE_MAX)
 */
require_once __DIR__ . '/core/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$pdo = db();
$staff = share_staff($uid);
$staffIds = array_map('intval', array_column($staff, 'id'));

$id = int_param('id');
$share = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM core_shares WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    $share = $st->fetch();
    if (!$share) {
        flash('ไม่พบข้อความ หรือไม่ใช่ข้อความที่คุณแชร์', 'danger');
        redirect(url('shares'));
    }
}
$data = ['body' => $share['body'] ?? '', 'audience' => $share['audience'] ?? 'select', 'users' => $share ? share_user_ids($id) : []];
$oldFiles = $share ? (share_files([$id])[$id] ?? []) : [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if ($share && post('action') === 'delete') {
        share_delete($id);
        audit_log('share_delete', '#' . $id);
        flash('ลบข้อความที่แชร์แล้ว');
        redirect(url('shares?tab=mine'));
    }
    $data = ['body' => richtext_clean((string)($_POST['body'] ?? '')), 'audience' => post('audience') === 'select' ? 'select' : 'all',
             'users' => array_values(array_intersect(array_map('intval', (array)($_POST['users'] ?? [])), $staffIds))];
    $removeFiles = array_map('intval', (array)($_POST['remove_files'] ?? []));
    $keptFiles = count(array_filter($oldFiles, fn($f) => !in_array((int)$f['id'], $removeFiles, true)));
    try {
        $files = msg_check_files(files_array('files'));
        if ($keptFiles + count($files) > MSG_MAX_FILES) {
            throw new RuntimeException('แนบไฟล์ได้ไม่เกิน ' . MSG_MAX_FILES . ' ไฟล์ต่อข้อความ');
        }
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
        $files = [];
    }
    if (trim(strip_tags($data['body'])) === '' && !$files && !$keptFiles && !str_contains($data['body'], '<img')) {
        $errors[] = 'กรุณาพิมพ์ข้อความ หรือแนบไฟล์';
    }
    if ($data['audience'] === 'select' && !$data['users']) {
        $errors[] = 'กรุณาเลือกผู้รับอย่างน้อย 1 คน (หรือเลือก "ทุกคน")';
    }
    if (!$errors) {
        $text = $data['audience'] === 'select' ? share_audience_text($data['users'], $staff) : null;
        $pdo->beginTransaction();
        try {
            if ($share) {
                $pdo->prepare('UPDATE core_shares SET body = ?, audience = ?, audience_text = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$data['body'], $data['audience'], $text, $id]);
            } else {
                $pdo->prepare('INSERT INTO core_shares (user_id, body, audience, audience_text) VALUES (?, ?, ?, ?)')
                    ->execute([$uid, $data['body'], $data['audience'], $text]);
                $id = (int)$pdo->lastInsertId();
            }
            // ผู้รับ: คงคนเดิม (ไม่แจ้งเตือนซ้ำ) · เพิ่มคนใหม่ (แจ้งเตือน) · เอาคนที่ไม่เลือกออก
            $want = $data['audience'] === 'select' ? $data['users'] : [];
            $have = $share ? share_user_ids($id) : [];
            if ($gone = array_diff($have, $want)) {
                $pdo->prepare('DELETE FROM core_share_users WHERE share_id = ? AND user_id IN (' . implode(',', array_map('intval', $gone)) . ')')->execute([$id]);
            }
            $ins = $pdo->prepare('INSERT IGNORE INTO core_share_users (share_id, user_id) VALUES (?, ?)');
            foreach (array_diff($want, $have) as $u) {
                $ins->execute([$id, $u]);
            }
            share_delete_files($id, $removeFiles);
            share_save_files($id, $files);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'บันทึกไม่สำเร็จ';
            error_log('[nsokhkaen] share save: ' . $ex->getMessage());
        }
        if (!$errors) {
            audit_log($share ? 'share_update' : 'share_create', '#' . $id . ' ' . ($data['audience'] === 'all' ? 'ทุกคน' : count($data['users']) . ' คน'));
            flash($share ? 'บันทึกการแก้ไขแล้ว' : 'แชร์ข้อความแล้ว — ' . ($data['audience'] === 'all' ? 'บุคลากรทุกคน' : count($data['users']) . ' คน') . 'จะได้รับการแจ้งเตือน');
            redirect(url('shares#share-' . $id));
        }
    }
}

// รายชื่อแยกตามกลุ่มงาน (ไม่มีกลุ่ม = "อื่น ๆ") ตามลำดับบุคลากร
$groups = [];
foreach ($staff as $u) {
    $groups[$u['work_group'] ?: 'อื่น ๆ'][] = $u;
}
uksort($groups, fn($a, $b) => ($a === 'อื่น ๆ') <=> ($b === 'อื่น ๆ'));
$selected = array_flip($data['users']);

$pageTitle = $share ? 'แก้ไขข้อความที่แชร์' : 'แชร์ข้อความ';
$active = 'shares';
$headExtra = richtext_assets();
$pageActions = '<a href="' . h(url('shares')) . '" class="btn btn-outline-secondary"><i class="bi bi-megaphone"></i> กระดานแชร์ข้อความ</a>';
$bodyClass = trim(($bodyClass ?? '') . ' mod-theme theme-admin');   // เมนูบัญชีผู้ใช้: สีน้ำเงินเดียวกับเมนูหลัก
require APP_ROOT . '/core/layout/header.php';
?>
<div class="row justify-content-center">
  <div class="col-xl-9">
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card" id="shareForm">
      <?= csrf_field() ?>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label">ข้อความ</label>
          <input type="hidden" name="body">
          <div id="shareBody"><?= richtext_clean(richtext_from_text($data['body'])) ?></div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="shareFiles"><i class="bi bi-paperclip"></i> แนบไฟล์</label>
          <?php if ($oldFiles): ?>
            <div class="d-flex flex-wrap gap-2 mb-2">
              <?php foreach ($oldFiles as $f): ?>
                <label class="btn btn-sm btn-outline-secondary"><input type="checkbox" class="form-check-input me-1" name="remove_files[]" value="<?= (int)$f['id'] ?>">
                  <i class="bi <?= msg_file_icon($f['file_name']) ?>"></i> <?= h($f['file_name']) ?> <span class="text-danger small">ติ๊ก = ลบ</span></label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <input type="file" name="files[]" id="shareFiles" class="form-control" multiple accept="<?= h(implode(',', array_map(fn($e) => '.' . $e, array_keys(MSG_FILE_TYPES)))) ?>">
          <div class="form-text">สูงสุด <?= MSG_MAX_FILES ?> ไฟล์ · ไฟล์ละไม่เกิน <?= MSG_FILE_MAX / 1048576 ?> MB · PDF, รูปภาพ, Word, Excel, PowerPoint, TXT, CSV, ZIP/RAR/7z</div>
        </div>

        <label class="form-label">แชร์ให้</label>
        <div class="btn-group w-100 mb-2" role="group">
          <input type="radio" class="btn-check" name="audience" value="all" id="audAll" <?= $data['audience'] === 'all' ? 'checked' : '' ?>>
          <label class="btn btn-outline-primary" for="audAll"><i class="bi bi-globe2"></i> บุคลากรทุกคน</label>
          <input type="radio" class="btn-check" name="audience" value="select" id="audSel" <?= $data['audience'] === 'select' ? 'checked' : '' ?>>
          <label class="btn btn-outline-primary" for="audSel"><i class="bi bi-people"></i> เลือกผู้รับเอง</label>
        </div>
        <div id="pickBox" class="border rounded p-2<?= $data['audience'] === 'select' ? '' : ' d-none' ?>">
          <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <input type="search" id="pickSearch" class="form-control form-control-sm" style="max-width:260px" placeholder="ค้นหาชื่อ / ชื่อเล่น / ตำแหน่ง">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-pick="all">เลือกทั้งหมด</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-pick="none">ล้าง</button>
            <span class="ms-auto small">เลือกแล้ว <strong id="pickCount">0</strong> คน</span>
          </div>
          <div class="share-pick">
            <?php foreach ($groups as $gname => $list): ?>
              <div class="share-group mb-2">
                <div class="d-flex align-items-center gap-2 small fw-semibold text-muted border-bottom pb-1 mb-1">
                  <span><?= h($gname) ?></span>
                  <button type="button" class="btn btn-link btn-sm p-0 ms-auto" data-pick-group>เลือกทั้งกลุ่ม</button>
                </div>
                <div class="row g-1">
                  <?php foreach ($list as $u): $name = preg_replace('/\s+/u', ' ', $u['full_name']); ?>
                    <label class="col-md-6 share-opt" data-text="<?= h(mb_strtolower($name . ' ' . $u['nickname'] . ' ' . $u['position'])) ?>">
                      <span class="d-flex align-items-center gap-2 p-1 rounded">
                        <input type="checkbox" class="form-check-input m-0" name="users[]" value="<?= (int)$u['id'] ?>" <?= isset($selected[(int)$u['id']]) ? 'checked' : '' ?>>
                        <?= user_avatar($u, 26) ?>
                        <span class="min-w-0"><span class="d-block text-truncate"><?= h($name) ?><?= $u['nickname'] ? ' <span class="text-muted">(' . h($u['nickname']) . ')</span>' : '' ?></span>
                          <span class="d-block small text-muted text-truncate"><?= h((string)$u['position']) ?></span></span>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-text">ผู้รับดูข้อความได้อย่างเดียว และได้รับการแจ้งเตือนที่กระดิ่ง · คุณแก้ไข/ลบข้อความ และเปลี่ยนผู้รับได้ภายหลัง</div>
      </div>
      <div class="card-footer d-flex gap-2 justify-content-end">
        <a href="<?= h(url('shares')) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
        <button class="btn btn-primary" id="shareSend"><i class="bi bi-megaphone"></i> <?= $share ? 'บันทึก' : 'แชร์ข้อความ' ?></button>
      </div>
    </form>
  </div>
</div>
<style>
  #shareBody .ql-editor { min-height: 12rem; }
  .share-pick { max-height: 380px; overflow-y: auto; }
  .share-opt { cursor: pointer; }
  .share-opt > span:hover { background: var(--bs-tertiary-bg); }
  .share-opt input:checked ~ .min-w-0 { font-weight: 600; }
</style>
<script>
  const quill = richEditor('#shareBody', { placeholder: 'พิมพ์ข้อความที่ต้องการแชร์…' });
  (() => {
    const form = document.getElementById('shareForm'), box = document.getElementById('pickBox'), search = document.getElementById('pickSearch');
    const boxes = () => [...box.querySelectorAll('input[name="users[]"]')];
    const visible = cb => !cb.closest('.share-opt').hidden;
    const count = () => { document.getElementById('pickCount').textContent = boxes().filter(b => b.checked).length; };
    document.querySelectorAll('[name=audience]').forEach(r => r.addEventListener('change', () => box.classList.toggle('d-none', !document.getElementById('audSel').checked)));
    box.addEventListener('change', count);
    box.querySelectorAll('[data-pick]').forEach(b => b.addEventListener('click', () => { boxes().filter(visible).forEach(c => c.checked = b.dataset.pick === 'all'); count(); }));
    box.querySelectorAll('[data-pick-group]').forEach(b => b.addEventListener('click', () => {
      const list = [...b.closest('.share-group').querySelectorAll('input[name="users[]"]')].filter(visible);
      const all = list.every(c => c.checked);
      list.forEach(c => c.checked = !all); count();
    }));
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      box.querySelectorAll('.share-opt').forEach(o => o.hidden = q !== '' && !o.dataset.text.includes(q));
      box.querySelectorAll('.share-group').forEach(g => g.hidden = ![...g.querySelectorAll('.share-opt')].some(o => !o.hidden));
    });
    form.addEventListener('submit', e => {
      if (document.getElementById('audSel').checked && !boxes().some(b => b.checked)) { e.preventDefault(); alert('กรุณาเลือกผู้รับอย่างน้อย 1 คน'); return; }
      form.body.value = richEditorHtml(quill);
      const b = document.getElementById('shareSend'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> กำลังบันทึก…';
    });
    count();
  })();
</script>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
