<?php
require_once __DIR__ . '/header.php';
requireLogin();
$pdo = getDbConnection();
$storageLocations = $pdo->query('SELECT * FROM inv_storage_locations ORDER BY name')->fetchAll();
$materialCategories = $pdo->query("SELECT * FROM inv_categories WHERE item_type = 'material' ORDER BY name")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3><?= isAdmin() ? 'จัดการวัสดุสิ้นเปลือง' : 'วัสดุสิ้นเปลือง' ?></h3>
    <?php if (isAdmin()): ?>
    <div>
        <a href="qr_print.php?type=material" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-qr-code"></i> พิมพ์ QR Code</a>
        <a href="catalog_print.php?type=material" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-journal-richtext"></i> พิมพ์แคตตาล็อก</a>
        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload"></i> นำเข้า Excel</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#materialModal" onclick="resetMaterialForm()"><i class="bi bi-plus-lg"></i> เพิ่มวัสดุ</button>
    </div>
    <?php endif; ?>
</div>

<div class="mb-3 row g-2">
    <div class="col-md-8">
        <input type="text" id="searchInput" class="form-control" placeholder="ค้นหาชื่อวัสดุ หรือรหัส...">
    </div>
    <div class="col-md-4">
        <div class="form-check pt-2">
            <input type="checkbox" class="form-check-input" id="lowStockOnly">
            <label class="form-check-label" for="lowStockOnly">แสดงเฉพาะวัสดุใกล้หมดสต๊อก</label>
        </div>
    </div>
</div>

<div class="table-responsive">
<table class="table table-striped align-middle" id="materialsTable">
    <thead>
    <tr>
        <th style="width:56px;">รูป</th>
        <th class="sortable" data-sort="material_code" style="cursor:pointer;">รหัส <i class="bi"></i></th>
        <th class="sortable" data-sort="name" style="cursor:pointer;">ชื่อวัสดุ <i class="bi"></i></th>
        <th class="sortable" data-sort="category_name" style="cursor:pointer;">หมวดหมู่ <i class="bi"></i></th>
        <th class="sortable" data-sort="stock_qty" style="cursor:pointer;">คงเหลือ <i class="bi"></i></th>
        <th class="sortable" data-sort="unit" style="cursor:pointer;">หน่วย <i class="bi"></i></th>
        <th>ต้นทุน/หน่วย</th><th>ที่จัดเก็บ</th><th></th>
    </tr>
    </thead>
    <tbody></tbody>
</table>
</div>

