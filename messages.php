<?php
/**
 * กล่องจดหมาย (เมนูบัญชีผู้ใช้ ต่อจาก "สมุดบันทึก"): ข้อความเข้า / ส่งแล้ว
 * เขียนข้อความใหม่ → message_compose.php · เปิดอ่าน → message_view.php
 */
require_once __DIR__ . '/core/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$tab = ($_GET['tab'] ?? '') === 'sent' ? 'sent' : 'in';
$q = trim($_GET['q'] ?? '');
$perPage = 30;
$page = max(1, int_param('page', 1));

$other = $tab === 'in' ? 'm.sender_id' : 'm.recipient_id';
$where = $tab === 'in' ? 'm.recipient_id = ? AND m.recipient_deleted = 0' : 'm.sender_id = ? AND m.sender_deleted = 0';
$params = [$uid];
if ($q !== '') {
    $where .= ' AND (m.subject LIKE ? OR m.body LIKE ? OR u.full_name LIKE ? OR u.nickname LIKE ?)';
    array_push($params, ...array_fill(0, 4, "%$q%"));
}
$cnt = db()->prepare("SELECT COUNT(*) FROM core_messages m JOIN core_users u ON u.id = $other WHERE $where");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$st = db()->prepare("SELECT m.id, m.subject, LEFT(m.body, 2000) AS snippet, m.read_at, m.created_at, u.full_name, u.photo,
                            (SELECT COUNT(*) FROM core_message_files f WHERE f.message_id = m.id) AS nfiles
                     FROM core_messages m JOIN core_users u ON u.id = $other
                     WHERE $where ORDER BY m.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll();
$unread = msg_unread_count($uid);

$pageTitle = 'กล่องจดหมาย';
$active = 'messages';
$pageActions = '<a href="' . h(url('message_compose.php')) . '" class="btn btn-primary"><i class="bi bi-pencil-square"></i> เขียนข้อความ</a>';
require APP_ROOT . '/core/layout/header.php';
$link = fn(array $p) => h(url('messages.php?' . http_build_query(array_filter(['tab' => $tab === 'sent' ? 'sent' : null, 'q' => $q ?: null] + $p))));
?>
<div class="card">
  <div class="card-header">
    <div class="row g-2 align-items-center">
      <div class="col-md-auto">
        <ul class="nav nav-pills">
          <li class="nav-item"><a class="nav-link<?= $tab === 'in' ? ' active' : '' ?>" href="<?= h(url('messages.php')) ?>"><i class="bi bi-inbox"></i> ข้อความเข้า<?php if ($unread): ?> <span class="badge rounded-pill text-bg-danger"><?= $unread ?></span><?php endif; ?></a></li>
          <li class="nav-item"><a class="nav-link<?= $tab === 'sent' ? ' active' : '' ?>" href="<?= h(url('messages.php?tab=sent')) ?>"><i class="bi bi-send"></i> ส่งแล้ว</a></li>
        </ul>
      </div>
      <form class="col-md-5 ms-md-auto d-flex gap-2">
        <?php if ($tab === 'sent'): ?><input type="hidden" name="tab" value="sent"><?php endif; ?>
        <input name="q" class="form-control" placeholder="ค้นหาเรื่อง / ข้อความ / ชื่อ" value="<?= h($q) ?>">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
      </form>
    </div>
  </div>
  <div class="list-group list-group-flush msg-list">
    <?php foreach ($rows as $r): $new = $tab === 'in' && !$r['read_at']; ?>
      <a href="<?= h(url('message_view.php?id=' . $r['id'])) ?>" class="list-group-item list-group-item-action d-flex gap-3 align-items-start py-3<?= $new ? ' msg-new' : '' ?>">
        <?= user_avatar($r, 40, 'flex-shrink-0') ?>
        <div class="min-w-0 flex-grow-1">
          <div class="d-flex gap-2 align-items-baseline">
            <span class="text-truncate<?= $new ? ' fw-bold' : ' fw-semibold' ?>"><?= $tab === 'sent' ? '<span class="text-muted fw-normal">ถึง</span> ' : '' ?><?= h(preg_replace('/\s+/u', ' ', $r['full_name'])) ?></span>
            <small class="ms-auto text-muted text-nowrap"><?= h(time_ago($r['created_at'])) ?></small>
          </div>
          <div class="text-truncate<?= $new ? ' fw-bold' : '' ?>">
            <?php if ($new): ?><span class="badge text-bg-primary me-1">ใหม่</span><?php endif; ?>
            <?= h($r['subject']) ?><?php if ($r['nfiles']): ?> <i class="bi bi-paperclip text-muted" title="แนบ <?= (int)$r['nfiles'] ?> ไฟล์"></i><?php endif; ?>
          </div>
          <div class="small text-muted text-truncate"><?= h(msg_snippet($r['snippet'])) ?></div>
          <?php if ($tab === 'sent'): ?><div class="small <?= $r['read_at'] ? 'text-success' : 'text-muted' ?>"><i class="bi <?= $r['read_at'] ? 'bi-check2-all' : 'bi-check2' ?>"></i> <?= $r['read_at'] ? 'อ่านแล้ว ' . h(time_ago($r['read_at'])) : 'ยังไม่ได้อ่าน' ?></div><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <div class="list-group-item text-center text-muted py-5"><i class="bi bi-envelope-open fs-1 d-block mb-2"></i><?= $q !== '' ? 'ไม่พบข้อความที่ค้นหา' : ($tab === 'in' ? 'ยังไม่มีข้อความเข้า' : 'ยังไม่มีข้อความที่ส่ง') ?></div>
    <?php endif; ?>
  </div>
  <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center small">
      <span class="text-muted">ทั้งหมด <?= number_format($total) ?> ข้อความ</span>
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= $link(['page' => $page - 1]) ?>">ก่อนหน้า</a></li>
        <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
        <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= $link(['page' => $page + 1]) ?>">ถัดไป</a></li>
      </ul>
    </div>
  <?php endif; ?>
</div>
<style>
  .msg-list .msg-new { background: rgba(var(--bs-primary-rgb), .06); }
  .msg-list .min-w-0 { min-width: 0; }
</style>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
