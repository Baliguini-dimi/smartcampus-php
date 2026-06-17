<?php
declare(strict_types=1);

class AI
{
    private Database $db;
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->db     = Database::getInstance();
        $this->apiKey = GROQ_API_KEY;
        $this->model  = GROQ_MODEL;
    }

    // Appel API Groq (compatible OpenAI)
    private function callGroq(array $messages, int $maxTokens = 1024): string
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Clé API Groq non configurée.');
        }

        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => 0.7,
        ]);

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log('[AI::callGroq] HTTP ' . $httpCode . ' — ' . $response);
            throw new RuntimeException('Erreur lors de la communication avec l\'IA. Réessayez.');
        }

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    // Chat conversationnel
    public function chat(int $conversationId, string $userMessage, int $tenantId): string
    {
        // Tronquer le message utilisateur
        $userMessage = mb_substr(trim($userMessage), 0, 2000);

        // Récupérer l'historique (max 10 derniers échanges)
        $history = $this->db->fetchAll(
            'SELECT role, content FROM ai_messages
             WHERE conversation_id = ? ORDER BY created_at ASC LIMIT 20',
            [$conversationId]
        );

        // Sauvegarder le message utilisateur
        $this->db->insert('ai_messages', [
            'conversation_id' => $conversationId,
            'role'            => 'user',
            'content'         => $userMessage,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $messages = [
            ['role' => 'system', 'content' => 'Tu es un assistant pédagogique pour une plateforme universitaire. Réponds en français de façon claire, concise et structurée.'],
            ...$history,
            ['role' => 'user', 'content' => $userMessage],
        ];

        $reply = $this->callGroq($messages);

        // Sauvegarder la réponse
        $this->db->insert('ai_messages', [
            'conversation_id' => $conversationId,
            'role'            => 'assistant',
            'content'         => $reply,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        return $reply;
    }

    // Génération de contenu de cours
    public function generateCourseContent(string $title, string $code): array
    {
        $prompt = "Génère en français le contenu pédagogique pour le cours suivant :
Titre : $title
Code : $code

Réponds UNIQUEMENT en JSON valide avec ce format exact :
{\"description\": \"...\", \"objectives\": \"...\", \"syllabus\": \"...\"}

- description : 2-3 phrases de présentation du cours
- objectives : liste des 4-5 objectifs principaux (séparés par \\n)
- syllabus : plan du cours avec 5-6 chapitres (séparés par \\n)";

        $response = $this->callGroq([
            ['role' => 'system', 'content' => 'Tu es un expert en ingénierie pédagogique. Réponds uniquement en JSON valide, sans markdown.'],
            ['role' => 'user',   'content' => $prompt],
        ], 800);

        $clean = preg_replace('/^```json|^```|```$/m', '', trim($response));
        $data  = json_decode(trim($clean), true);

        if (!$data || !isset($data['description'])) {
            throw new RuntimeException('Format de réponse IA invalide.');
        }

        return $data;
    }

    // Génération de quiz
    public function generateQuiz(string $courseTitle, string $courseContent, int $numQuestions): array
    {
        $numQuestions = max(3, min(20, $numQuestions));
        $context      = mb_substr($courseContent, 0, 1000);

        $prompt = "Génère $numQuestions questions QCM en français pour le cours : \"$courseTitle\".
Contexte : $context

Réponds UNIQUEMENT en JSON valide avec ce format :
[{\"question\":\"...\",\"option_a\":\"...\",\"option_b\":\"...\",\"option_c\":\"...\",\"option_d\":\"...\",\"correct_answer\":\"A\"}]

correct_answer doit être A, B, C ou D uniquement.";

        $response = $this->callGroq([
            ['role' => 'system', 'content' => 'Tu es un expert en création de quiz pédagogiques. Réponds uniquement en JSON valide.'],
            ['role' => 'user',   'content' => $prompt],
        ], 2048);

        $clean     = preg_replace('/^```json|^```|```$/m', '', trim($response));
        $questions = json_decode(trim($clean), true);

        if (!is_array($questions) || empty($questions)) {
            throw new RuntimeException('Format de quiz invalide reçu de l\'IA.');
        }

        return $questions;
    }

    // Gestion des conversations
    public function createConversation(int $userId, string $title, int $tenantId): ?int
    {
        return $this->db->insert('ai_conversations', [
            'tenant_id'  => $tenantId,
            'user_id'    => $userId,
            'title'      => mb_substr(trim($title), 0, 100),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getConversations(int $userId, int $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT ac.*, (SELECT content FROM ai_messages am WHERE am.conversation_id = ac.id ORDER BY am.created_at DESC LIMIT 1) AS last_message
             FROM ai_conversations ac
             WHERE ac.user_id = ? AND ac.tenant_id = ?
             ORDER BY ac.created_at DESC LIMIT 20',
            [$userId, $tenantId]
        );
    }

    public function getConversation(int $id, int $userId, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM ai_conversations WHERE id = ? AND user_id = ? AND tenant_id = ? LIMIT 1',
            [$id, $userId, $tenantId]
        );
    }

    public function getMessages(int $conversationId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ai_messages WHERE conversation_id = ? ORDER BY created_at ASC',
            [$conversationId]
        );
    }
}
