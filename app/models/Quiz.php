<?php
declare(strict_types=1);

class Quiz
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getQuizzesByCourse(int $courseId, int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT q.*, COUNT(qq.id) AS question_count
             FROM quizzes q
             LEFT JOIN quiz_questions qq ON qq.quiz_id = q.id
             WHERE q.course_id = ? AND q.tenant_id = ?
             GROUP BY q.id
             ORDER BY q.created_at DESC',
            [$courseId, $tenantId]
        );
    }

    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT q.*, c.title AS course_title
             FROM quizzes q
             JOIN courses c ON c.id = q.course_id
             WHERE q.id = ? AND q.tenant_id = ? LIMIT 1',
            [$id, $tenantId]
        );
    }

    public function getQuestions(int $quizId, int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT qq.* FROM quiz_questions qq
             JOIN quizzes q ON q.id = qq.quiz_id
             WHERE qq.quiz_id = ? AND q.tenant_id = ?
             ORDER BY qq.id',
            [$quizId, $tenantId]
        );
    }

    public function createQuiz(int $courseId, string $title, int $numQuestions, int $tenantId): ?int
    {
        return $this->db->insert('quizzes', [
            'tenant_id'     => $tenantId,
            'course_id'     => $courseId,
            'title'         => trim($title),
            'num_questions' => $numQuestions,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function addQuestion(int $quizId, array $q): ?int
    {
        return $this->db->insert('quiz_questions', [
            'quiz_id'        => $quizId,
            'question'       => trim($q['question']),
            'option_a'       => trim($q['option_a']),
            'option_b'       => trim($q['option_b']),
            'option_c'       => trim($q['option_c']),
            'option_d'       => trim($q['option_d']),
            'correct_answer' => strtoupper(trim($q['correct_answer'])),
        ]);
    }

    public function deleteQuiz(int $id, int $tenantId): bool
    {
        try {
            $this->db->transaction(function (Database $db) use ($id, $tenantId): void {
                // Vérifier que le quiz appartient bien au tenant
                $quiz = $db->fetchOne('SELECT id FROM quizzes WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
                if (!$quiz) throw new RuntimeException('Quiz introuvable.');
                $db->delete('quiz_attempts', 'quiz_id = ?', [$id]);
                $db->delete('quiz_questions','quiz_id = ?', [$id]);
                $db->delete('quizzes',       'id = ? AND tenant_id = ?', [$id, $tenantId]);
            });
            return true;
        } catch (Throwable $e) {
            error_log('[Quiz::delete] ' . $e->getMessage());
            return false;
        }
    }

    // Sauvegarde d'une tentative et calcul du score
    public function saveAttempt(int $quizId, int $studentId, array $answers, int $tenantId): array
    {
        $questions = $this->getQuestions($quizId, $tenantId);
        $correct   = 0;
        $details   = [];

        foreach ($questions as $q) {
            $given     = strtoupper(trim($answers[$q['id']] ?? ''));
            $isCorrect = ($given === $q['correct_answer']);
            if ($isCorrect) $correct++;
            $details[] = [
                'question'       => $q['question'],
                'given'          => $given,
                'correct_answer' => $q['correct_answer'],
                'is_correct'     => $isCorrect,
                'option_a'       => $q['option_a'],
                'option_b'       => $q['option_b'],
                'option_c'       => $q['option_c'],
                'option_d'       => $q['option_d'],
            ];
        }

        $total   = count($questions);
        $score   = $total > 0 ? round(($correct / $total) * 20, 2) : 0;

        // Sauvegarder la tentative
        $this->db->insert('quiz_attempts', [
            'tenant_id'  => $tenantId,
            'quiz_id'    => $quizId,
            'student_id' => $studentId,
            'score'      => $score,
            'correct'    => $correct,
            'total'      => $total,
            'attempted_at'=> date('Y-m-d H:i:s'),
        ]);

        return [
            'score'   => $score,
            'correct' => $correct,
            'total'   => $total,
            'details' => $details,
        ];
    }

    public function getAttempts(int $studentId, int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT qa.*, q.title AS quiz_title, c.title AS course_title
             FROM quiz_attempts qa
             JOIN quizzes q ON q.id = qa.quiz_id
             JOIN courses c ON c.id = q.course_id
             WHERE qa.student_id = ? AND qa.tenant_id = ?
             ORDER BY qa.attempted_at DESC',
            [$studentId, $tenantId]
        );
    }
}
