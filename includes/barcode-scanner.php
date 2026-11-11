<?php
/**
 * مكون مسح الباركود بالكاميرا - مشترك بين جميع الصفحات
 * يُستدعى بعد عنصر البحث في الصفحة
 * يتطلب متغير JS: window.onBarcodeScanned(code) ليتم تعريفه في كل صفحة
 */

// حساب base_url
$scanner_dir = dirname($_SERVER['SCRIPT_NAME']);
if (basename($scanner_dir) == 'products' || basename($scanner_dir) == 'purchases' || basename($scanner_dir) == 'sales' || basename($scanner_dir) == 'api' || basename($scanner_dir) == 'users' || basename($scanner_dir) == 'categories' || basename($scanner_dir) == 'units') {
    $scanner_base = rtrim(dirname($scanner_dir), '/');
} else {
    $scanner_base = rtrim($scanner_dir, '/');
}
if (empty($scanner_base)) $scanner_base = '';
?>

<!-- مكتبة مسح الباركود (محلية - بدون إنترنت) -->
<script src="<?php echo $scanner_base; ?>/assets/vendor/html5-qrcode.min.js"></script>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// === متغيرات الماسح ===
var barcodeScanner = null;
var scannerIsActive = false;
var cameraStream = null; // للطريقة البديلة

/**
 * فتح نافذة مسح الباركود
 * @param {HTMLElement} [triggerEl] - العنصر الذي فتح الماسح (يُقرأ منه data-scan-target)
 *
 * نمط data-attribute:
 *   <button data-scan-target="3" onclick="openBarcodeScanner(this)">مسح</button>
 *   <button data-scan-target="individual" onclick="openBarcodeScanner(this)">مسح</button>
 *
 * عند نجاح المسح، يُحفظ الهدف في window._scannerTarget
 * وتستخدمه الصفحة في window.onBarcodeScanned(code, target)
 */
function openBarcodeScanner(triggerEl) {
    // حفظ الهدف من data-scan-target إن وُجد
    if (triggerEl && triggerEl.dataset && triggerEl.dataset.scanTarget !== undefined) {
        window._scannerTarget = triggerEl.dataset.scanTarget;
    } else {
        window._scannerTarget = null;
    }

    if (typeof closeSearchDropdown === 'function') {
        closeSearchDropdown();
    }

    var overlay = document.getElementById('barcodeScannerOverlay');
    if (!overlay) return;
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';

    // إخفاء الرسائل السابقة
    var resultBar = document.getElementById('scannerResultBar');
    var errorDiv = document.getElementById('scannerError');
    var hintDiv = document.getElementById('scannerHint');
    if (resultBar) resultBar.classList.remove('active');
    if (errorDiv) errorDiv.style.display = 'none';
    if (hintDiv) hintDiv.style.display = 'none';

    // إظهار شاشة التشخيص
    document.getElementById('scannerDiagnostic').style.display = 'block';

    // تنظيف الماسح السابق
    stopAllScanners();

    // بدء التشخيص
    setTimeout(function() { diagnoseAndStart(); }, 400);
}

/**
 * تشخيص البيئة وبدء الماسح
 */
function diagnoseAndStart() {
    var diagDiv = document.getElementById('scannerDiagnostic');
    var statusEl = document.getElementById('diagStatus');

    // === فحص 1: دعم getUserMedia ===
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        var isSecure = window.isSecureContext;
        var isLocalhost = window.location.hostname === 'localhost' ||
                          window.location.hostname === '127.0.0.1' ||
                          window.location.protocol === 'https:';

        if (!isSecure && !isLocalhost) {
            showSecureContextError();
        } else {
            showFatalError(
                '<?php echo t('scanner_no_camera_support'); ?>',
                '<?php echo t('scanner_no_camera_support_solution'); ?>'
            );
        }
        return;
    }

    // === فحص 2: تحميل مكتبة المسح ===
    if (typeof Html5Qrcode === 'undefined') {
        // المكتبة لم تُحمّل — نستخدم الطريقة البديلة مباشرة
        startFallbackScanner();
        return;
    }

    // === كل شيء جاهز — ابدأ الماسح ===
    if (statusEl) statusEl.textContent = '<?php echo t('scanner_starting_camera'); ?>';
    startLibraryScanner(); // عرض الحاوية يتم داخل الدالة
}

