<?php
// =============================================================================
// SmartCampus SaaS — app/models/User.php
// Gestion des utilisateurs avec isolation par tenant_id.
// =============================================================================

declare(strict_types=1);

class User
{
    private Database $db;

    // Colonnes autorisées à être retournées (jamais le mot de passe)
    private const SAFE_COLUMNS = 'id, tenant_id, email, first_name, last_name, role,
                                   status, phone, address, profile_picture, created_at, last_login';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ---------------------------------------------------------------------
    // LECTURE
    // ---------------------------------------------------------------------

    /**
     * Trouve un utilisateur par email ET tenant (pour la connexion).
     * Retourne TOUTES les colonnes dont password pour la vérification.
     */
    public function findByEmail(string $email, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM users WHERE email = ? AND tenant_id = ? AND status = "active" LIMIT 1',
            [$email, $tenantId]
        );
    }

    /**
     * Trouve un utilisateur par ID, sans retourner le mot de passe.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT ' . self::SAFE_COLUMNS . '
             FROM users
             WHERE id = ? AND tenant_id = ?
             LIMIT 1',
            [$id, $tenantId]
        );
    }

    /**
     * Retourne tous les utilisateurs d'un tenant (sans mot de passe).
     * Utile pour les listes déroulantes (messagerie, etc.).
     */
    public function getAll(int $tenantId, string $role = ''): array
    {
        $sql    = 'SELECT ' . self::SAFE_COLUMNS . '
                   FROM users
                   WHERE tenant_id = ? AND status = "active"';
        $params = [$tenantId];

        if ($role !== '') {
            $sql     .= ' AND role = ?';
            $params[] = $role;
        }

        $sql .= ' ORDER BY last_name, first_name';
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Recherche d'utilisateurs (admin).
     */
    public function search(int $tenantId, string $query, string $role = ''): array
    {
        $like   = '%' . $query . '%';
        $sql    = 'SELECT ' . self::SAFE_COLUMNS . '
                   FROM users
                   WHERE tenant_id = ?
                     AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
        $params = [$tenantId, $like, $like, $like];

        if ($role !== '') {
            $sql     .= ' AND role = ?';
            $params[] = $role;
        }

        $sql .= ' ORDER BY last_name, first_name LIMIT 50';
        return $this->db->fetchAll($sql, $params);
    }

    // ---------------------------------------------------------------------
    // AUTHENTIFICATION
    // ---------------------------------------------------------------------

    /**
     * Vérifie les credentials et retourne l'utilisateur ou null.
     * Mise à jour de last_login en cas de succès.
     */
    public function authenticate(string $email, string $password, int $tenantId): ?array
    {
        $user = $this->findByEmail($email, $tenantId);

        if (!$user) return null;

        if (!password_verify($password, $user['password'])) return null;

        // Rehash si le coût de l'algorithme a changé
        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $this->updatePassword($user['id'], $tenantId, $password);
        }

        // Mettre à jour last_login
        $this->db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        // Retirer le mot de passe de l'array retourné
        unset($user['password']);
        return $user;
    }

    // ---------------------------------------------------------------------
    // CRÉATION
    // ---------------------------------------------------------------------

    /**
     * Crée un nouvel utilisateur.
     * ⚠️  Le rôle 'admin' ne peut être attribué que par un admin existant
     *     (cette vérification doit être faite dans le contrôleur/page).
     *
     * @return int|null ID de l'utilisateur créé
     */
    public function create(array $data, int $tenantId): ?int
    {
        // Rôles autorisés à l'auto-inscription
        $allowedRoles = ['student', 'professor'];
        $role = $data['role'] ?? 'student';

        // Sécurité : forcer un rôle valide
        if (!in_array($role, array_merge($allowedRoles, ['admin']), true)) {
            $role = 'student';
        }

        return $this->db->insert('users', [
            'tenant_id'       => $tenantId,
            'email'           => strtolower(trim($data['email'])),
            'password'        => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'first_name'      => trim($data['first_name']),
            'last_name'       => strtoupper(trim($data['last_name'])),
            'role'            => $role,
            'status'          => 'active',
            'phone'           => $data['phone']   ?? null,
            'address'         => $data['address'] ?? null,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    // ---------------------------------------------------------------------
    // MISE À JOUR
    // ---------------------------------------------------------------------

    /**
     * Met à jour les infos du profil (pas le mot de passe, pas le rôle).
     */
    public function updateProfile(int $id, int $tenantId, array $data): bool
    {
        $allowed = ['first_name', 'last_name', 'phone', 'address'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) return false;

        return $this->db->update('users', $filtered, 'id = ? AND tenant_id = ?', [$id, $tenantId]) >= 0;
    }

    /**
     * Met à jour le mot de passe (après vérification de l'ancien).
     */
    public function updatePassword(int $id, int $tenantId, string $newPassword): bool
    {
        return $this->db->update('users', [
            'password' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12])
        ], 'id = ? AND tenant_id = ?', [$id, $tenantId]) > 0;
    }

    /**
     * Vérifie le mot de passe actuel avant d'en changer.
     */
    public function verifyAndChangePassword(int $id, int $tenantId, string $currentPassword, string $newPassword): bool
    {
        $user = $this->db->fetchOne('SELECT password FROM users WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
        if (!$user) return false;
        if (!password_verify($currentPassword, $user['password'])) return false;
        return $this->updatePassword($id, $tenantId, $newPassword);
    }

    /**
     * Met à jour la photo de profil.
     */
    public function updateProfilePicture(int $id, int $tenantId, string $filename): bool
    {
        return $this->db->update('users',
            ['profile_picture' => $filename],
            'id = ? AND tenant_id = ?',
            [$id, $tenantId]
        ) >= 0;
    }

    /**
     * Change le statut d'un utilisateur.
     */
    public function setStatus(int $id, int $tenantId, string $status): bool
    {
        $allowed = ['active', 'inactive', 'suspended'];
        if (!in_array($status, $allowed, true)) return false;

        return $this->db->update('users', ['status' => $status], 'id = ? AND tenant_id = ?', [$id, $tenantId]) >= 0;
    }

    // ---------------------------------------------------------------------
    // RÉINITIALISATION DE MOT DE PASSE
    // ---------------------------------------------------------------------

    /**
     * Génère un token de reset et le stocke en session temporaire.
     * (Sans SMTP, on simule — à remplacer par un vrai envoi mail en prod)
     */
    public function generateResetToken(string $email, int $tenantId): ?string
    {
        $user = $this->db->fetchOne(
            'SELECT id FROM users WHERE email = ? AND tenant_id = ? AND status = "active"',
            [$email, $tenantId]
        );
        if (!$user) return null;

        $token = bin2hex(random_bytes(32));
        // Stocker en session pour la démo (en prod : table password_resets)
        $_SESSION['reset_token']   = hash('sha256', $token);
        $_SESSION['reset_user_id'] = $user['id'];
        $_SESSION['reset_expires'] = time() + 900; // 15 min

        return $token;
    }

    /**
     * Vérifie un token de reset et change le mot de passe.
     */
    public function resetPassword(string $token, string $newPassword, int $tenantId): bool
    {
        if (empty($_SESSION['reset_token']) || empty($_SESSION['reset_user_id'])) return false;
        if (time() > ($_SESSION['reset_expires'] ?? 0)) return false;
        if (!hash_equals($_SESSION['reset_token'], hash('sha256', $token))) return false;

        $userId = (int)$_SESSION['reset_user_id'];
        $ok = $this->updatePassword($userId, $tenantId, $newPassword);

        if ($ok) {
            unset($_SESSION['reset_token'], $_SESSION['reset_user_id'], $_SESSION['reset_expires']);
        }

        return $ok;
    }

    // ---------------------------------------------------------------------
    // SUPPRESSION
    // ---------------------------------------------------------------------

    /**
     * Supprime un utilisateur et ses données associées (transaction).
     */
    public function delete(int $id, int $tenantId): bool
    {
        try {
            $this->db->transaction(function (Database $db) use ($id, $tenantId): void {
                // Anonymiser les messages plutôt que les supprimer
                $db->execute(
                    'UPDATE messages SET sender_id = NULL WHERE sender_id = ? AND tenant_id = ?',
                    [$id, $tenantId]
                );
                $db->delete('ai_messages',
                    'conversation_id IN (SELECT id FROM ai_conversations WHERE user_id = ? AND tenant_id = ?)',
                    [$id, $tenantId]
                );
                $db->delete('ai_conversations', 'user_id = ? AND tenant_id = ?', [$id, $tenantId]);
                $db->delete('users', 'id = ? AND tenant_id = ?', [$id, $tenantId]);
            });
            return true;
        } catch (Throwable $e) {
            error_log('[User::delete] ' . $e->getMessage());
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // VALIDATIONS
    // ---------------------------------------------------------------------

    public function emailExists(string $email, int $tenantId, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT 1 FROM users WHERE email = ? AND tenant_id = ?';
        $params = [strtolower($email), $tenantId];
        if ($excludeId !== null) {
            $sql    .= ' AND id != ?';
            $params[] = $excludeId;
        }
        return (bool)$this->db->fetchScalar($sql . ' LIMIT 1', $params);
    }

    // ---------------------------------------------------------------------
    // RECHERCHE GLOBALE (navbar)
    // ---------------------------------------------------------------------

    /**
     * Recherche dans users, students, courses simultanément.
     */
    public function globalSearch(string $query, int $tenantId): array
    {
        $like = '%' . $query . '%';

        $users = $this->db->fetchAll(
            'SELECT id, first_name, last_name, email, role, "user" AS type
             FROM users
             WHERE tenant_id = ? AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
             LIMIT 5',
            [$tenantId, $like, $like, $like]
        );

        $courses = $this->db->fetchAll(
            'SELECT id, title, code, "course" AS type
             FROM courses
             WHERE tenant_id = ? AND (title LIKE ? OR code LIKE ?)
             LIMIT 5',
            [$tenantId, $like, $like]
        );

        return ['users' => $users, 'courses' => $courses];
    }

    // ---------------------------------------------------------------------
    // STATISTIQUES
    // ---------------------------------------------------------------------

    public function countByRole(int $tenantId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT role, COUNT(*) AS total FROM users WHERE tenant_id = ? AND status = "active" GROUP BY role',
            [$tenantId]
        );
        $result = ['admin' => 0, 'professor' => 0, 'student' => 0];
        foreach ($rows as $row) {
            $result[$row['role']] = (int)$row['total'];
        }
        return $result;
    }
}
