
<style>
/* === حقل الوحدة المخصصة === */
.custom-unit-section {
    display: none;
    animation: fadeSlideDown 0.3s ease;
}
.custom-unit-section.active {
    display: block;
}
@keyframes fadeSlideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}
.custom-unit-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
    padding: 14px 16px;
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border: 1px solid #fde68a;
    border-radius: 10px;
    margin-top: 8px;
}
.custom-unit-row .form-group {
    margin-bottom: 0;
}
.custom-unit-row .form-group.cu-name {
    flex: 1;
    min-width: 150px;
}
.custom-unit-row .form-group.cu-type {
    min-width: 150px;
}
.custom-unit-row .form-group.cu-conversion {
    min-width: 180px;
}
.custom-unit-row .form-group.cu-price {
    min-width: 140px;
}
.custom-unit-row label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #92400e;
    margin-bottom: 4px;
    display: block;
}
.custom-unit-row .form-control {
    border-color: #fbbf24;
    background: white;
}
.custom-unit-row .form-control:focus {
    border-color: #d97706;
    box-shadow: 0 0 0 3px rgba(217,119,6,0.1);
}
.conversion-hint-custom {
    font-size: 0.75rem;
    color: #92400e;
    margin-top: 3px;
}
.conversion-hint-custom strong {
    color: #78350f;
}
.custom-unit-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #fef3c7;
    color: #92400e;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    border: 1px solid #fde68a;
    margin-bottom: 4px;
}
/* === حقل اسم مخصص داخل صف الوحدة الإضافية === */
.au-custom-name-wrap {
    display: none;
    margin-top: 4px;
    animation: fadeSlideDown 0.3s ease;
}
.au-custom-name-wrap.active {
    display: block;
}
.au-custom-name-wrap input {
    border-color: #fbbf24;
    background: white;
}
/* === منطقة رفع الصور === */
.image-drop-zone {
    border: 2px dashed var(--gray-200);
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    cursor: pointer;
    transition: all 0.25s;
    background: var(--gray-50);
}
.image-drop-zone:hover, .image-drop-zone.drag-over {
    border-color: var(--primary);
    background: rgba(37, 99, 235, 0.04);
}
.image-drop-zone.drag-over {
    transform: scale(1.01);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}
.product-image-item:hover {
    border-color: var(--primary) !important;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
    transform: translateY(-2px);
}
.product-image-item {
    transition: all 0.2s;
}

</style>

