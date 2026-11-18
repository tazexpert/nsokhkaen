<?php
/**
 * แชร์ข้อความ (เมนูบัญชีผู้ใช้): กระดานข้อความที่แชร์ถึงฉัน / ทุกคน และที่ฉันแชร์ — ใหม่ก่อน
 * แชร์ใหม่/แก้ไข → share_form.php · ไฟล์แนบ → share_file.php · ผู้รับดูอย่างเดียว
 */
require_once __DIR__ . '/core/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$tab = ($_GET['tab'] ?? '') === 'mine' ? 'mine' : 'all';
$q = trim($_GET['q'] ?? '');
$perPage = 20;
$page = max(1, int_param('page', 1));

[$vis, $params] = share_visible_sql($uid);
$where = $tab === 'mine' ? 's.user_id = ?' : $vis;
$params = $tab === 'mine' ? [$uid] : $params;
if ($q !== '') {
    $where .= ' AND (s.body LIKE ? OR u.full_name LIKE ? OR u.nickname LIKE ?)';
    array_push($params, ...array_fill(0, 3, "%$q%"));
}
$cnt = db()->prepare("SELECT COUNT(*) FROM core_shares s JOIN core_users u ON u.id = s.user_id WHERE $where");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$st = db()->prepare("SELECT s.*, u.full_name, u.nickname, u.position, u.photo,
                            (SELECT COUNT(*) FROM core_share_users x WHERE x.share_id = s.id) AS nusers
                     FROM core_shares s JOIN core_users u ON u.id = s.user_id
                     WHERE $where ORDER BY s.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll();
$files = share_files(array_column($rows, 'id'));

$pageTitle = 'แชร์ข้อความ';
$active = 'shares';
$headExtra = richtext_assets(false);
$pageActions = '<a href="' . h(url('share_form')) . '" class="btn btn-primary"><i class="bi bi-megaphone"></i> แชร์ข้อความ</a>';
require APP_ROOT . '/core/layout/header.php';
$link = fn(array $p) => h(url('shares?' . http_build_query(array_filter(['tab' => $tab === 'mine' ? 'mine' : null, 'q' => $q ?: null] + $p))));
?>
<div class="row justify-content-center">
  <div class="col-xl-8 col-lg-10">
    <div class="card mb-3">
      <div class="card-body py-2">
        <div class="row g-2 align-items-center">
          <div class="col-md-auto">
            <ul class="nav nav-pills">
              <li class="nav-item"><a class="nav-link<?= $tab === 'all' ? ' active' : '' ?>" href="<?= h(url('shares')) ?>"><i class="bi bi-collection"></i> ทั้งหมด</a></li>
              <li class="nav-item"><a class="nav-link<?= $tab === 'mine' ? ' active' : '' ?>" href="<?= h(url('shares?tab=mine')) ?>"><i class="bi bi-person"></i> ที่ฉันแชร์</a></li>
            </ul>
          </div>
          <form class="col-md-6 ms-md-auto d-flex gap-2">
            <?php if ($tab === 'mine'): ?><input type="hidden" name="tab" value="mine"><?php endif; ?>
            <input name="q" class="form-control" placeholder="ค้นหาข้อความ / ชื่อผู้แชร์" value="<?= h($q) ?>">
            <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
          </form>
        </div>
      </div>
    </div>

    <?php foreach ($rows as $s): $mine = (int)$s['user_id'] === $uid; ?>
      <div class="card mb-3 share-card" id="share-<?= (int)$s['id'] ?>">
        <div class="card-body">
          <div class="d-flex gap-2 align-items-start mb-2">
            <?= user_avatar($s, 42, 'flex-shrink-0') ?>
            <div class="min-w-0 flex-grow-1">
              <div class="fw-semibold"><?= h(preg_replace('/\s+/u', ' ', $s['full_name'])) ?><?= $s['nickname'] ? ' <span class="text-muted fw-normal">(' . h($s['nickname']) . ')</span>' : '' ?></div>
              <div class="small text-muted">
                <?= h(time_ago($s['created_at'])) ?><?= $s['updated_at'] ? ' · แก้ไขแล้ว' : '' ?> ·
                <?php if ($s['audience'] === 'all'): ?><i class="bi bi-globe2"></i> ทุกคน
                <?php else: ?><span title="<?= h((string)$s['audience_text']) ?>"><i class="bi bi-people"></i> <?= h(mb_strimwidth((string)$s['audience_text'], 0, 70, '…')) ?> (<?= (int)$s['nusers'] ?> คน)</span><?php endif; ?>
              </div>
            </div>
            <?php if ($mine): ?>
              <div class="dropdown">
                <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-label="จัดการ"><i class="bi bi-three-dots"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="<?= h(url('share_form?id=' . $s['id'])) ?>"><i class="bi bi-pencil"></i> แก้ไข / เปลี่ยนผู้รับ</a></li>
                  <li><form method="post" action="<?= h(url('share_form?id=' . $s['id'])) ?>" data-confirm="ลบข้อความที่แชร์นี้? (ผู้รับจะไม่เห็นอีก)">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                    <button class="dropdown-item text-danger"><i class="bi bi-trash"></i> ลบ</button></form></li>
                </ul>
              </div>
            <?php endif; ?>
          </div>
          <div class="share-body"><?= msg_body_html($s['body']) ?></div>
          <?php if (!empty($files[(int)$s['id']])): ?>
            <div class="d-flex flex-wrap gap-2 mt-2">
              <?php foreach ($files[(int)$s['id']] as $f): ?>
                <a href="<?= h(url('share_file?id=' . $f['id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary text-start">
                  <i class="bi <?= msg_file_icon($f['file_name']) ?>"></i> <?= h($f['file_name']) ?> <span class="text-muted small"><?= msg_file_size((int)$f['file_size']) ?></span></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-megaphone fs-1 d-block mb-2"></i>
        <?= $q !== '' ? 'ไม่พบข้อความที่ค้นหา' : ($tab === 'mine' ? 'คุณยังไม่ได้แชร์ข้อความ' : 'ยังไม่มีข้อความที่แชร์ถึงคุณ') ?></div></div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
      <nav><ul class="pagination justify-content-center">
        <?php for ($i = 1; $i <= $pages; $i++): ?><li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= $link(['page' => $i > 1 ? $i : null]) ?>"><?= $i ?></a></li><?php endfor; ?>
      </ul></nav>
    <?php endif; ?>
  </div>
</div>
<style>
  .share-body { overflow-wrap: anywhere; }
  .share-body img { max-width: 100%; height: auto; }
  .share-card:target { box-shadow: 0 0 0 3px rgba(13, 110, 253, .35); }
</style>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
