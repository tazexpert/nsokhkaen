<?php
/**
 * สมุดบันทึกส่วนตัว (ทุกคนที่ login มีคนละเล่ม — เมนูบัญชีผู้ใช้ ก่อน "บุคลากรสถิติขอนแก่น")
 * - เห็นและแก้ไขได้เฉพาะเจ้าของ (ผู้ดูแลระบบก็ดูไม่ได้) · ดู/แก้ไขได้เท่านั้น ไม่มีการเพิ่มหรือลบสมุด
 * - จัดรูปแบบได้ (หัวข้อ ตัวหนา/เอียง/ขีดเส้นใต้ สี รายการ จัดแนว ลิงก์) ด้วย Quill · HTML ถูกกรองก่อนบันทึกและก่อนแสดง (richtext_clean ใน core/richtext.php)
 * - เนื้อหาเข้ารหัส AES-256-GCM ก่อนเก็บลงฐานข้อมูล (core/notebook.php) → เปิดดูในฐานข้อมูลก็อ่านไม่ออก
 * - ทุกครั้งที่บันทึกเก็บเป็นเวอร์ชันใหม่ → ดูประวัติ/นำเวอร์ชันเดิมกลับมาใช้ได้ หากลบข้อความโดยไม่ตั้งใจ
 * - เปิดแก้ไขหลายหน้าต่างพร้อมกัน: ไม่บันทึกทับ ให้ตรวจสอบก่อน
 */
require_once __DIR__ . '/core/bootstrap.php';
require_once APP_ROOT . '/core/notebook.php';
$user = require_login();
$uid = (int)$user['id'];
const NOTEBOOK_MAX = 1000000;  // ขนาดสูงสุดของเนื้อหา (ไบต์ รวมรูปแบบข้อความ)

/** อ่านเวอร์ชันของผู้ใช้ (ล่าสุด หรือตาม id) พร้อมถอดรหัส — text = null ถ้าถอดรหัสไม่ได้ */
function notebook_rev(int $uid, int $revId = 0): ?array
{
    $sql = 'SELECT id, content, created_at FROM core_notebook_revisions WHERE user_id = ?'
         . ($revId ? ' AND id = ?' : ' ORDER BY id DESC LIMIT 1');
    $st = db()->prepare($sql);
    $st->execute($revId ? [$uid, $revId] : [$uid]);
    if (!$r = $st->fetch()) {
        return null;
    }
    $r['text'] = notebook_decrypt($r['content'], $uid);
    unset($r['content']);
    return $r;
}

function notebook_when(string $dt): string
{
    return thai_date($dt) . ' ' . date('H.i', strtotime($dt)) . ' น.';
}

function notebook_save(string $text, int $uid): void
{
    db()->prepare('INSERT INTO core_notebook_revisions (user_id, content) VALUES (?, ?)')->execute([$uid, notebook_encrypt($text, $uid)]);
}

/** แสดงเนื้อหา (ผ่านตัวกรอง HTML) ด้วยสไตล์เดียวกับตัวแก้ไข */
function notebook_html(string $text): string
{
    return richtext_view($text, 'notebook-view');
}

$latest = notebook_rev($uid);
$conflict = null;     // ข้อความที่ยังไม่ได้บันทึก (มีการบันทึกจากหน้าต่างอื่นก่อน)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'save') {
        $raw = (string)($_POST['content'] ?? '');
        $text = richtext_clean($raw);
        if (strlen($raw) > NOTEBOOK_MAX) {
            flash('เนื้อหายาวเกินไป กรุณาแบ่งหรือลดเนื้อหาลง', 'danger');
            $conflict = $text;
        } elseif ($latest && (int)$latest['id'] !== int_param('base_id')) {
            $conflict = $text;
        } else {
            if (!$latest || richtext_clean(richtext_from_text((string)$latest['text'])) !== $text) {
                notebook_save($text, $uid);
                audit_log('notebook.edit', 'แก้ไขสมุดบันทึกส่วนตัว');   // ไม่บันทึกเนื้อหา
                flash('บันทึกเรียบร้อยแล้ว');
            }
            redirect(url('notebook.php'));
        }
    } elseif ($action === 'restore') {
        $old = notebook_rev($uid, int_param('rev'));
        if ($old && $old['text'] !== null) {
            if (!$latest || $latest['text'] !== $old['text']) {
                notebook_save($old['text'], $uid);
                audit_log('notebook.restore', 'นำสมุดบันทึกส่วนตัวเวอร์ชันเดิมกลับมาใช้');
            }
            flash('นำเวอร์ชัน ' . notebook_when($old['created_at']) . ' กลับมาใช้แล้ว');
        }
        redirect(url('notebook.php'));
    }
}

