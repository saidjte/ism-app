<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <strong><?php echo t('common_error_occurred'); ?></strong>
        <ul style="margin: 5px 20px 0;">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>بيانات المستخدم الجديد</h2></div>
    <div class="card-body">
        <form method="POST" action="">
            <div class="form-grid">
                <div class="form-group">
                    <label><?php echo t('users_username'); ?> <span class="required">*</span></label>
                    <input type="text" name="username" class="form-control" placeholder="مثال: ahmed" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required minlength="3" maxlength="50">
                    <div class="form-text">3 أحرف على الأقل، يُستخدم لتسجيل الدخول</div>
                </div>

                <div class="form-group">
                    <label><?php echo t('users_fullname'); ?> <span class="required">*</span></label>
                    <input type="text" name="full_name" class="form-control" placeholder="مثال: أحمد محمد" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required maxlength="100">
                </div>

                <div class="form-group">
                    <label>البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control" placeholder="مثال: ahmed@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" maxlength="150">
                    <div class="form-text">اختياري</div>
                </div>

                <div class="form-group">
                    <label>الدور <span class="required">*</span></label>
                    <select name="role" class="form-control" required>
                        <option value="user" <?php echo (($_POST['role'] ?? '') == 'user') ? 'selected' : ''; ?>>مستخدم عادي</option>
                        <option value="manager" <?php echo (($_POST['role'] ?? '') == 'manager') ? 'selected' : ''; ?>>مدير</option>
                        <option value="admin" <?php echo (($_POST['role'] ?? '') == 'admin') ? 'selected' : ''; ?>>مدير النظام</option>
                    </select>
                    <div class="form-text">مدير النظام: صلاحية كاملة | مدير: إدارة البيانات | مستخدم: عرض فقط</div>
                </div>

                <div class="form-group">
                    <label><?php echo t('users_password'); ?> <span class="required">*</span></label>
                    <input type="password" name="password" class="form-control" placeholder="<?php echo t('users_password_min_length'); ?>" required minlength="6">
                </div>

                <div class="form-group">
                    <label><?php echo t('users_confirm_password'); ?> <span class="required">*</span></label>
                    <input type="password" name="password_confirm" class="form-control" placeholder="<?php echo t('users_retype_password'); ?>" required minlength="6">
                </div>
            </div>

            <div style="margin-top: 1.5rem;" class="btn-group">
                <?php echo csrfField(); ?>
                <button type="submit" class="btn btn-primary btn-lg">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
                    <?php echo t('save'); ?>
                </button>
                <a href="list-users.php" class="btn btn-outline btn-lg"><?php echo t('cancel'); ?></a>
            </div>
        </form>
    </div>
</div>