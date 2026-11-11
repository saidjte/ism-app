<?php if ($print_mode): ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة ملصقات الأرفف</title>
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/fonts/cairo.css">
    <script src="<?php echo $base_url; ?>/assets/vendor/qrcode.min.js"></script>
    <script src="<?php echo $base_url; ?>/assets/vendor/qrcode-shim.js"></script>
    <style>
        /* === تنسيق صفحة اختيار المنتجات === */
        <?php if (!$print_mode): ?>
        .shelf-note {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #92400e;
        }
        .shelf-note svg {
            flex-shrink: 0;
        }
        .label-settings {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .label-settings .form-group {
            margin-bottom: 0;
        }
        .layout-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
        }
        .layout-option {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            position: relative;
        }
        .layout-option:hover {
            border-color: var(--primary-light);
            background: #f0f9ff;
        }
        .layout-option.active {
            border-color: var(--primary);
            background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .layout-option input[type="radio"] {
            display: none;
        }
        .layout-option .layout-icon {
            font-size: 1.8rem;
            margin-bottom: 0.3rem;
        }
        .layout-option .layout-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--gray-800);
        }
        .layout-option .layout-desc {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 2px;
        }
        .layout-option .layout-size {
            font-size: 0.8rem;
            color: var(--primary);
            font-weight: 600;
            margin-top: 4px;
        }
        .layout-option .badge-recommended {
            position: absolute;
            top: -8px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 10px;
            white-space: nowrap;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--gray-700);
            padding: 4px 0;
        }
        .checkbox-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }
        .radio-label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--gray-700);
            padding: 4px 0;
        }
        .radio-label input[type="radio"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }
        .qty-input {
            width: 70px !important;
            text-align: center !important;
            font-size: 1.05rem !important;
            font-weight: 800 !important;
            padding: 6px 4px !important;
            color: var(--gray-900) !important;
            background: #fff !important;
            border: 2px solid var(--primary) !important;
            border-radius: 6px !important;
        }
        .quick-add-btn {
            width: 28px;
            height: 28px;
            min-width: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-light);
            color: var(--primary);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 700;
            padding: 0;
            transition: var(--transition);
        }
        .quick-add-btn:hover {
            background: var(--primary);
            color: var(--white);
            transform: scale(1.1);
        }
        .qty-cell {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .row-selected {
            background: #eff6ff !important;
        }
        .row-selected td {
            border-bottom-color: #bfdbfe !important;
        }
        .product-check {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .add-all-btn {
            background: var(--secondary-light);
            color: var(--secondary-dark);
            border: 1px solid #a7f3d0;
            padding: 6px 12px;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: var(--transition);
        }
        .add-all-btn:hover {
            background: var(--secondary);
            color: var(--white);
        }
        .selected-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            color: var(--white);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 8px;
        }
        /* === اختيار فردي === */
        .individual-section {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 2px solid #86efac;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        .individual-section .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            font-weight: 700;
            color: #166534;
            margin-bottom: 0.75rem;
        }
        .individual-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .individual-form .form-group {
            margin-bottom: 0;
            flex: 1;
            min-width: 200px;
        }
        .individual-form .form-group.qty-group {
            flex: 0 0 100px;
            min-width: 80px;
        }
        .individual-form label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #166534;
            margin-bottom: 4px;
            display: block;
        }
        .individual-add-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #059669;
            color: #fff;
            border: none;
            padding: 9px 18px;
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
        }
        .individual-add-btn:hover {
            background: #047857;
            transform: translateY(-1px);
        }
        .individual-add-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
        }
        .individual-list {
            margin-top: 0.75rem;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .individual-list:empty {
            display: none;
        }
        .individual-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff;
            border: 1.5px solid #86efac;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #166534;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .individual-item .item-name {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .individual-item .item-qty {
            background: #059669;
            color: #fff;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
        }
        .individual-item .remove-item-btn {
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            padding: 0;
            display: flex;
            align-items: center;
            font-size: 1rem;
            line-height: 1;
        }
        .individual-item .remove-item-btn:hover {
            color: #dc2626;
            transform: scale(1.2);
        }
        .individual-empty-msg {
            font-size: 0.8rem;
            color: #6b7280;
            text-align: center;
            padding: 6px 0;
        }
        .individual-search-dropdown {
            position: relative;
        }
        .individual-search-dropdown .dropdown-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 50;
            display: none;
        }
        .individual-search-dropdown .dropdown-results.show {
            display: block;
        }
        .individual-search-dropdown .dropdown-item {
            padding: 8px 12px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s;
            font-size: 0.85rem;
        }
        .individual-search-dropdown .dropdown-item:last-child {
            border-bottom: none;
        }
        .individual-search-dropdown .dropdown-item:hover,
        .individual-search-dropdown .dropdown-item.active {
            background: #f0fdf4;
        }
        .individual-search-dropdown .dropdown-item .di-name {
            font-weight: 600;
            color: #0f172a;
        }
        .individual-search-dropdown .dropdown-item .di-code {
            font-family: monospace;
            font-size: 0.75rem;
            color: #64748b;
            background: #f1f5f9;
            padding: 1px 6px;
            border-radius: 4px;
        }
        /* === قسم التصفية والاستثناء === */
        .filter-section {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 2px solid #7dd3fc;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        .filter-section .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            font-weight: 700;
            color: #0369a1;
            margin-bottom: 0.75rem;
        }
        .filter-mode-toggle {
            display: inline-flex;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #e2e8f0;
        }
        .filter-mode-toggle button {
            padding: 4px 12px;
            font-size: 0.75rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 4px;
            background: #fff;
            color: #64748b;
        }
        .filter-mode-toggle button.active-include {
            background: #059669;
            color: #fff;
        }
        .filter-mode-toggle button.active-exclude {
            background: #dc2626;
            color: #fff;
        }
        .filter-item {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem;
            position: relative;
        }
        .filter-item.filter-item-active {
            border-color: #7dd3fc;
            box-shadow: 0 0 0 2px rgba(125,211,252,0.15);
        }
        .filter-item.filter-item-active.filter-item-exclude {
            border-color: #fca5a5;
            box-shadow: 0 0 0 2px rgba(252,165,165,0.15);
        }
        .filter-item-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            gap: 8px;
        }
        .filter-item-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .filter-item-label svg {
            flex-shrink: 0;
        }
        .filter-controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 0.75rem;
        }
        @media (max-width: 600px) {
            .filter-controls { grid-template-columns: 1fr; }
        }
        .filter-category-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }
        .filter-cat-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid transparent;
            transition: var(--transition);
            color: #fff;
            opacity: 0.5;
        }
        .filter-cat-chip.active {
            opacity: 1;
            border-color: #0f172a;
            box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
        }
        .filter-cat-chip:hover {
            opacity: 0.85;
        }
        .filter-price-row {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-top: 6px;
        }
        .filter-price-row input {
            flex: 1;
            text-align: center;
            font-weight: 700;
        }
        .filter-price-row .price-sep {
            color: #94a3b8;
            font-weight: 700;
        }
        .filter-summary {
            margin-top: 0.75rem;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            display: none;
        }
        .filter-summary.show { display: block; }
        .filter-summary.include-summary {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        .filter-summary.exclude-summary {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .filter-clear-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            background: #f1f5f9;
            color: #64748b;
            border: none;
            transition: var(--transition);
        }
        .filter-clear-btn:hover { background: #e2e8f0; color: #334155; }

        /* === ترتيب عناصر الملصق === */
        .element-arrange-section {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border: 2px solid #cbd5e1;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin-top: 1rem;
        }
        .element-arrange-section .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 0.75rem;
        }
        .element-arrange-section .section-hint {
            font-size: 0.72rem;
            color: #94a3b8;
            font-weight: 400;
        }
        .element-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .element-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1.5px solid #e2e8f0;
            background: #fff;
            transition: all 0.2s ease;
        }
        .element-item.element-hidden {
            opacity: 0.5;
            background: #f8fafc;
        }
        .element-item .el-icon {
            font-size: 1rem;
            width: 24px;
            text-align: center;
            flex-shrink: 0;
        }
        .element-item .el-name {
            flex: 1;
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
        }
        .element-item.element-hidden .el-name {
            text-decoration: line-through;
            color: #94a3b8;
        }
        .element-item .el-actions {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        .element-item .el-arrow-btn {
            width: 26px;
            height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            cursor: pointer;
            color: #64748b;
            font-size: 0.75rem;
            padding: 0;
            transition: all 0.15s ease;
        }
        .element-item .el-arrow-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        .element-item .el-arrow-btn:disabled {
            opacity: 0.25;
            cursor: not-allowed;
        }
        .element-item .el-toggle-btn {
            padding: 3px 10px;
            border-radius: 5px;
            font-size: 0.7rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .element-item .el-toggle-btn.btn-visible {
            background: #dcfce7;
            color: #166534;
        }
        .element-item .el-toggle-btn.btn-visible:hover {
            background: #bbf7d0;
        }
        .element-item .el-toggle-btn.btn-hidden {
            background: #fee2e2;
            color: #991b1b;
        }
        .element-item .el-toggle-btn.btn-hidden:hover {
            background: #fecaca;
        }
        .element-arrange-note {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #e2e8f0;
        }
        @media (max-width: 768px) {
            .element-item {
                padding: 6px 8px;
            }
            .element-item .el-name {
                font-size: 0.8rem;
            }
        }

        /* === الوافقية مع الهواتف === */
        @media (max-width: 768px) {
            .layout-options {
                grid-template-columns: repeat(3, 1fr);
            }
            .layout-option {
                padding: 0.65rem 0.5rem;
            }
            .layout-option .layout-icon {
                font-size: 1.3rem;
            }
            .layout-option .layout-name {
                font-size: 0.78rem;
            }
            .layout-option .layout-desc {
                font-size: 0.65rem;
            }
            .layout-option .layout-size {
                font-size: 0.7rem;
            }
            .label-settings {
                grid-template-columns: 1fr;
            }
            .shelf-note {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
                font-size: 0.82rem;
                padding: 0.85rem 1rem;
            }
            .shelf-note svg {
                align-self: center;
            }
            /* الإحصائيات */
            .stats-grid {
                grid-template-columns: 1fr !important;
            }
            .stat-card {
                padding: 0.75rem !important;
            }
            /* قسم الاختيار الفردي */
            .individual-form {
                flex-direction: column;
                align-items: stretch;
            }
            .individual-form .form-group {
                min-width: 100% !important;
                flex: none !important;
            }
            .individual-form .form-group.qty-group {
                flex: none !important;
                width: 100%;
            }
            .individual-form .qty-group {
                display: flex;
                gap: 10px;
                align-items: flex-end;
            }
            .individual-form .qty-group .qty-input {
                flex: 1;
            }
            .individual-add-btn {
                width: 100%;
                justify-content: center;
                padding: 10px 16px;
                font-size: 0.95rem;
            }
            .individual-item .item-name {
                max-width: 140px;
            }
            /* جدول المنتجات */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            table {
                font-size: 0.8rem;
                min-width: 600px;
            }
            table th {
                padding: 6px 4px;
                font-size: 0.72rem;
                white-space: nowrap;
            }
            table td {
                padding: 6px 4px;
                vertical-align: middle;
            }
            /* الكود في الجدول */
            table td span[style*="monospace"] {
                font-size: 0.72rem !important;
            }
            /* اسم المتجر */
            .form-control[placeholder*="متجر"] {
                max-width: 100% !important;
            }
            /* أزرار الطباعة */
            .btn-lg {
                width: 100%;
                justify-content: center;
                font-size: 0.95rem;
                padding: 12px 16px;
            }
        }
        @media (max-width: 480px) {
            .layout-options {
                grid-template-columns: repeat(2, 1fr);
            }
            .layout-option .badge-recommended {
                font-size: 0.55rem;
                padding: 1px 7px;
            }
            .filter-controls {
                grid-template-columns: 1fr !important;
            }
            .filter-section .section-title {
                flex-wrap: wrap;
                font-size: 0.9rem;
            }
            .filter-section .section-title span[style*="font-size:0.72rem"] {
                width: 100%;
                text-align: center;
                margin-top: 2px;
            }
            .filter-cat-chip {
                font-size: 0.72rem;
                padding: 3px 8px;
            }
            .card-header h2 {
                flex-wrap: wrap;
                gap: 6px;
                font-size: 0.95rem;
            }
            .card-header > div[style*="flex"] {
                width: 100%;
                justify-content: stretch;
            }
            .card-header .enhanced-search-input {
                max-width: 100% !important;
                flex: 1;
            }
            .card-header .add-all-btn {
                flex-shrink: 0;
            }
            .qty-input {
                width: 56px !important;
                font-size: 0.9rem !important;
                padding: 4px 2px !important;
            }
            .quick-add-btn {
                width: 32px;
                height: 32px;
                font-size: 1.1rem;
            }
            .selected-count {
                font-size: 0.72rem;
                padding: 1px 7px;
            }
        }
        <?php endif; ?>

        /* === تنسيق الملصقات للطباعة === */
        <?php if ($print_mode): ?>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f1f5f9;
            direction: rtl;
        }

        /* --- شريط الأدوات --- */
        .no-print {
            padding: 12px 20px;
            background: #fff;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            direction: rtl;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .no-print .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 28px;
            background: #059669;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }
        .no-print .btn-print:hover {
            background: #047857;
        }
        .no-print .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
        }
        .no-print .btn-back:hover {
            background: #1d4ed8;
        }
        .no-print .print-info {
            display: flex;
            gap: 20px;
            align-items: center;
            color: #475569;
            font-size: 0.9rem;
        }
        .no-print .print-info strong {
            color: #0f172a;
        }

        /* --- الصفحات --- */
        .print-pages {
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            padding: 5mm;
            background: #fff;
            page-break-after: always;
            position: relative;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .a4-page:last-child {
            page-break-after: auto;
        }
        .labels-grid {
            display: grid;
            grid-template-columns: repeat(<?php echo $cols; ?>, 1fr);
            gap: 3mm;
            height: calc(297mm - 10mm);
            align-content: start;
        }

        /* --- بطاقة الملصق (للرفوف) --- */
        .label-card {
            border: 1.5px solid #1e293b;
            border-radius: 4mm;
            padding: 3mm 4mm;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            direction: rtl;
            min-height: <?php echo $current_layout['label_h']; ?>mm;
            position: relative;
            background: #ffffff;
        }
        .label-card .store-name {
            font-size: <?php echo max(8, $current_layout['name_size'] - 5); ?>pt;
            color: #475569;
            text-align: center;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 1mm;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 1mm;
            width: 100%;
        }

        /* اسم المنتج - كبير وواضح بخط Cairo */
        .label-card .product-name {
            font-family: 'Cairo', Arial, Tahoma, sans-serif;
            font-size: <?php echo $boost_name; ?>pt;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            line-height: 1.3;
            margin-bottom: 1.5mm;
            word-break: break-word;
            text-align: center;
            width: 100%;
        }

        /* السعر - الأكبر دائماً بخط Cairo */
        .label-card .product-price {
            font-family: 'Cairo', Arial, Tahoma, sans-serif;
            font-size: <?php echo $boost_price; ?>pt;
            font-weight: 900;
            color: #dc2626;
            text-align: center;
            line-height: 1.1;
            letter-spacing: -0.5px;
            text-shadow: 0.5px 0.5px 0 rgba(0,0,0,0.05);
        }
        .label-card .product-price .currency {
            font-size: 0.5em;
            font-weight: 700;
            color: #991b1b;
            margin-right: 2px;
        }
        /* وحدة القياس بجانب السعر */
        .label-card .product-price.price-with-unit {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 2mm;
        }
        .label-card .unit-badge {
            font-size: <?php echo max(8, (int)($current_layout['price_size'] * 0.38)); ?>pt;
            font-weight: 700;
            color: #1e40af;
            background: #dbeafe;
            border: 1px solid #93c5fd;
            padding: 0.5mm 2.5mm;
            border-radius: 2mm;
            white-space: nowrap;
        }

        /* سعر الشراء */
        .label-card .product-price-purchase {
            font-size: <?php echo max(9, $current_layout['code_size']); ?>pt;
            color: #64748b;
            text-align: center;
            font-weight: 600;
            margin-top: 1mm;
        }

        /* الكود */
        .label-card .product-code-text {
            font-size: <?php echo $current_layout['code_size']; ?>pt;
            color: #64748b;
            text-align: center;
            font-family: 'Courier New', monospace;
            font-weight: 700;
            background: #f1f5f9;
            padding: 1mm 3mm;
            border-radius: 2mm;
            margin-top: 1mm;
        }

        /* الباركود */
        .label-card .barcode-container {
            text-align: center;
            margin-top: 1.5mm;
        }
        .label-card .barcode-container svg {
            max-width: 90%;
            height: auto;
        }

        /* التصنيف */
        .label-card .product-category {
            font-size: <?php echo max(8, $current_layout['code_size']); ?>pt;
            color: #fff;
            text-align: center;
            padding: 0.8mm 4mm;
            border-radius: 2mm;
            margin-top: 1mm;
            font-weight: 700;
            width: fit-content;
        }

        /* تذييل الملصق */
        .label-card .label-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-top: auto;
            padding-top: 1mm;
        }
        .label-card .label-unit {
            font-size: <?php echo max(8, $current_layout['code_size'] - 1); ?>pt;
            color: #94a3b8;
            font-weight: 600;
        }

        /* ختم التاريخ */
        .label-card .label-date {
            font-size: <?php echo max(7, $current_layout['code_size'] - 2); ?>pt;
            color: #64748b;
            font-weight: 600;
            text-align: center;
            margin-top: 1mm;
            direction: ltr;
        }

        /* حاوية QR Code */
        .label-card .qrcode-container {
            text-align: center;
            margin-top: 1.5mm;
        }
        .label-card .qrcode-container canvas {
            max-width: <?php echo min(90, $current_layout['barcode_height'] * 1.8); ?>px;
            height: auto !important;
        }

        /* ملصق فارغ */
        .empty-label {
            border: 1.5px dashed #cbd5e1;
            border-radius: 4mm;
            min-height: <?php echo $current_layout['label_h']; ?>mm;
        }

        /* === طباعة === */
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #fff;
            }
            .print-pages {
                padding: 0;
                gap: 0;
            }
            .a4-page {
                margin: 0;
                padding: 5mm;
                width: 210mm;
                box-shadow: none;
                border: none;
            }
            .label-card {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .label-card .product-category {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .label-card .product-code-text {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .label-card .unit-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
        <?php endif; ?>
        .enhanced-search-input {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .enhanced-search-input .search-icon {
            position: absolute;
            left: 12px;
            color: #9ca3af;
            pointer-events: none;
            z-index: 1;
        }
        .enhanced-search-input input {
            padding-left: 36px;
            padding-right: 36px;
        }
        .enhanced-search-input .search-clear-btn-inline {
            position: absolute;
            right: 6px;
            background: none;
            border: none;
            padding: 4px;
            color: #9ca3af;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s, visibility 0.2s;
            display: flex;
            align-items: center;
            z-index: 1;
        }
        .enhanced-search-input .search-clear-btn-inline.visible {
            opacity: 1;
            visibility: visible;
        }
        .enhanced-search-input .search-clear-btn-inline:hover {
            color: #6b7280;
        }
        /* زر مسح الباركود في حقل الفلتر */
        .print-labels-scan-btn {
            position: absolute;
            left: 6px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: var(--primary);
            border-radius: 4px;
            display: flex;
            align-items: center;
            z-index: 2;
            opacity: 0.7;
        }
        .print-labels-scan-btn:hover {
            color: var(--primary-dark);
            background: var(--primary-light);
            opacity: 1;
        }
        .enhanced-search-input input { padding-left: 70px !important; }
    </style>
</head>
<body>
    <!-- ===== وضع الطباعة ===== -->
    <div class="no-print">
        <button class="btn-print" onclick="window.print()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            طباعة الملصقات
        </button>
        <a href="print-labels.php" class="btn-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1"/></svg>
            العودة
        </a>
        <div class="print-info">
            <span>الملصقات: <strong><?php echo count($labels_data); ?></strong></span>
            <span>الصفحات: <strong><?php echo count($pages); ?></strong></span>
            <span>الحجم: <strong><?php echo $current_layout['name']; ?></strong></span>
        </div>
    </div>

    <div class="print-pages">
        <?php foreach ($pages as $page_idx => $page_labels): ?>
        <div class="a4-page">
            <div class="labels-grid">
                <?php for ($i = 0; $i < $per_page; $i++): ?>
                    <?php if (isset($page_labels[$i])): ?>
                        <?php $p = $page_labels[$i]; ?>
                        <div class="label-card">
                            <?php
                            $has_additional = !empty($p['additional_units']);
                            foreach ($visible_elements as $elId):
                                echo renderLabelElement($elId, $p, $current_layout, $store_name, $show_purchase, $show_barcode, $show_code, $show_category, $price_display, $currency, $has_additional, $unit_with_price, $code_type, $barcode_format, $show_date);
                            endforeach;
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-label"></div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- مكتبة الباركود (محلية - بدون إنترنت) -->
    <script src="<?php echo $base_url; ?>/assets/vendor/jsbarcode.min.js"></script>
    <script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
    // توليد الباركود / QR لكل ملصق
    document.addEventListener('DOMContentLoaded', function() {
        // توليد الباركود (خطوط)
        document.querySelectorAll('svg[id^="barcode-"]').forEach(function(svg) {
            var value = svg.getAttribute('data-value');
            var format = svg.getAttribute('data-format') || 'CODE128';
            if (value && typeof JsBarcode !== 'undefined') {
                try {
                    JsBarcode(svg, value, {
                        format: format,
                        width: 2,
                        height: <?php echo $current_layout['barcode_height']; ?>,
                        displayValue: true,
                        fontSize: <?php echo $current_layout['barcode_font']; ?>,
                        margin: 2,
                        textMargin: 2,
                        font: "Courier New, monospace",
                        fontOptions: "bold"
                    });
                } catch(e) {
                    // إذا فشل التنسيق المحدد، جرب CODE128
                    try {
                        JsBarcode(svg, value, {
                            format: "CODE128",
                            width: 2,
                            height: <?php echo $current_layout['barcode_height']; ?>,
                            displayValue: true,
                            fontSize: <?php echo $current_layout['barcode_font']; ?>,
                            margin: 2,
                            textMargin: 2,
                            font: "Courier New, monospace",
                            fontOptions: "bold"
                        });
                    } catch(e2) {
                        svg.outerHTML = '<span style="font-family:monospace; font-size:12pt; color:#334155; text-align:center; font-weight:bold;">' + value + '</span>';
                    }
                }
            }
        });

        // توليد QR Codes
        document.querySelectorAll('canvas[id^="qrcode-"]').forEach(function(canvas) {
            var value = canvas.getAttribute('data-value');
            var size = Math.min(<?php echo min(90, $current_layout['barcode_height'] * 1.8); ?>, 120);
            if (value && typeof QRCode !== 'undefined') {
                try {
                    QRCode.toCanvas(canvas, value, {
                        width: size,
                        margin: 1,
                        color: { dark: '#0f172a', light: '#ffffff' }
                    }, function(error) {
                        if (error) {
                            canvas.outerHTML = '<span style="font-family:monospace; font-size:10pt; color:#334155; text-align:center; font-weight:bold;">' + value + '</span>';
                        }
                    });
                } catch(e) {
                    canvas.outerHTML = '<span style="font-family:monospace; font-size:10pt; color:#334155; text-align:center; font-weight:bold;">' + value + '</span>';
                }
            }
        });
    });
    </script>

</body>
</html>
<?php else: ?>
    <style>
        /* === تنسيق صفحة اختيار المنتجات === */
        <?php if (!$print_mode): ?>
        .shelf-note {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #92400e;
        }
        .shelf-note svg {
            flex-shrink: 0;
        }
        .label-settings {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .label-settings .form-group {
            margin-bottom: 0;
        }
        .layout-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
        }
        .layout-option {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            position: relative;
        }
        .layout-option:hover {
            border-color: var(--primary-light);
            background: #f0f9ff;
        }
        .layout-option.active {
            border-color: var(--primary);
            background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .layout-option input[type="radio"] {
            display: none;
        }
        .layout-option .layout-icon {
            font-size: 1.8rem;
            margin-bottom: 0.3rem;
        }
        .layout-option .layout-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--gray-800);
        }
        .layout-option .layout-desc {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 2px;
        }
        .layout-option .layout-size {
            font-size: 0.8rem;
            color: var(--primary);
            font-weight: 600;
            margin-top: 4px;
        }
        .layout-option .badge-recommended {
            position: absolute;
            top: -8px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 10px;
            white-space: nowrap;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--gray-700);
            padding: 4px 0;
        }
        .checkbox-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }
        .radio-label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--gray-700);
            padding: 4px 0;
        }
        .radio-label input[type="radio"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }
        .qty-input {
            width: 70px !important;
            text-align: center !important;
            font-size: 1.05rem !important;
            font-weight: 800 !important;
            padding: 6px 4px !important;
            color: var(--gray-900) !important;
            background: #fff !important;
            border: 2px solid var(--primary) !important;
            border-radius: 6px !important;
        }
        .quick-add-btn {
            width: 28px;
            height: 28px;
            min-width: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-light);
            color: var(--primary);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 700;
            padding: 0;
            transition: var(--transition);
        }
        .quick-add-btn:hover {
            background: var(--primary);
            color: var(--white);
            transform: scale(1.1);
        }
        .qty-cell {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .row-selected {
            background: #eff6ff !important;
        }
        .row-selected td {
            border-bottom-color: #bfdbfe !important;
        }
        .product-check {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .add-all-btn {
            background: var(--secondary-light);
            color: var(--secondary-dark);
            border: 1px solid #a7f3d0;
            padding: 6px 12px;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: var(--transition);
        }
        .add-all-btn:hover {
            background: var(--secondary);
            color: var(--white);
        }
        .selected-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            color: var(--white);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 8px;
        }
        /* === اختيار فردي === */
        .individual-section {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 2px solid #86efac;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        .individual-section .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            font-weight: 700;
            color: #166534;
            margin-bottom: 0.75rem;
        }
        .individual-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .individual-form .form-group {
            margin-bottom: 0;
            flex: 1;
            min-width: 200px;
        }
        .individual-form .form-group.qty-group {
            flex: 0 0 100px;
            min-width: 80px;
        }
        .individual-form label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #166534;
            margin-bottom: 4px;
            display: block;
        }
        .individual-add-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #059669;
            color: #fff;
            border: none;
            padding: 9px 18px;
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
        }
        .individual-add-btn:hover {
            background: #047857;
            transform: translateY(-1px);
        }
        .individual-add-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
        }
        .individual-list {
            margin-top: 0.75rem;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .individual-list:empty {
            display: none;
        }
        .individual-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff;
            border: 1.5px solid #86efac;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #166534;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .individual-item .item-name {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .individual-item .item-qty {
            background: #059669;
            color: #fff;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
        }
        .individual-item .remove-item-btn {
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            padding: 0;
            display: flex;
            align-items: center;
            font-size: 1rem;
            line-height: 1;
        }
        .individual-item .remove-item-btn:hover {
            color: #dc2626;
            transform: scale(1.2);
        }
        .individual-empty-msg {
            font-size: 0.8rem;
            color: #6b7280;
            text-align: center;
            padding: 6px 0;
        }
        .individual-search-dropdown {
            position: relative;
        }
        .individual-search-dropdown .dropdown-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 50;
            display: none;
        }
        .individual-search-dropdown .dropdown-results.show {
            display: block;
        }
        .individual-search-dropdown .dropdown-item {
            padding: 8px 12px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s;
            font-size: 0.85rem;
        }
        .individual-search-dropdown .dropdown-item:last-child {
            border-bottom: none;
        }
        .individual-search-dropdown .dropdown-item:hover,
        .individual-search-dropdown .dropdown-item.active {
            background: #f0fdf4;
        }
        .individual-search-dropdown .dropdown-item .di-name {
            font-weight: 600;
            color: #0f172a;
        }
        .individual-search-dropdown .dropdown-item .di-code {
            font-family: monospace;
            font-size: 0.75rem;
            color: #64748b;
            background: #f1f5f9;
            padding: 1px 6px;
            border-radius: 4px;
        }
        /* === قسم التصفية والاستثناء === */
        .filter-section {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 2px solid #7dd3fc;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        .filter-section .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            font-weight: 700;
            color: #0369a1;
            margin-bottom: 0.75rem;
        }
        .filter-mode-toggle {
            display: inline-flex;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #e2e8f0;
        }
        .filter-mode-toggle button {
            padding: 4px 12px;
            font-size: 0.75rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 4px;
            background: #fff;
            color: #64748b;
        }
        .filter-mode-toggle button.active-include {
            background: #059669;
            color: #fff;
        }
        .filter-mode-toggle button.active-exclude {
            background: #dc2626;
            color: #fff;
        }
        .filter-item {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem;
            position: relative;
        }
        .filter-item.filter-item-active {
            border-color: #7dd3fc;
            box-shadow: 0 0 0 2px rgba(125,211,252,0.15);
        }
        .filter-item.filter-item-active.filter-item-exclude {
            border-color: #fca5a5;
            box-shadow: 0 0 0 2px rgba(252,165,165,0.15);
        }
        .filter-item-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            gap: 8px;
        }
        .filter-item-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .filter-item-label svg {
            flex-shrink: 0;
        }
        .filter-controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 0.75rem;
        }
        @media (max-width: 600px) {
            .filter-controls { grid-template-columns: 1fr; }
        }
        .filter-category-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }
        .filter-cat-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid transparent;
            transition: var(--transition);
            color: #fff;
            opacity: 0.5;
        }
        .filter-cat-chip.active {
            opacity: 1;
            border-color: #0f172a;
            box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
        }
        .filter-cat-chip:hover {
            opacity: 0.85;
        }
        .filter-price-row {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-top: 6px;
        }
        .filter-price-row input {
            flex: 1;
            text-align: center;
            font-weight: 700;
        }
        .filter-price-row .price-sep {
            color: #94a3b8;
            font-weight: 700;
        }
        .filter-summary {
            margin-top: 0.75rem;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            display: none;
        }
        .filter-summary.show { display: block; }
        .filter-summary.include-summary {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        .filter-summary.exclude-summary {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .filter-clear-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            background: #f1f5f9;
            color: #64748b;
            border: none;
            transition: var(--transition);
        }
        .filter-clear-btn:hover { background: #e2e8f0; color: #334155; }

        /* === ترتيب عناصر الملصق === */
        .element-arrange-section {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border: 2px solid #cbd5e1;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin-top: 1rem;
        }
        .element-arrange-section .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 0.75rem;
        }
        .element-arrange-section .section-hint {
            font-size: 0.72rem;
            color: #94a3b8;
            font-weight: 400;
        }
        .element-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .element-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1.5px solid #e2e8f0;
            background: #fff;
            transition: all 0.2s ease;
        }
        .element-item.element-hidden {
            opacity: 0.5;
            background: #f8fafc;
        }
        .element-item .el-icon {
            font-size: 1rem;
            width: 24px;
            text-align: center;
            flex-shrink: 0;
        }
        .element-item .el-name {
            flex: 1;
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
        }
        .element-item.element-hidden .el-name {
            text-decoration: line-through;
            color: #94a3b8;
        }
        .element-item .el-actions {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        .element-item .el-arrow-btn {
            width: 26px;
            height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            cursor: pointer;
            color: #64748b;
            font-size: 0.75rem;
            padding: 0;
            transition: all 0.15s ease;
        }
        .element-item .el-arrow-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        .element-item .el-arrow-btn:disabled {
            opacity: 0.25;
            cursor: not-allowed;
        }
        .element-item .el-toggle-btn {
            padding: 3px 10px;
            border-radius: 5px;
            font-size: 0.7rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .element-item .el-toggle-btn.btn-visible {
            background: #dcfce7;
            color: #166534;
        }
        .element-item .el-toggle-btn.btn-visible:hover {
            background: #bbf7d0;
        }
        .element-item .el-toggle-btn.btn-hidden {
            background: #fee2e2;
            color: #991b1b;
        }
        .element-item .el-toggle-btn.btn-hidden:hover {
            background: #fecaca;
        }
        .element-arrange-note {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #e2e8f0;
        }
        @media (max-width: 768px) {
            .element-item {
                padding: 6px 8px;
            }
            .element-item .el-name {
                font-size: 0.8rem;
            }
        }

        /* === الوافقية مع الهواتف === */
        @media (max-width: 768px) {
            .layout-options {
                grid-template-columns: repeat(3, 1fr);
            }
            .layout-option {
                padding: 0.65rem 0.5rem;
            }
            .layout-option .layout-icon {
                font-size: 1.3rem;
            }
            .layout-option .layout-name {
                font-size: 0.78rem;
            }
            .layout-option .layout-desc {
                font-size: 0.65rem;
            }
            .layout-option .layout-size {
                font-size: 0.7rem;
            }
            .label-settings {
                grid-template-columns: 1fr;
            }
            .shelf-note {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
                font-size: 0.82rem;
                padding: 0.85rem 1rem;
            }
            .shelf-note svg {
                align-self: center;
            }
            /* الإحصائيات */
            .stats-grid {
                grid-template-columns: 1fr !important;
            }
            .stat-card {
                padding: 0.75rem !important;
            }
            /* قسم الاختيار الفردي */
            .individual-form {
                flex-direction: column;
                align-items: stretch;
            }
            .individual-form .form-group {
                min-width: 100% !important;
                flex: none !important;
            }
            .individual-form .form-group.qty-group {
                flex: none !important;
                width: 100%;
            }
            .individual-form .qty-group {
                display: flex;
                gap: 10px;
                align-items: flex-end;
            }
            .individual-form .qty-group .qty-input {
                flex: 1;
            }
            .individual-add-btn {
                width: 100%;
                justify-content: center;
                padding: 10px 16px;
                font-size: 0.95rem;
            }
            .individual-item .item-name {
                max-width: 140px;
            }
            /* جدول المنتجات */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            table {
                font-size: 0.8rem;
                min-width: 600px;
            }
            table th {
                padding: 6px 4px;
                font-size: 0.72rem;
                white-space: nowrap;
            }
            table td {
                padding: 6px 4px;
                vertical-align: middle;
            }
            /* الكود في الجدول */
            table td span[style*="monospace"] {
                font-size: 0.72rem !important;
            }
            /* اسم المتجر */
            .form-control[placeholder*="متجر"] {
                max-width: 100% !important;
            }
            /* أزرار الطباعة */
            .btn-lg {
                width: 100%;
                justify-content: center;
                font-size: 0.95rem;
                padding: 12px 16px;
            }
        }
        @media (max-width: 480px) {
            .layout-options {
                grid-template-columns: repeat(2, 1fr);
            }
            .layout-option .badge-recommended {
                font-size: 0.55rem;
                padding: 1px 7px;
            }
            .filter-controls {
                grid-template-columns: 1fr !important;
            }
            .filter-section .section-title {
                flex-wrap: wrap;
                font-size: 0.9rem;
            }
            .filter-section .section-title span[style*="font-size:0.72rem"] {
                width: 100%;
                text-align: center;
                margin-top: 2px;
            }
            .filter-cat-chip {
                font-size: 0.72rem;
                padding: 3px 8px;
            }
            .card-header h2 {
                flex-wrap: wrap;
                gap: 6px;
                font-size: 0.95rem;
            }
            .card-header > div[style*="flex"] {
                width: 100%;
                justify-content: stretch;
            }
            .card-header .enhanced-search-input {
                max-width: 100% !important;
                flex: 1;
            }
            .card-header .add-all-btn {
                flex-shrink: 0;
            }
            .qty-input {
                width: 56px !important;
                font-size: 0.9rem !important;
                padding: 4px 2px !important;
            }
            .quick-add-btn {
                width: 32px;
                height: 32px;
                font-size: 1.1rem;
            }
            .selected-count {
                font-size: 0.72rem;
                padding: 1px 7px;
            }
        }
        <?php endif; ?>

        /* === تنسيق الملصقات للطباعة === */
        <?php if ($print_mode): ?>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f1f5f9;
            direction: rtl;
        }

        /* --- شريط الأدوات --- */
        .no-print {
            padding: 12px 20px;
            background: #fff;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            direction: rtl;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .no-print .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 28px;
            background: #059669;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }
        .no-print .btn-print:hover {
            background: #047857;
        }
        .no-print .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
        }
        .no-print .btn-back:hover {
            background: #1d4ed8;
        }
        .no-print .print-info {
            display: flex;
            gap: 20px;
            align-items: center;
            color: #475569;
            font-size: 0.9rem;
        }
        .no-print .print-info strong {
            color: #0f172a;
        }

        /* --- الصفحات --- */
        .print-pages {
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            padding: 5mm;
            background: #fff;
            page-break-after: always;
            position: relative;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .a4-page:last-child {
            page-break-after: auto;
        }
        .labels-grid {
            display: grid;
            grid-template-columns: repeat(<?php echo $cols; ?>, 1fr);
            gap: 3mm;
            height: calc(297mm - 10mm);
            align-content: start;
        }

        /* --- بطاقة الملصق (للرفوف) --- */
        .label-card {
            border: 1.5px solid #1e293b;
            border-radius: 4mm;
            padding: 3mm 4mm;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            direction: rtl;
            min-height: <?php echo $current_layout['label_h']; ?>mm;
            position: relative;
            background: #ffffff;
        }
        .label-card .store-name {
            font-size: <?php echo max(8, $current_layout['name_size'] - 5); ?>pt;
            color: #475569;
            text-align: center;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 1mm;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 1mm;
            width: 100%;
        }

        /* اسم المنتج - كبير وواضح بخط Cairo */
        .label-card .product-name {
            font-family: 'Cairo', Arial, Tahoma, sans-serif;
            font-size: <?php echo $boost_name; ?>pt;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            line-height: 1.3;
            margin-bottom: 1.5mm;
            word-break: break-word;
            text-align: center;
            width: 100%;
        }

        /* السعر - الأكبر دائماً بخط Cairo */
        .label-card .product-price {
            font-family: 'Cairo', Arial, Tahoma, sans-serif;
            font-size: <?php echo $boost_price; ?>pt;
            font-weight: 900;
            color: #dc2626;
            text-align: center;
            line-height: 1.1;
            letter-spacing: -0.5px;
            text-shadow: 0.5px 0.5px 0 rgba(0,0,0,0.05);
        }
        .label-card .product-price .currency {
            font-size: 0.5em;
            font-weight: 700;
            color: #991b1b;
            margin-right: 2px;
        }
        /* وحدة القياس بجانب السعر */
        .label-card .product-price.price-with-unit {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 2mm;
        }
        .label-card .unit-badge {
            font-size: <?php echo max(8, (int)($current_layout['price_size'] * 0.38)); ?>pt;
            font-weight: 700;
            color: #1e40af;
            background: #dbeafe;
            border: 1px solid #93c5fd;
            padding: 0.5mm 2.5mm;
            border-radius: 2mm;
            white-space: nowrap;
        }

        /* سعر الشراء */
        .label-card .product-price-purchase {
            font-size: <?php echo max(9, $current_layout['code_size']); ?>pt;
            color: #64748b;
            text-align: center;
            font-weight: 600;
            margin-top: 1mm;
        }

        /* الكود */
        .label-card .product-code-text {
            font-size: <?php echo $current_layout['code_size']; ?>pt;
            color: #64748b;
            text-align: center;
            font-family: 'Courier New', monospace;
            font-weight: 700;
            background: #f1f5f9;
            padding: 1mm 3mm;
            border-radius: 2mm;
            margin-top: 1mm;
        }

        /* الباركود */
        .label-card .barcode-container {
            text-align: center;
            margin-top: 1.5mm;
        }
        .label-card .barcode-container svg {
            max-width: 90%;
            height: auto;
        }

        /* التصنيف */
        .label-card .product-category {
            font-size: <?php echo max(8, $current_layout['code_size']); ?>pt;
            color: #fff;
            text-align: center;
            padding: 0.8mm 4mm;
            border-radius: 2mm;
            margin-top: 1mm;
            font-weight: 700;
            width: fit-content;
        }

        /* تذييل الملصق */
        .label-card .label-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-top: auto;
            padding-top: 1mm;
        }
        .label-card .label-unit {
            font-size: <?php echo max(8, $current_layout['code_size'] - 1); ?>pt;
            color: #94a3b8;
            font-weight: 600;
        }

        /* ختم التاريخ */
        .label-card .label-date {
            font-size: <?php echo max(7, $current_layout['code_size'] - 2); ?>pt;
            color: #64748b;
            font-weight: 600;
            text-align: center;
            margin-top: 1mm;
            direction: ltr;
        }

        /* حاوية QR Code */
        .label-card .qrcode-container {
            text-align: center;
            margin-top: 1.5mm;
        }
        .label-card .qrcode-container canvas {
            max-width: <?php echo min(90, $current_layout['barcode_height'] * 1.8); ?>px;
            height: auto !important;
        }

        /* ملصق فارغ */
        .empty-label {
            border: 1.5px dashed #cbd5e1;
            border-radius: 4mm;
            min-height: <?php echo $current_layout['label_h']; ?>mm;
        }

        /* === طباعة === */
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #fff;
            }
            .print-pages {
                padding: 0;
                gap: 0;
            }
            .a4-page {
                margin: 0;
                padding: 5mm;
                width: 210mm;
                box-shadow: none;
                border: none;
            }
            .label-card {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .label-card .product-category {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .label-card .product-code-text {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .label-card .unit-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
        <?php endif; ?>
        .enhanced-search-input {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .enhanced-search-input .search-icon {
            position: absolute;
            left: 12px;
            color: #9ca3af;
            pointer-events: none;
            z-index: 1;
        }
        .enhanced-search-input input {
            padding-left: 36px;
            padding-right: 36px;
        }
        .enhanced-search-input .search-clear-btn-inline {
            position: absolute;
            right: 6px;
            background: none;
            border: none;
            padding: 4px;
            color: #9ca3af;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s, visibility 0.2s;
            display: flex;
            align-items: center;
            z-index: 1;
        }
        .enhanced-search-input .search-clear-btn-inline.visible {
            opacity: 1;
            visibility: visible;
        }
        .enhanced-search-input .search-clear-btn-inline:hover {
            color: #6b7280;
        }
        /* زر مسح الباركود في حقل الفلتر */
        .print-labels-scan-btn {
            position: absolute;
            left: 6px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: var(--primary);
            border-radius: 4px;
            display: flex;
            align-items: center;
            z-index: 2;
            opacity: 0.7;
        }
        .print-labels-scan-btn:hover {
            color: var(--primary-dark);
            background: var(--primary-light);
            opacity: 1;
        }
        .enhanced-search-input input { padding-left: 70px !important; }
    </style>
        <!-- ===== وضع الاختيار ===== -->
    <div class="container">
        <div class="page-header">
            <h1>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28">
                    <path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                طباعة ملصقات الأرفف (Balisage)
            </h1>
        </div>

        <!-- ملاحظة مهمة -->
        <div class="shelf-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>هذه الملصقات مصممة خصيصاً للأرفف بحجم كبير ومقروء من مسافة متوسطة. يتم الطباعة على ورق A4.</span>
        </div>

        <!-- إحصائيات -->
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
            <div class="stat-card blue">
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <div class="stat-info"><h3><?php echo t('labels_total_products'); ?></h3><div class="stat-value"><?php echo count($all_products); ?></div></div>
            </div>
            <div class="stat-card cyan">
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <div class="stat-info"><h3><?php echo t('labels_selected_labels'); ?></h3><div class="stat-value" id="totalLabels">0</div></div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <div class="stat-info"><h3><?php echo t('labels_a4_pages'); ?></h3><div class="stat-value" id="totalPages">0</div></div>
            </div>
        </div>

        <form method="POST" action="" id="labelForm">
            <input type="hidden" name="print_labels" value="1">
            <!-- حاوية المنتجات الفردية -->
            <div id="individualHiddenFields"></div>

            <!-- إعدادات الطباعة -->
            <div class="card">
                <div class="card-header">
                    <h2>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        إعدادات الطباعة
                    </h2>
                </div>
                <div class="card-body">
                    <!-- اسم المتجر -->
                    <div class="form-group" style="margin-bottom:1.25rem;">
                        <label>اسم المتجر <span style="color:#94a3b8; font-weight:400;">(يظهر أعلى الملصق)</span></label>
                        <input type="text" name="store_name" class="form-control" placeholder="مثال: متجر الأمانة" value="" style="max-width:400px;">
                    </div>

                    <!-- خيار عرض السعر بالوحدة -->
                    <div class="form-group" style="margin-bottom:1.25rem;">
                        <label>عرض السعر حسب الوحدة</label>
                        <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin-top: 6px;">
                            <label class="radio-label">
                                <input type="radio" name="price_display" value="basic" checked>
                                الوحدة الأساسية فقط
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="price_display" value="additional">
                                الوحدة الإضافية فقط
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="price_display" value="multiple">
                                متعدد (أساسية + إضافية)
                            </label>
                        </div>
                        <div style="margin-top:6px; font-size:0.8rem; color:#94a3b8;">
                            الوحدة الأساسية = وحدة المنتج الأساسية &nbsp;|&nbsp; الوحدة الإضافية = الوحدات الثانوية (كرتونة، علبة...)
                        </div>
                    </div>

                    <!-- تنسيق الملصق -->
                    <div class="form-group" style="margin-bottom:1.25rem;">
                        <label>حجم الملصق</label>
                        <div class="layout-options">
                            <?php foreach ($layouts as $key => $layout): ?>
                            <label class="layout-option <?php echo $key == 'large' ? 'active' : ''; ?>" id="lo-<?php echo $key; ?>">
                                <?php if ($key == 'large'): ?>
                                <span class="badge-recommended">موصى به</span>
                                <?php endif; ?>
                                <input type="radio" name="label_layout" value="<?php echo $key; ?>" <?php echo $key == 'large' ? 'checked' : ''; ?> onchange="selectLayout('<?php echo $key; ?>', <?php echo $layout['cols'] * $layout['rows']; ?>)">
                                <div class="layout-name"><?php echo $layout['name']; ?></div>
                                <div class="layout-desc"><?php echo $layout['desc']; ?></div>
                                <div class="layout-size"><?php echo $layout['cols'] * $layout['rows']; ?> ملصق / صفحة</div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- خيارات العرض -->
                    <div class="form-group">
                        <label>محتوى الملصق</label>
                        <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin-top: 6px;">
                            <label class="checkbox-label">
                                <input type="checkbox" name="show_barcode" value="1" checked onchange="toggleCodeOption()">
                                باركود (الشفرة)
                            </label>
                            <label class="checkbox-label" id="code-label">
                                <input type="checkbox" name="show_code" value="1" disabled>
                                الكود نصياً
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="show_category" value="1" checked>
                                التصنيف
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="show_purchase" value="1">
                                إظهار سعر الشراء
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="show_date" value="1" id="showDateCheck">
                                <?php echo t('labels_date_stamp'); ?>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="unit_with_price" value="1" id="unitWithPriceCheck" onchange="toggleUnitInfoVisibility()">
                                الوحدة بجانب السعر <span style="font-size:0.75rem; color:#64748b; font-weight:400;">(يُكبِّر الخط)</span>
                            </label>
                        </div>

                        <!-- نوع الكود -->
                        <div style="margin-top:10px; padding:10px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                            <label style="font-size:0.85rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">نوع الكود</label>
                            <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                                <label class="radio-label">
                                    <input type="radio" name="code_type" value="barcode" checked onchange="toggleCodeTypeOption()">
                                    باركود (خطوط)
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="code_type" value="qrcode" onchange="toggleCodeTypeOption()">
                                    رمز QR
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="code_type" value="none" onchange="toggleCodeTypeOption()">
                                    بدون كود
                                </label>
                            </div>
                            <!-- تنسيق الباركود -->
                            <div id="barcodeFormatSection" style="margin-top:8px; display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">
                                <label style="font-size:0.8rem; font-weight:600; color:#64748b;">تنسيق الباركود:</label>
                                <select name="barcode_format" id="barcodeFormatSelect" class="form-control" style="width:auto; padding:4px 10px; font-size:0.82rem;">
                                    <option value="CODE128" selected>CODE128 (عام)</option>
                                    <option value="EAN13">EAN-13 (13 رقم)</option>
                                    <option value="CODE39">CODE39 (أحرف+أرقام)</option>
                                    <option value="UPC">UPC-A (12 رقم)</option>
                                </select>
                                <span id="barcodeFormatHint" style="font-size:0.72rem; color:#94a3b8;">يتطلب CODE128 تلقائياً إذا لم يطابق التنسيق</span>
                            </div>
                        </div>

                            <!-- ترتيب عناصر الملصق -->
                            <div class="element-arrange-section">
                                <div class="section-title">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 5v14M5 12h14"/></svg>
                                    ترتيب عناصر الملصق
                                    <span class="section-hint">— حرّك العناصر لإعادة الترتيب وفعّل/عطّل ما تريد</span>
                                </div>
                                <div class="element-list" id="elementList">
                                    <!-- سيتم ملؤها بواسطة JavaScript -->
                                </div>
                                <div class="element-arrange-note">العناصر المخفية لن تظهر في الملصق — ممّا يسمح باستغلال المساحة بشكل أفضل</div>
                            </div>
                    </div>
                </div>
            </div>

            <!-- تصفية واستثناء المنتجات -->
            <div class="filter-section" id="filterSection">
                <div class="section-title" style="margin-bottom:0.5rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    تصفية واستثناء المنتجات
                    <span style="font-size:0.72rem; font-weight:400; color:#64748b; margin-right:auto;">كل فلتر يعمل بشكل مستقل — يمكن الجمع بين التضمين والاستثناء</span>
                    <button type="button" class="filter-clear-btn" id="clearFiltersBtn" onclick="clearAllFilters()" style="display:none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        مسح الكل
                    </button>
                </div>
                <!-- عناصر التصفية -->
                <div class="filter-controls">
                    <!-- تصنيف -->
                    <div class="filter-item" id="filterCatItem">
                        <div class="filter-item-header">
                            <span class="filter-item-label">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                حسب الصنف
                            </span>
                            <div class="filter-mode-toggle">
                                <button type="button" id="catModeInclude" class="active-include" onclick="setCatMode('include')">✓ تضمين</button>
                                <button type="button" id="catModeExclude" onclick="setCatMode('exclude')">✕ استثناء</button>
                            </div>
                        </div>
                        <div class="filter-category-list" id="filterCatList">
                            <?php foreach ($categories as $cat): ?>
                            <span class="filter-cat-chip" data-cat-id="<?php echo $cat['id']; ?>" data-cat-name="<?php echo htmlspecialchars($cat['name']); ?>" style="background:<?php echo $cat['color']; ?>;" onclick="toggleFilterCat(this)"><?php echo htmlspecialchars($cat['name']); ?></span>
                            <?php endforeach; ?>
                            <?php if (empty($categories)): ?>
                            <span style="font-size:0.8rem; color:#94a3b8;">لا توجد أصناف</span>
                            <?php endif; ?>
                        </div>
                        <div id="filterCatHint" style="font-size:0.72rem; color:#94a3b8; margin-top:4px;"></div>
                    </div>
                    <!-- سعر -->
                    <div class="filter-item" id="filterPriceItem">
                        <div class="filter-item-header">
                            <span class="filter-item-label">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                                حسب السعر
                            </span>
                            <div class="filter-mode-toggle">
                                <button type="button" id="priceModeInclude" class="active-include" onclick="setPriceMode('include')">✓ تضمين</button>
                                <button type="button" id="priceModeExclude" onclick="setPriceMode('exclude')">✕ استثناء</button>
                            </div>
                        </div>
                        <div class="filter-price-row">
                            <input type="number" id="filterPriceMin" class="form-control" placeholder="الحد الأدنى" min="0" step="0.01" oninput="applyFilters()">
                            <span class="price-sep">—</span>
                            <input type="number" id="filterPriceMax" class="form-control" placeholder="الحد الأقصى" min="0" step="0.01" oninput="applyFilters()">
                        </div>
                        <div id="filterPriceHint" style="font-size:0.72rem; color:#94a3b8; margin-top:4px;"></div>
                    </div>
                    <!-- حالة المخزون -->
                    <div class="filter-item" id="filterStockItem">
                        <div class="filter-item-header">
                            <span class="filter-item-label">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                حسب المخزون
                            </span>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:4px;">
                            <label class="checkbox-label" style="font-size:0.82rem;">
                                <input type="checkbox" class="stock-filter-cb" value="instock" checked onchange="applyFilters()">
                                <span style="color:#059669; font-weight:700;">●</span> متوفر
                            </label>
                            <label class="checkbox-label" style="font-size:0.82rem;">
                                <input type="checkbox" class="stock-filter-cb" value="low" checked onchange="applyFilters()">
                                <span style="color:#d97706; font-weight:700;">●</span> منخفض
                            </label>
                            <label class="checkbox-label" style="font-size:0.82rem;">
                                <input type="checkbox" class="stock-filter-cb" value="out" checked onchange="applyFilters()">
                                <span style="color:#dc2626; font-weight:700;">●</span> نفد
                            </label>
                        </div>
                    </div>
                </div>
                <!-- ملخص التصفية -->
                <div class="filter-summary" id="filterSummary"></div>
                <!-- أزرار التحكم السريع للمنتجات المفلترة -->
                <div id="filterActions" style="display:none; margin-top:0.75rem; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="add-all-btn" onclick="selectAllFiltered(1)" id="btnSelectFiltered">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M20 6L9 17l-5-5"/></svg>
                        تحديد المفلترين (1)
                    </button>
                    <button type="button" class="add-all-btn" onclick="selectAllFiltered(0)" style="background:#fef2f2; color:#dc2626; border-color:#fca5a5;" id="btnDeselectFiltered">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        إلغاء تحديد المفلترين
                    </button>
                </div>
            </div>

            <!-- اختيار فردي سريع -->
            <div class="individual-section">
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    اختيار فردي سريع
                    <span style="font-size:0.75rem; font-weight:400; color:#6b7280;">(أضف منتجات مباشرة بالبحث)</span>
                </div>
                <div class="individual-form">
                    <div class="form-group individual-search-dropdown" id="individualDropdown">
                        <label>اختر المنتج</label>
                        <div style="position:relative;">
                            <input type="text" id="individualSearch" class="form-control" placeholder="ابحث بالاسم أو الكود..." autocomplete="off" oninput="searchIndividual(this.value)" onfocus="searchIndividual(this.value)" style="padding-left:110px;">
                            <button type="button" class="search-scan-btn" data-scan-target="individual" onclick="openBarcodeScanner(this)" title="مسح الباركود بالكاميرا">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                                    <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                                    <line x1="7" y1="12" x2="17" y2="12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="dropdown-results" id="individualResults"></div>
                    </div>
                    <div class="form-group qty-group">
                        <label>العدد</label>
                        <input type="number" id="individualQty" class="form-control qty-input" min="1" max="999" value="1">
                    </div>
                    <button type="button" class="individual-add-btn" id="addIndividualBtn" onclick="addIndividual()" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                        إضافة
                    </button>
                </div>
                <div class="individual-list" id="individualList"></div>
                <div class="individual-empty-msg" id="individualEmpty">لم يتم إضافة أي منتج بعد</div>
            </div>

            <!-- قائمة المنتجات -->
            <div class="card">
                <div class="card-header">
                    <h2>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        <span class="selected-count" id="selectedCount">0</span>
                        قائمة المنتجات
                    </h2>
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap: wrap;">
                        <div class="enhanced-search-input" style="max-width:250px;">
                            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" id="searchInput" class="form-control" placeholder="بحث بالكود أو الاسم..." oninput="filterProducts(this.value); toggleClearBtn();">
                            <button type="button" class="search-clear-btn-inline" id="searchClearBtn" onclick="clearSearchInput()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                            <button type="button" class="print-labels-scan-btn" data-scan-target="filter" onclick="openBarcodeScanner(this)" title="مسح الباركود بالكاميرا">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                    <path d="M3 7V5a2 2 0 012-2h2"/><path d="M17 3h2a2 2 0 012 2v2"/>
                                    <path d="M21 17v2a2 2 0 01-2 2h-2"/><path d="M7 21H5a2 2 0 01-2-2v-2"/>
                                    <line x1="7" y1="12" x2="17" y2="12"/>
                                </svg>
                            </button>
                        </div>
                        <button type="button" class="add-all-btn" onclick="setAllQty(1)">تحديد الكل (1)</button>
                        <button type="button" class="add-all-btn" onclick="setAllQty(0)">إلغاء الكل</button>

                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:36px;"><input type="checkbox" class="product-check" id="checkAll" onchange="toggleAll(this.checked)"></th>
                                    <th style="width:36px;">#</th>
                                    <th>الكود</th>
                                    <th>اسم المنتج</th>
                                    <th>التصنيف</th>
                                    <th>سعر البيع</th>
                                    <th style="width:120px;">العدد</th>
                                </tr>
                            </thead>
                            <tbody id="productsTable">
                                <?php foreach ($all_products as $idx => $product): ?>
                                <tr class="product-row" data-name="<?php echo htmlspecialchars($product['name']); ?>" data-code="<?php echo htmlspecialchars($product['code']); ?>" data-category="<?php echo htmlspecialchars($product['category_name'] ?? ''); ?>" data-cat-id="<?php echo (int)($product['category_id'] ?? 0); ?>" data-price="<?php echo floatval($product['selling_price']); ?>" data-quantity="<?php echo floatval($product['quantity']); ?>" data-min-quantity="<?php echo floatval($product['min_quantity'] ?? 0); ?>">
                                    <td><input type="checkbox" class="product-check row-check" onchange="toggleRow(this, <?php echo $idx; ?>)"></td>
                                    <td><?php echo $idx + 1; ?></td>
                                    <td><span style="font-family:monospace; font-size:0.85rem; background:#f1f5f9; padding:2px 6px; border-radius:4px;"><?php echo htmlspecialchars($product['code']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                                    <td>
                                        <?php if (!empty($product['category_name'])): ?>
                                            <span style="display:inline-block; padding:2px 10px; border-radius:12px; font-size:0.8rem; font-weight:600; color:#fff; background:<?php echo $product['category_color'] ?? '#64748b'; ?>;">
                                                <?php echo htmlspecialchars($product['category_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size:0.8rem; color:#94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight:700; color:#dc2626; font-size:1.05rem;"><?php echo formatMoney($product['selling_price']); ?></td>
                                    <td>
                                        <div class="qty-cell">
                                            <button type="button" class="quick-add-btn" onclick="quickAdd(<?php echo $idx; ?>, -1)" title="نقصان">−</button>
                                            <input type="number" name="quantities[<?php echo $idx; ?>]" class="form-control qty-input" min="0" max="999" value="0" onchange="updateCounts(); highlightRow(<?php echo $idx; ?>)" onfocus="this.select()" id="qty-<?php echo $idx; ?>">
                                            <button type="button" class="quick-add-btn" onclick="quickAdd(<?php echo $idx; ?>, 1)" title="زيادة">+</button>
                                        </div>
                                        <input type="hidden" name="product_ids[<?php echo $idx; ?>]" value="<?php echo $product['id']; ?>">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($all_products)): ?>
                                <tr><td colspan="6" style="text-align:center; padding:2rem; color:#94a3b8;">لا توجد منتجات بعد</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- أزرار الطباعة -->
            <div style="margin-top:1.5rem; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-lg" id="printBtn" onclick="saveTemplate(); return validateForm()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    إنشاء ملصقات الأرفف
                </button>
                <button type="button" class="btn btn-outline" onclick="saveTemplate()" title="<?php echo t('labels_save_layout'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    حفظ القالب
                </button>
                <button type="button" class="btn btn-outline" onclick="resetTemplate()" title="<?php echo t('labels_reset_layout'); ?>" style="color:#dc2626;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                    إعادة ضبط
                </button>
                <a href="<?php echo $base_url; ?>/products/list-products.php" class="btn btn-outline btn-lg">العودة للمنتجات</a>
            </div>
        </form>
    </div>

    <script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
    // === إدارة ترتيب عناصر الملصق ===
    const ELEMENT_DEFS = [
        {id:'storeName', label:'اسم المتجر', icon:'🏪'},
        {id:'productName', label:'اسم المنتج', icon:'📦'},
        {id:'price', label:'السعر', icon:'💰'},
        {id:'code', label:'<?php echo t('labels_code_barcode'); ?>', icon:'🔢'},
        {id:'category', label:'التصنيف', icon:'🏷️'},
        {id:'unitInfo', label:'معلومات الوحدة', icon:'📏'},
        {id:'date', label:'<?php echo t('labels_date_stamp'); ?>', icon:'📅'}
    ];
    let labelElements = ELEMENT_DEFS.map(e => ({...e, visible: true}));

    function moveElement(index, direction) {
        const target = direction === 'up' ? index - 1 : index + 1;
        if (target < 0 || target >= labelElements.length) return;
        [labelElements[index], labelElements[target]] = [labelElements[target], labelElements[index]];
        renderLabelElements();
    }

    function toggleElementVisibility(id) {
        const el = labelElements.find(e => e.id === id);
        if (el) el.visible = !el.visible;
        renderLabelElements();
    }

    function renderLabelElements() {
        const container = document.getElementById('elementList');
        if (!container) return;
        container.innerHTML = labelElements.map((el, idx) => `
            <div class="element-item ${el.visible ? '' : 'element-hidden'}">
                <span class="el-icon">${el.icon}</span>
                <span class="el-name">${el.label}</span>
                <div class="el-actions">
                    <button type="button" class="el-arrow-btn" onclick="moveElement(${idx},'up')" title="<?php echo t('labels_move_up'); ?>" ${idx === 0 ? 'disabled' : ''}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><path d="M18 15l-6-6-6 6"/></svg>
                    </button>
                    <button type="button" class="el-arrow-btn" onclick="moveElement(${idx},'down')" title="<?php echo t('labels_move_down'); ?>" ${idx === labelElements.length - 1 ? 'disabled' : ''}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><path d="M6 9l6 6 6-6"/></svg>
                    </button>
                    <button type="button" class="el-toggle-btn ${el.visible ? 'btn-visible' : 'btn-hidden'}" onclick="toggleElementVisibility('${el.id}')">
                        ${el.visible ? 'ظاهر' : 'مخفي'}
                    </button>
                </div>
            </div>
        `).join('');
        // Update hidden fields
        updateElementHiddenFields();
    }

    function updateElementHiddenFields() {
        let container = document.getElementById('elementHiddenFields');
        if (!container) {
            container = document.createElement('div');
            container.id = 'elementHiddenFields';
            const form = document.getElementById('labelForm');
            if (form) form.appendChild(container);
        }
        const order = JSON.stringify(labelElements.map(e => e.id));
        const visibility = JSON.stringify(Object.fromEntries(labelElements.map(e => [e.id, e.visible])));
        
        let orderInput = document.getElementById('elementOrderInput');
        let visInput = document.getElementById('elementVisibilityInput');
        if (!orderInput) {
            orderInput = document.createElement('input');
            orderInput.type = 'hidden';
            orderInput.id = 'elementOrderInput';
            orderInput.name = 'element_order';
            container.appendChild(orderInput);
        }
        if (!visInput) {
            visInput = document.createElement('input');
            visInput.type = 'hidden';
            visInput.id = 'elementVisibilityInput';
            visInput.name = 'element_visibility';
            container.appendChild(visInput);
        }
        orderInput.value = order;
        visInput.value = visibility;
    }

    // Initialize element list on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        renderLabelElements();
    });

    // === متغيرات عامة ===
    let labelsPerPage = 10; // القيمة الافتراضية (large = 2×5)

    function selectLayout(key, perPage) {
        labelsPerPage = perPage;
        document.querySelectorAll('.layout-option').forEach(el => el.classList.remove('active'));
        document.getElementById('lo-' + key).classList.add('active');
        updateCounts();
    }

    function toggleCodeOption() {
        const barcodeChecked = document.querySelector('input[name="show_barcode"]').checked;
        const codeCheckbox = document.querySelector('input[name="show_code"]');
        const codeType = document.querySelector('input[name="code_type"]:checked').value;
        if (barcodeChecked && codeType === 'barcode') {
            codeCheckbox.checked = false;
            codeCheckbox.disabled = true;
        } else {
            codeCheckbox.disabled = false;
            codeCheckbox.checked = true;
        }
    }

    function toggleCodeTypeOption() {
        const codeType = document.querySelector('input[name="code_type"]:checked').value;
        const barcodeSection = document.getElementById('barcodeFormatSection');
        const barcodeCheckbox = document.querySelector('input[name="show_barcode"]');
        const codeCheckbox = document.querySelector('input[name="show_code"]');

        if (codeType === 'barcode') {
            barcodeSection.style.display = 'flex';
            barcodeCheckbox.checked = true;
            barcodeCheckbox.disabled = false;
            codeCheckbox.checked = false;
            codeCheckbox.disabled = true;
        } else if (codeType === 'qrcode') {
            barcodeSection.style.display = 'none';
            barcodeCheckbox.checked = false;
            barcodeCheckbox.disabled = true;
            codeCheckbox.checked = false;
            codeCheckbox.disabled = true;
        } else {
            barcodeSection.style.display = 'none';
            barcodeCheckbox.checked = false;
            barcodeCheckbox.disabled = true;
            codeCheckbox.checked = false;
            codeCheckbox.disabled = true;
        }
    }

    function setAllQty(val) {
        document.querySelectorAll('.qty-input').forEach(input => {
            input.value = val;
        });
        updateCounts();
    }

    function updateCounts() {
        let total = 0;
        let selected = 0;
        document.querySelectorAll('.qty-input').forEach(input => {
            const qty = parseInt(input.value) || 0;
            total += qty;
            if (qty > 0) selected++;
        });
        document.getElementById('totalLabels').textContent = total;
        document.getElementById('selectedCount').textContent = selected;
        const pages = total > 0 ? Math.ceil(total / labelsPerPage) : 0;
        document.getElementById('totalPages').textContent = pages;
    }

    function validateForm() {
        let total = 0;
        document.querySelectorAll('.qty-input').forEach(input => {
            total += parseInt(input.value) || 0;
        });
        if (total === 0) {
            alert('يرجى تحديد عدد الملصقات لمنتج واحد على الأقل');
            return false;
        }
        return true;
    }

    // === بحث في الجدول ===
    function filterProducts(value) {
        const query = value.toLowerCase().trim();
        document.querySelectorAll('.product-row').forEach(row => {
            const name = (row.dataset.name || '').toLowerCase();
            const code = (row.dataset.code || '').toLowerCase();
            if (query === '' || name.includes(query) || code.includes(query)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        // إعادة تطبيق الفلاتر فوق البحث
        applyFilters();
    }

    function toggleClearBtn() {
        const input = document.getElementById('searchInput');
        const btn = document.getElementById('searchClearBtn');
        if (input.value.trim()) {
            btn.classList.add('visible');
        } else {
            btn.classList.remove('visible');
        }
    }

    function clearSearchInput() {
        const input = document.getElementById('searchInput');
        input.value = '';
        document.getElementById('searchClearBtn').classList.remove('visible');
        filterProducts('');
        input.focus();
    }

    function toggleRow(checkbox, idx) {
        const qtyInput = document.getElementById('qty-' + idx);
        if (checkbox.checked) {
            if (parseInt(qtyInput.value) === 0) qtyInput.value = 1;
        } else {
            qtyInput.value = 0;
        }
        highlightRow(idx);
        updateCounts();
    }

    function toggleAll(checked) {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = checked);
        setAllQty(checked ? 1 : 0);
        document.querySelectorAll('.product-row').forEach(row => {
            if (checked) row.classList.add('row-selected');
            else row.classList.remove('row-selected');
        });
    }

    function quickAdd(idx, delta) {
        const input = document.getElementById('qty-' + idx);
        let val = parseInt(input.value) || 0;
        val = Math.max(0, Math.min(999, val + delta));
        input.value = val;
        // تحديث checkbox
        const row = input.closest('.product-row');
        const cb = row.querySelector('.row-check');
        if (cb) cb.checked = (val > 0);
        highlightRow(idx);
        updateCounts();
    }

    function highlightRow(idx) {
        const row = document.getElementById('qty-' + idx).closest('.product-row');
        const qty = parseInt(document.getElementById('qty-' + idx).value) || 0;
        if (qty > 0) row.classList.add('row-selected');
        else row.classList.remove('row-selected');
    }

    // === تصفية واستثناء ===
    // كل فلتر له وضع مستقل: include أو exclude
    let catMode = 'include';   // وضع فلتر الصنف
    let priceMode = 'include'; // وضع فلتر السعر
    let filterCatIds = new Set();
    let filterPriceMin = null;
    let filterPriceMax = null;

    function setCatMode(mode) {
        catMode = mode;
        document.getElementById('catModeInclude').className = mode === 'include' ? 'active-include' : '';
        document.getElementById('catModeExclude').className = mode === 'exclude' ? 'active-exclude' : '';
        applyFilters();
    }

    function setPriceMode(mode) {
        priceMode = mode;
        document.getElementById('priceModeInclude').className = mode === 'include' ? 'active-include' : '';
        document.getElementById('priceModeExclude').className = mode === 'exclude' ? 'active-exclude' : '';
        applyFilters();
    }

    function toggleFilterCat(chip) {
        const id = parseInt(chip.dataset.catId);
        if (filterCatIds.has(id)) {
            filterCatIds.delete(id);
            chip.classList.remove('active');
        } else {
            filterCatIds.add(id);
            chip.classList.add('active');
        }
        applyFilters();
    }

    function clearAllFilters() {
        filterCatIds.clear();
        filterPriceMin = null;
        filterPriceMax = null;
        document.querySelectorAll('.filter-cat-chip').forEach(c => c.classList.remove('active'));
        document.getElementById('filterPriceMin').value = '';
        document.getElementById('filterPriceMax').value = '';
        // إعادة كل فلاتر المخزون محددة
        document.querySelectorAll('.stock-filter-cb').forEach(cb => cb.checked = true);
        // إعادة الأوضاع إلى تضمين
        setCatMode('include');
        setPriceMode('include');
        applyFilters();
    }

    function applyFilters() {
        // قراءة القيم
        const minVal = document.getElementById('filterPriceMin').value;
        const maxVal = document.getElementById('filterPriceMax').value;
        filterPriceMin = minVal !== '' ? parseFloat(minVal) : null;
        filterPriceMax = maxVal !== '' ? parseFloat(maxVal) : null;

        // قراءة فلتر المخزون
        const stockFilters = new Set();
        document.querySelectorAll('.stock-filter-cb:checked').forEach(cb => stockFilters.add(cb.value));
        const hasStockFilter = stockFilters.size < 3; // إذا كانت كلها محددة لا حاجة للفلتر

        const hasCatFilter = filterCatIds.size > 0;
        const hasPriceFilter = filterPriceMin !== null || filterPriceMax !== null;
        const hasAnyFilter = hasCatFilter || hasPriceFilter || hasStockFilter;

        // إظهار/إخفاء زر المسح
        document.getElementById('clearFiltersBtn').style.display = hasAnyFilter ? 'inline-flex' : 'none';

        // تحديث تنسيق عناصر الفلاتر (حدود ملونة حسب الوضع)
        const catItem = document.getElementById('filterCatItem');
        const priceItem = document.getElementById('filterPriceItem');
        catItem.className = hasCatFilter
            ? ('filter-item filter-item-active' + (catMode === 'exclude' ? ' filter-item-exclude' : ''))
            : 'filter-item';
        priceItem.className = hasPriceFilter
            ? ('filter-item filter-item-active' + (priceMode === 'exclude' ? ' filter-item-exclude' : ''))
            : 'filter-item';

        // تحديث نص تلميح الصنف
        const catHint = document.getElementById('filterCatHint');
        if (!hasCatFilter) {
            catHint.textContent = '';
        } else {
            const catNames = [];
            document.querySelectorAll('.filter-cat-chip.active').forEach(c => catNames.push(c.dataset.catName));
            if (catMode === 'include') {
                catHint.innerHTML = '<span style="color:#059669;">✓ تضمين فقط:</span> ' + catNames.join('، ');
            } else {
                catHint.innerHTML = '<span style="color:#dc2626;">✕ استثناء:</span> ' + catNames.join('، ');
            }
        }

        // تحديث نص تلميح السعر
        const hint = document.getElementById('filterPriceHint');
        if (!hasPriceFilter) {
            hint.textContent = '';
        } else {
            let priceDesc = '';
            if (filterPriceMin !== null && filterPriceMax !== null) priceDesc = 'بين ' + filterPriceMin + ' و ' + filterPriceMax;
            else if (filterPriceMin !== null) priceDesc = filterPriceMin + ' أو أكثر';
            else priceDesc = filterPriceMax + ' أو أقل';
            if (priceMode === 'include') {
                hint.innerHTML = '<span style="color:#059669;">✓ تضمين فقط:</span> السعر ' + priceDesc;
            } else {
                hint.innerHTML = '<span style="color:#dc2626;">✕ استثناء:</span> السعر ' + priceDesc;
            }
        }

        // تطبيق الفلاتر على الصفوف
        let totalBefore = 0, totalVisible = 0, totalExcluded = 0;
        const searchQuery = (document.getElementById('searchInput').value || '').toLowerCase().trim();

        document.querySelectorAll('.product-row').forEach(row => {
            // البحث النصي يطبق دائماً
            const name = (row.dataset.name || '').toLowerCase();
            const code = (row.dataset.code || '').toLowerCase();
            const matchesSearch = !searchQuery || name.includes(searchQuery) || code.includes(searchQuery);
            if (!matchesSearch) {
                row.style.display = 'none';
                return;
            }

            totalBefore++;
            const rowPrice = parseFloat(row.dataset.price) || 0;
            const rowCatId = parseInt(row.dataset.catId) || 0;
            const rowQty = parseFloat(row.dataset.quantity) || 0;
            const rowMinQty = parseFloat(row.dataset.minQuantity) || 0;

            // تحديد حالة المخزون
            let stockStatus = 'instock';
            if (rowQty <= 0) stockStatus = 'out';
            else if (rowMinQty > 0 && rowQty <= rowMinQty) stockStatus = 'low';

            // === تطبيق كل فلتر بشكل مستقل ===
            let visible = true;

            // 1) فلتر الصنف
            if (hasCatFilter) {
                const catMatch = filterCatIds.has(rowCatId);
                if (catMode === 'include') {
                    // تضمين: أظهر فقط المطابقين
                    visible = visible && catMatch;
                } else {
                    // استثناء: أخفِ المطابقين
                    if (catMatch) visible = false;
                }
            }

            // 2) فلتر السعر
            if (hasPriceFilter && visible) {
                let priceMatch = true;
                if (filterPriceMin !== null) priceMatch = priceMatch && rowPrice >= filterPriceMin;
                if (filterPriceMax !== null) priceMatch = priceMatch && rowPrice <= filterPriceMax;
                if (priceMode === 'include') {
                    // تضمين: أظهر فقط المطابقين
                    visible = visible && priceMatch;
                } else {
                    // استثناء: أخفِ المطابقين
                    if (priceMatch) visible = false;
                }
            }

            // 3) فلتر المخزون
            if (hasStockFilter && visible) {
                if (!stockFilters.has(stockStatus)) visible = false;
            }

            row.style.display = visible ? '' : 'none';
            if (visible) totalVisible++;
            else totalExcluded++;
        });

        // ملخص الفلاتر
        const summary = document.getElementById('filterSummary');
        if (hasAnyFilter) {
            summary.classList.add('show');
            
            // بناء وصف كل فلتر مع وضعه
            let parts = [];
            
            if (hasCatFilter) {
                const catNames = [];
                document.querySelectorAll('.filter-cat-chip.active').forEach(c => catNames.push(c.dataset.catName));
                const catIcon = catMode === 'include' ? '<span style="color:#059669;">✓ تضمين</span>' : '<span style="color:#dc2626;">✕ استثناء</span>';
                parts.push(catIcon + ' أصناف: <strong>' + catNames.join('، ') + '</strong>');
            }
            
            if (hasPriceFilter) {
                let priceDesc = '';
                if (filterPriceMin !== null && filterPriceMax !== null) priceDesc = filterPriceMin + ' - ' + filterPriceMax;
                else if (filterPriceMin !== null) priceDesc = '≥ ' + filterPriceMin;
                else priceDesc = '≤ ' + filterPriceMax;
                const priceIcon = priceMode === 'include' ? '<span style="color:#059669;">✓ تضمين</span>' : '<span style="color:#dc2626;">✕ استثناء</span>';
                parts.push(priceIcon + ' السعر: <strong>' + priceDesc + '</strong>');
            }

            // تحديد لون الملخص حسب الأوضاع
            const hasExclude = (hasCatFilter && catMode === 'exclude') || (hasPriceFilter && priceMode === 'exclude');
            
            if (hasExclude) {
                summary.className = 'filter-summary show exclude-summary';
            } else {
                summary.className = 'filter-summary show include-summary';
            }
            
            const modeNote = hasCatFilter && catMode !== priceMode 
                ? '  <small style="opacity:0.7;">(مزيج تضمين + استثناء)</small>' 
                : '';
            summary.innerHTML = 'يظهر <strong>' + totalVisible + '</strong> منتج من أصل ' + totalBefore + ' — تم إخفاء <strong>' + totalExcluded + '</strong>' + modeNote + '<br><small style="opacity:0.85;">' + parts.join(' &nbsp;+&nbsp; ') + '</small>';
        } else {
            summary.classList.remove('show');
        }

        // إظهار/إخفاء أزرار التحكم السريع
        const filterActions = document.getElementById('filterActions');
        if (hasAnyFilter) {
            filterActions.style.display = 'flex';
        } else {
            filterActions.style.display = 'none';
        }
    }

    // تحديد/إلغاء تحديد المنتجات الظاهرة فقط (المفلترة)
    function selectAllFiltered(val) {
        document.querySelectorAll('.product-row').forEach(row => {
            if (row.style.display !== 'none') {
                const qtyInput = row.querySelector('.qty-input');
                const cb = row.querySelector('.row-check');
                if (qtyInput) qtyInput.value = val;
                if (cb) cb.checked = (val > 0);
                if (val > 0) row.classList.add('row-selected');
                else row.classList.remove('row-selected');
            }
        });
        updateCounts();
    }

    function filterByCategory(category) {
        // هذه الدالة أصبحت جزءاً من applyFilters
        applyFilters();
    }

    // === اختيار فردي ===
    let selectedIndividualProduct = null;
    let individualItems = []; // {id, name, code, qty}

    const productsData = <?php echo json_encode(array_map(function($p) {
        return ['id' => $p['id'], 'name' => $p['name'], 'code' => $p['code']];
    }, $all_products)); ?>;

    function searchIndividual(query) {
        const results = document.getElementById('individualResults');
        query = query.toLowerCase().trim();
        if (!query) {
            results.innerHTML = '';
            results.classList.remove('show');
            selectedIndividualProduct = null;
            document.getElementById('addIndividualBtn').disabled = true;
            return;
        }
        const matches = productsData.filter(p =>
            p.name.toLowerCase().includes(query) || p.code.toLowerCase().includes(query)
        ).slice(0, 15);
        if (matches.length === 0) {
            results.innerHTML = '<div style="padding:10px; text-align:center; color:#94a3b8; font-size:0.85rem;"><?php echo t('labels_no_search_results'); ?></div>';
            results.classList.add('show');
            selectedIndividualProduct = null;
            document.getElementById('addIndividualBtn').disabled = true;
            return;
        }
        results.innerHTML = matches.map(p =>
            '<div class="dropdown-item" onclick="selectIndividual(' + p.id + ', \'' + p.name.replace(/'/g, "\\'") + '\', \'' + p.code + '\')">' +
            '<span class="di-name">' + p.name + '</span>' +
            '<span class="di-code">' + p.code + '</span>' +
            '</div>'
        ).join('');
        results.classList.add('show');
    }

    function selectIndividual(id, name, code) {
        selectedIndividualProduct = {id, name, code};
        document.getElementById('individualSearch').value = name + ' (' + code + ')';
        document.getElementById('individualResults').classList.remove('show');
        document.getElementById('addIndividualBtn').disabled = false;
    }

    function addIndividual() {
        if (!selectedIndividualProduct) return;
        const qty = parseInt(document.getElementById('individualQty').value) || 1;
        if (qty < 1) return;
        // التحقق إذا تمت إضافته مسبقاً
        const existing = individualItems.findIndex(item => item.id === selectedIndividualProduct.id);
        if (existing >= 0) {
            individualItems[existing].qty += qty;
        } else {
            individualItems.push({
                id: selectedIndividualProduct.id,
                name: selectedIndividualProduct.name,
                code: selectedIndividualProduct.code,
                qty: qty
            });
        }
        renderIndividualList();
        updateIndividualHidden();
        updateCounts();
        // إعادة تعيين
        selectedIndividualProduct = null;
        document.getElementById('individualSearch').value = '';
        document.getElementById('individualQty').value = 1;
        document.getElementById('addIndividualBtn').disabled = true;
    }

    function removeIndividual(index) {
        individualItems.splice(index, 1);
        renderIndividualList();
        updateIndividualHidden();
        updateCounts();
    }

    function renderIndividualList() {
        const list = document.getElementById('individualList');
        const emptyMsg = document.getElementById('individualEmpty');
        if (individualItems.length === 0) {
            list.innerHTML = '';
            emptyMsg.style.display = 'block';
            return;
        }
        emptyMsg.style.display = 'none';
        list.innerHTML = individualItems.map((item, idx) =>
            '<div class="individual-item">' +
            '<span class="item-name" title="' + item.name + ' (' + item.code + ')">' + item.name + '</span>' +
            '<span class="item-qty">' + item.qty + '</span>' +
            '<button type="button" class="remove-item-btn" onclick="removeIndividual(' + idx + ')" title="حذف">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
            '</button>' +
            '</div>'
        ).join('');
    }

    function updateIndividualHidden() {
        const container = document.getElementById('individualHiddenFields');
        container.innerHTML = individualItems.map((item, idx) =>
            '<input type="hidden" name="individual_ids[' + idx + ']" value="' + item.id + '">' +
            '<input type="hidden" name="individual_qtys[' + idx + ']" value="' + item.qty + '">'
        ).join('');
    }

    // updateCounts و validateForm محدثان أدناه لتشمل الفردية

    // إغلاق القائمة عند النقر خارجها
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('individualDropdown');
        if (dropdown && !dropdown.contains(e.target)) {
            document.getElementById('individualResults').classList.remove('show');
        }
    });

    // اختصار Enter للإضافة
    document.getElementById('individualQty').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!document.getElementById('addIndividualBtn').disabled) {
                addIndividual();
            }
        }
    });


    // === إظهار/إخفاء عنصر معلومات الوحدة عندما تكون الوحدة بجانب السعر ===
    function toggleUnitInfoVisibility() {
        const unitWithPrice = document.getElementById('unitWithPriceCheck').checked;
        const unitInfoEl = labelElements.find(e => e.id === 'unitInfo');
        if (unitInfoEl) {
            if (unitWithPrice) {
                unitInfoEl.visible = false;
            }
            // إذا أُلغي التحديد، لا نُعيد التفعيل تلقائياً (المستخدم يتحكم بنفسه)
            renderLabelElements();
        }
    }

    // === حفظ واستعادة إعدادات القالب ===
    const TEMPLATE_KEY = 'labelPrintTemplate_v1';

    function saveTemplate() {
        const data = {
            label_layout: document.querySelector('input[name="label_layout"]:checked')?.value || 'large',
            price_display: document.querySelector('input[name="price_display"]:checked')?.value || 'basic',
            code_type: document.querySelector('input[name="code_type"]:checked')?.value || 'barcode',
            barcode_format: document.getElementById('barcodeFormatSelect')?.value || 'CODE128',
            show_barcode: document.querySelector('input[name="show_barcode"]')?.checked || false,
            show_code: document.querySelector('input[name="show_code"]')?.checked || false,
            show_category: document.querySelector('input[name="show_category"]')?.checked || false,
            show_purchase: document.querySelector('input[name="show_purchase"]')?.checked || false,
            show_date: document.querySelector('input[name="show_date"]')?.checked || false,
            unit_with_price: document.querySelector('input[name="unit_with_price"]')?.checked || false,
            store_name: document.querySelector('input[name="store_name"]')?.value || '',
            element_order: labelElements.map(e => e.id),
            element_visibility: Object.fromEntries(labelElements.map(e => [e.id, e.visible])),
        };
        try { localStorage.setItem(TEMPLATE_KEY, JSON.stringify(data)); } catch(e) {}
    }

    function loadTemplate() {
        try {
            const raw = localStorage.getItem(TEMPLATE_KEY);
            if (!raw) return false;
            const data = JSON.parse(raw);
            // تخطيط
            const layoutInput = document.querySelector('input[name="label_layout"][value="' + (data.label_layout || 'large') + '"]');
            if (layoutInput) { layoutInput.checked = true; selectLayout(data.label_layout, parseInt(layoutInput.closest('.layout-option')?.querySelector('.layout-size')?.textContent) || 10); }
            // نوع السعر
            const priceInput = document.querySelector('input[name="price_display"][value="' + (data.price_display || 'basic') + '"]');
            if (priceInput) priceInput.checked = true;
            // نوع الكود
            const codeTypeInput = document.querySelector('input[name="code_type"][value="' + (data.code_type || 'barcode') + '"]');
            if (codeTypeInput) codeTypeInput.checked = true;
            toggleCodeTypeOption();
            // تنسيق الباركود
            const fmtSelect = document.getElementById('barcodeFormatSelect');
            if (fmtSelect && data.barcode_format) fmtSelect.value = data.barcode_format;
            // خيارات
            if (data.show_barcode !== undefined) document.querySelector('input[name="show_barcode"]').checked = data.show_barcode;
            if (data.show_code !== undefined) document.querySelector('input[name="show_code"]').checked = data.show_code;
            if (data.show_category !== undefined) document.querySelector('input[name="show_category"]').checked = data.show_category;
            if (data.show_purchase !== undefined) document.querySelector('input[name="show_purchase"]').checked = data.show_purchase;
            if (data.show_date !== undefined) document.querySelector('input[name="show_date"]').checked = data.show_date;
            if (data.unit_with_price !== undefined) document.querySelector('input[name="unit_with_price"]').checked = data.unit_with_price;
            if (data.store_name) document.querySelector('input[name="store_name"]').value = data.store_name;
            // ترتيب العناصر
            if (data.element_order && data.element_visibility) {
                labelElements = data.element_order.map(id => {
                    const def = ELEMENT_DEFS.find(d => d.id === id);
                    return def ? {...def, visible: !!data.element_visibility[id]} : null;
                }).filter(Boolean);
                // أضف أي عناصر جديدة غير موجودة في القالب المحفوظ
                ELEMENT_DEFS.forEach(def => {
                    if (!labelElements.find(e => e.id === def.id)) {
                        labelElements.push({...def, visible: !!data.element_visibility[def.id]});
                    }
                });
                renderLabelElements();
            }
            return true;
        } catch(e) { return false; }
    }

    function resetTemplate() {
        try { localStorage.removeItem(TEMPLATE_KEY); } catch(e) {}
        location.reload();
    }

    // === التهيئة الأولية ===
    toggleCodeOption();
    updateCounts();
    // استعادة الإعدادات المحفوظة
    loadTemplate();

    // === دعم مسح الباركود ===

    // target: قيمة data-scan-target من زر المسح ('individual' أو 'filter') أو null
    window.onBarcodeScanned = function(code, target) {
        if (!code) return;
        var scanTarget = target !== undefined ? target : 'individual';
        if (scanTarget === 'filter') {
            // ملء حقل البحث/الفلتر في جدول المنتجات
            var filterInput = document.getElementById('searchInput');
            if (filterInput) {
                filterInput.value = code;
                filterProducts(code);
                toggleClearBtn();
            }
        } else {
            // البحث الفردي — محاولة مطابقة منتج تلقائياً
            var match = productsData.find(function(p) {
                return p.code.toLowerCase() === code.toLowerCase();
            });
            if (match) {
                selectIndividual(match.id, match.name, match.code);
                document.getElementById('addIndividualBtn').disabled = false;
            } else {
                // عرض النتائج القريبة
                var individualInput = document.getElementById('individualSearch');
                if (individualInput) {
                    individualInput.value = code;
                    searchIndividual(code);
                }
            }
        }
    };
    </script>

    <?php require_once '../includes/barcode-scanner.php'; ?>
<?php endif; ?>
