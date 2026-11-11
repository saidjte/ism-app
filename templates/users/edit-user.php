<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul style="margin:5px 20px;"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>بيانات المستخدم</h2></div>
    <div class="card-body">
        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label><?php echo t('users_username'); ?></label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" readonly style="background:#f1f5f9; color:#64748b; cursor:not-allowed;">
                    <div class="form-text">لا يمكن تغيير اسم المستخدم</div>
                </div>

                <div class="form-group">
                    <label><?php echo t('users_fullname'); ?> <span class="required">*</span></label>
                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                </div>

                <div class="form-group">
                    <label>البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>الدور <span class="required">*</span></label>
                    <select name="role" class="form-control" required <?php echo ($user['id'] == $_SESSION['user_id'] || $_SESSION['user_role'] !== 'admin') ? 'disabled' : ''; ?>>
                        <option value="user" <?php echo $user['role'] == 'user' ? 'selected' : ''; ?>>مستخدم عادي</option>
                        <option value="manager" <?php echo $user['role'] == 'manager' ? 'selected' : ''; ?>>مدير</option>
                        <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>مدير النظام</option>
                    </select>
                    <?php if ($user['id'] == $_SESSION['user_id']): ?>
                        <div class="form-text" style="color:#d97706;">لا يمكنك تغيير دورك الخاص</div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label><?php echo t('users_new_password'); ?></label>
                    <input type="password" name="password" class="form-control" placeholder="<?php echo t('users_leave_empty_no_change'); ?>" minlength="6">
                    <div class="form-text">اتركها فارغة للإبقاء على كلمة المرور الحالية</div>
                </div>

                <div class="form-group">
                    <label><?php echo t('users_confirm_password'); ?></label>
                    <input type="password" name="password_confirm" class="form-control" placeholder="<?php echo t('users_retype_new_password'); ?>">
                </div>
            </div>

            <div style="margin-top:1.5rem;" class="btn-group">
                <?php echo csrfField(); ?>
                <button type="submit" class="btn btn-primary btn-lg"><?php echo t('save'); ?></button>
                <a href="list-users.php" class="btn btn-outline btn-lg"><?php echo t('cancel'); ?></a>
            </div>
        </form>
    </div>
</div>

<!-- معلومات إضافية عن المستخدم -->
<div class="card" style="margin-top:2rem;">
    <div class="card-header"><h2>معلومات النظام</h2></div>
    <div class="card-body">
        <div class="form-grid">
            <div><strong>تاريخ التسجيل:</strong> <?php echo htmlspecialchars($user['created_at']); ?></div>
            <div><strong>آخر تحديث:</strong> <?php echo htmlspecialchars($user['updated_at']); ?></div>
            <div><strong>آخر تسجيل دخول:</strong> <?php echo htmlspecialchars($user['last_login'] ?? 'لم يسجل دخول بعد'); ?></div>
            <div><strong>عدد مرات الدخول:</strong> <?php echo intval($user['login_count']); ?></div>
            <div><strong>المحاولات الفاشلة:</strong> <?php echo intval($user['failed_attempts']); ?></div>
            <div><strong>الحالة:</strong>
                <?php if ($user['is_active'] == 1): ?>
                    <span class="badge badge-success">نشط</span>
                <?php else: ?>
                    <span class="badge badge-danger">معطّل</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>