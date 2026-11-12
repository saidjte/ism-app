/* ============================================================================
 * pages-edit-product-images.js — منطق الصفحة (مستخرج من products/edit-product.php في المرحلة 3 — M3)
 * الإصدار: v=6.3.5
 * يُحمَّل بعد سكربت الجسر المضمّن في edit-product.php (الثوابت + كائن PTXT).
 * ============================================================================ */

// === نظام صور المنتج (تعديل) — رفع مباشر + مرجع: add-product.php ===
var IMAGE_UPLOAD_URL = '../api/upload-product-image.php';
var IMAGE_ACTION_URL = '../api/product-image-action.php';
var pendingImages = [];
var isUploading = false;

// === مستمع إزالة الخلفية ===
document.getElementById('removeBgCheckbox').addEventListener('change', function() {
    document.getElementById('bgToleranceSelect').style.display = this.checked ? 'inline-block' : 'none';
});

// === السحب والإفلات (Drag & Drop) ===
var imageDropZone = document.getElementById('imageDropZone');
if (imageDropZone) {
    ['dragenter', 'dragover'].forEach(function(ev) {
        imageDropZone.addEventListener(ev, function(e) { e.preventDefault(); e.stopPropagation(); imageDropZone.classList.add('drag-over'); });
    });
    ['dragleave', 'drop'].forEach(function(ev) {
        imageDropZone.addEventListener(ev, function(e) { e.preventDefault(); e.stopPropagation(); imageDropZone.classList.remove('drag-over'); });
    });
    imageDropZone.addEventListener('drop', function(e) { handleImageSelect(e.dataTransfer.files); });
}

// === اختيار الصور ورفعها مباشرة ===
function handleImageSelect(files) {
    if (!files || files.length === 0) return;

    var removeBg = document.getElementById('removeBgCheckbox').checked;
    var bgTolerance = document.getElementById('bgToleranceSelect').value;
    var progressDiv = document.getElementById('imageUploadProgress');
    var progressBar = document.getElementById('imageProgressBar');
    var statusText = document.getElementById('imageUploadStatus');
    var noMsg = document.getElementById('noImagesMsg');

    progressDiv.style.display = 'block';
    progressBar.style.width = '0%';
    noMsg.style.display = 'none';
    isUploading = true;

    var total = files.length;
    var uploaded = 0;
    var successfullyUploaded = 0;
    var csrfToken = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

    for (var i = 0; i < files.length; i++) {
        (function(file) {
            var fd = new FormData();
            fd.append('image', file);
            fd.append('product_id', PRODUCT_ID);
            if (csrfToken) fd.append('csrf_token', csrfToken);
            if (removeBg) { fd.append('remove_bg', '1'); fd.append('bg_tolerance', bgTolerance); }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', IMAGE_UPLOAD_URL, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);

            xhr.onload = function() {
                uploaded++;
                progressBar.style.width = Math.round(uploaded / total * 100) + '%';
                statusText.textContent = 'جاري رفع الصور... ' + uploaded + ' من ' + total;
                if (xhr.status === 200) {
                    try {
                        var result = JSON.parse(xhr.responseText);
                        if (result.success) {
                            successfullyUploaded++;
                            addImageToGallery(result);
                        } else {
                            console.error('Upload failed:', result);
                        }
                    } catch(e) {
                        console.error('Parse error:', e);
                    }
                }
                if (uploaded === total) {
                    isUploading = false;
                    setTimeout(function() {
                        progressDiv.style.display = 'none';
                        progressBar.style.width = '0%';
                        statusText.textContent = 'جاري الرفع...';
                        var gallery = document.getElementById('imageGallery');
                        if (gallery && gallery.querySelectorAll('.product-image-item').length > 0) {
                            noMsg.style.display = 'none';
                        } else {
                            noMsg.style.display = '';
                        }
                    }, 600);
                }
            };

            xhr.onerror = function() {
                uploaded++;
                progressBar.style.width = Math.round(uploaded / total * 100) + '%';
                statusText.textContent = 'فشل رفع صورة (' + uploaded + '/' + total + ')';
                if (uploaded === total) {
                    isUploading = false;
                    setTimeout(function() {
                        progressDiv.style.display = 'none';
                        progressBar.style.width = '0%';
                    }, 2000);
                }
            };

            xhr.send(fd);
        })(files[i]);
    }
}

