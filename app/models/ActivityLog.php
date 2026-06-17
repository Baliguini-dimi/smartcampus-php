<?php
declare(strict_types=1);

class ActivityLog
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function log(int $tenantId, ?int $userId, string $action, string $description, string $entity = '', ?int $entityId = null): void
    {
        try {
            $this->db->insert('activity_logs', [
                'tenant_id'   => $tenantId,
                'user_id'     => $userId,
                'action'      => $action,
                'description' => substr($description, 0, 500),
                'entity'      => $entity,
                'entity_id'   => $entityId,
                'ip_address'  => getClientIp(),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // Les logs ne doivent jamais faire planter l'app
        }
    }

    public function getRecent(int $tenantId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT al.*, CONCAT(u.first_name, " ", u.last_name) AS user_name, u.role
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.tenant_id = ?
             ORDER BY al.created_at DESC LIMIT ?',
            [$tenantId, $limit]
        );
    }
}