/**
 * بدء الماسح باستخدام مكتبة html5-qrcode
 */
function startLibraryScanner() {
    var scannerDiv = document.getElementById('barcodeScannerElement');
    var libContainer = document.getElementById('scannerLibraryContainer');

    stopAllScanners();

    // عرض حاوية المكتبة
    if (libContainer) libContainer.style.display = 'block';

    try {
        barcodeScanner = new Html5Qrcode("barcodeScannerElement");
        scannerIsActive = true;

        barcodeScanner.start(
            { facingMode: "environment" },
            { fps: 10 },
            onScanSuccess,
            function onScanFailure(error) {
                // تجاهل أخطاء المسح المتكررة (طبيعية)
            }
        ).then(function() {
            // نجاح تشغيل الكاميرا
            scannerIsActive = true;
            document.getElementById('scannerDiagnostic').style.display = 'none';
            var hintDiv = document.getElementById('scannerHint');
            if (hintDiv) hintDiv.style.display = 'block';
            var errorDiv = document.getElementById('scannerError');
            if (errorDiv) errorDiv.style.display = 'none';
        }).catch(function(err) {
            scannerIsActive = false;
            barcodeScanner = null;

            var errStr = '';
            try { errStr = err.toString(); } catch(e) { errStr = String(err); }
            console.error('خطأ المكتبة:', errStr);

            // إذا فشلت المكتبة، جرب الطريقة البديلة
            startFallbackScanner();
        });
    } catch(err) {
        scannerIsActive = false;
        barcodeScanner = null;
        console.error('خطأ إنشاء الماسح:', err);
        startFallbackScanner();
    }
}

/**
 * الطريقة البديلة: استخدام getUserMedia مباشرة + BarcodeDetector أو إدخال يدوي
 */
function startFallbackScanner() {
    document.getElementById('scannerDiagnostic').style.display = 'none';
    document.getElementById('scannerHint').style.display = 'none';

    var container = document.getElementById('scannerElement');
    var fallbackDiv = document.getElementById('scannerFallback');
    if (!container || !fallbackDiv) return;

    fallbackDiv.style.display = 'flex';

    // تفعيل الكاميرا مباشرة
    var video = document.getElementById('fallbackVideo');
    if (!video) return;

    navigator.mediaDevices.getUserMedia({
        video: { facingMode: "environment", width: { ideal: 1280 }, height: { ideal: 720 } }
    }).then(function(stream) {
        cameraStream = stream;
        video.srcObject = stream;
        video.play();
        scannerIsActive = true;
        document.getElementById('fallbackCaptureBtn').style.display = 'flex';

        // تشغيل BarcodeDetector إذا متوفر (Chrome Android)
        if ('BarcodeDetector' in window) {
            startNativeDetection(video);
        }
    }).catch(function(err) {
        var errStr = '';
        try { errStr = err.toString(); } catch(e) { errStr = String(err); }
        console.error('خطأ الكاميرا البديلة:', errStr);

        // === فحص ما إذا كان الخطأ بسبب Secure Context ===
        var isSecure = window.isSecureContext;
        var isLocalhost = window.location.hostname === 'localhost' ||
                          window.location.hostname === '127.0.0.1' ||
                          window.location.hostname === '[::1]' ||
                          window.location.protocol === 'https:';

        if (!isSecure && !isLocalhost) {
            showSecureContextError();
            return;
        }

        // إظهار رسالة الخطأ مع حل
        if (errStr.indexOf('NotAllowed') !== -1 || errStr.indexOf('denied') !== -1 || errStr.indexOf('Permission') !== -1) {
            showFatalError(
                '<?php echo t('scanner_camera_denied'); ?>',
                '<?php echo t('scanner_camera_denied_solution'); ?>'
            );
        } else if (errStr.indexOf('NotFound') !== -1 || errStr.indexOf('not found') !== -1) {
            showFatalError(
                '<?php echo t('scanner_no_camera_found'); ?>',
                '<?php echo t('scanner_no_camera_found_solution'); ?>'
            );
        } else {
            showFatalError(
                '<?php echo t('scanner_cannot_start_camera'); ?>',
                '<?php echo t('scanner_cannot_start_camera_solution'); ?>'
            );
        }
    });
}

