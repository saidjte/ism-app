<?php
/**
 * ===== متحكم مستخدمي الموبايل (MobileUserController) =====
 * يوفر عمليات إدارة المستخدمين عبر API الموبايل (مدير فقط)
 *
 * المسارات:
 *   GET  /api/mobile/users              — قائمة المستخدمين
 *   POST /api/mobile/users              — إضافة مستخدم جديد
 *   PUT  /api/mobile/users/{id}         — تحديث مستخدم
 *   POST /api/mobile/users/{id}/toggle  — تفعيل/تعطيل مستخدم
 *   POST /api/mobile/users/{id}/reset-password — إعادة تعيين كلمة المرور
 *
 * v5.11.0: إنشاء متحكم مستخدمي الموبايل
 */

require_once __DIR__ . '/../ApiController.php';
require_once __DIR__ . '/../../Router/Middleware/TokenAuthMiddleware.php';

class MobileUserController extends ApiController
{
    /**
     * التحقق من صلاحيات المدير
     */
    protected function requireAdmin(): void
    {
        // v5.11.3: إصلاح — استخدام user_role (المفتاح الذي يضعه TokenAuthMiddleware)
        $role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'user';
        if ($role !== 'admin') {
            ApiResponse::forbidden('هذه العملية تتطلب صلاحيات المدير');
            return; // v5.11.3: إضافة return دفاعية
        }
    }

