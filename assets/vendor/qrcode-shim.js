/**
 * QRCode Browser Shim - يوفر واجهة QRCode.toCanvas() المتوافقة مع مكتبة qrcode npm
 * باستخدام مكتبة qrcodejs (davidshimjs/qrcodejs) المحملة محلياً
 *
 * الاستخدام:
 *   QRCode.toCanvas(canvasElement, text, options, callback)
 */

// تحقق من تحميل مكتبة qrcodejs الأساسية
if (typeof QRCode !== 'undefined' && typeof QRCode.toCanvas === 'undefined') {
    // مكتبة qrcodejs محملة لكنها لا تحتوي على toCanvas - نضيفها
    var _QRCodeOriginal = QRCode;

    QRCode.toCanvas = function(canvas, text, options, callback) {
        if (typeof options === 'function') {
            callback = options;
            options = {};
        }
        options = options || {};

        try {
            var width = options.width || 128;
            var margin = options.margin !== undefined ? options.margin : 4;
            var darkColor = (options.color && options.color.dark) || '#000000';
            var lightColor = (options.color && options.color.light) || '#ffffff';

            // استخدام qrcodejs لإنشاء QR Code
            var tempDiv = document.createElement('div');
            tempDiv.style.display = 'none';
            document.body.appendChild(tempDiv);

            var qr = new _QRCodeOriginal(tempDiv, {
                text: text,
                width: width,
                height: width,
                colorDark: darkColor,
                colorLight: lightColor,
                correctLevel: _QRCodeOriginal.CorrectLevel.M
            });

            // نسخ الرسم من canvas الذي أنشأته qrcodejs إلى canvas المطلوب
            setTimeout(function() {
                var srcCanvas = tempDiv.querySelector('canvas');
                if (srcCanvas) {
                    canvas.width = width;
                    canvas.height = width;
                    var ctx = canvas.getContext('2d');

                    // رسم الخلفية
                    ctx.fillStyle = lightColor;
                    ctx.fillRect(0, 0, width, height);

                    // نسخ QR
                    ctx.drawImage(srcCanvas, 0, 0, width, width);

                    // إضافة الهامش إذا لزم الأمر
                    if (margin > 0) {
                        var totalSize = width + margin * 2;
                        var newCanvas = document.createElement('canvas');
                        newCanvas.width = totalSize;
                        newCanvas.height = totalSize;
                        var newCtx = newCanvas.getContext('2d');
                        newCtx.fillStyle = lightColor;
                        newCtx.fillRect(0, 0, totalSize, totalSize);
                        newCtx.drawImage(canvas, margin, margin);
                        canvas.width = totalSize;
                        canvas.height = totalSize;
                        ctx = canvas.getContext('2d');
                        ctx.drawImage(newCanvas, 0, 0);
                    }
                }

                // تنظيف
                document.body.removeChild(tempDiv);

                if (callback) callback(null);
            }, 100);
        } catch(e) {
            if (callback) callback(e);
        }
    };

    QRCode.toString = function(text, options, callback) {
        if (typeof options === 'function') {
            callback = options;
            options = {};
        }
        callback(new Error('toString not supported in browser shim'), null);
    };

    QRCode.toDataURL = function(text, options, callback) {
        if (typeof options === 'function') {
            callback = options;
            options = {};
        }
        options = options || {};
        try {
            var width = options.width || 128;
            var darkColor = (options.color && options.color.dark) || '#000000';
            var lightColor = (options.color && options.color.light) || '#ffffff';

            var tempDiv = document.createElement('div');
            tempDiv.style.display = 'none';
            document.body.appendChild(tempDiv);

            var qr = new _QRCodeOriginal(tempDiv, {
                text: text,
                width: width,
                height: width,
                colorDark: darkColor,
                colorLight: lightColor,
                correctLevel: _QRCodeOriginal.CorrectLevel.M
            });

            setTimeout(function() {
                var srcCanvas = tempDiv.querySelector('canvas');
                var dataUrl = srcCanvas ? srcCanvas.toDataURL('image/png') : '';
                document.body.removeChild(tempDiv);
                if (callback) callback(null, dataUrl);
            }, 100);
        } catch(e) {
            if (callback) callback(e, null);
        }
    };
}