/**
 * تشغيل BarcodeDetector المدمج في Chrome
 */
function startNativeDetection(video) {
    var detector = new BarcodeDetector({ formats: ['code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code'] });
    var scanning = true;

    function detect() {
        if (!scanning || !scannerIsActive) return;
        try {
            detector.detect(video).then(function(barcodes) {
                if (barcodes.length > 0) {
                    scanning = false;
                    onScanSuccess(barcodes[0].rawValue, barcodes[0]);
                } else {
                    requestAnimationFrame(detect);
                }
            }).catch(function() {
                requestAnimationFrame(detect);
            });
        } catch(e) {
            requestAnimationFrame(detect);
        }
    }
    requestAnimationFrame(detect);
}

/**
 * التقاط صورة من الفيديو البديل ومحاولة قراءة الباركود
 */
function captureAndDetect() {
    var video = document.getElementById('fallbackVideo');
    if (!video) return;

    var canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);

    // محاولة مع BarcodeDetector
    if ('BarcodeDetector' in window) {
        var detector = new BarcodeDetector({ formats: ['code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code', 'itf'] });
        detector.detect(canvas).then(function(barcodes) {
            if (barcodes.length > 0) {
                onScanSuccess(barcodes[0].rawValue, barcodes[0]);
            } else {
                showTemporaryMessage('<?php echo t('scanner_barcode_not_recognized'); ?>');
            }
        }).catch(function() {
            showTemporaryMessage('<?php echo t('scanner_recognition_error'); ?>');
        });
    } else {
        showTemporaryMessage('<?php echo t('scanner_no_automatic_detection'); ?>');
    }
}

/**
 * عرض رسالة مؤقتة
 */
function showTemporaryMessage(msg) {
    var hintDiv = document.getElementById('scannerHint');
    if (hintDiv) {
        hintDiv.textContent = msg;
        hintDiv.style.display = 'block';
        hintDiv.style.whiteSpace = 'pre-line';
        hintDiv.style.color = '#fbbf24';
        setTimeout(function() { hintDiv.style.display = 'none'; }, 3000);
    }
}

/**
 * عرض رسالة خطأ نهائية مع زر إغلاق
 */
function showFatalError(title, message) {
    document.getElementById('scannerDiagnostic').style.display = 'none';
    var errorDiv = document.getElementById('scannerError');
    if (!errorDiv) return;

    errorDiv.innerHTML = '';

    var icon = document.createElement('div');
    icon.style.cssText = 'font-size:2.2rem; margin-bottom:10px;';
    icon.textContent = '📷';
    errorDiv.appendChild(icon);

    var titleEl = document.createElement('div');
    titleEl.style.cssText = 'font-size:1rem; font-weight:700; color:#fca5a5; margin-bottom:8px;';
    titleEl.textContent = title;
    errorDiv.appendChild(titleEl);

    var msgEl = document.createElement('div');
    msgEl.style.cssText = 'font-size:0.82rem; line-height:1.7; color:#94a3b8; white-space:pre-line; margin-bottom:14px; text-align:right;';
    msgEl.textContent = message;
    errorDiv.appendChild(msgEl);

    var btnRow = document.createElement('div');
    btnRow.style.cssText = 'display:flex; gap:8px; justify-content:center; flex-wrap:wrap;';

    var retryBtn = document.createElement('button');
    retryBtn.type = 'button';
    retryBtn.onclick = function() {
        errorDiv.style.display = 'none';
        document.getElementById('scannerFallback').style.display = 'none';
        stopAllScanners();
        document.getElementById('scannerDiagnostic').style.display = 'block';
        var statusEl = document.getElementById('diagStatus');
        if (statusEl) statusEl.textContent = '<?php echo t('scanner_diagnosing'); ?>';
        setTimeout(function() { diagnoseAndStart(); }, 300);
    };
    retryBtn.style.cssText = 'background:#3b82f6; color:#fff; border:none; padding:10px 20px; border-radius:8px; font-size:0.85rem; font-weight:600; cursor:pointer; font-family:inherit;';
    retryBtn.textContent = '<?php echo t('scanner_retry'); ?>';
    btnRow.appendChild(retryBtn);

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.onclick = function() { closeBarcodeScanner(); };
    closeBtn.style.cssText = 'background:rgba(255,255,255,0.1); color:#94a3b8; border:1px solid rgba(255,255,255,0.2); padding:10px 20px; border-radius:8px; font-size:0.85rem; cursor:pointer; font-family:inherit;';
    closeBtn.textContent = '✕ <?php echo t('close'); ?>';
    btnRow.appendChild(closeBtn);

    errorDiv.appendChild(btnRow);
    errorDiv.style.display = 'block';
}