// === حذف صورة معلقة (احتياطي) ===
function removePendingImage(idx) {
    pendingImages.splice(idx, 1);
    renderPendingImages();
}

// === عرض الصور المعلقة (احتياطي) ===
function renderPendingImages() {
    var gallery = document.getElementById('pendingImageGallery');
    var noMsg = document.getElementById('noImagesMsg');
    gallery.innerHTML = '';
    if (pendingImages.length === 0) { noMsg.style.display = ''; return; }
    noMsg.style.display = 'none';
    pendingImages.forEach(function(img, idx) {
        var div = document.createElement('div');
        div.style.cssText = 'width:100px; height:100px; border-radius:10px; overflow:hidden; border:2px solid var(--gray-200); background:#fff; display:flex; align-items:center; justify-content:center; position:relative;';
        div.innerHTML = '<img src="' + img.dataUrl + '" style="max-width:100%; max-height:100%; object-fit:contain;">' +
            '<button type="button" onclick="removePendingImage(' + idx + ')" style="position:absolute; top:2px; left:2px; background:rgba(220,38,38,0.8); color:#fff; border:none; border-radius:50%; width:22px; height:22px; cursor:pointer; font-size:0.7rem; display:flex; align-items:center; justify-content:center;">&times;</button>';
        gallery.appendChild(div);
    });
}

// === دالة مساعدة: الحصول على رمز CSRF ===
function getImageCsrfToken() {
    var csrfField = document.querySelector('input[name="csrf_token"]');
    return csrfField ? csrfField.value : '';
}

// === إضافة صورة مرفوعة حديثاً إلى المعرض ===
function addImageToGallery(result) {
    var gallery = document.getElementById('imageGallery');
    var noMsg = document.getElementById('noImagesMsg');
    if (noMsg) noMsg.style.display = 'none';
    var imageId = result.image_id || result.id;
    var thumbWeb = result.thumbnail_path_web ? BASE_URL + '/' + result.thumbnail_path_web : '';
    var mediumWeb = result.medium_path_web ? BASE_URL + '/' + result.medium_path_web : thumbWeb;
    var isPrimary = gallery.querySelectorAll('.product-image-item').length === 0;
    var div = document.createElement('div');
    div.className = 'product-image-item';
    div.setAttribute('data-image-id', imageId);
    div.style.cssText = 'width:120px; position:relative; border-radius:10px; overflow:hidden; border:2px solid ' + (isPrimary ? 'var(--primary)' : 'var(--gray-200)') + '; background:#fff; cursor:pointer;';
    div.onclick = function() { viewImage(mediumWeb); };
    var html = '<div style="width:120px; height:90px; overflow:hidden; display:flex; align-items:center; justify-content:center; background:var(--gray-50);"><img src="' + thumbWeb + '" alt="صورة" style="max-width:100%; max-height:100%; object-fit:contain;"></div>';
    html += '<div style="padding:4px 6px; font-size:0.7rem; display:flex; justify-content:space-between; align-items:center;">';
    if (isPrimary) html += '<span style="background:var(--primary); color:#fff; padding:1px 5px; border-radius:4px; font-weight:600;">رئيسية</span>';
    html += '<div style="display:flex; gap:4px; margin-' + (isPrimary ? '' : 'right:auto; margin-left:auto;') + '">';
    html += '<button type="button" onclick="event.stopPropagation(); setImagePrimary(' + imageId + ')" title="تعيين كرئيسية" style="background:none; border:none; cursor:pointer; color:var(--gray-400); padding:2px;" onmouseover="this.style.color=\'var(--primary)\'" onmouseout="this.style.color=\'var(--gray-400)\'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></button>';
    html += '<button type="button" onclick="event.stopPropagation(); deleteImage(' + imageId + ')" title="حذف" style="background:none; border:none; cursor:pointer; color:var(--gray-400); padding:2px;" onmouseover="this.style.color=\'#dc2626\'" onmouseout="this.style.color=\'var(--gray-400)\'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg></button>';
    html += '</div></div>';
    div.innerHTML = html;
    gallery.appendChild(div);
}

