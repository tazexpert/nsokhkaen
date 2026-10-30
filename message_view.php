<?php
/**
 * อ่านข้อความ (เฉพาะผู้ส่ง/ผู้รับ) — ผู้รับเปิดอ่าน = ทำเครื่องหมายอ่านแล้ว (การแจ้งเตือนหายไป)
 * ตอบกลับ → message_compose.php?reply=id · ลบ = ซ่อนจากกล่องของตนเอง
 */
require_once __DIR__ . '/core/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$msg = msg_get(int_param('id'), $uid);
if (!$msg) {
    flash('ไม่พบข้อความ หรือข้อความถูกลบแล้ว', 'warning');
    redirect(url('messages.php'));
}
$isRecipient = (int)$msg['recipient_id'] === $uid;
$isSender = (int)$msg['sender_id'] === $uid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'delete') {
        $pdo = db();
        if ($isRecipient) {
            $pdo->prepare('UPDATE core_messages SET recipient_deleted = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ?')->execute([$msg['id']]);
        }
        if ($isSender) {
            $pdo->prepare('UPDATE core_messages SET sender_deleted = 1 WHERE id = ?')->execute([$msg['id']]);
        }
        $st = $pdo->prepare('SELECT sender_deleted AND recipient_deleted FROM core_messages WHERE id = ?');
        $st->execute([$msg['id']]);
        if ($st->fetchColumn()) {
            msg_purge((int)$msg['id']);           // ทั้งสองฝ่ายลบแล้ว → ลบข้อความและไฟล์แนบจริง
        }
        flash('ลบข้อความแล้ว');
        redirect(url('messages.php' . ($isRecipient ? '' : '?tab=sent')));
    }
}

if ($isRecipient && !$msg['read_at']) {
    db()->prepare('UPDATE core_messages SET read_at = NOW() WHERE id = ? AND read_at IS NULL')->execute([$msg['id']]);
}
$files = msg_files((int)$msg['id']);
$parent = $msg['reply_to_id'] ? msg_get((int)$msg['reply_to_id'], $uid) : null;

$pageTitle = 'ข้อความ';
$active = 'messages';
$pageActions = '<a href="' . h(url('messages.php' . ($isRecipient ? '' : '?tab=sent'))) . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> กล่องจดหมาย</a>';
require APP_ROOT . '/core/layout/header.php';
$from = ['photo' => $msg['sender_photo']];
?>
<div class="row justify-content-center">
  <div class="col-xl-8 col-lg-10">
    <div class="card">
      <div class="card-header py-3">
        <h2 class="h5 mb-3"><?= h($msg['subject']) ?></h2>
        <div class="d-flex gap-3 align-items-center">
          <?= user_avatar($from, 44, 'flex-shrink-0') ?>
          <div class="min-w-0 small">
            <div><span class="text-muted">จาก</span> <b><?= h(preg_replace('/\s+/u', ' ', $msg['sender_name'])) ?></b><?= $msg['sender_position'] ? ' <span class="text-muted">· ' . h($msg['sender_position']) . '</span>' : '' ?></div>
            <div><span class="text-muted">ถึง</span> <?= h(preg_replace('/\s+/u', ' ', $msg['recipient_name'])) ?></div>
            <div class="text-muted"><?= h(thai_date($msg['created_at'])) ?> <?= h(date('H.i', strtotime($msg['created_at']))) ?> น.
              <?php if ($isSender && !$isRecipient): ?> · <span class="<?= $msg['read_at'] ? 'text-success' : '' ?>"><i class="bi <?= $msg['read_at'] ? 'bi-check2-all' : 'bi-check2' ?>"></i> <?= $msg['read_at'] ? 'ผู้รับอ่านแล้ว ' . h(time_ago($msg['read_at'])) : 'ผู้รับยังไม่ได้อ่าน' ?></span><?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="card-body msg-body">
        <?php if ($parent): ?>
          <a href="<?= h(url('message_view.php?id=' . $parent['id'])) ?>" class="d-inline-block small mb-3 text-decoration-none"><i class="bi bi-reply"></i> ตอบกลับข้อความ: <?= h($parent['subject']) ?></a>
        <?php endif; ?>
        <?= $msg['body'] !== '' ? msg_body_html($msg['body']) : '<span class="text-muted">(ไม่มีข้อความ)</span>' ?>
      </div>
      <?php if ($files): ?>
        <div class="card-body border-top">
          <div class="small text-muted mb-2"><i class="bi bi-paperclip"></i> ไฟล์แนบ <?= count($files) ?> ไฟล์</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($files as $f): $inline = in_array(strtolower(pathinfo($f['file_name'], PATHINFO_EXTENSION)), MSG_INLINE_TYPES, true); ?>
              <div class="msg-file border rounded d-flex align-items-center gap-2 px-2 py-1">
                <i class="bi <?= msg_file_icon($f['file_name']) ?> fs-4"></i>
                <div class="min-w-0 small">
                  <a href="<?= h(url('message_file.php?id=' . $f['id'])) ?>"<?= $inline ? ' target="_blank"' : '' ?> class="d-block text-truncate text-decoration-none" title="<?= h($f['file_name']) ?>"><?= h($f['file_name']) ?></a>
                  <span class="text-muted"><?= msg_file_size((int)$f['file_size']) ?></span>
                  · <a href="<?= h(url('message_file.php?id=' . $f['id'] . '&download=1')) ?>" class="text-decoration-none">ดาวน์โหลด</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <div class="card-footer d-flex gap-2">
        <form method="post" onsubmit="return confirm('ลบข้อความนี้ออกจากกล่องจดหมายของคุณ?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> ลบ</button>
        </form>
        <a href="<?= h(url('message_compose.php?reply=' . $msg['id'])) ?>" class="btn btn-primary ms-auto"><i class="bi bi-reply"></i> <?= $isRecipient ? 'ตอบกลับ' : 'ส่งข้อความเพิ่ม' ?></a>
      </div>
    </div>
  </div>
</div>
<style>
  .msg-body { line-height: 1.8; word-break: break-word; min-height: 6rem; }
  .msg-file { max-width: 100%; width: 300px; }
  .min-w-0 { min-width: 0; }
</style>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
