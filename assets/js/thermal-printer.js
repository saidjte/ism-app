/**
 * ========================================================================
 *  مكتبة الطابعة الحرارية — ESC/POS
 *  Thermal Printer Library for POS Receipt Printers
 *  يدعم: Web Serial API (USB) + طباعة شبكية (TCP/IP)
 * ========================================================================
 *
 *  البروتوكول: ESC/POS (Epson Standard Code for POS Printers)
 *  الأنواع المدعومة:
 *    - طابعات USB عبر Web Serial API (Chrome/Edge 89+)
 *    - طابعات شبكية عبر WebSocket Proxy (IP:Port)
 *    - طباعة عادية عبر window.print() كبديل
 *
 *  عرض الورق: 58mm (32 حرف) أو 80mm (48 حرف)
 */

const ThermalPrinter = (function () {
    'use strict';

    // ===== ثوابت ESC/POS =====
    const ESC = 0x1B;
    const GS = 0x1D;
    const LF = 0x0A;
    const CR = 0x0D;

    // ===== عرض الورق =====
    const PAPER_WIDTHS = {
        '58mm': 32,
        '80mm': 48,
    };

    // ===== محاذاة =====
    const ALIGN = {
        LEFT: 0,
        CENTER: 1,
        RIGHT: 2,
    };

    // ===== حالة الاتصال =====
    let serialPort = null;
    let serialWriter = null;
    let isConnected = false;
    let connectionType = 'serial'; // 'serial' | 'network'

    // ===== إعدادات الطابعة =====
    let printerConfig = {
        paperWidth: '80mm',
        charsPerLine: 48,
        printerIp: '192.168.1.100',
        printerPort: 9100,
        autoCut: true,
        cashDrawer: false,
        encoding: 'utf-8',
    };

    /**
     * تحديث إعدادات الطابعة
     */
    function configure(config) {
        Object.assign(printerConfig, config);
        if (PAPER_WIDTHS[printerConfig.paperWidth]) {
            printerConfig.charsPerLine = PAPER_WIDTHS[printerConfig.paperWidth];
        }
    }

    /**
     * بناء أمر ESC/POS
     */
    function cmd(...bytes) {
        return new Uint8Array(bytes);
    }

    /**
     * تهيئة الطابعة (INIT)
     */
    function init() {
        return cmd(ESC, 0x40);
    }

    /**
     * ضبط المحاذاة
     */
    function setAlign(alignment) {
        return cmd(ESC, 0x61, alignment);
    }

    /**
     * ضبط الخط الغامق
     */
    function setBold(enable) {
        return cmd(ESC, 0x45, enable ? 1 : 0);
    }

    /**
     * ضبط التسطير
     */
    function setUnderline(enable) {
        return cmd(ESC, 0x2D, enable ? 1 : 0);
    }

    /**
     * ضبط حجم الخط (مضاعف العرض والارتفاع)
     */
    function setCharSize(widthMultiplier, heightMultiplier) {
        // القيم: 0 = عادي (1x), 1 = 2x, 2 = 3x, 3 = 4x
        const w = Math.max(0, Math.min(3, widthMultiplier - 1));
        const h = Math.max(0, Math.min(3, heightMultiplier - 1));
        return cmd(ESC, 0x21, (w << 4) | h);
    }

    /**
     * سطر جديد
     */
    function newLine() {
        return cmd(LF);
    }

    /**
     * تغذية أسطر
     */
    function feedLines(count) {
        const cmds = [];
        for (let i = 0; i < count; i++) {
            cmds.push(cmd(LF));
        }
        return cmds;
    }

    /**
     * قص الورق
     */
    function cutPaper(partial) {
        // GS V m: m=0 (كامل), m=1 (جزئي)
        return cmd(GS, 0x56, partial ? 1 : 0);
    }

    /**
     * فتح درج النقود
     */
    function openCashDrawer() {
        // ESC p m t1 t2 — فتح الدرج على Pin 2
        return cmd(ESC, 0x70, 0x00, 0x19, 0xFA);
    }

    /**
     * طباعة نص مع ترميز
     */
    function text(str) {
        // تحويل النص إلى بايتات حسب الترميز
        const encoder = new TextEncoder();
        return encoder.encode(str);
    }

    /**
     * خط فاصل
     */
    function divider(char) {
        char = char || '-';
        return text(char.repeat(printerConfig.charsPerLine));
    }

    /**
     * نص بعرض مزدوج (عريض)
     */
    function doubleWidthText(str) {
        return [
            setCharSize(2, 1),
            text(str),
            setCharSize(1, 1),
        ];
    }

    /**
     * نص بمحاذاة مركزية
     */
    function centeredText(str) {
        return [
            setAlign(ALIGN.CENTER),
            text(str),
            setAlign(ALIGN.LEFT),
        ];
    }

    /**
     * سطر واحد: اسم على اليمين + سعر على اليسار
     */
    function priceLine(label, value, labelWidth) {
        labelWidth = labelWidth || Math.floor(printerConfig.charsPerLine * 0.6);
        const valueWidth = printerConfig.charsPerLine - labelWidth;
        const lbl = label.length > labelWidth ? label.substring(0, labelWidth - 1) + '.' : label.padEnd(labelWidth);
        const val = value.length > valueWidth ? value.substring(value.length - valueWidth) : value.padStart(valueWidth);
        return text(lbl + val);
    }

    /**
     * سطر عنصر في الإيصال: اسم المنتج + الكمية × السعر = الإجمالي
     */
    function receiptItemLine(name, qty, unit, price, total) {
        const lines = [];
        const cw = printerConfig.charsPerLine;

        // السطر الأول: اسم المنتج (يُقطع إذا طويل)
        const maxNameLen = cw;
        const displayName = name.length > maxNameLen ? name.substring(0, maxNameLen - 1) + '.' : name;
        lines.push(text(displayName));

        // السطر الثاني: الكمية × السعر = الإجمالي
        const qtyStr = parseFloat(qty).toFixed(2);
        const priceStr = parseFloat(price).toFixed(2);
        const totalStr = parseFloat(total).toFixed(2);

        const detailLeft = '  ' + qtyStr + (unit ? ' ' + unit : '') + ' x ' + priceStr;
        const detailRight = '= ' + totalStr;

        // محاذاة: التفاصيل على اليمين، الإجمالي على اليسار
        if (detailLeft.length + detailRight.length <= cw) {
            const space = ' '.repeat(cw - detailLeft.length - detailRight.length);
            lines.push(text(detailLeft + space + detailRight));
        } else {
            lines.push(text(detailLeft));
            lines.push(text(detailRight.padStart(cw)));
        }

        return lines;
    }

    /**
     * بناء إيصال كامل بصيغة ESC/POS
     */
    function buildReceipt(receiptData) {
        const parts = [];
        const cw = printerConfig.charsPerLine;

        // تهيئة الطابعة
        parts.push(init());

        // === رأس الإيصال ===
        // اسم المتجر (كبير + مركزي)
        parts.push(setAlign(ALIGN.CENTER));
        parts.push(setBold(true));
        parts.push(setCharSize(2, 2));
        parts.push(text(receiptData.storeName || ''));
        parts.push(setCharSize(1, 1));
        parts.push(setBold(false));
        parts.push(newLine());

        // معلومات المتجر
        if (receiptData.storeAddress) {
            parts.push(text(receiptData.storeAddress));
            parts.push(newLine());
        }
        if (receiptData.storePhone) {
            parts.push(text('Tel: ' + receiptData.storePhone));
            parts.push(newLine());
        }

        parts.push(newLine());

        // التاريخ والوقت
        parts.push(setAlign(ALIGN.LEFT));
        parts.push(text(receiptData.dateTime || ''));
        parts.push(newLine());
        if (receiptData.cashier) {
            parts.push(text(receiptData.cashier));
            parts.push(newLine());
        }
        // v6.1.6: رقم الإيصال
        if (receiptData.receiptNo) {
            parts.push(setBold(true));
            parts.push(text('Receipt No: ' + receiptData.receiptNo));
            parts.push(setBold(false));
            parts.push(newLine());
        }

        // خط فاصل
        parts.push(divider('='));

        // === عناصر الإيصال ===
        if (receiptData.items && receiptData.items.length > 0) {
            for (const item of receiptData.items) {
                const itemLines = receiptItemLine(
                    item.name || '',
                    item.quantity || 0,
                    item.unit || '',
                    item.price || 0,
                    item.total || 0
                );
                parts.push(...itemLines);
                parts.push(newLine());
            }
        }

        // خط فاصل
        parts.push(divider('-'));

        // === الملخص المالي ===
        parts.push(setAlign(ALIGN.LEFT));

        // المجموع الفرعي
        parts.push(priceLine(
            'Subtotal',
            (receiptData.subtotal || 0).toFixed(2)
        ));
        parts.push(newLine());

        // الضريبة
        if (receiptData.tax && receiptData.tax > 0) {
            parts.push(priceLine(
                'Tax',
                receiptData.tax.toFixed(2)
            ));
            parts.push(newLine());
        }

        // الخصم
        if (receiptData.discount && receiptData.discount > 0) {
            parts.push(priceLine(
                'Discount',
                '-' + receiptData.discount.toFixed(2)
            ));
            parts.push(newLine());
        }

        // خط فاصل
        parts.push(divider('-'));

        // الإجمالي (غامق + كبير)
        parts.push(setBold(true));
        parts.push(setCharSize(2, 1));
        parts.push(priceLine(
            'TOTAL',
            (receiptData.total || 0).toFixed(2)
        ));
        parts.push(setCharSize(1, 1));
        parts.push(setBold(false));
        parts.push(newLine());

        // المدفوع
        parts.push(priceLine(
            'Paid',
            (receiptData.paid || 0).toFixed(2)
        ));
        parts.push(newLine());

        // الباقي
        if (receiptData.change && receiptData.change > 0) {
            parts.push(setBold(true));
            parts.push(priceLine(
                'Change',
                receiptData.change.toFixed(2)
            ));
            parts.push(setBold(false));
            parts.push(newLine());
        }

        // خط فاصل
        parts.push(divider('='));

        // === تذييل الإيصال ===
        parts.push(newLine());
        parts.push(setAlign(ALIGN.CENTER));
        parts.push(text(receiptData.footer || 'Thank you!'));
        parts.push(newLine());

        if (receiptData.appName) {
            parts.push(text(receiptData.appName));
            parts.push(newLine());
        }

        // تغذية + قص
        parts.push(...feedLines(3));

        if (printerConfig.autoCut) {
            parts.push(cutPaper(false));
        }

        if (printerConfig.cashDrawer) {
            parts.push(openCashDrawer());
        }

        return parts;
    }

    /**
     * دمج كل الأجزاء في مصفوفة بايت واحدة
     */
    function flattenToBytes(parts) {
        let totalLength = 0;
        for (const part of parts) {
            totalLength += part.length;
        }
        const result = new Uint8Array(totalLength);
        let offset = 0;
        for (const part of parts) {
            result.set(part, offset);
            offset += part.length;
        }
        return result;
    }

    // ================================================================
    //  Web Serial API — طابعات USB
    // ================================================================

    /**
     * فحص دعم المتصفح لـ Web Serial API
     */
    function isWebSerialSupported() {
        return 'serial' in navigator;
    }

    /**
     * اتصال بطابعة USB عبر Web Serial API
     */
    async function connectSerial() {
        if (!isWebSerialSupported()) {
            throw new Error('المتصفح لا يدعم Web Serial API. استخدم Chrome أو Edge.');
        }

        try {
            serialPort = await navigator.serial.requestPort();
            await serialPort.open({ baudRate: 9600 });

            if (serialPort.writable) {
                serialWriter = serialPort.writable.getWriter();
                isConnected = true;
                connectionType = 'serial';
                return true;
            } else {
                throw new Error('المنفذ غير قابل للكتابة');
            }
        } catch (err) {
            serialPort = null;
            serialWriter = null;
            isConnected = false;
            throw err;
        }
    }

    /**
     * قطع اتصال USB
     */
    async function disconnectSerial() {
        try {
            if (serialWriter) {
                await serialWriter.close();
                serialWriter = null;
            }
            if (serialPort) {
                await serialPort.close();
                serialPort = null;
            }
        } catch (e) {
            // تجاهل أخطاء الإغلاق
        }
        isConnected = false;
    }

    /**
     * إرسال بيانات عبر USB Serial
     */
    async function sendSerial(data) {
        if (!serialWriter) throw new Error('الطابعة غير متصلة');
        await serialWriter.write(data);
    }

    // ================================================================
    //  Network Printing — طابعات شبكية (WiFi/Ethernet)
    // ================================================================

    /**
     * إرسال بيانات ESC/POS إلى طابعة شبكية عبر WebSocket Proxy
     * ملاحظة: يتطلب خادم WebSocket وسيط (proxy) يحول WebSocket إلى TCP
     */
    async function sendNetwork(data) {
        return new Promise((resolve, reject) => {
            const wsUrl = 'ws://' + printerConfig.printerIp + ':' + (parseInt(printerConfig.printerPort) + 1);
            let ws;

            try {
                ws = new WebSocket(wsUrl);
            } catch (e) {
                reject(new Error('فشل الاتصال بالطابعة عبر الشبكة. تأكد من عنوان IP والمنفذ.'));
                return;
            }

            const timeout = setTimeout(() => {
                ws.close();
                reject(new Error('انتهت مهلة الاتصال بالطابعة'));
            }, 5000);

            ws.onopen = function () {
                clearTimeout(timeout);
                ws.send(data);
                // انتظر قليلاً قبل الإغلاق لضمان إرسال البيانات
                setTimeout(() => {
                    ws.close();
                    resolve(true);
                }, 500);
            };

            ws.onerror = function () {
                clearTimeout(timeout);
                reject(new Error('فشل الاتصال بالطابعة على ' + printerConfig.printerIp + ':' + printerConfig.printerPort));
            };

            ws.onclose = function () {
                clearTimeout(timeout);
            };
        });
    }

    // ================================================================
    //  طباعة HTML — بديل عادي عبر window.print()
    // ================================================================

    /**
     * طباعة الإيصال عبر window.print() مع تنسيق حراري
     */
    function printHtmlReceipt(receiptData) {
        const cw = printerConfig.charsPerLine;
        const is58mm = printerConfig.paperWidth === '58mm';
        const receiptWidth = is58mm ? '58mm' : '80mm';

        let html = `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<title>Receipt</title>
<style>
  @page { size: ${receiptWidth} auto; margin: 0; }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Courier New', 'Lucida Console', monospace;
    font-size: ${is58mm ? '10px' : '12px'};
    line-height: 1.4;
    width: ${receiptWidth};
    max-width: ${receiptWidth};
    padding: 2mm;
    direction: rtl;
  }
  .center { text-align: center; }
  .bold { font-weight: bold; }
  .big { font-size: ${is58mm ? '14px' : '18px'}; font-weight: bold; }
  .divider { border-top: 1px dashed #000; margin: 4px 0; }
  .item { margin: 2px 0; }
  .item-name { font-weight: bold; }
  .item-detail { font-size: ${is58mm ? '9px' : '10px'}; color: #333; }
  .total-line { display: flex; justify-content: space-between; }
  .grand-total { font-size: ${is58mm ? '14px' : '16px'}; font-weight: bold; }
  .change { font-weight: bold; color: #059669; }
  @media print {
    body { width: ${receiptWidth}; }
    .no-print { display: none; }
  }
</style>
</head>
<body>
<div class="center big">${escapeHtml(receiptData.storeName || '')}</div>
`;

        if (receiptData.storeAddress) {
            html += `<div class="center">${escapeHtml(receiptData.storeAddress)}</div>`;
        }
        if (receiptData.storePhone) {
            html += `<div class="center">Tel: ${escapeHtml(receiptData.storePhone)}</div>`;
        }

        html += `<div class="divider"></div>
<div>${escapeHtml(receiptData.dateTime || '')}</div>
<div>${escapeHtml(receiptData.cashier || '')}</div>
`;

        if (receiptData.receiptNo) {
            html += `<div style="font-weight:700;">${escapeHtml(receiptData.receiptNo)}</div>`;
        }

        html += `<div class="divider"></div>\n`;

        if (receiptData.items) {
            for (const item of receiptData.items) {
                html += `<div class="item">
  <div class="item-name">${escapeHtml(item.name)}</div>
  <div class="item-detail">${item.quantity} ${escapeHtml(item.unit || '')} x ${item.price.toFixed(2)} = ${item.total.toFixed(2)}</div>
</div>`;
            }
        }

        html += `<div class="divider"></div>
<div class="total-line"><span>Subtotal</span><span>${(receiptData.subtotal || 0).toFixed(2)}</span></div>
`;

        if (receiptData.tax && receiptData.tax > 0) {
            html += `<div class="total-line"><span>Tax</span><span>${receiptData.tax.toFixed(2)}</span></div>`;
        }
        if (receiptData.discount && receiptData.discount > 0) {
            html += `<div class="total-line"><span>Discount</span><span>-${receiptData.discount.toFixed(2)}</span></div>`;
        }

        html += `<div class="divider"></div>
<div class="total-line grand-total"><span>TOTAL</span><span>${(receiptData.total || 0).toFixed(2)}</span></div>
<div class="total-line"><span>Paid</span><span>${(receiptData.paid || 0).toFixed(2)}</span></div>
`;

        if (receiptData.change && receiptData.change > 0) {
            html += `<div class="total-line change"><span>Change</span><span>${receiptData.change.toFixed(2)}</span></div>`;
        }

        html += `<div class="divider"></div>
<div class="center" style="margin-top:8px;">${escapeHtml(receiptData.footer || 'Thank you!')}</div>
`;

        if (receiptData.appName) {
            html += `<div class="center" style="font-size:9px;color:#999;">${escapeHtml(receiptData.appName)}</div>`;
        }

        html += `</body></html>`;

        const printWindow = window.open('', '_blank', 'width=400,height=600');
        if (printWindow) {
            printWindow.document.write(html);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => {
                printWindow.print();
                setTimeout(() => printWindow.close(), 500);
            }, 300);
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ================================================================
    //  الواجهة العامة
    // ================================================================

    return {
        /**
         * إعداد الطابعة
         */
        configure: configure,

        /**
         * دعم المتصفح لـ Web Serial
         */
        isWebSerialSupported: isWebSerialSupported,

        /**
         * اتصال بطابعة USB
         */
        connectSerial: connectSerial,

        /**
         * قطع اتصال USB
         */
        disconnectSerial: disconnectSerial,

        /**
         * حالة الاتصال
         */
        isConnected: function () { return isConnected; },

        /**
         * نوع الاتصال الحالي
         */
        getConnectionType: function () { return connectionType; },

        /**
         * طباعة إيصال — الوظيفة الرئيسية
         *
         * @param {Object} receiptData بيانات الإيصال
         * @param {string} receiptData.storeName اسم المتجر
         * @param {string} receiptData.storeAddress عنوان المتجر
         * @param {string} receiptData.storePhone هاتف المتجر
         * @param {string} receiptData.dateTime التاريخ والوقت
         * @param {string} receiptData.cashier اسم الكاشير
         * @param {Array} receiptData.items المنتجات
         * @param {number} receiptData.subtotal المجموع الفرعي
         * @param {number} receiptData.tax الضريبة
         * @param {number} receiptData.discount الخصم
         * @param {number} receiptData.total الإجمالي
         * @param {number} receiptData.paid المدفوع
         * @param {number} receiptData.change الباقي
         * @param {string} receiptData.footer نص التذييل
         * @param {string} receiptData.appName اسم التطبيق
         * @param {string} mode وضع الطباعة: 'thermal-serial' | 'thermal-network' | 'html' | 'browser'
         */
        print: async function (receiptData, mode) {
            mode = mode || 'browser';

            if (mode === 'thermal-serial') {
                // طباعة حرارية عبر USB Serial
                if (!isConnected) {
                    await connectSerial();
                }
                const parts = buildReceipt(receiptData);
                const bytes = flattenToBytes(parts);
                await sendSerial(bytes);

            } else if (mode === 'thermal-network') {
                // طباعة حرارية عبر الشبكة (TCP/IP)
                const parts = buildReceipt(receiptData);
                const bytes = flattenToBytes(parts);
                await sendNetwork(bytes);

            } else if (mode === 'html') {
                // طباعة بتنسيق حراري عبر window.print()
                printHtmlReceipt(receiptData);

            } else {
                // طباعة عادية عبر window.print()
                window.print();
            }
        },

        /**
         * فحص اتصال الطابعة الشبكية
         */
        testNetworkConnection: async function (ip, port) {
            return new Promise((resolve, reject) => {
                const wsUrl = 'ws://' + ip + ':' + (parseInt(port) + 1);
                let ws;
                try {
                    ws = new WebSocket(wsUrl);
                } catch (e) {
                    reject(new Error('فشل الاتصال'));
                    return;
                }
                const timeout = setTimeout(() => {
                    ws.close();
                    reject(new Error('انتهت المهلة'));
                }, 3000);
                ws.onopen = function () {
                    clearTimeout(timeout);
                    ws.close();
                    resolve(true);
                };
                ws.onerror = function () {
                    clearTimeout(timeout);
                    reject(new Error('فشل الاتصال'));
                };
            });
        },

        /**
         * الحصول على إعدادات الطابعة الحالية
         */
        getConfig: function () {
            return { ...printerConfig };
        },

        /**
         * عرض الورق المدعوم
         */
        PAPER_WIDTHS: PAPER_WIDTHS,

        /**
         * ثوابت المحاذاة
         */
        ALIGN: ALIGN,
    };
})();
