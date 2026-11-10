<?php /* แถบสลับแบบแดชบอร์ด (ทดลอง) — ใช้ใน dash_a/b/c.php */ ?>
<div class="d-flex mb-3"><div class="dz-switch ms-auto">
  <?php foreach (['a' => 'แบบ A', 'b' => 'แบบ B', 'c' => 'แบบ C', '' => 'แบบปัจจุบัน'] as $dk => $dl): ?>
    <a href="?<?= h(http_build_query(array_filter(['fy' => $fy, 'design' => $dk]))) ?>" class="<?= $design === $dk ? 'on' : '' ?>"><?= $dl ?></a>
  <?php endforeach; ?>
</div></div>