/**
 * عرض رسالة خاصة لمشكلة Secure Context مع حلول مفصلة
 */
function showSecureContextError() {
    document.getElementById('scannerDiagnostic').style.display = 'none';

    var errorDiv = document.getElementById('scannerError');
    if (!errorDiv) return;

    var ip = window.location.hostname;

    errorDiv.innerHTML = '';

    // الأيقونة
    var icon = document.createElement('div');
    icon.style.cssText = 'font-size:2.2rem; margin-bottom:8px;';
    icon.textContent = '🔒';
    errorDiv.appendChild(icon);

    // العنوان
    var titleEl = document.createElement('div');
    titleEl.style.cssText = 'font-size:0.95rem; font-weight:700; color:#fca5a5; margin-bottom:12px; line-height:1.5;';
    titleEl.textContent = '<?php echo t('scanner_browser_blocks_camera'); ?>';
    errorDiv.appendChild(titleEl);

    // === الحل 1: الأسرع — إعداد Chrome Flags ===
    var sol1 = document.createElement('div');
    sol1.style.cssText = 'background:rgba(59,130,246,0.15); border:1px solid rgba(59,130,246,0.3); border-radius:10px; padding:12px; margin-bottom:10px; text-align:right;';
    sol1.innerHTML =
        '<div style="color:#93c5fd; font-weight:700; font-size:0.88rem; margin-bottom:6px;">⚡ <?php echo t('scanner_solution_1_fastest'); ?></div>' +
        '<div style="color:#cbd5e1; font-size:0.78rem; line-height:1.8;">' +
        '<?php echo t('scanner_solution_1_step1'); ?><br>' +
        '<code style="background:#1e293b; padding:2px 8px; border-radius:4px; font-size:0.75rem; direction:ltr; display:inline-block; margin-top:4px;">chrome://flags/#unsafely-treat-insecure-origin-as-secure</code><br>' +
        '<?php echo t('scanner_solution_1_step2'); ?><br>' +
        '<code style="background:#1e293b; padding:2px 8px; border-radius:4px; font-size:0.75rem; direction:ltr; display:inline-block; margin-top:4px;">http://' + ip + '</code><br>' +
        '<?php echo t('scanner_solution_1_step3'); ?><br>' +
        '<?php echo t('scanner_solution_1_step4'); ?><br>' +
        '<?php echo t('scanner_solution_1_step5'); ?><br>' +
        '<span style="color:#fbbf24; margin-top:4px; display:block;">✅ <?php echo t('scanner_solution_1_note'); ?></span>' +
        '</div>';
    errorDiv.appendChild(sol1);

    // === الحل 2: إعداد HTTPS على WampServer ===
    var sol2 = document.createElement('div');
    sol2.style.cssText = 'background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.3); border-radius:10px; padding:12px; margin-bottom:10px; text-align:right;';
    sol2.innerHTML =
        '<div style="color:#86efac; font-weight:700; font-size:0.88rem; margin-bottom:6px;">🛡️ <?php echo t('scanner_solution_2_https'); ?></div>' +
        '<div style="color:#cbd5e1; font-size:0.78rem; line-height:1.8;">' +
        '<?php echo t('scanner_solution_2_step1'); ?><br>' +
        '<code style="background:#1e293b; padding:2px 6px; border-radius:4px; font-size:0.72rem; direction:ltr; display:inline-block; margin-top:4px;">github.com/FiloSottile/mkcert</code><br>' +
        '<?php echo t('scanner_solution_2_step2'); ?><br>' +
        '<code style="background:#1e293b; padding:2px 6px; border-radius:4px; font-size:0.72rem; direction:ltr; display:inline-block; margin-top:4px;">mkcert -install</code><br>' +
        '<code style="background:#1e293b; padding:2px 6px; border-radius:4px; font-size:0.72rem; direction:ltr; display:inline-block; margin-top:2px;">mkcert ' + ip + ' localhost</code><br>' +
        '<?php echo t('scanner_solution_2_step3'); ?><br>' +
        '<code style="background:#1e293b; padding:2px 6px; border-radius:4px; font-size:0.72rem; direction:ltr; display:inline-block; margin-top:4px;">https://' + ip + '/is-app/</code><br>' +
        '</div>';
    errorDiv.appendChild(sol2);

    // === الحل 3: تثبيت APK ===
    var sol3 = document.createElement('div');
    sol3.style.cssText = 'background:rgba(251,191,36,0.1); border:1px solid rgba(251,191,36,0.3); border-radius:10px; padding:12px; margin-bottom:14px; text-align:right;';
    sol3.innerHTML =
        '<div style="color:#fcd34d; font-weight:700; font-size:0.88rem; margin-bottom:6px;">📱 <?php echo t('scanner_solution_3_apk'); ?></div>' +
        '<div style="color:#cbd5e1; font-size:0.78rem; line-height:1.8;">' +
        '<?php echo t('scanner_solution_3_step1'); ?><br>' +
        '<?php echo t('scanner_solution_3_step2'); ?><br>' +
        '<span style="color:#94a3b8;">(<?php echo t('scanner_solution_3_note'); ?>)</span>' +
        '</div>';
    errorDiv.appendChild(sol3);

    // زر إغلاق
    var btnRow = document.createElement('div');
    btnRow.style.cssText = 'display:flex; gap:8px; justify-content:center; flex-wrap:wrap;';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.onclick = function() { closeBarcodeScanner(); };
    closeBtn.style.cssText = 'background:rgba(255,255,255,0.1); color:#94a3b8; border:1px solid rgba(255,255,255,0.2); padding:10px 20px; border-radius:8px; font-size:0.85rem; cursor:pointer; font-family:inherit;';
    closeBtn.textContent = '✕ <?php echo t('close'); ?>';
    btnRow.appendChild(closeBtn);

    errorDiv.appendChild(btnRow);
    errorDiv.style.display = 'block';
}