<div class="container">
    <div class="page-header">
        <h1><?php echo t('products_edit'); ?>: <?php echo htmlspecialchars($product['name']); ?></h1>
        <a href="list-products.php" class="btn btn-outline"><?php echo t('back'); ?></a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?>
        <div class="alert alert-success">
            تم إنشاء المنتج بنجاح!
            <?php $imgCount = intval($_GET['img_count'] ?? 0); ?>
            <?php if ($imgCount > 0): ?>
                — تم رفع <?php echo $imgCount; ?> صورة تلقائياً
            <?php endif; ?>
        </div>
    <?php endif; ?>


    <form method="POST">
        <div class="card">
            <div class="card-header"><h2>معلومات المنتج الأساسية</h2></div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>كود المنتج <span class="required">*</span></label>
                        <input type="text" name="code" class="form-control" value="<?php echo htmlspecialchars($product['code']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>اسم المنتج <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>سعر الشراء <span class="required">*</span></label>
                        <input type="number" name="purchase_price" id="purchase_price_input" class="form-control" step="0.01" value="<?php echo $product['purchase_price']; ?>" required oninput="checkPriceChange()">
                        <div class="form-text" id="purchase_price_hint" style="display:none; color:#d97706;">تم تغيير سعر الشراء! سيتم تسجيل التغيير في السجل.</div>
                    </div>
                    <div class="form-group">
                        <label>سعر البيع <span class="required">*</span></label>
                        <input type="number" name="selling_price" id="selling_price_input" class="form-control" step="0.01" value="<?php echo $product['selling_price']; ?>" required oninput="checkPriceChange()">
                        <div class="form-text" id="selling_price_hint" style="display:none; color:#d97706;">تم تغيير سعر البيع! سيتم تسجيل التغيير في السجل.</div>
                    </div>
                    <div class="form-group">
                        <label>وحدة القياس الأساسية <span class="required">*</span></label>
                        <?php if (!empty($units)): ?>
                        <select name="unit" id="unitSelect" class="form-control" required>
                            <option value="">-- اختر وحدة القياس --</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo htmlspecialchars($u['abbreviation']); ?>" <?php echo ($product['unit'] == $u['abbreviation']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['abbreviation']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="text" name="unit" id="unitSelect" class="form-control" value="<?php echo htmlspecialchars($product['unit']); ?>" required>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>وحدة القياس الأساسية للشراء</label>
                        <div class="form-text" style="margin-bottom:4px;">اتركه فارغاً لاستخدام وحدة البيع الأساسية</div>
                        <?php if (!empty($units)): ?>
                        <select name="purchase_unit" id="purchaseUnitSelect" class="form-control" onchange="onPurchaseUnitChange()">
                            <option value="">-- وحدة البيع (<?php echo htmlspecialchars($product['unit']); ?>) --</option>
                            <?php foreach ($units as $u): ?>
                            <option value="<?php echo htmlspecialchars($u['abbreviation']); ?>" <?php echo (isset($product['purchase_unit']) && $product['purchase_unit'] == $u['abbreviation']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['abbreviation']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="text" name="purchase_unit" id="purchaseUnitSelect" class="form-control" placeholder="اتركه فارغاً لاستخدام وحدة البيع" value="<?php echo htmlspecialchars($product['purchase_unit'] ?? ''); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="form-group" id="purchaseUnitFactorGroup" style="<?php echo empty($product['purchase_unit']) ? 'display:none;' : ''; ?>">
                        <label>معامل التحويل <span class="form-text" id="purchaseUnitFactorHint" style="font-weight:400; font-size:0.78rem;"></span></label>
                        <input type="number" name="purchase_unit_factor" id="purchaseUnitFactor" class="form-control" step="0.0001" min="0.0001" value="<?php echo floatval($product['purchase_unit_factor'] ?? 1); ?>">
                        <div class="form-text">1 وحدة شراء = X وحدة بيع أساسية</div>
                    </div>

                    <?php if (!empty($categories)): ?>
                    <div class="form-group">
                        <label>التصنيف</label>
                        <select name="category_id" class="form-control">
                            <option value="">-- بدون تصنيف --</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($product['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>الكمية الحالية</label>
                        <input type="number" name="quantity" class="form-control" step="0.01" value="<?php echo $product['quantity']; ?>">
                    </div>
                    <div class="form-group">
                        <label>الحد الأدنى للمخزون</label>
                        <input type="number" name="min_quantity" class="form-control" step="0.01" value="<?php echo $product['min_quantity']; ?>">
                    </div>

                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="has_expiry" id="has_expiry" value="1" onchange="toggleExpirySectionEdit()" style="width:18px; height:18px; cursor:pointer;" <?php echo !empty($product['has_expiry']) ? 'checked' : ''; ?>>
                            <span style="font-weight:600;">يتطلب تاريخ انتهاء الصلاحية</span>
                        </label>
                        <div class="form-text">فعّل لتتبع تواريخ انتهاء الصلاحية عند الشراء والبيع (FEFO)</div>
                    </div>
                </div>

                <!-- سبب تغيير السعر -->
                <div id="price_change_section" style="display:none; margin-top:1.25rem;">
                    <div class="form-group">
                        <label>سبب تغيير السعر (اختياري)</label>
                        <input type="text" name="change_reason" class="form-control" placeholder="مثال: ارتفاع سعر السوق، خصم من المورد...">
                    </div>
                </div>
            </div>
        </div>

        <!-- وحدات القياس الإضافية للشراء -->
        <div class="card">
            <div class="card-header">
                <h2>وحدات قياس إضافية للشراء</h2>
                <button type="button" class="btn btn-sm btn-outline" onclick="addPuUnitRow()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                    إضافة وحدة شراء
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted" style="margin-bottom: 1rem;">وحدات شراء إضافية مرتبطة بوحدة الشراء الأساسية (مثال: 1 صندوق = 10 كراتين)</p>

                <div id="purchase-additional-units-container">
                    <?php if (!empty($purchase_additional_units_prepared)):
                        $pu_counter = 0;
                        foreach ($purchase_additional_units_prepared as $pau):
                            $pu_counter++;
                            $pau_sel_val = $pau['is_custom'] ? '__custom__' : $pau['unit_name'];
                    ?>
                    <div class="unit-row" id="pu-row-<?php echo $pu_counter; ?>">
                        <div class="form-group" style="min-width:160px;">
                            <label>اسم الوحدة</label>
                            <select name="pau_name[]" class="form-control" onchange="handlePuUnitChange('pu-row-<?php echo $pu_counter; ?>')">
                                <?php echo buildEditPuUnitOptions($units, $product['purchase_unit'] ?? $product['unit'], $pau_sel_val); ?>
                            </select>
                            <?php if ($pau['is_custom']): ?>
                            <div class="au-custom-name-wrap active" id="pu-custom-<?php echo $pu_counter; ?>">
                                <input type="text" name="pau_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;" value="<?php echo htmlspecialchars($pau['unit_name']); ?>">
                            </div>
                            <?php else: ?>
                            <div class="au-custom-name-wrap" id="pu-custom-<?php echo $pu_counter; ?>">
                                <input type="text" name="pau_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;">
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>معامل التحويل <span class="form-text pu-conv-hint" style="font-weight:400; font-size:0.78rem;"></span></label>
                            <input type="number" name="pau_factor[]" class="form-control" step="0.0001" min="0.0001" value="<?php echo $pau['conversion_factor']; ?>">
                        </div>
                        <button type="button" class="btn-remove-unit" onclick="removePuUnitRow('pu-row-<?php echo $pu_counter; ?>')">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <div id="no-pu-units-msg" class="text-muted" style="text-align:center; padding:1rem; <?php echo !empty($purchase_additional_units) ? 'display:none;' : ''; ?>">لم تتم إضافة وحدات شراء إضافية بعد</div>
            </div>
        </div>

        <!-- وحدات القياس الإضافية للبيع -->
        <div class="card">
            <div class="card-header">
                <h2>وحدات قياس إضافية</h2>
                <button type="button" class="btn btn-sm btn-outline" onclick="addUnitRow()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                    إضافة وحدة
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted" style="margin-bottom: 1rem;">أضف وحدات قياس بديلة للمنتج (مثال: كيس يحتوي على 5 كيلوغرامات)</p>

                <!-- وحدة مخصصة (معامل تحويل) -->
                <div class="custom-unit-section" id="customUnitSection">
                    <div class="custom-unit-badge" style="cursor:pointer;" onclick="toggleCustomUnitSection()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                        وحدة مخصصة (معامل تحويل)
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" id="customUnitArrow" style="transition:transform 0.2s;"><path d="M6 9l6 6 6-6"/></svg>
                    </div>
                    <div id="customUnitBody" style="display:none;">
                        <div class="custom-unit-row">
                            <div class="form-group cu-name">
                                <label>اسم الوحدة <span class="required">*</span></label>
                                <input type="text" name="custom_unit_name" id="customUnitName" class="form-control" placeholder="مثال: كيس، صندوق، طرد" value="<?php echo htmlspecialchars($custom_unit_name); ?>">
                            </div>
                            <div class="form-group cu-type">
                                <label>نوع الوحدة</label>
                                <select name="custom_unit_type" id="customUnitType" class="form-control">
                                    <?php foreach ($unit_types_for_custom as $ck => $cl): ?>
                                    <option value="<?php echo $ck; ?>" <?php echo ($custom_unit_type == $ck) ? 'selected' : ''; ?>>
                                        <?php echo $cl; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group cu-conversion">
                                <label>معامل التحويل</label>
                                <input type="number" name="custom_unit_conversion" id="customUnitConversion" class="form-control" step="0.0001" min="0.0001" value="<?php echo $custom_unit_conversion; ?>" placeholder="1">
                                <div class="conversion-hint-custom" id="customConversionHint">
                                    1 وحدة مخصصة = <strong><?php echo htmlspecialchars($custom_unit_conversion); ?></strong> كيلوغرام (كلغ)
                                </div>
                            </div>
                            <div class="form-group cu-price">
                                <label>سعر البيع</label>
                                <input type="number" name="custom_unit_selling_price" id="customUnitSellingPrice" class="form-control" step="0.01" min="0" value="<?php echo $custom_unit_selling_price; ?>" placeholder="0.00">
                                <div class="conversion-hint-custom">سعر البيع لـ 1 وحدة مخصصة</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="additional-units-container">
                    <?php if (!empty($additional_units_prepared)):
                        $edit_counter = 0;
                        foreach ($additional_units_prepared as $au):
                            $edit_counter++;
                            $au_sel_val = $au['is_custom'] ? '__custom__' : $au['unit_name'];
                    ?>
                    <div class="unit-row" id="unit-row-<?php echo $edit_counter; ?>">
                        <div class="form-group" style="min-width:160px;">
                            <label>اسم الوحدة</label>
                            <select name="au_name[]" class="form-control" onchange="handleAuUnitChange('unit-row-<?php echo $edit_counter; ?>')">
                                <?php echo buildEditUnitOptions($units, $product['unit'], $au_sel_val); ?>
                            </select>
                            <?php if ($au['is_custom']): ?>
                            <div class="au-custom-name-wrap active" id="au-custom-<?php echo $edit_counter; ?>">
                                <input type="text" name="au_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;" value="<?php echo htmlspecialchars($au['unit_name']); ?>">
                            </div>
                            <?php else: ?>
                            <div class="au-custom-name-wrap" id="au-custom-<?php echo $edit_counter; ?>">
                                <input type="text" name="au_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;">
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>معامل التحويل <span class="form-text au-conv-hint" style="font-weight:400; font-size:0.78rem;"></span></label>
                            <input type="number" name="au_factor[]" class="form-control" step="0.0001" min="0.0001" value="<?php echo $au['conversion_factor']; ?>">
                        </div>
                        <div class="form-group">
                            <label>سعر البيع</label>
                            <input type="number" name="au_price[]" class="form-control" step="0.01" value="<?php echo $au['selling_price']; ?>">
                        </div>
                        <button type="button" class="btn-remove-unit" onclick="removeUnitRow('unit-row-<?php echo $edit_counter; ?>')">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <div id="no-units-msg" class="text-muted" style="text-align:center; padding:1rem; <?php echo !empty($additional_units) ? 'display:none;' : ''; ?>">لم تتم إضافة وحدات قياس إضافية بعد</div>
            </div>
        </div>

        <!-- صور المنتج — اختيار الصور قبل التعديل -->
        <div class="card" id="productImagesCard">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align:middle; margin-left:4px;">
                        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>
                    </svg>
                    صور المنتج
                </h2>
                <div style="display:flex; gap:8px; align-items:center;">
                    <label class="btn btn-sm btn-outline" style="cursor:pointer; margin:0;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 4v16m8-8H4"/></svg>
                        إضافة صورة
                        <input type="file" id="productImageInput" accept="image/jpeg,image/png,image/gif,image/webp" multiple style="display:none;" onchange="handleImageSelect(this.files)">
                    </label>
                </div>
            </div>
            <div class="card-body">
                <div class="image-drop-zone" id="imageDropZone" onclick="document.getElementById('productImageInput').click()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="40" height="40" style="color:var(--gray-300); margin-bottom:8px;">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <p style="color:var(--gray-400); margin:0; font-weight:600;">اسحب الصور هنا أو انقر للاختيار</p>
                    <p style="color:var(--gray-300); margin:4px 0 0; font-size:0.78rem;">JPEG, PNG, GIF, WebP — الحد الأقصى 5 ميغابايت</p>
                    <p style="color:var(--primary); margin:6px 0 0; font-size:0.75rem; font-weight:600;">سيتم رفع الصور تلقائياً بعد حفظ التعديلات</p>
                </div>
                <div style="display:flex; align-items:center; gap:12px; margin-top:12px; flex-wrap:wrap;">
                    <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.85rem;">
                        <input type="checkbox" id="removeBgCheckbox" value="1" style="width:16px; height:16px; cursor:pointer;">
                        <span style="font-weight:600;">إزالة الخلفية</span>
                    </label>
                    <select id="bgToleranceSelect" class="form-control" style="width:auto; min-width:120px; padding:4px 8px; font-size:0.82rem; display:none;">
                        <option value="low">منخفض (دقيق)</option>
                        <option value="medium" selected>متوسط</option>
                        <option value="high">عالي (قوي)</option>
                    </select>
                </div>
                <div id="imageUploadProgress" style="display:none; margin-top:12px;">
                    <div style="background:var(--gray-100); border-radius:8px; overflow:hidden; height:8px;">
                        <div id="imageProgressBar" style="background:var(--primary); height:100%; width:0%; transition:width 0.3s;"></div>
                    </div>
                    <p id="imageUploadStatus" style="font-size:0.8rem; color:var(--gray-500); margin-top:4px;">جاري الرفع...</p>
                </div>
                <div id="pendingImageGallery" style="display:flex; gap:12px; flex-wrap:wrap; margin-top:16px;"></div>

                <!-- معرض الصور الموجودة -->
                <div id="imageGallery" style="display:flex; gap:12px; flex-wrap:wrap; margin-top:16px;">
                    <?php if (!empty($productImages)):
                        $_editImgBase = (!empty($base_url) ? $base_url . '/' : '/');
                        foreach ($productImages as $img):
                            $thumbWeb = !empty($img['thumbnail_path']) ? $_editImgBase . ProductImageService::getWebPath($img['thumbnail_path']) : '';
                            $mediumWeb = !empty($img['medium_path']) ? $_editImgBase . ProductImageService::getWebPath($img['medium_path']) : $thumbWeb;
                            $isPrimary = !empty($img['is_primary']);
                    ?>
                    <div class="product-image-item" data-image-id="<?php echo $img['id']; ?>" style="width:120px; position:relative; border-radius:10px; overflow:hidden; border:2px solid <?php echo $isPrimary ? 'var(--primary)' : 'var(--gray-200)'; ?>; background:#fff; cursor:pointer;">
                        <div style="width:120px; height:90px; overflow:hidden; display:flex; align-items:center; justify-content:center; background:var(--gray-50);" onclick="viewImage('<?php echo htmlspecialchars($mediumWeb); ?>')">
                            <img src="<?php echo htmlspecialchars($thumbWeb); ?>" alt="صورة" style="max-width:100%; max-height:100%; object-fit:contain;">
                        </div>
                        <div style="padding:4px 6px; font-size:0.7rem; display:flex; justify-content:space-between; align-items:center;">
                            <?php if ($isPrimary): ?>
                            <span style="background:var(--primary); color:#fff; padding:1px 5px; border-radius:4px; font-weight:600;">رئيسية</span>
                            <?php endif; ?>
                            <div style="display:flex; gap:4px; margin-<?php echo $isPrimary ? '' : 'right:auto; margin-left:auto;'; ?>">
                                <button type="button" onclick="event.stopPropagation(); setImagePrimary(<?php echo $img['id']; ?>)" title="تعيين كرئيسية" style="background:none; border:none; cursor:pointer; color:var(--gray-400); padding:2px;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--gray-400)'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </button>
                                <button type="button" onclick="event.stopPropagation(); deleteImage(<?php echo $img['id']; ?>)" title="حذف" style="background:none; border:none; cursor:pointer; color:var(--gray-400); padding:2px;" onmouseover="this.style.color='#dc2626'" onmouseout="this.style.color='var(--gray-400)'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p id="noImagesMsg" style="text-align:center; color:var(--gray-400); margin-top:16px; <?php echo !empty($productImages) ? 'display:none;' : ''; ?>">لا توجد صور بعد — اختر صورة أو اسحبها هنا</p>
            </div>
        </div>


        <!-- تواريخ انتهاء الصلاحية -->
        <div class="card" id="expiryDatesCard" style="<?php echo empty($product['has_expiry']) ? 'display:none;' : ''; ?>">
            <div class="card-header">
                <h2>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" style="vertical-align:middle; margin-left:4px;">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                        <path d="M9 16l2 2 4-4"/>
                    </svg>
                    تواريخ انتهاء الصلاحية
                </h2>
            </div>
            <div class="card-body">
                <!-- الدفعات الحالية (للعرض فقط) -->
                <div id="existing-batches-section">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                        <h3 style="margin:0; font-size:0.95rem; color:var(--gray-600);">📦 الدفعات الحالية</h3>
                        <span style="font-size:0.78rem; color:var(--gray-400);">للعرض فقط</span>
                    </div>
                    <div id="expiry-rows-container"></div>
                    <div id="expiry-loading-msg" style="text-align:center; padding:1rem; color:var(--gray-400);">
                        <div class="spinner" style="margin:0 auto 6px;"></div>
                        جاري تحميل الدفعات...
                    </div>
                    <div id="expiry-empty-msg" style="display:none; text-align:center; padding:1rem; color:var(--gray-400);">
                        لا توجد دفعات مسجلة لهذا المنتج بعد.
                    </div>
                    <div id="expiry-summary" style="display:none; margin-top:0.75rem;">
                        <div style="display:flex; gap:12px; flex-wrap:wrap;">
                            <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:8px 14px; text-align:center;">
                                <div style="font-size:0.75rem; color:var(--gray-500);">إجمالي الكمية في الدفعات</div>
                                <div id="expiryTotalQty" style="font-weight:800; color:#059669; font-size:1.1rem;">0</div>
                            </div>
                            <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:8px 14px; text-align:center;">
                                <div style="font-size:0.75rem; color:var(--gray-500);">دفعات قاربت على الانتهاء</div>
                                <div id="expiryNearEndQty" style="font-weight:800; color:#dc2626; font-size:1.1rem;">0</div>
                            </div>
                            <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:8px; padding:8px 14px; text-align:center;">
                                <div style="font-size:0.75rem; color:var(--gray-500);">دفعات منتهية الصلاحية</div>
                                <div id="expiryExpiredQty" style="font-weight:800; color:#1e40af; font-size:1.1rem;">0</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- فاصل -->
                <hr style="margin: 1.25rem 0; border:none; border-top:2px dashed #93c5fd;">

                <!-- قسم إضافة تواريخ جديدة (يظهر عند تغيير الكمية) -->
                <div id="new-expiry-section" style="display:none;">
                    <div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:0.5rem; margin-bottom:0.75rem;">
                        <h3 style="margin:0; font-size:0.95rem; color:var(--gray-700);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" width="16" height="16" style="vertical-align:middle; margin-left:4px;">
                                <path d="M12 4v16m8-8H4"/>
                            </svg>
                            إضافة تواريخ انتهاء للكمية الجديدة
                        </h3>
                        <button type="button" onclick="addExpiryRowEdit()"
                                style="background:#eff6ff; color:#2563eb; border:1px solid #93c5fd; padding:6px 14px; border-radius:8px; cursor:pointer; font-weight:700; font-size:0.85rem;">
                            + إضافة تاريخ
                        </button>
                    </div>
                    <div class="alert alert-info" id="newExpiryInfoAlert" style="font-size:0.85rem;">
                        <strong>ملاحظة:</strong> مجموع كميات التواريخ الجديدة يجب أن يساوي الزيادة في الكمية
                        (<span id="newExpiryQtyDiff" style="font-weight:800; color:#2563eb;">0</span>)
                    </div>
                    <div id="new-expiry-rows-container"></div>
                    <div id="new-expiry-summary" style="display:none; margin-top:0.75rem;">
                        <div style="display:flex; gap:12px; flex-wrap:wrap;">
                            <div style="background:#eff6ff; border:1px solid #93c5fd; border-radius:8px; padding:8px 14px; text-align:center;">
                                <div style="font-size:0.75rem; color:var(--gray-500);">مجموع كميات التواريخ الجديدة</div>
                                <div id="newExpiryTotalQty" style="font-weight:800; color:#2563eb; font-size:1.1rem;">0</div>
                            </div>
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 14px; text-align:center;">
                                <div style="font-size:0.75rem; color:var(--gray-500);">الزيادة المطلوبة</div>
                                <div id="newExpiryRequiredQty" style="font-weight:800; color:#1e40af; font-size:1.1rem;">0</div>
                            </div>
                            <div id="newExpiryDiffBox" style="border-radius:8px; padding:8px 14px; text-align:center; display:none;">
                                <div style="font-size:0.75rem; color:var(--gray-500);">الفارق</div>
                                <div id="newExpiryDiff" style="font-weight:800; font-size:1.1rem;">0</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- رسالة عند عدم تغيير الكمية -->
                <div id="expiry-no-change-msg" style="display:none; text-align:center; padding:1rem; color:var(--gray-400); font-size:0.9rem;">
                    قم بتغيير حقل <strong>الكمية الحالية</strong> أعلاه لإضافة تواريخ انتهاء صلاحية جديدة.
                </div>
            </div>
        </div>

        <div style="margin-top:1.5rem;" class="btn-group">
            <?php echo csrfField(); ?>
            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">حفظ التعديلات</button>
            <a href="list-products.php" class="btn btn-outline btn-lg">إلغاء</a>
        </div>
    </form>

    <!-- سجل تغيير الأسعار -->
    <div class="card" style="margin-top:2rem;">
        <div class="card-header"><h2>سجل تغيير الأسعار</h2></div>
        <div class="card-body">
            <?php if (empty($price_history)): ?>
                <p class="text-muted" style="text-align:center; padding:1.5rem;">لم يتم تسجيل أي تغيير في الأسعار بعد</p>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>التاريخ والوقت</th>
                            <th>سعر الشراء القديم</th>
                            <th>سعر الشراء الجديد</th>
                            <th>سعر البيع القديم</th>
                            <th>سعر البيع الجديد</th>
                            <th>السبب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($price_history as $ph): ?>
                        <tr>
                            <td><?php echo $ph['changed_at']; ?></td>
                            <td><?php echo formatMoney($ph['old_purchase_price']); ?></td>
                            <td class="text-bold"><?php echo formatMoney($ph['new_purchase_price']); ?></td>
                            <td><?php echo formatMoney($ph['old_selling_price']); ?></td>
                            <td class="text-bold"><?php echo formatMoney($ph['new_selling_price']); ?></td>
                            <td><?php echo htmlspecialchars($ph['change_reason'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
 
<?php
// دالة مساعدة لبناء قائمة الوحدات المنسدلة حسب نوع الوحدة الأساسية
function buildEditUnitOptions($allUnits, $baseUnitAbbr, $selectedVal) {
    $baseType = '';
    foreach ($allUnits as $u) {
        if ($u['abbreviation'] === $baseUnitAbbr) {
            $baseType = $u['unit_type'] ?? '';
            break;
        }
    }
    $html = '<option value="">-- اختر الوحدة --</option>';
    foreach ($allUnits as $u) {
        $utype = $u['unit_type'] ?? '';
        if ($baseType && $utype !== $baseType) continue;
        $sel = ($selectedVal === $u['abbreviation'] && $selectedVal !== '__custom__') ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($u['abbreviation']) . '"' . $sel . '>' . htmlspecialchars($u['name']) . ' (' . htmlspecialchars($u['abbreviation']) . ')</option>';
    }
    $selCustom = ($selectedVal === '__custom__') ? ' selected' : '';
    $html .= '<option value="__custom__"' . $selCustom . '>────────── أخرى ──────────</option>';
    return $html;
}

function buildEditPuUnitOptions($allUnits, $baseUnitAbbr, $selectedVal) {
    $baseType = '';
    foreach ($allUnits as $u) {
        if ($u['abbreviation'] === $baseUnitAbbr) {
            $baseType = $u['unit_type'] ?? '';
            break;
        }
    }
    $html = '<option value="">-- اختر الوحدة --</option>';
    foreach ($allUnits as $u) {
        $utype = $u['unit_type'] ?? '';
        if ($baseType && $utype !== $baseType) continue;
        // استبعاد وحدة البيع الأساسية نفسها (لأنها ليست وحدة شراء إضافية)
        $sel = ($selectedVal === $u['abbreviation'] && $selectedVal !== '__custom__') ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($u['abbreviation']) . '"' . $sel . '>' . htmlspecialchars($u['name']) . ' (' . htmlspecialchars($u['abbreviation']) . ')</option>';
    }
    $selCustom = ($selectedVal === '__custom__') ? ' selected' : '';
    $html .= '<option value="__custom__"' . $selCustom . '>────────── أخرى ──────────</option>';
    return $html;
}
?>

<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// === بيانات الوحدات (من PHP) ===
var unitsList = <?php echo json_encode(array_map(function($u) { return ['name' => $u['name'], 'abbr' => $u['abbreviation'], 'type' => isset($u['unit_type']) ? $u['unit_type'] : 'other', 'conv' => isset($u['conversion_to_base']) ? floatval($u['conversion_to_base']) : 1, 'isBase' => isset($u['is_base_unit']) ? intval($u['is_base_unit']) : 0]; }, $units)); ?>;
var baseUnitsByType = <?php echo json_encode($base_units_by_type); ?>;

// === القيم الأصلية للأسعار ===
var originalPurchasePrice = <?php echo $product['purchase_price']; ?>;
var originalSellingPrice = <?php echo $product['selling_price']; ?>;

function checkPriceChange() {
    var currentPurchase = parseFloat(document.getElementById('purchase_price_input').value) || 0;
    var currentSelling = parseFloat(document.getElementById('selling_price_input').value) || 0;
    var purchaseChanged = currentPurchase !== originalPurchasePrice;
    var sellingChanged = currentSelling !== originalSellingPrice;
    document.getElementById('purchase_price_hint').style.display = purchaseChanged ? 'block' : 'none';
    document.getElementById('selling_price_hint').style.display = sellingChanged ? 'block' : 'none';
    document.getElementById('price_change_section').style.display = (purchaseChanged || sellingChanged) ? 'block' : 'none';
}

// === تحديد نوع الوحدة الأساسية ===
function getSelectedBaseUnitType() {
    var sel = document.getElementById('unitSelect');
    if (!sel || !sel.value) return '';
    for (var i = 0; i < unitsList.length; i++) {
        if (unitsList[i].abbr === sel.value) return unitsList[i].type;
    }
    return '';
}

// === بناء قائمة الوحدات المناسبة لنوع الوحدة الأساسية ===
function buildUnitOptionsHTML(selectedVal) {
    var baseType = getSelectedBaseUnitType();
    var html = '<option value="">-- اختر الوحدة --</option>';
    for (var i = 0; i < unitsList.length; i++) {
        var u = unitsList[i];
        if (baseType && u.type !== baseType) continue;
        var sel = (selectedVal === u.abbr) ? ' selected' : '';
        html += '<option value="' + u.abbr + '"' + sel + '>' + u.name + ' (' + u.abbr + ')</option>';
    }
    var selCustom = (selectedVal === '__custom__') ? ' selected' : '';
    html += '<option value="__custom__"' + selCustom + '>────────── أخرى ──────────</option>';
    return html;
}

// === تحديث جميع قوائم الوحدات الإضافية عند تغيير الوحدة الأساسية ===
function refreshAllAdditionalUnitDropdowns() {
    var rows = document.querySelectorAll('#additional-units-container .unit-row');
    rows.forEach(function(row) {
        var rowId = row.id;
        var sel = row.querySelector('select[name="au_name[]"]');
        var factorInput = row.querySelector('input[name="au_factor[]"]');
        var customWrap = row.querySelector('.au-custom-name-wrap');
        if (!sel) return;
        var prevVal = sel.value;
        sel.innerHTML = buildUnitOptionsHTML('');
        if (prevVal && prevVal !== '__custom__') {
            var opts = sel.querySelectorAll('option');
            var found = false;
            for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === prevVal) { found = true; break; }
            }
            if (found) {
                sel.value = prevVal;
                // تحديث معامل التحويل من unitsList بعد تغيير الوحدة الأساسية
                handleAuUnitChange(rowId);
            } else {
                sel.value = '';
                if (factorInput) { factorInput.value = '1'; factorInput.removeAttribute('readonly'); factorInput.style.opacity = '1'; }
            }
        } else {
            sel.value = '';
            if (customWrap) customWrap.classList.remove('active');
            var customInput = row.querySelector('input[name="au_custom_name[]"]');
            if (customInput) customInput.value = '';
        }
    });
}

// === وحدات القياس الإضافية ===
var unitCounter = <?php echo count($additional_units_prepared); ?>;

function addUnitRow() {
    unitCounter++;
    var container = document.getElementById('additional-units-container');
    document.getElementById('no-units-msg').style.display = 'none';
    var row = document.createElement('div');
    row.className = 'unit-row';
    row.id = 'unit-row-' + unitCounter;
    row.innerHTML = `
        <div class="form-group" style="min-width:160px;">
            <label>اسم الوحدة</label>
            <select name="au_name[]" class="form-control" onchange="handleAuUnitChange('unit-row-${unitCounter}')">
                ${buildUnitOptionsHTML('')}
            </select>
            <div class="au-custom-name-wrap" id="au-custom-${unitCounter}">
                <input type="text" name="au_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;">
            </div>
        </div>
        <div class="form-group">
            <label>معامل التحويل <span class="form-text au-conv-hint" style="font-weight:400; font-size:0.78rem;"></span></label>
            <input type="number" name="au_factor[]" class="form-control" step="0.0001" min="0.0001" value="1" placeholder="1">
        </div>
        <div class="form-group">
            <label>سعر البيع</label>
            <input type="number" name="au_price[]" class="form-control" step="0.01" min="0" value="0" placeholder="0.00">
        </div>
        <button type="button" class="btn-remove-unit" onclick="removeUnitRow('unit-row-${unitCounter}')">✕</button>
    `;
    container.appendChild(row);
}

function getBaseUnitConv() {
    var sel = document.getElementById('unitSelect');
    if (!sel || !sel.value) return 1;
    for (var i = 0; i < unitsList.length; i++) {
        if (unitsList[i].abbr === sel.value) return unitsList[i].conv;
    }
    return 1;
}

function getBaseUnitAbbr() {
    var sel = document.getElementById('unitSelect');
    if (!sel || !sel.value) return '';
    return sel.value;
}

function handleAuUnitChange(rowId) {
    var row = document.getElementById(rowId);
    if (!row) return;
    var sel = row.querySelector('select[name="au_name[]"]');
    var customWrap = row.querySelector('.au-custom-name-wrap');
    var customInput = row.querySelector('input[name="au_custom_name[]"]');
    var factorInput = row.querySelector('input[name="au_factor[]"]');
    if (!sel || !customWrap) return;

    if (sel.value === '__custom__') {
        customWrap.classList.add('active');
        if (customInput) customInput.focus();
        if (factorInput) { factorInput.value = '1'; factorInput.removeAttribute('readonly'); factorInput.style.opacity = '1'; }
    } else {
        customWrap.classList.remove('active');
        if (customInput) customInput.value = '';
        // حساب معامل التحويل النسبي بين الوحدة الإضافية ووحدة المنتج الأساسية
        // المعامل = conversion_to_base للوحدة الإضافية / conversion_to_base للوحدة الأساسية
        // مثال: إذا الوحدة الأساسية هي أوقية (conv=0.05) والإضافية كلغ (conv=1)
        // المعامل = 1 / 0.05 = 20  أي 1 كلغ = 20 أوقية
        if (factorInput) {
            var matched = false;
            var baseConv = getBaseUnitConv();
            for (var i = 0; i < unitsList.length; i++) {
                if (unitsList[i].abbr === sel.value) {
                    matched = true;
                    var auConv = unitsList[i].conv;
                    var relativeConv = (baseConv > 0) ? (auConv / baseConv) : 1;
                    if (relativeConv > 0) {
                        factorInput.value = relativeConv;
                    } else {
                        factorInput.value = '1';
                    }
                    // تحديث التلميح ليوضح الوحدة الأساسية الفعلية
                    var hintSpan = row.querySelector('.au-conv-hint');
                    if (hintSpan) {
                        var baseAbbr = getBaseUnitAbbr();
                        hintSpan.textContent = '(1 ' + sel.value + ' = ? ' + baseAbbr + ')';
                    }
                    if (relativeConv === 1) {
                        factorInput.setAttribute('readonly', true);
                        factorInput.style.opacity = '0.5';
                    } else {
                        factorInput.removeAttribute('readonly');
                        factorInput.style.opacity = '1';
                    }
                    break;
                }
            }
            if (!matched) {
                factorInput.value = '1';
                factorInput.removeAttribute('readonly');
                factorInput.style.opacity = '1';
            }
        }
    }
}

function removeUnitRow(id) {
    var row = document.getElementById(id);
    if (row) {
        row.remove();
        var container = document.getElementById('additional-units-container');
        if (container.children.length === 0) {
            document.getElementById('no-units-msg').style.display = 'block';
        }
    }
}

// === فتح/إغلاق قسم الوحدة المخصصة ===
function toggleCustomUnitSection() {
    var section = document.getElementById('customUnitSection');
    var body = document.getElementById('customUnitBody');
    var arrow = document.getElementById('customUnitArrow');
    if (!section.classList.contains('active')) {
        section.classList.add('active');
        body.style.display = '';
        arrow.style.transform = '';
        document.getElementById('customUnitName').focus();
    } else {
        body.style.display = 'none';
        arrow.style.transform = 'rotate(-90deg)';
    }
}

// === تحديث معامل التحويل المخصص ===
var customUnitType = document.getElementById('customUnitType');
var customUnitConversion = document.getElementById('customUnitConversion');
var customConversionHint = document.getElementById('customConversionHint');

function updateCustomConversionHint() {
    if (!customUnitType || !customUnitConversion || !customConversionHint) return;
    var type = customUnitType.value;
    var bu = baseUnitsByType[type];
    var val = parseFloat(customUnitConversion.value) || 1;
    if (bu) {
        customConversionHint.innerHTML = '1 وحدة مخصصة = <strong>' + val + '</strong> ' + bu.name + ' (' + bu.abbreviation + ')';
    } else {
        customConversionHint.innerHTML = '1 وحدة مخصصة = <strong>' + val + '</strong> وحدة أساسية';
    }
}

if (customUnitType) customUnitType.addEventListener('change', updateCustomConversionHint);
if (customUnitConversion) customUnitConversion.addEventListener('input', updateCustomConversionHint);

// === تهيئة عند التحميل ===
document.addEventListener('DOMContentLoaded', function() {
    // عند تغيير الوحدة الأساسية، حدّث قوائم الوحدات الإضافية
    var unitSelectMain = document.getElementById('unitSelect');
    if (unitSelectMain) {
        unitSelectMain.addEventListener('change', function() {
            refreshAllAdditionalUnitDropdowns();
        });
    }

    // تحديث معاملات التحويل للوحدات الإضافية الموجودة عند تحميل الصفحة
    var existingRows = document.querySelectorAll('#additional-units-container .unit-row');
    existingRows.forEach(function(row) {
        var sel = row.querySelector('select[name="au_name[]"]');
        if (sel && sel.value && sel.value !== '' && sel.value !== '__custom__') {
            handleAuUnitChange(row.id);
        }
    });

    // التحقق من الوحدات المخصصة قبل الإرسال
    var form = document.querySelector('form[method="POST"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            var customName = document.getElementById('customUnitName');
            var customSection = document.getElementById('customUnitSection');
            if (customSection && customSection.classList.contains('active') && customName && customName.value.trim()) {
                var customConv = document.getElementById('customUnitConversion');
                if (parseFloat(customConv.value) <= 0) {
                    e.preventDefault();
                    customConv.style.borderColor = 'var(--danger)';
                    customConv.focus();
                    alert('معامل التحويل يجب أن يكون أكبر من صفر');
                    return;
                }
            }
            var rows = document.querySelectorAll('#additional-units-container .unit-row');
            rows.forEach(function(row) {
                var sel = row.querySelector('select[name="au_name[]"]');
                var customInput = row.querySelector('input[name="au_custom_name[]"]');
                if (sel && sel.value === '__custom__' && customInput && !customInput.value.trim()) {
                    e.preventDefault();
                    customInput.style.borderColor = 'var(--danger)';
                    customInput.focus();
                    alert('يرجى إدخال اسم الوحدة المخصصة');
                }
            });
            // التحقق من وحدات الشراء المخصصة
            var puRows = document.querySelectorAll('#purchase-additional-units-container .unit-row');
            puRows.forEach(function(row) {
                var sel = row.querySelector('select[name="pau_name[]"]');
                var customInput = row.querySelector('input[name="pau_custom_name[]"]');
                if (sel && sel.value === '__custom__' && customInput && !customInput.value.trim()) {
                    e.preventDefault();
                    customInput.style.borderColor = 'var(--danger)';
                    customInput.focus();
                    alert('يرجى إدخال اسم وحدة الشراء المخصصة');
                }
            });
        });
    }

    // تهيئة وحدات الشراء الإضافية عند تحميل الصفحة
    initPurchaseUnitSystem();

    // تحميل الدفعات تلقائياً إذا كان تاريخ الانتهاء مفعّلاً
    var hasExpiryCheckbox = document.getElementById('has_expiry');
    if (hasExpiryCheckbox && hasExpiryCheckbox.checked) {
        loadProductBatches().then(function() {
            checkQuantityChange();
        });
    }

    // مراقبة تغيير الكمية لفتح قسم التواريخ
    var qtyInputField = document.querySelector('input[name="quantity"]');
    if (qtyInputField) {
        qtyInputField.addEventListener('input', function() {
            var hasExpiry = document.getElementById('has_expiry');
            if (hasExpiry && hasExpiry.checked) {
                checkQuantityChange();
            }
        });
    }
});

// === نظام وحدات الشراء ===
var purchaseUnitCounter = <?php echo count($purchase_additional_units_prepared); ?>;

function onPurchaseUnitChange() {
    var puSel = document.getElementById('purchaseUnitSelect');
    var factorGroup = document.getElementById('purchaseUnitFactorGroup');
    var factorInput = document.getElementById('purchaseUnitFactor');
    var hintSpan = document.getElementById('purchaseUnitFactorHint');
    if (!puSel || !factorGroup) return;

    if (puSel.value) {
        factorGroup.style.display = '';
        // حساب معامل التحويل النسبي (كما في handleAuUnitChange)
        var baseConv = getBaseUnitConv();
        for (var i = 0; i < unitsList.length; i++) {
            if (unitsList[i].abbr === puSel.value) {
                var auConv = unitsList[i].conv;
                var relativeConv = (baseConv > 0) ? (auConv / baseConv) : 1;
                factorInput.value = relativeConv;
                if (hintSpan) {
                    var baseAbbr = getBaseUnitAbbr();
                    hintSpan.textContent = '(1 ' + puSel.value + ' = ? ' + baseAbbr + ')';
                }
                break;
            }
        }
        refreshAllPurchaseUnitDropdowns();
    } else {
        factorGroup.style.display = 'none';
        factorInput.value = '1';
        refreshAllPurchaseUnitDropdowns();
    }
}

function getPurchaseBaseUnitConv() {
    var sel = document.getElementById('purchaseUnitSelect');
    if (!sel || !sel.value) return getBaseUnitConv();
    for (var i = 0; i < unitsList.length; i++) {
        if (unitsList[i].abbr === sel.value) return unitsList[i].conv;
    }
    return 1;
}

function getPurchaseBaseUnitAbbr() {
    var sel = document.getElementById('purchaseUnitSelect');
    if (!sel || !sel.value) return getBaseUnitAbbr();
    return sel.value;
}

function getSelectedPurchaseBaseUnitType() {
    var sel = document.getElementById('purchaseUnitSelect');
    if (!sel || !sel.value) return getSelectedBaseUnitType();
    for (var i = 0; i < unitsList.length; i++) {
        if (unitsList[i].abbr === sel.value) return unitsList[i].type;
    }
    return '';
}

function buildPuUnitOptionsHTML(selectedVal) {
    var puBaseType = getSelectedPurchaseBaseUnitType();
    var html = '<option value="">-- اختر الوحدة --</option>';
    for (var i = 0; i < unitsList.length; i++) {
        var u = unitsList[i];
        if (puBaseType && u.type !== puBaseType) continue;
        var sel = (selectedVal === u.abbr) ? ' selected' : '';
        html += '<option value="' + u.abbr + '"' + sel + '>' + u.name + ' (' + u.abbr + ')</option>';
    }
    html += '<option value="__custom__">────────── أخرى ──────────</option>';
    return html;
}

function refreshAllPurchaseUnitDropdowns() {
    var rows = document.querySelectorAll('#purchase-additional-units-container .unit-row');
    rows.forEach(function(row) {
        var rowId = row.id;
        var sel = row.querySelector('select[name="pau_name[]"]');
        var factorInput = row.querySelector('input[name="pau_factor[]"]');
        var customWrap = row.querySelector('.au-custom-name-wrap');
        if (!sel) return;
        var prevVal = sel.value;
        sel.innerHTML = buildPuUnitOptionsHTML('');
        if (prevVal && prevVal !== '__custom__') {
            var opts = sel.querySelectorAll('option');
            var found = false;
            for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === prevVal) { found = true; break; }
            }
            if (found) {
                sel.value = prevVal;
                handlePuUnitChange(rowId);
            } else {
                sel.value = '';
                if (factorInput) { factorInput.value = '1'; factorInput.removeAttribute('readonly'); factorInput.style.opacity = '1'; }
            }
        } else {
            sel.value = '';
            if (customWrap) customWrap.classList.remove('active');
            var customInput = row.querySelector('input[name="pau_custom_name[]"]');
            if (customInput) customInput.value = '';
        }
    });
}