    /**
     * قائمة المستخدمين
     *
     * GET /api/mobile/users?page=1&per_page=20&role=admin&search=محمد
     */
    public function index(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $page = max(1, intval($this->input('page', 1)));
            $perPage = min(100, max(1, intval($this->input('per_page', 20))));
            $offset = ($page - 1) * $perPage;

            $role = trim($this->input('role', ''));
            $search = trim($this->input('search', ''));

            // بناء الاستعلام
            $where = [];
            $bindings = [];

            if (in_array($role, ['admin', 'manager', 'user'])) {
                $where[] = "u.role = ?";
                $bindings[] = $role;
            }
            if (!empty($search)) {
                $where[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
                $searchParam = "%{$search}%";
                $bindings[] = $searchParam;
                $bindings[] = $searchParam;
                $bindings[] = $searchParam;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // عدد الإجمالي
            $countSql = "SELECT COUNT(*) FROM users u {$whereClause}";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($bindings);
            $total = intval($countStmt->fetchColumn());

            // جلب البيانات
            $sql = "SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active,
                           u.must_change_password, u.last_login, u.login_count, u.created_at, u.updated_at
                    FROM users u
                    {$whereClause}
                    ORDER BY u.id ASC
                    LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($bindings, [$perPage, $offset]));
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $users = array_map(function ($row) {
                return [
                    'id' => intval($row['id']),
                    'username' => $row['username'],
                    'full_name' => $row['full_name'],
                    'email' => $row['email'] ?? '',
                    'role' => $row['role'],
                    'role_label' => $this->getRoleLabel($row['role']),
                    'is_active' => intval($row['is_active']) === 1,
                    'must_change_password' => intval($row['must_change_password']) === 1,
                    'last_login' => $row['last_login'],
                    'login_count' => intval($row['login_count']),
                    'created_at' => $row['created_at'],
                ];
            }, $users);

            ApiResponse::success([
                'data' => $users,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => max(1, ceil($total / $perPage)),
                ],
            ]);

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileUserController::index');
            }
            ApiResponse::serverError('خطأ في جلب المستخدمين');
        }
    }

    /**
     * إضافة مستخدم جديد
     *
     * POST /api/mobile/users
     * Body: { "username": "ahmed", "full_name": "أحمد", "password": "12345678", "role": "user", "email": "a@b.com" }
     */
    public function store(array $params = []): void
    {
        $this->requirePost();

        try {
            $this->requireAdmin();

            $username = trim($this->input('username', ''));
            $fullName = trim($this->input('full_name', ''));
            $password = $this->input('password', '');
            $role = trim($this->input('role', 'user'));
            $email = trim($this->input('email', ''));

            // التحقق من المدخلات
            $errors = [];
            if (empty($username) || mb_strlen($username) < 3) {
                $errors[] = 'اسم المستخدم مطلوب (3 أحرف على الأقل)';
            } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                $errors[] = 'اسم المستخدم يجب أن يحتوي فقط على حروف إنجليزية وأرقام و_';
            }
            if (empty($fullName)) {
                $errors[] = 'الاسم الكامل مطلوب';
            }
            if (empty($password) || mb_strlen($password) < 8) {
                $errors[] = 'كلمة المرور مطلوبة (8 أحرف على الأقل)';
            }
            if (!in_array($role, ['admin', 'manager', 'user'])) {
                $errors[] = 'الدور غير صالح';
            }
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'البريد الإلكتروني غير صالح';
            }

            if (!empty($errors)) {
                ApiResponse::validationError($errors);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من عدم تكرار اسم المستخدم
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                ApiResponse::error('اسم المستخدم موجود مسبقاً', 'validation', 422);
                return;
            }

            // تشفير كلمة المرور
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

            $stmt = $pdo->prepare(
                "INSERT INTO users (username, password, full_name, email, role, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)"
            );
            $stmt->execute([$username, $hashedPassword, $fullName, $email ?: null, $role]);

            $id = intval($pdo->lastInsertId());

            // تسجيل في سجل المراجعة
            if (function_exists('addAuditLog')) {
                addAuditLog('user_add', "إضافة مستخدم جديد: {$username} ({$role})");
            }

            ApiResponse::created([
                'id' => $id,
                'username' => $username,
                'full_name' => $fullName,
                'role' => $role,
            ], 'تم إضافة المستخدم بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileUserController::store');
            }
            ApiResponse::serverError('خطأ في إضافة المستخدم');
        }
    }

    /**
     * تحديث مستخدم
     *
     * PUT /api/mobile/users/{id}
     * Body: { "full_name": "أحمد الجديد", "email": "new@b.com", "role": "manager" }
     */
    public function update(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $id = intval($params['id'] ?? $this->input('id', 0));
            if ($id <= 0) {
                ApiResponse::validationError(['معرف المستخدم مطلوب']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            // التحقق من وجود المستخدم
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                ApiResponse::notFound('المستخدم غير موجود');
                return;
            }

            // لا يمكن تعديل دور المدير الرئيسي
            if ($id === 1 && $user['role'] === 'admin') {
                $newRole = trim($this->input('role', ''));
                if ($newRole !== 'admin') {
                    ApiResponse::forbidden('لا يمكن تغيير دور المدير الرئيسي');
                    return;
                }
            }

            // بناء التحديث
            $updates = [];
            $values = [];

            $fullName = trim($this->input('full_name', ''));
            if (!empty($fullName)) {
                $updates[] = "full_name = ?";
                $values[] = $fullName;
            }

            $email = $this->input('email');
            if ($email !== null) {
                $updates[] = "email = ?";
                $values[] = trim($email) ?: null;
            }

            $role = trim($this->input('role', ''));
            if (!empty($role) && in_array($role, ['admin', 'manager', 'user'])) {
                $updates[] = "role = ?";
                $values[] = $role;
            }

            if (empty($updates)) {
                ApiResponse::validationError(['لم يتم تقديم بيانات للتحديث']);
                return;
            }

            $values[] = $id;
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($values);

            // تسجيل في سجل المراجعة
            if (function_exists('addAuditLog')) {
                addAuditLog('user_update', "تحديث مستخدم: {$user['username']}");
            }

            ApiResponse::success(null, 'تم تحديث المستخدم بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileUserController::update');
            }
            ApiResponse::serverError('خطأ في تحديث المستخدم');
        }
    }

    /**
     * تفعيل/تعطيل مستخدم
     *
     * POST /api/mobile/users/{id}/toggle
     */
    public function toggle(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['معرف المستخدم مطلوب']);
                return;
            }

            // لا يمكن تعطيل المدير الرئيسي
            if ($id === 1) {
                ApiResponse::forbidden('لا يمكن تعطيل المدير الرئيسي');
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                ApiResponse::notFound('المستخدم غير موجود');
                return;
            }

            $newStatus = intval($user['is_active']) === 1 ? 0 : 1;
            $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);

            $statusLabel = $newStatus === 1 ? 'تفعيل' : 'تعطيل';

            if (function_exists('addAuditLog')) {
                addAuditLog('user_toggle', "{$statusLabel} مستخدم: {$user['username']}");
            }

            ApiResponse::success([
                'is_active' => $newStatus === 1,
            ], "تم {$statusLabel} المستخدم بنجاح");

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileUserController::toggle');
            }
            ApiResponse::serverError('خطأ في تغيير حالة المستخدم');
        }
    }

    /**
     * إعادة تعيين كلمة مرور مستخدم
     *
     * POST /api/mobile/users/{id}/reset-password
     * Body: { "new_password": "newpass123" }
     */
    public function resetPassword(array $params = []): void
    {
        try {
            $this->requireAdmin();

            $id = intval($params['id'] ?? 0);
            if ($id <= 0) {
                ApiResponse::validationError(['معرف المستخدم مطلوب']);
                return;
            }

            $newPassword = $this->input('new_password', '');
            if (empty($newPassword) || mb_strlen($newPassword) < 8) {
                ApiResponse::validationError(['كلمة المرور الجديدة مطلوبة (8 أحرف على الأقل)']);
                return;
            }

            $db = AppDatabase::getInstance();
            $pdo = $db->getConnection();

            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                ApiResponse::notFound('المستخدم غير موجود');
                return;
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
            $pdo->prepare(
                "UPDATE users SET password = ?, must_change_password = 1, password_changed_at = NOW() WHERE id = ?"
            )->execute([$hashedPassword, $id]);

            // إلغاء جميع رموز API للمستخدم (أمني)
            $pdo->prepare("UPDATE api_tokens SET is_revoked = 1 WHERE user_id = ?")->execute([$id]);

            if (function_exists('addAuditLog')) {
                addAuditLog('user_reset_password', "إعادة تعيين كلمة مرور: {$user['username']}");
            }

            ApiResponse::success(null, 'تم إعادة تعيين كلمة المرور بنجاح');

        } catch (\Throwable $e) {
            if (class_exists('ErrorHandler') && method_exists('ErrorHandler', 'logException')) {
                ErrorHandler::logException($e, 'MobileUserController::resetPassword');
            }
            ApiResponse::serverError('خطأ في إعادة تعيين كلمة المرور');
        }
    }

    /**
     * ترجمة الدور إلى العربية
     */
    private function getRoleLabel(string $role): string
    {
        return match ($role) {
            'admin' => 'مدير النظام',
            'manager' => 'مدير',
            'user' => 'مستخدم',
            default => $role,
        };
    }
}
