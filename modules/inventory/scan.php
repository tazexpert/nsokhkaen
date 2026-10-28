<?php
require_once __DIR__ . '/header.php';

// Render the in-progress slip straight from the session on every page load,
// including the ones triggered by scanning another sticker with the phone's
// own camera/QR app (each such scan opens scan.php?qr=... as a fresh page -
// a JS-only cart would be wiped out by that; session storage survives it).
$draft = $_SESSION['inv_requisition_draft'] ?? [
    'purpose' => '',
    'items' => [],
];
?>


<div class="row g-4">
    <div class="col-md-5">
        <div class="card">
            <div class="card-body text-center">
                <p class="text-muted small mb-2">
                    ใช้แอปกล้อง/สแกน QR ของเครื่องสแกนสติ๊กเกอร์ รายการจะถูกเพิ่มลงใบเบิกนี้ให้อัตโนมัติ
                </p>
                <div class="input-group">
                    <input type="text" id="manualQr" class="form-control" placeholder="หรือกรอกรหัส QR หรือค้นหาด้วยชื่อวัสดุ">
                    <button class="btn btn-outline-secondary" id="btnManualLookup">ค้นหา</button>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cart"></i> รายการในใบเบิกปัจจุบัน (<span id="cartCount"><?= count($draft['items']) ?></span>)</span>
                <button class="btn btn-sm btn-outline-secondary" onclick="clearCart()" title="ล้างรายการทั้งหมด"><i class="bi bi-x-circle"></i></button>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:44px;">รูป</th><th>รายการ</th><th>จำนวน</th><th></th></tr></thead>
                    <tbody id="cartBody"></tbody>
                </table>
            </div>
            <div class="card-body border-top" id="cartFormWrapper" style="<?= $draft['items'] ? '' : 'display:none;' ?>">
                <div class="mb-2">
                    <label class="form-label">ขอเบิกวัสดุเพื่อใช้งาน <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="req_purpose" required value="<?= htmlspecialchars($draft['purpose']) ?>" placeholder="เช่น ใช้ในงานประชุม...">
                </div>
                <button class="btn btn-primary w-100" onclick="submitRequisition()">
                    <i class="bi bi-save"></i> บันทึกใบเบิก
                </button>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div id="resultCard" class="card d-none">
            <div class="card-header">รายละเอียดรายการที่สแกน</div>
            <div class="card-body" id="resultBody"></div>
        </div>
        <div id="alertBox"></div>
        <div id="submitResultBox"></div>
    </div>
</div>

<script>
const SCAN_API_URL = '<?= INV_URL ?>api/scan_api.php';
const REQ_API_URL = '<?= INV_URL ?>api/requisition_api.php';
const PRINT_URL = '<?= INV_URL ?>requisition_print.php';
const QR_FROM_LINK = <?= json_encode($_GET['qr'] ?? '') ?>;

function showAlert(message, type = 'danger') {
    $('#alertBox').html(`<div class="alert alert-${type} mt-3">${message}</div>`);
}

$('#btnManualLookup').on('click', function () {
    const qr = $('#manualQr').val().trim();
    if (qr) lookupQr(qr);
});

$('#req_purpose').on('input', function () {
    $(this).removeClass('is-invalid');
});

$('#manualQr').on('keypress', function (e) {
    if (e.which === 13) {
        const qr = $(this).val().trim();
        if (qr) lookupQr(qr);
    }
});