function addPuUnitRow() {
    purchaseUnitCounter++;
    var container = document.getElementById('purchase-additional-units-container');
    document.getElementById('no-pu-units-msg').style.display = 'none';
    var row = document.createElement('div');
    row.className = 'unit-row';
    row.id = 'pu-row-' + purchaseUnitCounter;
    row.innerHTML = `
        <div class="form-group" style="min-width:160px;">
            <label>اسم الوحدة</label>
            <select name="pau_name[]" class="form-control" onchange="handlePuUnitChange('pu-row-${purchaseUnitCounter}')">
                ${buildPuUnitOptionsHTML('')}
            </select>
            <div class="au-custom-name-wrap" id="pu-custom-${purchaseUnitCounter}">
                <input type="text" name="pau_custom_name[]" class="form-control" placeholder="أدخل اسم الوحدة المخصصة" style="margin-top:4px; border-color:#fbbf24;">
            </div>
        </div>
        <div class="form-group">
            <label>معامل التحويل <span class="form-text pu-conv-hint" style="font-weight:400; font-size:0.78rem;"></span></label>
            <input type="number" name="pau_factor[]" class="form-control" step="0.0001" min="0.0001" value="1" placeholder="1">
        </div>
        <button type="button" class="btn-remove-unit" onclick="removePuUnitRow('pu-row-${purchaseUnitCounter}')">✕</button>
    `;
    container.appendChild(row);
}

