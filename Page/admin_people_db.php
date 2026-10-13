<?php
declare(strict_types=1);

require_once __DIR__ . '/password.php';

const PERSON_FIELDS = ['username', 'display_name', 'password', 'date_of_birth', 'social_security_number', 'person_status', 'department', 'graduation_date', 'is_active'];

function personRoleValid(string $role): bool
{
    return in_array($role, ['student', 'teacher'], true);
}

function personDateValid(string $value): bool
{
    if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
    return $date !== false && $date->format('Y-m-d') === $value && $value >= '1000-01-01';
}

function validatePersonInput(string $role, array $input, bool $creating): array
{
    if (!personRoleValid($role)) {
        return ['只能维护学生或教师资料。'];
    }
    foreach (PERSON_FIELDS as $field) {
        if (!isset($input[$field]) || !is_string($input[$field])) {
            return ['资料字段无效，请重新填写。'];
        }
    }
    $errors = [];
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{2,63}\z/', $input['username'])) {
        $errors[] = '账号须为 3—64 位英文字母、数字、下划线、点或连字符。';
    }
    foreach (['display_name' => [100, '姓名'], 'person_status' => [32, '状态']] as $field => [$limit, $label]) {
        if (!preg_match('/\A[^\x00-\x1f\x7f]{1,' . $limit . '}\z/u', $input[$field]) || trim($input[$field]) !== $input[$field]) {
            $errors[] = $label . '不能为空、含控制字符或超过 ' . $limit . ' 个字符。';
        }
    }
    $password = $input['password'];
    if (($creating || $password !== '') && (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0"))) {
        $errors[] = '密码须为 8—72 字节，不能含空字符。';
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    if (!personDateValid($input['date_of_birth']) || $input['date_of_birth'] > $today) {
        $errors[] = '出生日期无效，不能晚于今天。';
    }
    if (!preg_match('/\A[A-Za-z0-9-]{1,32}\z/', $input['social_security_number'])) {
        $errors[] = '证件号须为 1—32 位数字、英文字母或连字符。';
    }
    if (!in_array($input['is_active'], ['0', '1'], true)) {
        $errors[] = '账号启用状态无效。';
    }
    if ($role === 'teacher') {
        if (!preg_match('/\A[^\x00-\x1f\x7f]{1,100}\z/u', $input['department']) || trim($input['department']) !== $input['department']) {
            $errors[] = '教师院系不能为空或超过 100 个字符。';
        }
        if ($input['graduation_date'] !== '') {
            $errors[] = '教师资料不填写毕业日期。';
        }
    } else {
        if ($input['department'] !== '') {
            $errors[] = '学生资料不填写教师院系。';
        }
        if (!personDateValid($input['graduation_date']) || $input['graduation_date'] <= $input['date_of_birth']) {
            $errors[] = '毕业日期无效，须晚于出生日期。';
        }
    }
    return $errors;
}

function dbPersonRecord(PDO $pdo, int $id, string $role, bool $lock = false): ?array
{
    if ($id < 1 || !personRoleValid($role)) {
        return null;
    }
    $query = $pdo->prepare('SELECT u.id, u.username, u.display_name, u.role, u.is_active, u.password_hash AS password,
        p.date_of_birth, p.social_security_number, p.person_status, p.department, p.graduation_date,
        COALESCE(p.revision, 0) AS revision
        FROM users u LEFT JOIN person_profiles p ON p.user_id = u.id
        WHERE u.id = ? AND u.role = ?' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$id, $role]);
    $record = $query->fetch();
    return is_array($record) ? $record : null;
}

function dbPersonList(PDO $pdo, string $role, string $search, int $page): array
{
    if (!personRoleValid($role)) {
        throw new InvalidArgumentException('无效的人员类型。');
    }
    $where = 'u.role = ? AND (? = \'\' OR INSTR(u.username, ?) > 0 OR INSTR(u.display_name, ?) > 0 OR CAST(u.id AS CHAR) = ?)';
    $params = [$role, $search, $search, $search, $search];
    $count = $pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / 50));
    $page = min(max(1, $page), $pages);
    $query = $pdo->prepare('SELECT u.id, u.username, u.display_name, u.is_active, u.password_hash AS password, p.person_status
        FROM users u LEFT JOIN person_profiles p ON p.user_id = u.id WHERE ' . $where . ' ORDER BY u.id LIMIT 50 OFFSET ' . (($page - 1) * 50));
    $query->execute($params);
    return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function dbPersonAdminAllowed(PDO $pdo, int $adminId): bool
{
    $query = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1 FOR UPDATE");
    $query->execute([$adminId]);
    return $query->fetchColumn() !== false;
}

function dbSavePerson(PDO $pdo, int $adminId, string $role, ?int $id, array $input, int $revision = 0): array
{
    $errors = validatePersonInput($role, $input, $id === null);
    if ($id !== null && ($id < 1 || $revision < 0)) {
        $errors[] = '人员编号或版本无效。';
    }
    if ($errors !== []) {
        return ['id' => null, 'errors' => $errors];
    }
    try {
        $pdo->beginTransaction();
        if (!dbPersonAdminAllowed($pdo, $adminId)) {
            $pdo->rollBack();
            return ['id' => null, 'errors' => ['教务员账号不可用。']];
        }
        $passwordValue = $input['password'] === '' ? null : password_hash($input['password'], PASSWORD_DEFAULT);
        $profileExists = false;
        if ($id === null) {
            $pdo->prepare('INSERT INTO users (username, display_name, role, password_hash, is_active) VALUES (?, ?, ?, ?, ?)')
                ->execute([$input['username'], $input['display_name'], $role, $passwordValue, $input['is_active']]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $record = dbPersonRecord($pdo, $id, $role, true);
            if ($record === null) {
                $pdo->rollBack();
                return ['id' => null, 'errors' => ['该人员不存在，请重新查找。']];
            }
            if ((int) $record['revision'] !== $revision) {
                $pdo->rollBack();
                return ['id' => null, 'errors' => ['资料已被其他操作修改，请重新打开后再保存。']];
            }
            if ($record['username'] !== $input['username']) {
                $pdo->rollBack();
                return ['id' => null, 'errors' => ['已有账号不能修改，请保留原账号。']];
            }
            $profileExists = (int) $record['revision'] > 0;
            $pdo->prepare('UPDATE users SET display_name = ?, is_active = ?, password_hash = COALESCE(?, password_hash) WHERE id = ?')
                ->execute([$input['display_name'], $input['is_active'], $passwordValue, $id]);
        }
        $profile = [$input['date_of_birth'], $input['social_security_number'], $input['person_status'],
            $role === 'teacher' ? $input['department'] : null, $role === 'student' ? $input['graduation_date'] : null];
        if ($profileExists) {
            $pdo->prepare('UPDATE person_profiles SET date_of_birth = ?, social_security_number = ?, person_status = ?,
                department = ?, graduation_date = ?, revision = revision + 1 WHERE user_id = ?')->execute(array_merge($profile, [$id]));
        } else {
            // 不用 UPSERT：证件号冲突必须拒绝，不能更新另一个人的资料。
            $pdo->prepare('INSERT INTO person_profiles (date_of_birth, social_security_number, person_status, department, graduation_date, user_id)
                VALUES (?, ?, ?, ?, ?, ?)')->execute(array_merge($profile, [$id]));
        }
        $pdo->commit();
        return ['id' => $id, 'errors' => []];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
            return ['id' => null, 'errors' => ['账号或证件号已存在，请使用唯一资料。']];
        }
        throw $error;
    }
}

function dbDeletePerson(PDO $pdo, int $adminId, string $role, int $id, int $revision): array
{
    if ($id < 1 || $revision < 0 || !personRoleValid($role)) {
        return ['人员类型、编号或版本无效。'];
    }
    try {
        $pdo->beginTransaction();
        if (!dbPersonAdminAllowed($pdo, $adminId)) {
            $pdo->rollBack();
            return ['教务员账号不可用。'];
        }
        $record = dbPersonRecord($pdo, $id, $role, true);
        if ($record === null) {
            $pdo->rollBack();
            return ['该人员不存在，请重新查找。'];
        }
        if ((int) $record['revision'] !== $revision) {
            $pdo->rollBack();
            return ['资料已被其他操作修改，请重新确认删除。'];
        }
        // 草稿和任教资格不属于业务历史；删除失败时连同清理操作一起回滚。
        if ($role === 'student') {
            $pdo->prepare('DELETE FROM selection_drafts WHERE student_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('DELETE FROM teacher_qualifications WHERE teacher_id = ?')->execute([$id]);
        }
        // 选课、授课、成绩及计费历史仍由外键保护，不级联删除。
        $pdo->prepare('DELETE FROM users WHERE id = ? AND role = ?')->execute([$id, $role]);
        $pdo->commit();
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1451) {
            return ['该人员已有选课、授课、成绩或计费等业务记录，不能删除；资料及关联记录均已保留，请编辑资料并停用账号。'];
        }
        throw $error;
    }
}