<?php if (isAdmin()): ?>
<!-- Add/Edit modal -->
<div class="modal fade" id="materialModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="materialModalTitle">เพิ่มวัสดุ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="materialForm">
                    <input type="hidden" id="m_id">
                    <div class="mb-3">
                        <label class="form-label">ชื่อวัสดุ</label>
                        <input type="text" class="form-control" id="m_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสหมวดวัสดุ</label>
                        <input type="text" class="form-control" id="m_category_code" list="m_category_code_list" placeholder="เช่น 14111500" required>
                        <datalist id="m_category_code_list">
                            <?php foreach ($materialCategories as $c): ?>
                                <option value="<?= htmlspecialchars($c['name']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">รหัสตามรายงานสำรวจ/ตรวจนับพัสดุของสำนักงาน เป็นทั้งหมวดหมู่และส่วนต้นของรหัสวัสดุ (เลือกรหัสที่มีอยู่ หรือพิมพ์รหัสใหม่)</div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">หน่วยนับ</label>
                            <input type="text" class="form-control" id="m_unit" value="ชิ้น">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label" id="m_stock_qty_label">จำนวนคงเหลือเริ่มต้น</label>
                            <input type="number" class="form-control" id="m_stock_qty" value="0">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">ต้นทุน/หน่วย (บาท)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="m_unit_cost" value="0">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">จุดสั่งซื้อขั้นต่ำ (แจ้งเตือน)</label>
                            <input type="number" class="form-control" id="m_min_stock" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">สถานที่จัดเก็บ</label>
                        <select class="form-select" id="m_storage_location">
                            <option value="">-- ไม่ระบุ --</option>
                            <?php foreach ($storageLocations as $loc): ?>
                                <option value="<?= htmlspecialchars($loc['name']) ?>"><?= htmlspecialchars($loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">จัดการรายการสถานที่จัดเก็บได้ที่เมนู "ตั้งค่าระบบ"</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" id="m_note"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รูปภาพ</label>
                        <div class="mb-2">
                            <img id="m_image_preview" src="" alt="" style="max-height:120px; display:none;" class="border rounded">
                            <div><a href="#" id="m_image_recrop" style="display:none;" onclick="reopenCrop(); return false;">ครอปรูปนี้ใหม่</a></div>
                        </div>
                        <input type="file" class="form-control" id="m_image" accept="image/*">
                        <div class="form-text">JPG, PNG, WEBP หรือ GIF ขนาดไม่เกิน 5MB - เลือกไฟล์แล้วจะเปิดหน้าต่างให้ครอปเฉพาะส่วนที่ต้องการก่อนบันทึก</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button class="btn btn-primary" onclick="saveMaterial()">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<!-- Crop modal - opens automatically when a new image file is chosen above -->
<div class="modal fade" id="cropModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">ครอปรูปภาพ - เลือกเฉพาะส่วนที่ต้องการ</h5>
                <button type="button" class="btn-close" onclick="cancelCrop()"></button>
            </div>
            <div class="modal-body">
                <div style="max-height:60vh; overflow:hidden;">
                    <img id="cropImage" src="" alt="" style="max-width:100%;">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="cancelCrop()">ยกเลิก</button>
                <button class="btn btn-primary" onclick="confirmCrop()"><i class="bi bi-crop"></i> ใช้ส่วนที่ครอปนี้</button>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>

<!-- Receive stock modal -->
<div class="modal fade" id="receiveStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="receiveStockModalTitle">รับเข้าสต็อก</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="receiveStockForm">
                    <input type="hidden" id="rs_id">
                    <p class="mb-3">จำนวนคงเหลือปัจจุบัน: <strong id="rs_current_qty">0</strong> <span id="rs_unit"></span></p>
                    <div class="mb-3">
                        <label class="form-label">จำนวนที่รับเข้า</label>
                        <input type="number" min="1" class="form-control" id="rs_quantity" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ต้นทุน/หน่วย (บาท)</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="rs_unit_cost" required>
                        <div class="form-text">จะใช้แทนที่ต้นทุน/หน่วยเดิมของรายการนี้</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <input type="text" class="form-control" id="rs_note">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button class="btn btn-primary" onclick="saveReceiveStock()">บันทึก</button>
            </div>
        </div>
    </div>
</div>

<!-- Import modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">นำเข้าวัสดุจากไฟล์ Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-1">รองรับ 3 รูปแบบไฟล์ - ระบบตรวจจับอัตโนมัติ (หมวดหมู่และรหัสวัสดุกำหนดจากรหัสหมวดวัสดุ (category_code) ในไฟล์ - รายการที่ไม่มีรหัสหมวดวัสดุจะไม่มีหมวดหมู่และใช้รหัสวัสดุแบบเดิม MAT-0001):</p>
                <ul class="text-muted small">
                    <li>แบบฟอร์มของระบบ: คอลัมน์ name, category_code, unit, unit_cost, stock_qty, min_stock, storage_location, note</li>
                    <li>แบบฟอร์ม "รายละเอียดพัสดุ" ของสำนักงาน (ลำดับที่ / รายละเอียดของพัสดุ / ราคาที่ได้มาจากการสืบราคา(หน่วยละ) / จำนวน(หน่วย)) - ใช้ไฟล์เดิมได้เลยโดยไม่ต้องปรับคอลัมน์ (ไม่มีรหัสหมวดวัสดุ)</li>
                    <li>แบบฟอร์ม "รายงานวัสดุคงเหลือ" ของสำนักงาน (ลำดับที่ / รหัสหมวดวัสดุ / รายการ / หน่วยนับ / ยอดตามบัญชี) - ใช้ยอดตามบัญชีเป็นจำนวนคงเหลือ และใช้รหัสหมวดวัสดุกำหนดหมวดหมู่และรหัสวัสดุ</li>
                </ul>
                <a href="<?= INV_URL ?>api/import_template.php?type=material" class="btn btn-sm btn-outline-primary mb-3">
                    <i class="bi bi-download"></i> ดาวน์โหลดแบบฟอร์มเปล่า (Excel)
                </a>
                <form id="importForm" enctype="multipart/form-data">
                    <input type="hidden" name="type" value="material">
                    <input type="file" name="excel_file" accept=".xlsx" class="form-control" required>
                </form>
                <div id="importResult" class="mt-3"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                <button class="btn btn-success" onclick="doImport()">นำเข้าข้อมูล</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>const IS_ADMIN = <?= json_encode(isAdmin()) ?>;</script>
<script src="<?= INV_URL ?>assets/js/materials.js<?= assetVersion('assets/js/materials.js') ?>"></script>
<?php require_once __DIR__ . '/footer.php'; ?>