function handlePuUnitChange(rowId) {
    var row = document.getElementById(rowId);
    if (!row) return;
    var sel = row.querySelector('select[name="pau_name[]"]');
    var customWrap = row.querySelector('.au-custom-name-wrap');
    var customInput = row.querySelector('input[name="pau_custom_name[]"]');
    var factorInput = row.querySelector('input[name="pau_factor[]"]');
    if (!sel || !customWrap) return;

    if (sel.value === '__custom__') {
        customWrap.classList.add('active');
        if (customInput) customInput.focus();
        if (factorInput) { factorInput.value = '1'; factorInput.removeAttribute('readonly'); factorInput.style.opacity = '1'; }
    } else {
        customWrap.classList.remove('active');
        if (customInput) customInput.value = '';
        if (factorInput) {
            var puBaseConv = getPurchaseBaseUnitConv();
            for (var i = 0; i < unitsList.length; i++) {
                if (unitsList[i].abbr === sel.value) {
                    var auConv = unitsList[i].conv;
                    var relativeConv = (puBaseConv > 0) ? (auConv / puBaseConv) : 1;
                    factorInput.value = relativeConv;
                    var hintSpan = row.querySelector('.pu-conv-hint');
                    if (hintSpan) {
                        var puBaseAbbr = getPurchaseBaseUnitAbbr();
                        hintSpan.textContent = '(1 ' + sel.value + ' = ? ' + puBaseAbbr + ')';
                    }
                    if (relativeConv === 1) {
                        factorInput.setAttribute('readonly', true);
                        factorInput.style.opacity = '0.5';
                    } else {
                        factorInput.removeAttribute('readonly');
                        factorInput.style.opacity = '1';
                    }
                    break;
                }
            }
        }
    }
}

