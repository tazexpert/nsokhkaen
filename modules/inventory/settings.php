<?php
/** ตั้งค่าระบบวัสดุ (ผู้ดูแลระบบวัสดุ) — ผู้ใช้งานและสิทธิ์ย้ายไปที่เมนู "ผู้ดูแลระบบกลาง" */
require_once __DIR__ . '/header.php';
?>

<ul class="nav nav-tabs mb-4" id="settingsTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabLocations">สถานที่จัดเก็บ</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSigners">ผู้ลงนามในใบเบิก</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabLoan">ใบยืมครุภัณฑ์ / การแจ้งเตือน</button>
    </li>
</ul>

<div class="tab-content">
    <!-- สถานที่จัดเก็บ -->
    <div class="tab-pane fade show active" id="tabLocations">
        <div class="row">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">เพิ่มสถานที่จัดเก็บ</div>
                    <div class="card-body">
                        <div class="input-group">
                            <input type="text" id="newLocationName" class="form-control" placeholder="เช่น ห้องพัสดุ ชั้น 1">
                            <button class="btn btn-primary" onclick="addStorageLocation()">เพิ่ม</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="table-responsive">
                    <table class="table table-striped align-middle" id="locationsTable">
                        <thead><tr><th>สถานที่จัดเก็บ</th><th></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ผู้ลงนามในใบเบิก -->
    <div class="tab-pane fade" id="tabSigners">
        <div class="card" style="max-width: 640px;">
            <div class="card-header">ผู้ลงนามในใบเบิกวัสดุ (พิมพ์)</div>
            <div class="card-body">
                <p class="text-muted small">
                    ชื่อ-ตำแหน่งที่จะพิมพ์ไว้ในช่องลายเซ็น "ผู้จ่าย" และ "ผู้รับพัสดุ" (ใต้หัวข้อ "อนุญาติให้เบิกได้") บนใบเบิกวัสดุทุกใบ
                    โดยไม่ต้องพิมพ์ชื่อเองทุกครั้ง (ช่อง "ผู้เบิก" ใช้ชื่อ-ตำแหน่งของผู้ที่ล็อกอินบันทึกใบเบิกนั้นโดยอัตโนมัติ)
                </p>
                <div class="row">
                    <div class="col-6 mb-3"><label class="form-label">ชื่อผู้จ่ายวัสดุ</label><input type="text" class="form-control" id="s_issuer_name"></div>
                    <div class="col-6 mb-3"><label class="form-label">ตำแหน่งผู้จ่ายวัสดุ</label><input type="text" class="form-control" id="s_issuer_position"></div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3"><label class="form-label">ชื่อผู้อนุมัติ</label><input type="text" class="form-control" id="s_approver_name"></div>
                    <div class="col-6 mb-3"><label class="form-label">ตำแหน่งผู้อนุมัติ</label><input type="text" class="form-control" id="s_approver_position"></div>
                </div>
                <div id="appSettingsResult"></div>
            </div>
            <div class="card-footer text-end">
                <button class="btn btn-primary" onclick="saveAppSettings()">บันทึก</button>
            </div>
        </div>
        <p class="small text-muted mt-3">
            <i class="bi bi-info-circle"></i> ชื่อระบบและ URL ที่ฝังใน QR Code ตั้งค่าได้ที่เมนู "ผู้ดูแลระบบกลาง → ตั้งค่าระบบ" ·
            ผู้ใช้งาน/PIN/สิทธิ์ จัดการที่ "ผู้ดูแลระบบกลาง → ผู้ใช้งานและสิทธิ์"
        </p>
    </div>

    <!-- ใบยืมครุภัณฑ์ -->
    <div class="tab-pane fade" id="tabLoan">
        <div class="card" style="max-width: 760px;">
            <div class="card-header">บันทึกข้อความขออนุมัติยืมพัสดุ (พิมพ์)</div>
            <div class="card-body row g-3">
                <div class="col-md-6"><label class="form-label">โทรศัพท์ส่วนราชการ</label><input class="form-control loan-set" id="s_loan_org_phone"></div>
                <div class="col-md-6"><label class="form-label">โทรสาร</label><input class="form-control loan-set" id="s_loan_org_fax"></div>
                <div class="col-12"><label class="form-label">เรียน</label><input class="form-control loan-set" id="s_loan_to"></div>
                <div class="col-md-6"><label class="form-label">ชื่อผู้อนุมัติ</label><input class="form-control loan-set" id="s_loan_approver_name" placeholder="เช่น นางเยาวภา มณีฉาย"></div>
                <div class="col-md-6"><label class="form-label">ตำแหน่งผู้อนุมัติ <small class="text-muted">(ขึ้นบรรทัดใหม่ได้)</small></label>
                    <textarea class="form-control loan-set" id="s_loan_approver_position" rows="3" placeholder="นักวิชาการสถิติชำนาญการพิเศษ รักษาราชการแทน&#10;สถิติจังหวัดขอนแก่น ปฏิบัติหน้าที่แทน&#10;ผู้ว่าราชการจังหวัดขอนแก่น"></textarea></div>
                <div class="col-12 small text-muted">ผู้ให้ยืม/ผู้รับคืน = ผู้ดูแลระบบที่กดอนุมัติ/รับคืนในระบบ (ใช้ชื่อ-ตำแหน่งจาก "บัญชีของฉัน")</div>
                <hr class="my-1">
                <div class="col-md-8"><label class="form-label">แจ้งเตือน "วัสดุใกล้หมด" เมื่อคงเหลือไม่เกิน</label>
                    <div class="input-group"><input type="number" min="0" class="form-control loan-set" id="s_low_stock_default"><span class="input-group-text">หน่วย</span></div>
                    <div class="form-text">ใช้กับวัสดุที่ไม่ได้กำหนด "จำนวนขั้นต่ำ" ไว้ (ถ้ากำหนดไว้ จะใช้ค่าขั้นต่ำของวัสดุนั้น)</div></div>
                <div id="loanSettingsResult" class="col-12"></div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary" onclick="saveLoanSettings()">บันทึก</button></div>
        </div>
    </div>
</div>

<script src="<?= INV_URL ?>assets/js/settings.js<?= assetVersion('assets/js/settings.js') ?>"></script>
<?php require_once __DIR__ . '/footer.php'; ?>