// === حذف صورة موجودة ===
function deleteImage(imageId) {
    if (!confirm('هل تريد حذف هذه الصورة؟')) return;
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('image_id', imageId);
    var csrfToken = getImageCsrfToken();
    if (csrfToken) fd.append('csrf_token', csrfToken);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', IMAGE_ACTION_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var result = JSON.parse(xhr.responseText);
                if (result.success) {
                    var item = document.querySelector('.product-image-item[data-image-id="' + imageId + '"]');
                    if (item) item.remove();
                    var gallery = document.getElementById('imageGallery');
                    if (gallery && gallery.querySelectorAll('.product-image-item').length === 0) {
                        document.getElementById('noImagesMsg').style.display = '';
                    }
                }
            } catch(e) {}
        }
    };
    xhr.onerror = function() {
        alert('خطأ في الاتصال أثناء حذف الصورة');
    };
    xhr.send(fd);
}

// === تعيين صورة كرئيسية ===
function setImagePrimary(imageId) {
    var fd = new FormData();
    fd.append('action', 'set_primary');
    fd.append('image_id', imageId);
    fd.append('product_id', PRODUCT_ID);
    var csrfToken = getImageCsrfToken();
    if (csrfToken) fd.append('csrf_token', csrfToken);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', IMAGE_ACTION_URL, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (csrfToken) xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var result = JSON.parse(xhr.responseText);
                if (result.success) {
                    document.querySelectorAll('.product-image-item').forEach(function(el) {
                        el.style.borderColor = 'var(--gray-200)';
                        var badge = el.querySelector('span');
                        if (badge) badge.remove();
                    });
                    var item = document.querySelector('.product-image-item[data-image-id="' + imageId + '"]');
                    if (item) {
                        item.style.borderColor = 'var(--primary)';
                        var actionsDiv = item.querySelector('div[style*="display:flex"]');
                        if (actionsDiv && !actionsDiv.querySelector('span')) {
                            var s = document.createElement('span');
                            s.style.cssText = 'background:var(--primary); color:#fff; padding:1px 5px; border-radius:4px; font-weight:600;';
                            s.textContent = 'رئيسية';
                            actionsDiv.insertBefore(s, actionsDiv.firstChild);
                        }
                    }
                }
            } catch(e) {}
        }
    };
    xhr.onerror = function() {
        alert('خطأ في الاتصال أثناء تعيين الصورة الرئيسية');
    };
    xhr.send(fd);
}

// === عرض الصورة مكبرة (Lightbox) ===
function viewImage(src) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:10000; display:flex; align-items:center; justify-content:center; cursor:pointer; backdrop-filter:blur(4px);';
    overlay.onclick = function() { document.body.removeChild(overlay); document.body.style.overflow = ''; };
    var img = document.createElement('img');
    img.src = src;
    img.style.cssText = 'max-width:90vw; max-height:85vh; object-fit:contain; border-radius:12px; box-shadow:0 8px 40px rgba(0,0,0,0.5);';
    img.onclick = function(e) { e.stopPropagation(); };
    overlay.appendChild(img);
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', function handler(e) {
        if (e.key === 'Escape') { if (document.body.contains(overlay)) document.body.removeChild(overlay); document.body.style.overflow = ''; document.removeEventListener('keydown', handler); }
    });
}