function lookupQr(qrCode) {
    $('#alertBox').empty();
    $('#submitResultBox').empty();
    $.get(SCAN_API_URL, { action: 'lookup', qr_code: qrCode })
        .done((res) => renderResult(res))
        .fail((xhr) => showAlert(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'));
}

function renderResult(res) {
    if (!res.success) {
        showAlert(res.message);
        $('#resultCard').addClass('d-none');
        return;
    }

    $('#resultCard').removeClass('d-none');
    if (res.item_type === 'search_results') {
        renderSearchResults(res.data);
    } else {
        renderMaterial(res.data);
    }
}

// When the manual box doesn't match a QR code exactly, the API falls back to
// searching by name/code instead - if that turns up more than one material,
// show them as a pickable list rather than guessing which one was meant.
let lastSearchResults = [];

function renderSearchResults(materials) {
    lastSearchResults = materials;
    const rows = materials.map((m) => {
        const thumb = m.image_path
            ? `<img src="${BASE_URL_JS}${m.image_path}" alt="" style="width:36px;height:36px;object-fit:cover;" class="rounded">`
            : `<span class="text-muted d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;"><i class="bi bi-image"></i></span>`;
        return `
            <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-2" onclick="selectSearchResult(${m.id})">
                ${thumb}
                <span class="flex-grow-1">
                    <div>${m.name}</div>
                    <small class="text-muted">${m.material_code}</small>
                </span>
                <span class="badge bg-secondary">${m.stock_qty} ${m.unit}</span>
            </button>
        `;
    }).join('');
    $('#resultBody').html(`
        <p class="text-muted mb-2">พบ ${materials.length} รายการที่ตรงกับคำค้นหา เลือกรายการที่ต้องการ:</p>
        <div class="list-group">${rows}</div>
    `);
}

function selectSearchResult(materialId) {
    const m = lastSearchResults.find((x) => x.id === materialId);
    if (m) renderMaterial(m);
}

function renderMaterial(m) {
    const image = m.image_path ? `<img src="${BASE_URL_JS}${m.image_path}" alt="" class="img-fluid rounded mb-2" style="max-height:160px;">` : '';
    $('#resultBody').html(`
        <span class="badge bg-info mb-2">วัสดุสิ้นเปลือง</span>
        ${image}
        <h5>${m.name}</h5>
        <p class="mb-1">รหัส: ${m.material_code}</p>
        <p class="mb-1">คงเหลือ: <strong>${m.stock_qty}</strong> ${m.unit}</p>
        <div class="mb-3">
            <label class="form-label">จำนวนที่ต้องการเบิก</label>
            <input type="number" min="1" max="${m.stock_qty}" class="form-control" id="withdrawQty" value="1">
        </div>
        <div class="mb-3">
            <label class="form-label">หมายเหตุ</label>
            <input type="text" class="form-control" id="withdrawNote">
        </div>
        <button class="btn btn-primary" onclick="addMaterialToCart(${m.id})">
            <i class="bi bi-cart-plus"></i> เพิ่มลงใบเบิก
        </button>
    `);
}

function addMaterialToCart(materialId) {
    const quantity = parseInt($('#withdrawQty').val(), 10) || 0;
    const note = $('#withdrawNote').val();

    if (quantity <= 0) {
        showAlert('กรุณาระบุจำนวนที่ต้องการเบิก');
        return;
    }

    $.post(REQ_API_URL, {
        action: 'cart_add_material',
        material_id: materialId,
        quantity,
        note,
        purpose: $('#req_purpose').val(),
    })
        .done((res) => {
            renderCart(res.data.items);
            $('#resultCard').addClass('d-none');
            $('#alertBox').empty();
        })
        .fail((xhr) => showAlert(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'));
}

function removeFromCart(index) {
    $.post(REQ_API_URL, { action: 'cart_remove', index })
        .done((res) => renderCart(res.data.items))
        .fail((xhr) => showAlert(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'));
}

function clearCart() {
    if (!confirm('ล้างรายการในใบเบิกปัจจุบันทั้งหมด?')) return;
    $.post(REQ_API_URL, { action: 'cart_clear' })
        .done((res) => renderCart(res.data.items));
}

function renderCart(items) {
    $('#cartCount').text(items.length);
    const tbody = $('#cartBody').empty();

    if (items.length === 0) {
        tbody.append('<tr id="cartEmptyRow"><td colspan="4" class="text-center text-muted py-3">ยังไม่มีรายการ — สแกนแล้วกด "เพิ่มลงใบเบิก"</td></tr>');
        $('#cartFormWrapper').hide();
        return;
    }

    items.forEach((item, index) => {
        const thumb = item.image_path
            ? `<img src="${BASE_URL_JS}${item.image_path}" alt="" style="width:36px;height:36px;object-fit:cover;" class="rounded">`
            : `<span class="text-muted d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;"><i class="bi bi-image"></i></span>`;
        tbody.append(`
            <tr>
                <td>${thumb}</td>
                <td>${item.name}</td>
                <td>${item.display}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-danger" onclick="removeFromCart(${index})"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        `);
    });
    $('#cartFormWrapper').show();
}

function submitRequisition() {
    // #req_purpose isn't inside a <form>, so its `required` attribute alone
    // never gets enforced (there's no submit event for the browser to
    // intercept) - check it explicitly instead.
    const purpose = $('#req_purpose').val().trim();
    if (!purpose) {
        showAlert('กรุณากรอกวัตถุประสงค์ที่ขอเบิกวัสดุเพื่อใช้งาน');
        $('#req_purpose').addClass('is-invalid').trigger('focus');
        return;
    }
    $('#req_purpose').removeClass('is-invalid');

    const payload = {
        action: 'create',
        purpose,
    };

    $.post(REQ_API_URL, payload)
        .done((res) => {
            $('#submitResultBox').html(`
                <div class="alert alert-success mt-3">
                    ${res.message}
                    <a href="${PRINT_URL}?id=${res.requisition_id}" target="_blank" class="btn btn-sm btn-outline-success ms-2">
                        <i class="bi bi-printer"></i> พิมพ์ใบเบิก
                    </a>
                </div>
            `);
            renderCart([]);
            $('#req_purpose').val('');
        })
        .fail((xhr) => showAlert(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'));
}

// Initial cart state, rendered server-side from the session (works even if
// this page load came from scanning another sticker with the phone's own
// camera app, which opens scan.php?qr=... as a brand-new page).
renderCart(<?= json_encode(array_values($draft['items'])) ?>);

// Opened directly from a printed QR code sticker (scan.php?qr=...) - look it up
// immediately instead of waiting for the camera or manual entry.
if (QR_FROM_LINK) {
    $('#manualQr').val(QR_FROM_LINK);
    $(document).ready(() => lookupQr(QR_FROM_LINK));
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