function removePuUnitRow(id) {
    var row = document.getElementById(id);
    if (row) {
        row.remove();
        var container = document.getElementById('purchase-additional-units-container');
        if (container.children.length === 0) {
            document.getElementById('no-pu-units-msg').style.display = 'block';
        }
    }
}

function initPurchaseUnitSystem() {
    // تهيئة معامل التحويل لوحدة الشراء الأساسية
    var puSel = document.getElementById('purchaseUnitSelect');
    if (puSel && puSel.value) {
        onPurchaseUnitChange();
    }
    // تهيئة تلميحات وحدات الشراء الإضافية
    var puRows = document.querySelectorAll('#purchase-additional-units-container .unit-row');
    puRows.forEach(function(row) {
        var sel = row.querySelector('select[name="pau_name[]"]');
        if (sel && sel.value && sel.value !== '' && sel.value !== '__custom__') {
            handlePuUnitChange(row.id);
        }
    });
}

// === تواريخ انتهاء الصلاحية (صفحة التعديل) ===
var productId = <?php echo $id; ?>;
var editExpiryCounter = 0;
var newExpiryCounter = 0;
var originalQuantity = <?php echo floatval($product['quantity']); ?>;
var batchesLoaded = false;

function toggleExpirySectionEdit() {
    var checkbox = document.getElementById('has_expiry');
    var card = document.getElementById('expiryDatesCard');
    if (checkbox.checked) {
        card.style.display = '';
        if (!batchesLoaded) loadProductBatches();
        checkQuantityChange();
    } else {
        card.style.display = 'none';
    }
}