/**
 * إيقاف جميع المسحات
 */
function stopAllScanners() {
    scannerIsActive = false;

    // إيقاف html5-qrcode
    if (barcodeScanner) {
        try {
            barcodeScanner.stop().then(function() {
                try { barcodeScanner.clear(); } catch(e) {}
            }).catch(function() {
                try { barcodeScanner.clear(); } catch(e) {}
            });
        } catch(e) {
            try { barcodeScanner.clear(); } catch(e2) {}
        }
        barcodeScanner = null;
    }

    // إيقاف الكاميرا البديلة
    if (cameraStream) {
        try {
            cameraStream.getTracks().forEach(function(t) { t.stop(); });
        } catch(e) {}
        cameraStream = null;
    }

    // إخفاء الفيديو البديل
    var video = document.getElementById('fallbackVideo');
    if (video) { video.srcObject = null; }
    var fallbackDiv = document.getElementById('scannerFallback');
    if (fallbackDiv) fallbackDiv.style.display = 'none';
}

/**
 * عند نجاح المسح
 */
function onScanSuccess(decodedText, decodedResult) {
    // منع التكرار
    if (onScanSuccess._last === decodedText && (Date.now() - onScanSuccess._t) < 3000) return;
    onScanSuccess._last = decodedText;
    onScanSuccess._t = Date.now();

    // إظهار النتيجة
    var resultBar = document.getElementById('scannerResultBar');
    var resultCode = document.getElementById('scannerResultCode');
    if (resultBar && resultCode) {
        resultCode.textContent = decodedText;
        resultBar.classList.add('active');
    }

    if (navigator.vibrate) navigator.vibrate(100);

    // إغلاق ثم معالجة
    setTimeout(function() {
        var scannedTarget = window._scannerTarget || null;
        closeBarcodeScanner();
        if (typeof window.onBarcodeScanned === 'function') {
            window.onBarcodeScanned(decodedText.trim(), scannedTarget);
        }
    }, 600);
}

/**
 * إغلاق نافذة المسح
 */
