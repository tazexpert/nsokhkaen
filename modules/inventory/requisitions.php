<?php require_once __DIR__ . '/header.php'; ?>


<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">ค้นหา (เลขที่ใบเบิก / ชื่อผู้เบิก / งานที่ใช้)</label>
                <input type="text" id="f_keyword" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">วันที่เริ่มต้น</label>
                <?php renderThaiDateSelect('f_start') ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">วันที่สิ้นสุด</label>
                <?php renderThaiDateSelect('f_end') ?>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn btn-primary w-100" onclick="loadRequisitions()">ค้นหา</button>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js"></script>
<script src="<?= INV_URL ?>assets/js/thai-date-select.js<?= assetVersion('assets/js/thai-date-select.js') ?>"></script>
<script>const IS_ADMIN_JS = <?= isAdmin() ? 'true' : 'false' ?>;</script>

<div class="table-responsive">
<table class="table table-striped align-middle" id="requisitionsTable">
    <thead>
    <tr>
        <th>เลขที่ใบเบิก</th><th>วันที่</th><th>ผู้เบิก</th><th>ใช้งาน</th><th>จำนวนรายการ</th><th>ผู้บันทึก</th><th>สถานะ</th><th></th>
    </tr>
    </thead>
    <tbody></tbody>
</table>
</div>

<script src="<?= INV_URL ?>assets/js/requisitions.js<?= assetVersion('assets/js/requisitions.js') ?>"></script>
<?php require_once __DIR__ . '/footer.php'; ?>