function loadProductBatches() {
    var container = document.getElementById('expiry-rows-container');
    var loadingMsg = document.getElementById('expiry-loading-msg');
    var emptyMsg = document.getElementById('expiry-empty-msg');
    var summary = document.getElementById('expiry-summary');

    container.innerHTML = '';
    if (loadingMsg) loadingMsg.style.display = '';
    if (emptyMsg) emptyMsg.style.display = 'none';
    if (summary) summary.style.display = 'none';

    return fetch('../api/get_batches.php?product_id=' + productId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (loadingMsg) loadingMsg.style.display = 'none';
            batchesLoaded = true;

            if (!data.batches || data.batches.length === 0) {
                if (emptyMsg) emptyMsg.style.display = '';
                return;
            }

            editExpiryCounter = 0;
            var totalQty = 0;
            var nearEndQty = 0;
            var expiredQty = 0;
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            var nearEndDays = 30;

            data.batches.forEach(function(batch) {
                editExpiryCounter++;
                var remaining = parseFloat(batch.remaining_quantity) || 0;
                totalQty += remaining;

                var expiryDate = batch.expiry_date ? new Date(batch.expiry_date) : null;
                var statusClass = '';
                var statusText = '';
                var rowBg = '';

                if (!expiryDate) {
                    statusClass = 'color: var(--gray-500);';
                    statusText = 'بدون تاريخ';
                    rowBg = 'background: #f8fafc; border: 1px solid #e2e8f0;';
                } else {
                    var diffDays = Math.floor((expiryDate - today) / (1000 * 60 * 60 * 24));
                    if (diffDays < 0) {
                        statusClass = 'color: #dc2626; font-weight: 800;';
                        statusText = 'منتهي الصلاحية';
                        rowBg = 'background: #fef2f2; border: 1px solid #fca5a5;';
                        expiredQty += remaining;
                    } else if (diffDays <= nearEndDays) {
                        statusClass = 'color: #d97706; font-weight: 700;';
                        statusText = 'قارب على الانتهاء (' + diffDays + ' يوم)';
                        rowBg = 'background: #fffbeb; border: 1px solid #fde68a;';
                        nearEndQty += remaining;
                    } else {
                        statusClass = 'color: #059669;';
                        statusText = 'صالح (' + diffDays + ' يوم متبقي)';
                        rowBg = 'background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 1px solid #86efac;';
                    }
                }

                var row = document.createElement('div');
                row.className = 'expiry-row';
                row.id = 'expiry-row-' + editExpiryCounter;
                row.style.cssText = rowBg + ' border-radius:10px; padding:10px 14px; margin-bottom:8px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; animation: fadeSlideDown 0.3s ease;';
                row.innerHTML = '\
                    <div style="flex:1; min-width:130px;">\
                        <div style="font-size:0.72rem; color:var(--gray-400); margin-bottom:2px;">تاريخ الانتهاء</div>\
                        <div style="font-weight:700; font-size:0.95rem;">' + (batch.expiry_date || '—') + '</div>\
                    </div>\
                    <div style="min-width:100px; text-align:center;">\
                        <div style="font-size:0.72rem; color:var(--gray-400); margin-bottom:2px;">الكمية المتبقية</div>\
                        <div style="font-weight:800; font-size:1rem; color:#1e40af;">' + remaining.toFixed(2) + '</div>\
                    </div>\
                    <div style="min-width:100px; text-align:center;">\
                        <div style="font-size:0.72rem; color:var(--gray-400); margin-bottom:2px;">الكمية الأصلية</div>\
                        <div style="font-weight:600; font-size:0.9rem; color:var(--gray-600);">' + parseFloat(batch.original_quantity).toFixed(2) + '</div>\
                    </div>\
                    <div style="min-width:120px; text-align:center;">\
                        <div style="font-size:0.72rem; color:var(--gray-400); margin-bottom:2px;">الحالة</div>\
                        <div style="' + statusClass + ' font-size:0.82rem;">' + statusText + '</div>\
                    </div>\
                    <div style="min-width:90px; text-align:center;">\
                        <div style="font-size:0.72rem; color:var(--gray-400); margin-bottom:2px;">تاريخ الشراء</div>\
                        <div style="font-weight:600; font-size:0.82rem; color:var(--gray-600);">' + (batch.purchase_date || '—') + '</div>\
                    </div>\
                ';
                container.appendChild(row);
            });

            if (summary) {
                summary.style.display = '';
                document.getElementById('expiryTotalQty').textContent = totalQty.toFixed(2);
                document.getElementById('expiryNearEndQty').textContent = nearEndQty.toFixed(2);
                document.getElementById('expiryExpiredQty').textContent = expiredQty.toFixed(2);
            }
        })
        .catch(function() {
            if (loadingMsg) loadingMsg.style.display = 'none';
            container.innerHTML = '<div style="text-align:center; padding:1rem; color:#ef4444;">خطأ في تحميل الدفعات</div>';
        });
}

