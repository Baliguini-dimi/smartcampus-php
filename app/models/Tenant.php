<?php
// =============================================================================
// SmartCampus SaaS — app/models/Tenant.php
// Gestion des établissements (tenants).
// Chaque tenant est totalement isolé par tenant_id.
// =============================================================================

declare(strict_types=1);

class Tenant
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ---------------------------------------------------------------------
    // LECTURE
    // ---------------------------------------------------------------------

    /**
     * Trouve un tenant par son slug unique.
     * Utilisé à chaque requête pour résoudre le tenant courant.
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM tenants WHERE slug = ? LIMIT 1',
            [strtolower(trim($slug))]
        );
    }

    /**
     * Trouve un tenant par son ID.
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM tenants WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Retourne tous les tenants (super admin uniquement).
     */
    public function getAll(string $status = ''): array
    {
        $sql    = 'SELECT t.*,
                     (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
                     (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.role = "student") AS student_count,
                     (SELECT COUNT(*) FROM courses c WHERE c.tenant_id = t.id) AS course_count
                   FROM tenants t';
        $params = [];

        if ($status !== '') {
            $sql   .= ' WHERE t.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY t.created_at DESC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Retourne les statistiques globales (super admin dashboard).
     */
    public function getGlobalStats(): array
    {
        return [
            'total_tenants'  => (int)$this->db->fetchScalar('SELECT COUNT(*) FROM tenants'),
            'active_tenants' => (int)$this->db->fetchScalar('SELECT COUNT(*) FROM tenants WHERE status = "active"'),
            'total_users'    => (int)$this->db->fetchScalar('SELECT COUNT(*) FROM users'),
            'total_students' => (int)$this->db->fetchScalar('SELECT COUNT(*) FROM users WHERE role = "student"'),
            'total_courses'  => (int)$this->db->fetchScalar('SELECT COUNT(*) FROM courses'),
        ];
    }

    // ---------------------------------------------------------------------
    // CRÉATION
    // ---------------------------------------------------------------------

    /**
     * Crée un nouveau tenant + son compte admin par défaut.
     * Utilise une transaction pour garantir la cohérence.
     *
     * @param  array $data Champs du tenant (name, slug, email_admin, ...)
     * @return int|null    ID du tenant créé ou null en cas d'erreur
     */
    public function create(array $data): ?int
    {
        // Vérifier que le slug est unique
        if ($this->slugExists($data['slug'])) {
            return null;
        }

        try {
            return $this->db->transaction(function (Database $db) use ($data): int {
                // 1. Insérer le tenant
                $tenantId = $db->insert('tenants', [
                    'name'            => $data['name'],
                    'slug'            => strtolower(trim($data['slug'])),
                    'email'           => $data['email']           ?? null,
                    'phone'           => $data['phone']           ?? null,
                    'address'         => $data['address']         ?? null,
                    'logo'            => $data['logo']            ?? null,
                    'primary_color'   => $data['primary_color']   ?? '#0b2b4f',
                    'secondary_color' => $data['secondary_color'] ?? '#ffb347',
                    'plan'            => $data['plan']            ?? 'free',
                    'status'          => 'active',
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);

                if (!$tenantId) {
                    throw new RuntimeException('Impossible de créer le tenant.');
                }

                // 2. Créer le compte admin de l'établissement
                $adminId = $db->insert('users', [
                    'tenant_id'  => $tenantId,
                    'email'      => $data['admin_email'],
                    'password'   => password_hash($data['admin_password'], PASSWORD_BCRYPT, ['cost' => 12]),
                    'first_name' => $data['admin_first_name'] ?? 'Admin',
                    'last_name'  => $data['admin_last_name']  ?? $data['name'],
                    'role'       => 'admin',
                    'status'     => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                if (!$adminId) {
                    throw new RuntimeException('Impossible de créer le compte admin.');
                }

                return $tenantId;
            });
        } catch (Throwable $e) {
            error_log('[Tenant::create] ' . $e->getMessage());
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // MISE À JOUR
    // ---------------------------------------------------------------------

    /**
     * Met à jour les informations d'un tenant.
     */
    public function update(int $id, array $data): bool
    {
        // Ne pas autoriser la modification du slug une fois créé
        unset($data['slug'], $data['id']);

        $rows = $this->db->update('tenants', $data, 'id = ?', [$id]);
        return $rows >= 0;
    }

    /**
     * Change le statut d'un tenant (active / suspended / inactive).
     */
    public function setStatus(int $id, string $status): bool
    {
        $allowed = ['active', 'suspended', 'inactive'];
        if (!in_array($status, $allowed, true)) return false;

        return $this->db->update('tenants', ['status' => $status], 'id = ?', [$id]) >= 0;
    }

    /**
     * Met à jour le logo d'un tenant.
     */
    public function updateLogo(int $id, string $filename): bool
    {
        return $this->db->update('tenants', ['logo' => $filename], 'id = ?', [$id]) >= 0;
    }

    // ---------------------------------------------------------------------
    // SUPPRESSION
    // ---------------------------------------------------------------------

    /**
     * Supprime un tenant et TOUTES ses données (cascade).
     * À utiliser avec une extrême prudence (super admin uniquement).
     */
    public function delete(int $id): bool
    {
        try {
            $this->db->transaction(function (Database $db) use ($id): void {
                // L'ordre respecte les contraintes FK
                $tables = [
                    'ai_messages', 'ai_conversations',
                    'quiz_questions', 'quizzes',
                    'quiz_attempts',
                    'grades', 'messages', 'announcements',
                    'courses', 'students', 'professors',
                    'activity_logs', 'users',
                    'tenants',
                ];
                foreach ($tables as $table) {
                    $col = ($table === 'tenants') ? 'id' : 'tenant_id';
                    $db->delete($table, "$col = ?", [$id]);
                }
            });
            return true;
        } catch (Throwable $e) {
            error_log('[Tenant::delete] ' . $e->getMessage());
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // VALIDATIONS
    // ---------------------------------------------------------------------

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        return $this->db->exists('tenants', 'slug', strtolower($slug), $excludeId);
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        return $this->db->exists('tenants', 'email', $email, $excludeId);
    }

    // ---------------------------------------------------------------------
    // LIMITES DU PLAN (scalabilité SaaS)
    // ---------------------------------------------------------------------

    /**
     * Retourne les limites du plan d'un tenant.
     */
    public static function getPlanLimits(string $plan): array
    {
        return match ($plan) {
            'free'       => ['students' => 50,   'courses' => 5,   'ai' => false, 'professors' => 3],
            'pro'        => ['students' => 500,  'courses' => 50,  'ai' => true,  'professors' => 20],
            'enterprise' => ['students' => 9999, 'courses' => 999, 'ai' => true,  'professors' => 999],
            default      => ['students' => 50,   'courses' => 5,   'ai' => false, 'professors' => 3],
        };
    }

    /**
     * Vérifie si un tenant peut encore ajouter des étudiants selon son plan.
     */
    public function canAddStudent(int $tenantId, string $plan): bool
    {
        $limits = self::getPlanLimits($plan);
        $count  = $this->db->count('students', 'tenant_id = ?', [$tenantId]);
        return $count < $limits['students'];
    }

    /**
     * Vérifie si un tenant a accès à l'IA selon son plan.
     */
    public function canUseAI(string $plan): bool
    {
        return self::getPlanLimits($plan)['ai'];
    }
}