$editing = $conflict !== null || isset($_GET['edit']);
$viewRev = null;      // กำลังดูเวอร์ชันเก่า
if (!$editing && ($rid = int_param('rev')) > 0 && (!$latest || $rid !== (int)$latest['id'])) {
    $viewRev = notebook_rev($uid, $rid);
}
$hs = db()->prepare('SELECT id, created_at FROM core_notebook_revisions WHERE user_id = ? ORDER BY id DESC LIMIT 30');
$hs->execute([$uid]);
$history = $hs->fetchAll();
$shown = $viewRev ?: $latest;
$broken = $latest && $latest['text'] === null;   // ถอดรหัสไม่ได้ (กุญแจไม่ตรง)

$pageTitle = 'สมุดบันทึก';
$headExtra = richtext_assets($editing && !$broken);
$active = 'notebook';
$pageActions = $editing || $broken ? '' : '<a href="' . h(url('notebook.php?edit=1')) . '" class="btn btn-primary"><i class="bi bi-pencil-square"></i> แก้ไข</a>';
require APP_ROOT . '/core/layout/header.php';
?>
<div class="alert alert-light border small py-2"><i class="bi bi-shield-lock text-success"></i> สมุดบันทึกส่วนตัว — เห็นได้เฉพาะคุณ เนื้อหาถูกเข้ารหัสก่อนจัดเก็บ ผู้อื่น (รวมถึงผู้ดูแลระบบ) เปิดอ่านไม่ได้</div>
<?php if ($broken): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-octagon"></i> ไม่สามารถถอดรหัสสมุดบันทึกได้ (กุญแจเข้ารหัสบนเซิร์ฟเวอร์ไม่ตรงกับตอนบันทึก) — กรุณาแจ้งผู้ดูแลระบบให้นำไฟล์กุญแจที่สำรองไว้กลับมา ระหว่างนี้ปิดการแก้ไขไว้เพื่อไม่ให้ข้อมูลเดิมสูญหาย</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-9">
    <?php if ($editing && !$broken): ?>
      <?php if ($conflict !== null && $latest && (int)$latest['id'] !== int_param('base_id')): ?>
        <div class="alert alert-warning">
          <i class="bi bi-exclamation-triangle"></i> <b>ยังไม่ได้บันทึก</b> — มีการบันทึกสมุดจากหน้าต่างอื่นเมื่อ <?= h(notebook_when($latest['created_at'])) ?>
          ระหว่างที่กำลังแก้ไข กรุณาตรวจสอบเนื้อหาล่าสุด แล้วรวมข้อความก่อนกดบันทึกอีกครั้ง
          <details class="mt-2"><summary>ดูเนื้อหาล่าสุด</summary>
            <div class="bg-white rounded border mt-2 small"><?= notebook_html($latest['text']) ?></div>
          </details>
        </div>
      <?php endif; ?>
      <form method="post" class="card" id="notebookForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="base_id" value="<?= $latest ? (int)$latest['id'] : 0 ?>">
        <div class="card-body">
          <input type="hidden" name="content">
          <div id="nbEditor"><?= richtext_clean(richtext_from_text($conflict ?? ($latest['text'] ?? ''))) ?></div>
          <div class="form-text">ทุกครั้งที่บันทึกจะเก็บเป็นประวัติ นำเวอร์ชันเดิมกลับมาใช้ได้</div>
        </div>
        <div class="card-footer d-flex gap-2 justify-content-end">
          <a href="<?= h(url('notebook.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
          <button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
        </div>
      </form>
    <?php else: ?>
      <?php if ($viewRev): ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2">
          <span><i class="bi bi-clock-history"></i> กำลังดูเวอร์ชันเก่า: <?= h(notebook_when($viewRev['created_at'])) ?></span>
          <form method="post" class="ms-auto d-flex gap-2" onsubmit="return confirm('นำเวอร์ชันนี้กลับมาใช้แทนเนื้อหาปัจจุบัน?\n(เนื้อหาปัจจุบันยังเก็บไว้ในประวัติ)')">
            <?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="rev" value="<?= (int)$viewRev['id'] ?>">
            <a href="<?= h(url('notebook.php')) ?>" class="btn btn-sm btn-outline-secondary">กลับไปเวอร์ชันปัจจุบัน</a>
            <?php if ($viewRev['text'] !== null): ?><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-counterclockwise"></i> นำเวอร์ชันนี้กลับมาใช้</button><?php endif; ?>
          </form>
        </div>
      <?php endif; ?>
      <div class="card">
        <div class="card-body">
          <?php if ($shown && $shown['text'] === null): ?>
            <div class="text-center text-danger py-5"><i class="bi bi-lock fs-1 d-block mb-2"></i>ถอดรหัสเวอร์ชันนี้ไม่ได้</div>
          <?php elseif ($shown && trim($shown['text']) !== ''): ?>
            <?= notebook_html($shown['text']) ?>
          <?php else: ?>
            <div class="text-center text-muted py-5"><i class="bi bi-journal fs-1 d-block mb-2"></i>ยังไม่มีบันทึก — กด "แก้ไข" เพื่อเริ่มเขียน</div>
          <?php endif; ?>
        </div>
        <?php if ($latest && !$viewRev): ?>
          <div class="card-footer small text-muted"><i class="bi bi-pencil"></i> แก้ไขล่าสุดเมื่อ <?= h(notebook_when($latest['created_at'])) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-3">
    <div class="card">
      <div class="card-header fw-semibold"><i class="bi bi-clock-history"></i> ประวัติการแก้ไข</div>
      <div class="list-group list-group-flush small notebook-history">
        <?php foreach ($history as $i => $r):
          $cur = !$editing && ($viewRev ? (int)$viewRev['id'] === (int)$r['id'] : $i === 0); ?>
          <a href="<?= h(url($i === 0 ? 'notebook.php' : 'notebook.php?rev=' . (int)$r['id'])) ?>" class="list-group-item list-group-item-action<?= $cur ? ' active' : '' ?>">
            <?= h(notebook_when($r['created_at'])) ?><?= $i === 0 ? ' <span class="badge text-bg-success">ปัจจุบัน</span>' : '' ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$history): ?><div class="list-group-item text-muted">ยังไม่มีประวัติ</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<style>
  .notebook-view { min-height: 8rem; }
  #nbEditor .ql-editor { min-height: 55vh; }
  .notebook-history { max-height: 70vh; overflow-y: auto; }
</style>
<?php if ($editing && !$broken): ?>
<script>
  (() => {
    const quill = richEditor('#nbEditor', { placeholder: 'พิมพ์บันทึกที่นี่…' });
    quill.focus();

    const f = document.getElementById('notebookForm');
    const html = () => richEditorHtml(quill);
    const orig = html();
    let saving = false;
    f.addEventListener('submit', () => { f.content.value = html(); saving = true; });
    // เตือนก่อนออกจากหน้าถ้ายังไม่ได้บันทึก
    window.addEventListener('beforeunload', e => { if (!saving && html() !== orig) { e.preventDefault(); e.returnValue = ''; } });
  })();
</script>
<?php endif; ?>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
