<?php if ($success_msg): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
<?php endif; ?>

<?php if (isset($error_msg)): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
<?php endif; ?>

<div class="stats-grid" style="margin-bottom: 1.5rem;">
    <div class="stat-card blue">
        <div class="stat-info"><h3><?php echo t('users_total_users'); ?></h3><div class="stat-value"><?php echo count($users); ?></div></div>
    </div>
    <div class="stat-card green">
        <div class="stat-info"><h3><?php echo t('users_active_users'); ?></h3><div class="stat-value"><?php echo count(array_filter($users, fn($u) => $u['is_active'] == 1)); ?></div></div>
    </div>
    <div class="stat-card red">
        <div class="stat-info"><h3><?php echo t('users_inactive_users'); ?></h3><div class="stat-value"><?php echo count(array_filter($users, fn($u) => $u['is_active'] == 0)); ?></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>قائمة المستخدمين</h2></div>
    <div class="card-body">
        <?php if (empty($users)): ?>
            <p class="text-muted" style="text-align:center; padding: 2rem;">لا يوجد مستخدمين بعد.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم المستخدم</th>
                        <th>الاسم الكامل</th>
                        <th>البريد الإلكتروني</th>
                        <th>الدور</th>
                        <th>الحالة</th>
                        <th>آخر دخول</th>
                        <th>مرات الدخول</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; foreach ($users as $user): ?>
                    <tr style="<?php echo $user['is_active'] == 0 ? 'opacity:0.5;' : ''; ?>">
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($user['username']); ?></strong>
                            <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                <span class="badge badge-success" style="font-size:0.65rem;">أنت</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                        <td><span class="badge <?php echo getRoleBadgeClass($user['role']); ?>"><?php echo getRoleName($user['role']); ?></span></td>
                        <td>
                            <?php if ($user['is_active'] == 1): ?>
                                <span class="badge badge-success">نشط</span>
                            <?php else: ?>
                                <span class="badge badge-danger">معطّل</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.85rem;"><?php echo htmlspecialchars($user['last_login'] ?? 'لم يسجل دخول بعد'); ?></td>
                        <td><?php echo intval($user['login_count']); ?></td>
                        <td>
                            <div class="btn-group">
                                <a href="edit-user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-primary"><?php echo t('edit'); ?></a>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('users_confirm_toggle_status'); ?>')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $user['is_active'] == 1 ? 'btn-warning' : 'btn-success'; ?>">
                                            <?php echo $user['is_active'] == 1 ? t('common_deactivate') : t('common_activate'); ?>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('users_confirm_reset_password'); ?>')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline"><?php echo t('users_change_password'); ?></button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo t('users_confirm_delete_user'); ?>')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><?php echo t('delete'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>