<?php
declare(strict_types=1);

class Professor
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAll(int $tenantId, int $limit = 0, int $offset = 0, string $search = ''): array
    {
        $sql = 'SELECT p.*, u.first_name, u.last_name, u.email, u.phone,
                       u.profile_picture, u.status,
                       (SELECT COUNT(*) FROM courses c WHERE c.professor_id = p.id AND c.tenant_id = p.tenant_id) AS course_count
                FROM professors p
                JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
                WHERE p.tenant_id = ?';
        $params = [$tenantId];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR p.department LIKE ?)';
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
        $sql    = 'SELECT COUNT(*) FROM professors p
                   JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
                   WHERE p.tenant_id = ?';
        $params = [$tenantId];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return (int)($this->db->fetchScalar($sql, $params) ?? 0);
    }

    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT p.*, u.first_name, u.last_name, u.email, u.phone, u.address, u.profile_picture, u.status
             FROM professors p
             JOIN users u ON u.id = p.user_id AND u.tenant_id = p.tenant_id
             WHERE p.id = ? AND p.tenant_id = ? LIMIT 1',
            [$id, $tenantId]
        );
    }

    public function findByUserId(int $userId, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT p.*, u.first_name, u.last_name, u.email, u.phone, u.address, u.profile_picture
             FROM professors p
             JOIN users u ON u.id = p.user_id
             WHERE p.user_id = ? AND p.tenant_id = ? LIMIT 1',
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
                    'role'       => 'professor',
                    'status'     => 'active',
                    'phone'      => $data['phone']   ?? null,
                    'address'    => $data['address'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                if (!$userId) throw new RuntimeException('Impossible de créer le compte utilisateur.');

                $profId = $db->insert('professors', [
                    'tenant_id'       => $tenantId,
                    'user_id'         => $userId,
                    'employee_id'     => $data['employee_id'] ?? generateEmployeeId($userId),
                    'department'      => $data['department']      ?? null,
                    'specialization'  => $data['specialization']  ?? null,
                    'office_location' => $data['office_location'] ?? null,
                    'office_hours'    => $data['office_hours']    ?? null,
                    'hire_date'       => $data['hire_date']       ?? date('Y-m-d'),
                ]);

                if (!$profId) throw new RuntimeException('Impossible de créer le profil professeur.');

                return $profId;
            });
        } catch (Throwable $e) {
            error_log('[Professor::create] ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        try {
            $this->db->transaction(function (Database $db) use ($id, $tenantId, $data): void {
                $prof = $db->fetchOne('SELECT user_id FROM professors WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
                if (!$prof) throw new RuntimeException('Professeur introuvable.');

                $userFields = array_filter([
                    'first_name' => $data['first_name'] ?? null,
                    'last_name'  => $data['last_name']  ?? null,
                    'email'      => $data['email']       ?? null,
                    'phone'      => $data['phone']       ?? null,
                    'address'    => $data['address']     ?? null,
                ], fn($v) => $v !== null);

                if (!empty($userFields)) {
                    $db->update('users', $userFields, 'id = ? AND tenant_id = ?', [$prof['user_id'], $tenantId]);
                }

                $profFields = array_filter([
                    'department'      => $data['department']      ?? null,
                    'specialization'  => $data['specialization']  ?? null,
                    'office_location' => $data['office_location'] ?? null,
                    'office_hours'    => $data['office_hours']    ?? null,
                ], fn($v) => $v !== null);

                if (!empty($profFields)) {
                    $db->update('professors', $profFields, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
                }
            });
            return true;
        } catch (Throwable $e) {
            error_log('[Professor::update] ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id, int $tenantId): bool
    {
        try {
            $this->db->transaction(function (Database $db) use ($id, $tenantId): void {
                $prof = $db->fetchOne('SELECT user_id FROM professors WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
                if (!$prof) throw new RuntimeException('Professeur introuvable.');
                $userId = $prof['user_id'];
                // Désaffecter les cours (ne pas les supprimer)
                $db->update('courses', ['professor_id' => null], 'professor_id = ? AND tenant_id = ?', [$id, $tenantId]);
                $db->delete('professors', 'id = ? AND tenant_id = ?',      [$id,     $tenantId]);
                $db->delete('users',      'id = ? AND tenant_id = ?',      [$userId, $tenantId]);
            });
            return true;
        } catch (Throwable $e) {
            error_log('[Professor::delete] ' . $e->getMessage());
            return false;
        }
    }

    public function getForSelect(int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT p.id, u.first_name, u.last_name, p.department, p.specialization
             FROM professors p
             JOIN users u ON u.id = p.user_id
             WHERE p.tenant_id = ? AND u.status = "active"
             ORDER BY u.last_name',
            [$tenantId]
        );
    }
}
