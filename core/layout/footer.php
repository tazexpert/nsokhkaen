<?php /** ส่วนท้าย — $footerScripts: รายการ URL ของสคริปต์เพิ่มเติม */ ?>
    </div>
    <footer class="app-footer">&copy; <?= (int)date('Y') + 543 ?> <?= h(ORG_NAME) ?></footer>
  </main>
</div>
<?php if (!empty($useLeaflet)): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script><?php endif; ?>
<script src="<?= h(asset_v('assets/js/app.js')) ?>"></script>
<?php foreach ($footerScripts ?? [] as $src): ?><script src="<?= h($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
