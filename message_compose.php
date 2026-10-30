<?php
/**
 * เขียนข้อความถึงบุคลากร (แนบไฟล์ได้สูงสุด MSG_MAX_FILES ไฟล์) — เปิดจากไอคอนในหน้าบุคลากร (?to=id) หรือปุ่มตอบกลับ (?reply=id)
 * ส่งแล้วผู้รับจะเห็นการแจ้งเตือนที่กระดิ่งและในกล่องจดหมาย
 */
require_once __DIR__ . '/core/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];

/** ขนาดจาก php.ini (เช่น 40M) เป็นไบต์ */
function ini_bytes(string $key): int
{
    $v = trim((string)ini_get($key));
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}
$postMax = ini_bytes('post_max_size');
$fileMax = min(MSG_FILE_MAX, ini_bytes('upload_max_filesize') ?: MSG_FILE_MAX);

$recipients = msg_recipients($uid);
$replyTo = null;
if ($rid = int_param('reply')) {
    $replyTo = msg_get($rid, $uid);
}
$data = ['to' => int_param('to'), 'subject' => '', 'body' => ''];
if ($replyTo) {
    $data['to'] = (int)($replyTo['sender_id'] == $uid ? $replyTo['recipient_id'] : $replyTo['sender_id']);
    $data['subject'] = preg_match('/^ตอบกลับ:/u', $replyTo['subject']) ? $replyTo['subject'] : 'ตอบกลับ: ' . $replyTo['subject'];
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {     // เกิน post_max_size → PHP ทิ้งข้อมูลทั้งหมด
        $errors[] = 'ไฟล์แนบรวมกันใหญ่เกินที่เซิร์ฟเวอร์รับได้ (' . round($postMax / 1048576) . ' MB) กรุณาลดจำนวนหรือขนาดไฟล์';
    } else {
        check_csrf();
        $rawBody = (string)($_POST['body'] ?? '');
        $data = ['to' => (int)post('to'), 'subject' => trim(post('subject')), 'body' => richtext_clean($rawBody)];   // HTML จัดรูปแบบ ผ่านตัวกรอง
        $allowed = array_column($recipients, 'id');
        if ($replyTo) {                                    // ตอบกลับผู้ดูแลระบบ (ไม่ใช่เจ้าหน้าที่) ได้ด้วย
            $allowed[] = $replyTo['sender_id'] == $uid ? $replyTo['recipient_id'] : $replyTo['sender_id'];
        }
        if (!$data['to'] || !in_array($data['to'], array_map('intval', $allowed), true) || $data['to'] === $uid) {
            $errors[] = 'กรุณาเลือกผู้รับ';
        }
        if ($data['subject'] === '') {
            $errors[] = 'กรุณาระบุเรื่อง';
        } elseif (mb_strlen($data['subject']) > 200) {
            $errors[] = 'เรื่องยาวเกิน 200 ตัวอักษร';
        }
        if ($data['body'] === '' && !array_filter(files_array('files'), fn($f) => $f['error'] !== UPLOAD_ERR_NO_FILE)) {
            $errors[] = 'กรุณาพิมพ์ข้อความหรือแนบไฟล์';
        } elseif (strlen($rawBody) > 1000000) {
            $errors[] = 'ข้อความยาวเกินไป';
        }
        try {
            $files = msg_check_files(files_array('files'));
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage() . ' — กรุณาเลือกไฟล์แนบใหม่';
        }
        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO core_messages (sender_id, recipient_id, reply_to_id, subject, body) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$uid, $data['to'], $replyTo['id'] ?? null, $data['subject'], $data['body']]);
                $mid = (int)$pdo->lastInsertId();
                msg_save_files($mid, $files);
                $pdo->commit();
                audit_log('message.send', 'ส่งข้อความ #' . $mid . ' ถึงผู้ใช้ #' . $data['to'] . ($files ? ' (แนบ ' . count($files) . ' ไฟล์)' : ''));
                flash('ส่งข้อความเรียบร้อยแล้ว');
                redirect(url('messages.php?tab=sent'));
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'ส่งข้อความไม่สำเร็จ กรุณาลองใหม่';
                error_log('message send: ' . $e->getMessage());
            }
        }
    }
}

// ผู้รับที่เป็นผู้ดูแลระบบ (กรณีตอบกลับ) ไม่อยู่ในรายชื่อเจ้าหน้าที่ → เพิ่มเข้าไปในตัวเลือก
if ($replyTo && !in_array($data['to'], array_map('intval', array_column($recipients, 'id')), true)) {
    $other = $replyTo['sender_id'] == $uid ? 'recipient' : 'sender';
    array_unshift($recipients, ['id' => $replyTo[$other . '_id'], 'full_name' => $replyTo[$other . '_name'], 'nickname' => '', 'position' => $replyTo[$other . '_position']]);
}

