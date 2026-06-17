<?php
declare(strict_types=1);

class Course
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAll(int $tenantId, int $limit = 0, int $offset = 0, string $search = ''): array
    {
        $sql = 'SELECT c.*,
                       CONCAT(u.first_name, " ", u.last_name) AS professor_name,
                       p.department AS prof_department,
                       (SELECT COUNT(*) FROM quizzes q WHERE q.course_id = c.id AND q.tenant_id = c.tenant_id) AS quiz_count
                FROM courses c
                LEFT JOIN professors p ON p.id = c.professor_id AND p.tenant_id = c.tenant_id
                LEFT JOIN users u ON u.id = p.user_id
                WHERE c.tenant_id = ?';
        $params = [$tenantId];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= ' AND (c.title LIKE ? OR c.code LIKE ? OR c.department LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $sql .= ' ORDER BY c.created_at DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset;
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function count(int $tenantId, string $search = ''): int
    {
        $sql    = 'SELECT COUNT(*) FROM courses WHERE tenant_id = ?';
        $params = [$tenantId];
        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= ' AND (title LIKE ? OR code LIKE ? OR department LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        return (int)($this->db->fetchScalar($sql, $params) ?? 0);
    }

    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT c.*, CONCAT(u.first_name, " ", u.last_name) AS professor_name
             FROM courses c
             LEFT JOIN professors p ON p.id = c.professor_id
             LEFT JOIN users u ON u.id = p.user_id
             WHERE c.id = ? AND c.tenant_id = ? LIMIT 1',
            [$id, $tenantId]
        );
    }

    public function create(array $data, int $tenantId): ?int
    {
        return $this->db->insert('courses', [
            'tenant_id'     => $tenantId,
            'code'          => strtoupper(trim($data['code'])),
            'title'         => trim($data['title']),
            'description'   => $data['description']   ?? null,
            'objectives'    => $data['objectives']    ?? null,
            'syllabus'      => $data['syllabus']      ?? null,
            'credits'       => (int)($data['credits'] ?? 3),
            'department'    => $data['department']    ?? null,
            'capacity'      => (int)($data['capacity'] ?? 50),
            'professor_id'  => $data['professor_id']  ? (int)$data['professor_id'] : null,
            'semester'      => $data['semester']      ?? null,
            'academic_year' => $data['academic_year'] ?? date('Y') . '-' . (date('Y') + 1),
            'status'        => $data['status']        ?? 'active',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        $allowed = ['code','title','description','objectives','syllabus',
                    'credits','department','capacity','professor_id','semester','academic_year','status'];
        $filtered = array_intersect_key($data, array_flip($allowed));
        if (empty($filtered)) return false;
        return $this->db->update('courses', $filtered, 'id = ? AND tenant_id = ?', [$id, $tenantId]) >= 0;
    }

    // Correction du bug original : méthode bien dans la classe
    public function updateContent(int $id, int $tenantId, string $description, string $objectives, string $syllabus): bool
    {
        return $this->db->update('courses', [
            'description' => $description,
            'objectives'  => $objectives,
            'syllabus'    => $syllabus,
        ], 'id = ? AND tenant_id = ?', [$id, $tenantId]) >= 0;
    }

    public function delete(int $id, int $tenantId): bool
    {
        try {
            $this->db->transaction(function (Database $db) use ($id, $tenantId): void {
                $db->execute(
                    'DELETE qq FROM quiz_questions qq
                     JOIN quizzes q ON q.id = qq.quiz_id
                     WHERE q.course_id = ? AND q.tenant_id = ?',
                    [$id, $tenantId]
                );
                $db->delete('quizzes',       'course_id = ? AND tenant_id = ?', [$id, $tenantId]);
                $db->delete('grades',        'course_id = ? AND tenant_id = ?', [$id, $tenantId]);
                $db->delete('courses',       'id = ? AND tenant_id = ?',        [$id, $tenantId]);
            });
            return true;
        } catch (Throwable $e) {
            error_log('[Course::delete] ' . $e->getMessage());
            return false;
        }
    }

    public function codeExists(string $code, int $tenantId, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT 1 FROM courses WHERE code = ? AND tenant_id = ?';
        $params = [strtoupper($code), $tenantId];
        if ($excludeId !== null) { $sql .= ' AND id != ?'; $params[] = $excludeId; }
        return (bool)$this->db->fetchScalar($sql . ' LIMIT 1', $params);
    }

    public function getForSelect(int $tenantId, ?int $professorId = null): array
    {
        $sql    = 'SELECT id, code, title FROM courses WHERE tenant_id = ? AND status = "active"';
        $params = [$tenantId];
        if ($professorId !== null) { $sql .= ' AND professor_id = ?'; $params[] = $professorId; }
        $sql .= ' ORDER BY title';
        return $this->db->fetchAll($sql, $params);
    }
}
