<?php
declare(strict_types=1);

class Student
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAll(int $tenantId, int $limit = 0, int $offset = 0, string $search = ''): array
    {
        $sql = 'SELECT s.*, u.first_name, u.last_name, u.email, u.phone,
                       u.profile_picture, u.status, u.created_at AS user_created_at
                FROM students s
                JOIN users u ON u.id = s.user_id AND u.tenant_id = s.tenant_id
                WHERE s.tenant_id = ?';
        $params = [$tenantId];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR s.matricule LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $sql .= ' ORDER BY u.last_name, u.first_name';

        if ($limit > 0) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset;
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function count(int $tenantId, string $search = ''): int
    {
        $sql    = 'SELECT COUNT(*) FROM students s
                   JOIN users u ON u.id = s.user_id AND u.tenant_id = s.tenant_id
                   WHERE s.tenant_id = ?';
        $params = [$tenantId];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR s.matricule LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        return (int)($this->db->fetchScalar($sql, $params) ?? 0);
    }

    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT s.*, u.first_name, u.last_name, u.email, u.phone,
                    u.address, u.profile_picture, u.status
             FROM students s
             JOIN users u ON u.id = s.user_id AND u.tenant_id = s.tenant_id
             WHERE s.id = ? AND s.tenant_id = ? LIMIT 1',
            [$id, $tenantId]
        );
    }

    public function findByUserId(int $userId, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT s.*, u.first_name, u.last_name, u.email, u.phone,
                    u.address, u.profile_picture, u.status
             FROM students s
             JOIN users u ON u.id = s.user_id
             WHERE s.user_id = ? AND s.tenant_id = ? LIMIT 1',
            [$userId, $tenantId]
        );
    }

    public function create(array $data, int $tenantId): ?int
    {
        try {
            return $this->db->transaction(function (Database $db) use ($data, $tenantId): int {
                $userId = $db->insert('users', [
                    'tenant_id'  => $tenantId,
                    'email'      => strtolower(trim($data['email'])),
                    'password'   => password_hash($data['password'] ?? 'SmartCampus@2025', PASSWORD_BCRYPT, ['cost' => 12]),
                    'first_name' => trim($data['first_name']),
                    'last_name'  => strtoupper(trim($data['last_name'])),
                    'role'       => 'student',
                    'status'     => 'active',
                    'phone'      => $data['phone']   ?? null,
                    'address'    => $data['address'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                if (!$userId) throw new RuntimeException('Impossible de créer le compte utilisateur.');

                $matricule = $data['matricule'] ?? generateStudentId($userId);

                $studentId = $db->insert('students', [
                    'tenant_id'       => $tenantId,
                    'user_id'         => $userId,
                    'matricule'       => $matricule,
                    'department'      => $data['department']     ?? null,
                    'field_of_study'  => $data['field_of_study'] ?? null,
                    'enrollment_date' => $data['enrollment_date'] ?? date('Y-m-d'),
                    'status'          => 'active',
                ]);

                if (!$studentId) throw new RuntimeException('Impossible de créer le profil étudiant.');

                return $studentId;
            });
        } catch (Throwable $e) {
            error_log('[Student::create] ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        try {
            $this->db->transaction(function (Database $db) use ($id, $tenantId, $data): void {
                $student = $db->fetchOne('SELECT user_id FROM students WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
                if (!$student) throw new RuntimeException('Étudiant introuvable.');

                $userFields = array_filter([
                    'first_name' => $data['first_name'] ?? null,
                    'last_name'  => $data['last_name']  ?? null,
                    'email'      => $data['email']       ?? null,
                    'phone'      => $data['phone']       ?? null,
                    'address'    => $data['address']     ?? null,
                ], fn($v) => $v !== null);

                if (!empty($userFields)) {
                    $db->update('users', $userFields, 'id = ? AND tenant_id = ?', [$student['user_id'], $tenantId]);
                }

                $studentFields = array_filter([
                    'department'     => $data['department']     ?? null,
                    'field_of_study' => $data['field_of_study'] ?? null,
                    'status'         => $data['status']         ?? null,
                ], fn($v) => $v !== null);

                if (!empty($studentFields)) {
                    $db->update('students', $studentFields, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
                }
            });
            return true;
        } catch (Throwable $e) {
            error_log('[Student::update] ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id, int $tenantId): bool
    {
        try {
            $this->db->transaction(function (Database $db) use ($id, $tenantId): void {
                $student = $db->fetchOne('SELECT user_id FROM students WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
                if (!$student) throw new RuntimeException('Étudiant introuvable.');
                $userId = $student['user_id'];
                $db->delete('grades',        'student_id = ? AND tenant_id = ?', [$id,     $tenantId]);
                $db->delete('quiz_attempts', 'student_id = ? AND tenant_id = ?', [$id,     $tenantId]);
                $db->execute(
                    'DELETE am FROM ai_messages am
                     JOIN ai_conversations ac ON ac.id = am.conversation_id
                     WHERE ac.user_id = ? AND ac.tenant_id = ?',
                    [$userId, $tenantId]
                );
                $db->delete('ai_conversations', 'user_id = ? AND tenant_id = ?', [$userId, $tenantId]);
                $db->delete('students',          'id = ? AND tenant_id = ?',      [$id,     $tenantId]);
                $db->delete('users',             'id = ? AND tenant_id = ?',      [$userId, $tenantId]);
            });
            return true;
        } catch (Throwable $e) {
            error_log('[Student::delete] ' . $e->getMessage());
            return false;
        }
    }

    public function getStats(int $studentId, int $tenantId): array
    {
        $grades = $this->db->fetchAll(
            'SELECT g.*, c.title AS course_title, c.code AS course_code
             FROM grades g
             JOIN courses c ON c.id = g.course_id
             WHERE g.student_id = ? AND g.tenant_id = ?',
            [$studentId, $tenantId]
        );

        $scores = array_filter(array_column($grades, 'score'), fn($s) => $s !== null);
        $avg    = empty($scores) ? null : array_sum($scores) / count($scores);

        return [
            'grades'        => $grades,
            'average'       => $avg,
            'letter_grade'  => getLetterGrade($avg),
            'total_courses' => count($grades),
            'passed'        => count(array_filter($scores, fn($s) => $s >= 10)),
            'failed'        => count(array_filter($scores, fn($s) => $s <  10)),
        ];
    }

    public function matriculeExists(string $matricule, int $tenantId, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT 1 FROM students WHERE matricule = ? AND tenant_id = ?';
        $params = [$matricule, $tenantId];
        if ($excludeId !== null) { $sql .= ' AND id != ?'; $params[] = $excludeId; }
        return (bool)$this->db->fetchScalar($sql . ' LIMIT 1', $params);
    }
}
