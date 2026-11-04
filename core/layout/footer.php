<?php /** ส่วนท้าย — $footerScripts: รายการ URL ของสคริปต์เพิ่มเติม */ ?>
  </div>
  <footer class="app-footer">&copy; <?= (int)date('Y') + 543 ?> <?= h(APP_NAME) ?></footer>
</main>
<?php if (!empty($useLeaflet)): ?><script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script><?php endif; ?>
<script src="<?= h(asset_v('assets/js/app.js')) ?>"></script>
<?php foreach ($footerScripts ?? [] as $src): ?><script src="<?= h($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
