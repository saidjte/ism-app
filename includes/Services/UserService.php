<?php
/**
 * ===== خدمة المستخدمين (UserService) =====
 * تجمع كل المنطق التجاري المتعلق بالمستخدمين في مكان واحد
 *
 * المسؤوليات:
 * - إضافة / تعديل / حذف المستخدمين
 * - تفعيل / تعطيل الحسابات
 * - إعادة تعيين كلمات المرور
 * - تغيير كلمة المرور (إجباري / اختياري)
 * - التحقق من صحة بيانات المستخدم
 *
 * @since v5.25.0 فصل MVC لصفحات users/
 */

class UserService
{
    /**
     * التحقق من صحة بيانات المستخدم الجديد
     *
     * @param array $data بيانات النموذج
     * @return array|true قائمة الأخطاء أو true
     */
    public static function validateNewUser(array $data): array|bool
    {
        $errors = [];
        $username = trim($data['username'] ?? '');
        $full_name = trim($data['full_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $role = $data['role'] ?? 'user';
        $password = $data['password'] ?? '';
        $password_confirm = $data['password_confirm'] ?? '';

        if (empty($username)) $errors[] = t('users_username_required');
        elseif (strlen($username) < 3) $errors[] = t('users_username_min_length');

        if (empty($full_name)) $errors[] = t('users_fullname_required');

        if (empty($password)) $errors[] = t('users_password');
        elseif (strlen($password) < 6) $errors[] = t('users_password_min_length');

        if ($password !== $password_confirm) $errors[] = t('users_confirm_password');

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('users_email_invalid');
        }

        if (!in_array($role, ['admin', 'manager', 'user'])) {
            $errors[] = t('users_role_invalid');
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * التحقق من عدم تكرار اسم المستخدم أو البريد الإلكتروني
     *
     * @param string $username اسم المستخدم
     * @param string $email البريد الإلكتروني
     * @param int|null $excludeId معرف المستخدم المراد استبعاده (للتعديل)
     * @return array|true
     */
    public static function checkUniqueness(string $username, string $email = '', ?int $excludeId = null): array|bool
    {
        $errors = [];

        $existing = AppDatabase::getInstance()->fetchOne(
            "SELECT id FROM users WHERE username = ?" . ($excludeId ? " AND id != ?" : ""),
            $excludeId ? [$username, $excludeId] : [$username]
        );
        if ($existing) $errors[] = t('users_username_exists');

        if (!empty($email)) {
            $existingEmail = AppDatabase::getInstance()->fetchOne(
                "SELECT id FROM users WHERE email = ?" . ($excludeId ? " AND id != ?" : ""),
                $excludeId ? [$email, $excludeId] : [$email]
            );
            if ($existingEmail) $errors[] = t('users_email_exists');
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * إنشاء مستخدم جديد
     *
     * @param array $data بيانات المستخدم
     * @return array نتيجة العملية
     * @throws AppException
     */
    public static function create(array $data): array
    {
        $validation = self::validateNewUser($data);
        if ($validation !== true) {
            throw AppException::validation(implode('، ', (array)$validation));
        }

        $username = trim($data['username']);
        $full_name = trim($data['full_name']);
        $email = trim($data['email'] ?? '');
        $role = $data['role'] ?? 'user';
        $password = $data['password'];

        $uniqueness = self::checkUniqueness($username, $email);
        if ($uniqueness !== true) {
            throw AppException::validation(implode('، ', (array)$uniqueness));
        }

        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $id = AppDatabase::getInstance()->insert(
                "INSERT INTO users (username, password, full_name, email, role, password_changed_at) VALUES (?, ?, ?, ?, ?, NOW())",
                [$username, $hashed_password, $full_name, $email ?: null, $role]
            );

            // إشعار مستخدم جديد
            try {
                AppNotification::add(NOTIF_USER, t('notif_title_new_user'), t('notif_msg_new_user', $username), null, null, 'notif_title_new_user', 'notif_msg_new_user', ['username' => $username]);
            } catch (\Exception $e) {}

            return ['success' => true, 'id' => $id, 'message' => 'تم إنشاء المستخدم بنجاح'];
        } catch (AppException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('UserService::create Error: ' . $e->getMessage());
            throw AppException::database(t('users_error_creating'));
        }
    }

    /**
     * تحديث بيانات مستخدم
     *
     * @param int $id معرف المستخدم
     * @param array $data البيانات الجديدة
     * @param int $currentUserId معرف المستخدم الحالي (للتحقق من الصلاحيات)
     * @param string $currentRole دور المستخدم الحالي
     * @return array نتيجة العملية
     * @throws AppException
     */
    public static function update(int $id, array $data, int $currentUserId, string $currentRole): array
    {
        $user = AppDatabase::getInstance()->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$user) {
            throw AppException::notFound('المستخدم غير موجود');
        }

        $errors = [];
        $full_name = trim($data['full_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $role = $data['role'] ?? $user['role'];
        $password = $data['password'] ?? '';
        $password_confirm = $data['password_confirm'] ?? '';

        if (empty($full_name)) $errors[] = t('users_fullname_required');

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('users_email_invalid');
        }

        if (!in_array($role, ['admin', 'manager', 'user'])) {
            $errors[] = t('users_role_invalid');
        }

        if (!empty($password)) {
            if (strlen($password) < 6) $errors[] = t('users_password_min_length');
            if ($password !== $password_confirm) $errors[] = t('users_confirm_password');
        }

        // التحقق من عدم تكرار البريد الإلكتروني
        if (empty($errors) && !empty($email)) {
            $existingEmail = AppDatabase::getInstance()->fetchOne(
                "SELECT id FROM users WHERE email = ? AND id != ?", [$email, $id]
            );
            if ($existingEmail) $errors[] = t('users_email_used_by_another');
        }

        // منع المدير من إزالة صلاحية الإدارة عن نفسه
        if (empty($errors) && $id == $currentUserId && $role !== 'admin') {
            $errors[] = 'لا يمكنك تغيير دورك الخاص من مدير النظام';
        }

        // المستخدمون غير المديرين لا يمكنهم تغيير الدور
        if ($currentRole !== 'admin' && $role !== $user['role']) {
            $role = $user['role'];
        }

        if (!empty($errors)) {
            throw AppException::validation(implode('، ', $errors));
        }

        try {
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                AppDatabase::getInstance()->query(
                    "UPDATE users SET full_name = ?, email = ?, role = ?, password = ?, password_changed_at = NOW(), must_change_password = 0 WHERE id = ?",
                    [$full_name, $email ?: null, $role, $hashed_password, $id]
                );
            } else {
                AppDatabase::getInstance()->query(
                    "UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?",
                    [$full_name, $email ?: null, $role, $id]
                );
            }

            return ['success' => true, 'message' => 'تم تحديث المستخدم بنجاح'];
        } catch (\Throwable $e) {
            error_log('UserService::update Error: ' . $e->getMessage());
            throw AppException::database(t('users_error_updating'));
        }
    }

    /**
     * حذف مستخدم
     *
     * @param int $userId معرف المستخدم المراد حذفه
     * @param int $currentUserId معرف المستخدم الحالي
     * @return array نتيجة العملية
     * @throws AppException
     */
    public static function delete(int $userId, int $currentUserId): array
    {
        if ($userId <= 0) {
            throw AppException::validation('معرف المستخدم مطلوب');
        }
        if ($userId == $currentUserId) {
            throw AppException::validation('لا يمكنك حذف حسابك الخاص');
        }

        try {
            AppDatabase::getInstance()->query("DELETE FROM users WHERE id = ?", [$userId]);
            return ['success' => true, 'message' => t('users_user_deleted_success')];
        } catch (\Throwable $e) {
            error_log('UserService::delete Error: ' . $e->getMessage());
            throw AppException::database(t('users_error_deleting'));
        }
    }

    /**
     * تفعيل أو تعطيل مستخدم
     *
     * @param int $userId معرف المستخدم
     * @param int $currentUserId معرف المستخدم الحالي
     * @return array نتيجة العملية مع حالة المستخدم الجديدة
     * @throws AppException
     */
    public static function toggleActive(int $userId, int $currentUserId): array
    {
        if ($userId <= 0 || $userId == $currentUserId) {
            throw AppException::validation('معرف المستخدم غير صالح');
        }

        try {
            $pdo = AppDatabase::getInstance()->getConnection();
            $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$userId]);

            $userRow = AppDatabase::getInstance()->fetchOne("SELECT username, is_active FROM users WHERE id = ?", [$userId]);
            $username = $userRow ? $userRow['username'] : '';
            $newStatus = $userRow ? (intval($userRow['is_active']) === 1) : true;

            try {
                AppNotification::add(NOTIF_USER, t('notif_title_user_status'), ($newStatus ? t('notif_msg_user_activated', $username) : t('notif_msg_user_deactivated', $username)), null, null, 'notif_title_user_status', $newStatus ? 'notif_msg_user_activated' : 'notif_msg_user_deactivated', ['username' => $username]);
            } catch (\Exception $e) {}

            return ['success' => true, 'active' => $newStatus, 'message' => t('users_status_changed')];
        } catch (\Throwable $e) {
            error_log('UserService::toggleActive Error: ' . $e->getMessage());
            throw AppException::database(t('users_error_updating_status'));
        }
    }

    /**
     * إعادة تعيين كلمة مرور مستخدم
     *
     * @param int $userId معرف المستخدم
     * @return array نتيجة العملية مع كلمة المرور الجديدة
     * @throws AppException
     */
    public static function resetPassword(int $userId): array
    {
        if ($userId <= 0) {
            throw AppException::validation('معرف المستخدم مطلوب');
        }

        try {
            $new_password_plain = bin2hex(random_bytes(4)); // 8 أحرف
            $new_password = password_hash($new_password_plain, PASSWORD_DEFAULT);
            AppDatabase::getInstance()->query(
                "UPDATE users SET password = ?, failed_attempts = 0 WHERE id = ?",
                [$new_password, $userId]
            );
            return ['success' => true, 'plain_password' => $new_password_plain, 'message' => t('users_password_reset_success') . ' ' . $new_password_plain];
        } catch (\Throwable $e) {
            error_log('UserService::resetPassword Error: ' . $e->getMessage());
            throw AppException::database(t('users_error_resetting_password'));
        }
    }

    /**
     * تغيير كلمة المرور الحالية (إجباري أو اختياري)
     *
     * @param int $userId معرف المستخدم
     * @param string $currentPassword كلمة المرور الحالية
     * @param string $newPassword كلمة المرور الجديدة
     * @param string $confirmPassword تأكيد كلمة المرور
     * @return array نتيجة العملية
     * @throws AppException
     */
    public static function changePassword(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): array
    {
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            throw AppException::validation(t('login_error_empty'));
        }
        if (strlen($newPassword) < 6) {
            throw AppException::validation(t('users_password_min_length'));
        }
        if ($newPassword !== $confirmPassword) {
            throw AppException::validation(t('users_confirm_password'));
        }

        $user = AppDatabase::getInstance()->fetchOne("SELECT id, password FROM users WHERE id = ?", [$userId]);
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            throw AppException::validation(t('login_password'));
        }

        try {
            $new_hash = password_hash($newPassword, PASSWORD_DEFAULT);
            AppDatabase::getInstance()->query(
                "UPDATE users SET password = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?",
                [$new_hash, $userId]
            );

            addAuditLog('password_change', 'تغيير كلمة المرور', '', 'user', $userId);

            return ['success' => true, 'message' => t('users_password_changed_success')];
        } catch (\Throwable $e) {
            error_log('UserService::changePassword Error: ' . $e->getMessage());
            throw AppException::database('حدث خطأ أثناء تحديث كلمة المرور');
        }
    }

    /**
     * جلب جميع المستخدمين مرتبين بالتاريخ
     *
     * @return array قائمة المستخدمين
     */
    public static function getAll(): array
    {
        return AppDatabase::getInstance()->fetchAll("SELECT * FROM users ORDER BY created_at DESC");
    }

    /**
     * جلب مستخدم بالمعرف
     *
     * @param int $id معرف المستخدم
     * @return array|null بيانات المستخدم
     */
    public static function getById(int $id): ?array
    {
        return AppDatabase::getInstance()->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    }
}