// === مراقبة تغيير الكمية ===
function checkQuantityChange() {
    var qtyInput = document.querySelector('input[name="quantity"]');
    if (!qtyInput) return;
    var newQty = parseFloat(qtyInput.value) || 0;
    var diff = newQty - originalQuantity;
    var newSection = document.getElementById('new-expiry-section');
    var noChangeMsg = document.getElementById('expiry-no-change-msg');
    var diffHint = document.getElementById('newExpiryQtyDiff');

    if (diff > 0.001) {
        // الكمية زادت → إظهار قسم إضافة تواريخ
        if (newSection) newSection.style.display = '';
        if (noChangeMsg) noChangeMsg.style.display = 'none';
        if (diffHint) diffHint.textContent = diff.toFixed(2);
        document.getElementById('newExpiryRequiredQty').textContent = diff.toFixed(2);
        updateNewExpirySummary();
    } else {
        // الكمية لم تتغير أو نقصت → إخفاء قسم الإضافة
        if (newSection) newSection.style.display = 'none';
        if (noChangeMsg) {
            if (diff < -0.001) {
                noChangeMsg.innerHTML = 'الكمية تناقصت بمقدار <strong style="color:#dc2626;">' + Math.abs(diff).toFixed(2) + '</strong>. سيتم خصم الكمية من أقدم الدفعات عند الحفظ.';
                noChangeMsg.style.display = '';
            } else {
                noChangeMsg.innerHTML = 'قم بزيادة حقل <strong>الكمية الحالية</strong> أعلاه لإضافة تواريخ انتهاء صلاحية جديدة.';
                noChangeMsg.style.display = '';
            }
        }
    }
}

