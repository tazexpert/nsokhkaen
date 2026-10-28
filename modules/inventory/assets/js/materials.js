const API = INV_URL_JS + 'api/materials_api.php';
const IMPORT_API = INV_URL_JS + 'api/import_api.php';

// If the item's saved storage_location isn't in the current master list
// (e.g. it was removed from Settings), add it so the dropdown still shows
// the real current value instead of silently resetting to blank.
function ensureSelectOption(selectEl, value) {
    if (!value) return;
    const $select = $(selectEl);
    if ($select.find(`option[value="${CSS.escape(value)}"]`).length === 0) {
        $select.append(`<option value="${value}">${value} (ไม่อยู่ในรายการ)</option>`);
    }
    $select.val(value);
}

function imageUrl(path) {
    return path ? BASE_URL_JS + path : '';
}

// Column sort state for the materials table - defaults to รหัส (material_code)
// ascending, toggles direction when the same header is clicked again.
let materialSort = { key: 'material_code', dir: 'asc' };

function sortMaterials(data) {
    const { key, dir } = materialSort;
    const sign = dir === 'asc' ? 1 : -1;
    return [...data].sort((a, b) => {
        const av = a[key] ?? '';
        const bv = b[key] ?? '';
        if (typeof av === 'number' && typeof bv === 'number') {
            return (av - bv) * sign;
        }
        return String(av).localeCompare(String(bv), 'th') * sign;
    });
}