$pageTitle = $replyTo ? 'ตอบกลับข้อความ' : 'เขียนข้อความ';
$active = 'messages';
$headExtra = richtext_assets();
$pageActions = '<a href="' . h(url('messages.php')) . '" class="btn btn-outline-secondary"><i class="bi bi-inbox"></i> กล่องจดหมาย</a>';
require APP_ROOT . '/core/layout/header.php';
?>
<div class="row justify-content-center">
  <div class="col-xl-8 col-lg-10">
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card" id="msgForm">
      <?= csrf_field() ?>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="msgTo">ถึง <span class="text-danger">*</span></label>
          <select name="to" id="msgTo" class="form-select" required>
            <option value="">— เลือกผู้รับ —</option>
            <?php foreach ($recipients as $r): $name = preg_replace('/\s+/u', ' ', $r['full_name']); ?>
              <option value="<?= (int)$r['id'] ?>"<?= (int)$r['id'] === $data['to'] ? ' selected' : '' ?>><?= h($name . ($r['nickname'] ? ' (' . $r['nickname'] . ')' : '') . ($r['position'] ? ' — ' . $r['position'] : '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label" for="msgSubject">เรื่อง <span class="text-danger">*</span></label>
          <input name="subject" id="msgSubject" class="form-control" maxlength="200" required value="<?= h($data['subject']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">ข้อความ</label>
          <input type="hidden" name="body">
          <div id="msgBody"><?= richtext_clean(richtext_from_text($data['body'])) ?></div>
        </div>
        <div>
          <label class="form-label" for="msgFiles"><i class="bi bi-paperclip"></i> แนบไฟล์</label>
          <input type="file" name="files[]" id="msgFiles" class="form-control" multiple
                 accept="<?= h(implode(',', array_map(fn($e) => '.' . $e, array_keys(MSG_FILE_TYPES)))) ?>">
          <div class="form-text">สูงสุด <?= MSG_MAX_FILES ?> ไฟล์ · ไฟล์ละไม่เกิน <?= round($fileMax / 1048576) ?> MB · รวมไม่เกิน <?= round($postMax / 1048576) ?> MB
            · PDF, รูปภาพ, Word, Excel, PowerPoint, TXT, CSV, ZIP/RAR/7z</div>
          <div class="text-danger small mt-1 d-none" id="msgFileErr"></div>
        </div>
      </div>
      <div class="card-footer d-flex gap-2 justify-content-end">
        <a href="<?= h($replyTo ? url('message_view.php?id=' . $replyTo['id']) : url('messages.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
        <button class="btn btn-primary" id="msgSend"><i class="bi bi-send"></i> ส่งข้อความ</button>
      </div>
    </form>

    <?php if ($replyTo): ?>
      <div class="card mt-3">
        <div class="card-header small text-muted"><i class="bi bi-reply"></i> ข้อความเดิมจาก <?= h(preg_replace('/\s+/u', ' ', $replyTo['sender_name'])) ?> · <?= h(time_ago($replyTo['created_at'])) ?></div>
        <div class="card-body small">
          <div class="fw-semibold mb-1"><?= h($replyTo['subject']) ?></div>
          <?= msg_body_html($replyTo['body']) ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<style>
  #msgBody .ql-editor { min-height: 14rem; }
</style>
<script>
  const quill = richEditor('#msgBody', { placeholder: 'พิมพ์ข้อความ…' });
  // ตรวจไฟล์แนบก่อนส่ง (จำนวน / ขนาด) เพื่อไม่ต้องรออัปโหลดแล้วค่อยแจ้งข้อผิดพลาด
  (() => {
    const input = document.getElementById('msgFiles'), err = document.getElementById('msgFileErr'), form = document.getElementById('msgForm');
    const MAX_FILES = <?= MSG_MAX_FILES ?>, FILE_MAX = <?= $fileMax ?>, POST_MAX = <?= $postMax ?>;
    const check = () => {
      const files = [...input.files];
      let msg = '';
      if (files.length > MAX_FILES) msg = 'แนบได้ไม่เกิน ' + MAX_FILES + ' ไฟล์';
      else if (files.some(f => f.size > FILE_MAX)) msg = 'ไฟล์ "' + files.find(f => f.size > FILE_MAX).name + '" ใหญ่เกิน ' + Math.round(FILE_MAX / 1048576) + ' MB';
      else if (files.reduce((s, f) => s + f.size, 0) > POST_MAX - 1048576) msg = 'ไฟล์รวมกันใหญ่เกิน ' + Math.round(POST_MAX / 1048576) + ' MB';
      err.textContent = msg; err.classList.toggle('d-none', !msg);
      return !msg;
    };
    input.addEventListener('change', check);
    form.addEventListener('submit', e => {
      if (!check()) { e.preventDefault(); return; }
      form.body.value = richEditorHtml(quill);
      const b = document.getElementById('msgSend'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> กำลังส่ง…';
    });
  })();
</script>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