// === إضافة صف تاريخ انتهاء جديد (قابل للتعديل) ===
function addExpiryRowEdit() {
    newExpiryCounter++;
    var container = document.getElementById('new-expiry-rows-container');
    var row = document.createElement('div');
    row.className = 'new-expiry-row';
    row.id = 'new-expiry-row-' + newExpiryCounter;
    row.style.cssText = 'display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; padding:10px 14px; background:linear-gradient(135deg, #eff6ff, #dbeafe); border:1px solid #93c5fd; border-radius:10px; margin-bottom:8px; animation: fadeSlideDown 0.3s ease;';
    row.innerHTML = '\
        <div style="flex:1; min-width:160px;">\
            <label style="font-size:0.78rem; font-weight:600; color:#1e40af; display:block; margin-bottom:3px;">تاريخ الانتهاء</label>\
            <input type="date" name="new_expiry_date[]" class="form-control" style="border-color:#93c5fd; font-weight:600;" onchange="updateNewExpirySummary()">\
        </div>\
        <div style="min-width:140px;">\
            <label style="font-size:0.78rem; font-weight:600; color:#1e40af; display:block; margin-bottom:3px;">الكمية</label>\
            <input type="number" name="new_expiry_quantity[]" class="form-control" step="0.01" min="0.01" value="0" style="border-color:#93c5fd; font-weight:700;" oninput="updateNewExpirySummary()">\
        </div>\
        <button type="button" onclick="removeNewExpiryRow(\'new-expiry-row-' + newExpiryCounter + '\')" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.2rem; padding:6px;" title="حذف">✕</button>\
    ';
    container.appendChild(row);
    // ضبط الكمية تلقائياً على الزيادة المتبقية إذا كان هناك تاريخ واحد فقط
    var allNewRows = container.querySelectorAll('.new-expiry-row');
    if (allNewRows.length === 1) {
        var qtyInput = document.querySelector('input[name="quantity"]');
        var diff = (parseFloat(qtyInput.value) || 0) - originalQuantity;
        if (diff > 0) {
            row.querySelector('input[name="new_expiry_quantity[]"]').value = diff.toFixed(2);
        }
    }
    updateNewExpirySummary();
}

function removeNewExpiryRow(id) {
    var row = document.getElementById(id);
    if (row) row.remove();
    updateNewExpirySummary();
}

function updateNewExpirySummary() {
    var totalQty = 0;
    var rows = document.querySelectorAll('.new-expiry-row');
    rows.forEach(function(row) {
        var qtyInput = row.querySelector('input[name="new_expiry_quantity[]"]');
        if (qtyInput) totalQty += parseFloat(qtyInput.value) || 0;
    });

    var qtyInput = document.querySelector('input[name="quantity"]');
    var newQty = parseFloat(qtyInput.value) || 0;
    var diff = newQty - originalQuantity;

    var totalEl = document.getElementById('newExpiryTotalQty');
    var requiredEl = document.getElementById('newExpiryRequiredQty');
    var diffBox = document.getElementById('newExpiryDiffBox');
    var diffEl = document.getElementById('newExpiryDiff');
    var summary = document.getElementById('newExpirySummary');
    var diffHint = document.getElementById('newExpiryQtyDiff');

    if (!totalEl) return;

    requiredEl.textContent = diff.toFixed(2);
    if (diffHint) diffHint.textContent = diff.toFixed(2);
    totalEl.textContent = totalQty.toFixed(2);

    if (totalQty > 0 || diff > 0) {
        summary.style.display = '';
        diffBox.style.display = '';
        var diffVal = totalQty - diff;
        diffEl.textContent = (diffVal >= 0 ? '+' : '') + diffVal.toFixed(2);
        if (Math.abs(diffVal) < 0.01) {
            diffBox.style.background = '#f0fdf4';
            diffBox.style.borderColor = '#86efac';
            diffBox.style.color = '#059669';
        } else {
            diffBox.style.background = '#fef2f2';
            diffBox.style.borderColor = '#fca5a5';
            diffBox.style.color = '#dc2626';
        }
    } else {
        summary.style.display = 'none';
    }
}
</script>


<script <?php echo class_exists('SecurityHeaders') ? SecurityHeaders::nonceAttr() : ''; ?>>
// === نظام صور المنتج (تعديل) — رفع مباشر + مرجع: add-product.php ===
var IMAGE_UPLOAD_URL = '../api/upload-product-image.php';
var IMAGE_ACTION_URL = '../api/product-image-action.php';
var PRODUCT_ID = <?php echo intval($id); ?>;
var BASE_URL = '<?php echo (!empty($base_url) ? rtrim($base_url, '/') : ''); ?>';
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
</script>