function updateSortIndicators() {
    $('#materialsTable th.sortable i.bi').attr('class', 'bi');
    const $active = $(`#materialsTable th.sortable[data-sort="${materialSort.key}"] i`);
    $active.addClass(materialSort.dir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill');
}

function loadMaterials(keyword = '') {
    const lowStock = $('#lowStockOnly').is(':checked') ? '1' : '';
    $.get(API, { action: 'list', keyword, low_stock: lowStock }, function (res) {
        const tbody = $('#materialsTable tbody').empty();
        updateSortIndicators();
        sortMaterials(res.data).forEach((m) => {
            const lowStock = m.stock_qty <= m.min_stock;
            const thumb = m.image_path
                ? `<img src="${imageUrl(m.image_path)}" alt="" style="width:40px;height:40px;object-fit:cover;" class="rounded">`
                : `<span class="text-muted d-inline-flex align-items-center justify-content-center" style="width:40px;height:40px;"><i class="bi bi-image"></i></span>`;
            const actions = IS_ADMIN ? `
                        <button class="btn btn-sm btn-outline-success" title="รับเข้าสต็อก" onclick="openReceiveStock(${m.id}, '${(m.name || '').replace(/'/g, "\\'")}', ${m.stock_qty}, '${m.unit}')"><i class="bi bi-box-arrow-in-down"></i></button>
                        <button class="btn btn-sm btn-outline-primary" onclick="editMaterial(${m.id})"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteMaterial(${m.id})"><i class="bi bi-trash"></i></button>` : '';
            tbody.append(`
                <tr>
                    <td>${thumb}</td>
                    <td>${m.material_code}</td>
                    <td>${m.name}</td>
                    <td>${m.category_name || '-'}</td>
                    <td class="${lowStock ? 'text-danger fw-bold' : ''}">${m.stock_qty}</td>
                    <td>${m.unit}</td>
                    <td>${Number(m.unit_cost).toLocaleString('th-TH', { minimumFractionDigits: 2 })}</td>
                    <td>${m.storage_location || '-'}</td>
                    <td class="text-end">${actions}</td>
                </tr>
            `);
        });
    });
}

// Cropping state for the material image field. croppedImageBlob (not the
// raw <input type=file> contents) is what actually gets uploaded in
// saveMaterial() below - see the change handler further down for why.
let cropper = null;
let croppedImageBlob = null;
let originalImageDataUrl = null; // lets "ครอปรูปนี้ใหม่" re-open the crop tool on the ORIGINAL image, not an already-cropped one
let preCropPreviewState = null; // { src, visible, blob } snapshot to restore to if the crop dialog is cancelled

function resetMaterialForm() {
    $('#materialForm')[0].reset();
    $('#m_id').val('');
    $('#m_category_code').val('');
    $('#m_image_preview').attr('src', '').hide();
    $('#m_image_recrop').hide();
    $('#m_stock_qty_label').text('จำนวนคงเหลือเริ่มต้น');
    $('#materialModalTitle').text('เพิ่มวัสดุ');
    croppedImageBlob = null;
    originalImageDataUrl = null;
}

function openCropModal() {
    $('#cropImage').attr('src', originalImageDataUrl);
    const modalEl = document.getElementById('cropModal');
    new bootstrap.Modal(modalEl).show();
    // Cropper.js needs the <img> laid out at its final size to measure
    // correctly - 'shown.bs.modal' fires after the modal's CSS transition
    // finishes, unlike a plain .show() callback.
    $(modalEl).one('shown.bs.modal', function () {
        if (cropper) {
            cropper.destroy();
        }
        cropper = new Cropper(document.getElementById('cropImage'), {
            aspectRatio: NaN,
            viewMode: 1,
            autoCropArea: 1,
        });
    });
}

function closeCropModal() {
    bootstrap.Modal.getInstance(document.getElementById('cropModal'))?.hide();
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
}

function confirmCrop() {
    if (!cropper) {
        return;
    }
    const canvas = cropper.getCroppedCanvas({ maxWidth: 1600, maxHeight: 1600 });
    canvas.toBlob((blob) => {
        croppedImageBlob = blob;
        $('#m_image_preview').attr('src', canvas.toDataURL('image/jpeg', 0.92)).show();
        $('#m_image_recrop').show();
        closeCropModal();
    }, 'image/jpeg', 0.92);
}

function cancelCrop() {
    closeCropModal();
    // No image was actually chosen as far as the form is concerned - put
    // everything back exactly as it was before this file was picked, and
    // clear the raw <input> so re-picking the same file still fires 'change'.
    $('#m_image').val('');
    if (preCropPreviewState) {
        $('#m_image_preview').attr('src', preCropPreviewState.src).toggle(preCropPreviewState.visible);
        $('#m_image_recrop').toggle(!!preCropPreviewState.blob);
        croppedImageBlob = preCropPreviewState.blob;
    } else {
        $('#m_image_preview').attr('src', '').hide();
        $('#m_image_recrop').hide();
        croppedImageBlob = null;
    }
    originalImageDataUrl = null;
}

function reopenCrop() {
    if (!originalImageDataUrl) {
        return;
    }
    openCropModal();
}

$('#m_image').on('change', function () {
    const file = this.files[0];
    if (!file) {
        return;
    }
    preCropPreviewState = {
        src: $('#m_image_preview').attr('src') || '',
        visible: $('#m_image_preview').is(':visible'),
        blob: croppedImageBlob,
    };
    const reader = new FileReader();
    reader.onload = (e) => {
        originalImageDataUrl = e.target.result;
        openCropModal();
    };
    reader.readAsDataURL(file);
});

function editMaterial(id) {
    $.get(API, { action: 'get', id }, function (res) {
        const m = res.data;
        $('#m_id').val(m.id);
        $('#m_name').val(m.name);
        $('#m_unit').val(m.unit);
        $('#m_unit_cost').val(m.unit_cost);
        $('#m_stock_qty').val(m.stock_qty);
        $('#m_stock_qty_label').text('จำนวนคงเหลือ');
        $('#m_min_stock').val(m.min_stock);
        ensureSelectOption('#m_storage_location', m.storage_location);
        $('#m_note').val(m.note);
        $('#m_category_code').val(m.category_code || '');
        croppedImageBlob = null;
        originalImageDataUrl = null;
        $('#m_image_recrop').hide();
        if (m.image_path) {
            $('#m_image_preview').attr('src', imageUrl(m.image_path)).show();
        } else {
            $('#m_image_preview').attr('src', '').hide();
        }
        $('#materialModalTitle').text('แก้ไขวัสดุ: ' + m.name);
        new bootstrap.Modal('#materialModal').show();
    });
}

function saveMaterial() {
    const id = $('#m_id').val();
    const formData = new FormData();
    formData.append('action', id ? 'update' : 'create');
    formData.append('id', id);
    formData.append('name', $('#m_name').val());
    formData.append('unit', $('#m_unit').val());
    formData.append('unit_cost', $('#m_unit_cost').val());
    formData.append('stock_qty', $('#m_stock_qty').val());
    formData.append('min_stock', $('#m_min_stock').val());
    formData.append('storage_location', $('#m_storage_location').val());
    formData.append('note', $('#m_note').val());
    formData.append('category_code', $('#m_category_code').val());
    // Uploads the CROPPED result, not the raw file the <input> holds - see
    // the #m_image change handler above, which opens the crop dialog and
    // only ever sets croppedImageBlob once the admin confirms a crop.
    if (croppedImageBlob) {
        formData.append('image', croppedImageBlob, 'crop.jpg');
    }

    $.ajax({
        url: API,
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
    })
        .done((res) => {
            alert(res.message);
            bootstrap.Modal.getInstance(document.getElementById('materialModal')).hide();
            loadMaterials();
        })
        .fail((xhr) => alert(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'));
}

function openReceiveStock(id, name, currentQty, unit) {
    $('#rs_id').val(id);
    $('#rs_current_qty').text(currentQty);
    $('#rs_unit').text(unit || '');
    $('#rs_quantity').val('');
    $('#rs_unit_cost').val('');
    $('#rs_note').val('');
    $('#receiveStockModalTitle').text('รับเข้าสต็อก: ' + name);
    new bootstrap.Modal('#receiveStockModal').show();
}

function saveReceiveStock() {
    const payload = {
        action: 'receive_stock',
        id: $('#rs_id').val(),
        quantity: $('#rs_quantity').val(),
        unit_cost: $('#rs_unit_cost').val(),
        note: $('#rs_note').val(),
    };
    $.post(API, payload)
        .done((res) => {
            alert(res.message);
            bootstrap.Modal.getInstance(document.getElementById('receiveStockModal')).hide();
            loadMaterials();
        })
        .fail((xhr) => alert(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'));
}

function deleteMaterial(id) {
    if (!confirm('ยืนยันการลบรายการนี้?')) return;
    $.post(API, { action: 'delete', id })
        .done((res) => { alert(res.message); loadMaterials(); })
        .fail((xhr) => alert(xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'));
}

function doImport() {
    const formData = new FormData($('#importForm')[0]);
    $.ajax({
        url: IMPORT_API,
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
    }).done((res) => {
        $('#importResult').html(`<div class="alert alert-${res.success ? 'success' : 'danger'}">${res.message}</div>`);
        if (res.success) loadMaterials();
    }).fail((xhr) => {
        $('#importResult').html(`<div class="alert alert-danger">${xhr.responseJSON?.message || 'เกิดข้อผิดพลาด'}</div>`);
    });
}

$('#searchInput').on('input', function () {
    loadMaterials($(this).val());
});

$('#lowStockOnly').on('change', function () {
    loadMaterials($('#searchInput').val());
});

$('#materialsTable thead').on('click', 'th.sortable', function () {
    const key = $(this).data('sort');
    if (materialSort.key === key) {
        materialSort.dir = materialSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
        materialSort = { key, dir: 'asc' };
    }
    loadMaterials($('#searchInput').val());
});

$(document).ready(() => {
    // Came from a dashboard link like materials.php?filter=low_stock
    const params = new URLSearchParams(window.location.search);
    if (params.get('filter') === 'low_stock') {
        $('#lowStockOnly').prop('checked', true);
    }
    loadMaterials($('#searchInput').val());
});