function closeBarcodeScanner() {
    var overlay = document.getElementById('barcodeScannerOverlay');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
    stopAllScanners();

    // إعادة تعيين حالات العرض
    var fallbackDiv = document.getElementById('scannerFallback');
    if (fallbackDiv) fallbackDiv.style.display = 'none';
}

/**
 * تبديل الفلاش (للطريقة البديلة)
 */
function toggleScannerFlash() {
    var video = null;

    // محاولة من html5-qrcode
    video = document.querySelector('#barcodeScannerElement video');
    // أو من الفيديو البديل
    if (!video) video = document.getElementById('fallbackVideo');

    if (!video || !video.srcObject) return;

    var tracks = video.srcObject.getVideoTracks();
    tracks.forEach(function(track) {
        try {
            var caps = track.getCapabilities ? track.getCapabilities() : {};
            if (caps.torch) {
                var current = track.getSettings().torch || false;
                track.applyConstraints({ advanced: [{ torch: !current }] }).catch(function() {});
            }
        } catch(e) {}
    });
}
</script>

<!-- نافذة مسح الباركود (Overlay) -->
<div class="barcode-scanner-overlay" id="barcodeScannerOverlay">
    <div class="scanner-header">
        <h3>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22">
                <path d="M3 7V5a2 2 0 012-2h2"/>
                <path d="M17 3h2a2 2 0 012 2v2"/>
                <path d="M21 17v2a2 2 0 01-2 2h-2"/>
                <path d="M7 21H5a2 2 0 01-2-2v-2"/>
                <line x1="7" y1="12" x2="17" y2="12"/>
            </svg>
            <?php echo t('scanner_title'); ?>
        </h3>
        <button class="scanner-close-btn" onclick="closeBarcodeScanner()" title="<?php echo t('close'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="scanner-body" id="scannerElement">

        <!-- شاشة تشخيص سريعة -->
        <div id="scannerDiagnostic" style="display:none; text-align:center; padding:20px;">
            <div style="font-size:2rem; margin-bottom:12px;">🔍</div>
            <div id="diagStatus" style="color:#94a3b8; font-size:0.9rem;"><?php echo t('scanner_diagnosing'); ?></div>
        </div>

        <!-- ماسح المكتبة -->
        <div class="scanner-container" style="display:none;" id="scannerLibraryContainer">
            <div id="barcodeScannerElement"></div>
        </div>

        <!-- كاميرا بديلة (مباشرة) -->
        <div id="scannerFallback" style="display:none; width:100%; flex-direction:column; align-items:center;">
            <div style="width:100%; max-width:400px; border-radius:12px; overflow:hidden; background:#000; position:relative;">
                <video id="fallbackVideo" autoplay playsinline muted style="width:100%; display:block; border-radius:12px;"></video>
                <!-- خطوط التوجيه -->
                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:280px; height:100px; border:2px solid rgba(255,255,255,0.5); border-radius:8px; box-shadow: 0 0 0 9999px rgba(0,0,0,0.3);"></div>
            </div>
            <button type="button" class="scanner-flash-btn" id="fallbackCaptureBtn" onclick="captureAndDetect()" style="display:none; margin-top:12px; background:rgba(59,130,246,0.9); border:none; color:white; padding:12px 28px; border-radius:10px; cursor:pointer; font-size:0.95rem; font-weight:700; font-family:inherit; gap:8px;">
                📸 <?php echo t('scanner_capture_and_scan'); ?>
            </button>
        </div>

        <!-- رسالة الخطأ -->
        <div class="scanner-error" id="scannerError" style="display:none;"></div>

        <!-- نص المساعدة -->
        <div class="scanner-hint-text" id="scannerHint">
            <?php echo t('scanner_hint_text'); ?>
        </div>

        <!-- شريط النتيجة -->
        <div class="scanner-result-bar" id="scannerResultBar">
            <div class="result-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" width="18" height="18"><path d="M5 13l4 4L19 7"/></svg>
            </div>
            <span class="result-code" id="scannerResultCode"></span>
        </div>

        <!-- زر الفلاش -->
        <button class="scanner-flash-btn" onclick="toggleScannerFlash()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
            </svg>
            <?php echo t('scanner_flash'); ?>
        </button>
    </div>
</div>
