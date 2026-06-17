<?php
declare(strict_types=1);

class Grade
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // Calcule le score final correctement (ignore les null)
    private function computeScore(?float $assignment, ?float $midterm, ?float $final): ?float
    {
        $values = array_filter(
            ['assignment' => $assignment, 'midterm' => $midterm, 'final' => $final],
            fn($v) => $v !== null
        );
        if (empty($values)) return null;
        return array_sum($values) / count($values);
    }

    public function getCourseGrades(int $courseId, int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT g.*, u.first_name, u.last_name, s.matricule
             FROM grades g
             JOIN students s ON s.id = g.student_id AND s.tenant_id = g.tenant_id
             JOIN users u ON u.id = s.user_id
             WHERE g.course_id = ? AND g.tenant_id = ?
             ORDER BY u.last_name, u.first_name',
            [$courseId, $tenantId]
        );
    }

    public function getStudentGrades(int $studentId, int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT g.*, c.title AS course_title, c.code AS course_code, c.credits
             FROM grades g
             JOIN courses c ON c.id = g.course_id
             WHERE g.student_id = ? AND g.tenant_id = ?
             ORDER BY c.title',
            [$studentId, $tenantId]
        );
    }

    public function findGrade(int $studentId, int $courseId, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM grades WHERE student_id = ? AND course_id = ? AND tenant_id = ? LIMIT 1',
            [$studentId, $courseId, $tenantId]
        );
    }

    public function addOrUpdate(array $data, int $tenantId): bool
    {
        $score = $this->computeScore(
            isset($data['assignment_score']) && $data['assignment_score'] !== '' ? (float)$data['assignment_score'] : null,
            isset($data['midterm_score'])    && $data['midterm_score']    !== '' ? (float)$data['midterm_score']    : null,
            isset($data['final_score'])      && $data['final_score']      !== '' ? (float)$data['final_score']      : null
        );

        $existing = $this->findGrade((int)$data['student_id'], (int)$data['course_id'], $tenantId);

        if ($existing) {
            return $this->db->update('grades', [
                'assignment_score' => $data['assignment_score'] !== '' ? (float)$data['assignment_score'] : null,
                'midterm_score'    => $data['midterm_score']    !== '' ? (float)$data['midterm_score']    : null,
                'final_score'      => $data['final_score']      !== '' ? (float)$data['final_score']      : null,
                'score'            => $score,
                'professor_id'     => $data['professor_id'] ?? null,
            ], 'id = ?', [$existing['id']]) >= 0;
        }

        return (bool)$this->db->insert('grades', [
            'tenant_id'        => $tenantId,
            'student_id'       => (int)$data['student_id'],
            'course_id'        => (int)$data['course_id'],
            'professor_id'     => $data['professor_id'] ?? null,
            'assignment_score' => $data['assignment_score'] !== '' ? (float)$data['assignment_score'] : null,
            'midterm_score'    => $data['midterm_score']    !== '' ? (float)$data['midterm_score']    : null,
            'final_score'      => $data['final_score']      !== '' ? (float)$data['final_score']      : null,
            'score'            => $score,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    // Statistiques dashboard — distribution A/B/C/D/F
    public function getGradeDistribution(int $tenantId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT score FROM grades WHERE tenant_id = ? AND score IS NOT NULL',
            [$tenantId]
        );

        $dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
        foreach ($rows as $row) {
            $letter = getLetterGrade($row['score']);
            if (isset($dist[$letter])) $dist[$letter]++;
        }
        return $dist;
    }

    // Moyenne par cours (top 10) — dashboard
    public function getAverageByCourse(int $tenantId, int $limit = 10): array
    {
        return $this->db->fetchAll(
            'SELECT c.title, c.code, ROUND(AVG(g.score), 2) AS average, COUNT(g.id) AS student_count
             FROM grades g
             JOIN courses c ON c.id = g.course_id
             WHERE g.tenant_id = ? AND g.score IS NOT NULL
             GROUP BY g.course_id, c.title, c.code
             ORDER BY average DESC
             LIMIT ?',
            [$tenantId, $limit]
        );
    }

    // Stats globales dashboard
    public function getStatistics(int $tenantId): array
    {
        $row = $this->db->fetchOne(
            'SELECT
               ROUND(AVG(score), 2)  AS average,
               ROUND(MAX(score), 2)  AS maximum,
               ROUND(MIN(score), 2)  AS minimum,
               COUNT(*)              AS total,
               SUM(score >= 10)      AS passed,
               SUM(score < 10)       AS failed
             FROM grades
             WHERE tenant_id = ? AND score IS NOT NULL',
            [$tenantId]
        );
        return $row ?? ['average' => 0, 'maximum' => 0, 'minimum' => 0, 'total' => 0, 'passed' => 0, 'failed' => 0];
    }

    // Export pour PDF/Excel
    public function getExportData(int $courseId, int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT u.last_name, u.first_name, s.matricule,
                    g.assignment_score, g.midterm_score, g.final_score,
                    g.score, c.title AS course_title, c.code AS course_code
             FROM grades g
             JOIN students s ON s.id = g.student_id
             JOIN users u ON u.id = s.user_id
             JOIN courses c ON c.id = g.course_id
             WHERE g.course_id = ? AND g.tenant_id = ?
             ORDER BY u.last_name, u.first_name',
            [$courseId, $tenantId]
        );
    }
}
