<?php
declare(strict_types=1);

class Announcement
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAll(int $tenantId, bool $publishedOnly = true, int $limit = 0, int $offset = 0): array
    {
        $sql    = 'SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS author_name
                   FROM announcements a
                   JOIN users u ON u.id = a.author_id
                   WHERE a.tenant_id = ?';
        $params = [$tenantId];

        if ($publishedOnly) { $sql .= ' AND a.is_published = 1'; }

        $sql .= ' ORDER BY a.priority DESC, a.created_at DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset;
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function count(int $tenantId, bool $publishedOnly = true): int
    {
        $sql    = 'SELECT COUNT(*) FROM announcements WHERE tenant_id = ?';
        $params = [$tenantId];
        if ($publishedOnly) { $sql .= ' AND is_published = 1'; }
        return (int)($this->db->fetchScalar($sql, $params) ?? 0);
    }

    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS author_name
             FROM announcements a
             JOIN users u ON u.id = a.author_id
             WHERE a.id = ? AND a.tenant_id = ? LIMIT 1',
            [$id, $tenantId]
        );
    }

    public function create(array $data, int $tenantId): ?int
    {
        return $this->db->insert('announcements', [
            'tenant_id'    => $tenantId,
            'title'        => trim($data['title']),
            'content'      => trim($data['content']),
            'author_id'    => (int)$data['author_id'],
            'priority'     => (int)($data['priority'] ?? 0),
            'is_published' => isset($data['is_published']) ? (int)(bool)$data['is_published'] : 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, int $tenantId, array $data): bool
    {
        $allowed  = ['title', 'content', 'priority', 'is_published'];
        $filtered = array_intersect_key($data, array_flip($allowed));
        if (empty($filtered)) return false;
        return $this->db->update('announcements', $filtered, 'id = ? AND tenant_id = ?', [$id, $tenantId]) >= 0;
    }

    public function delete(int $id, int $tenantId): bool
    {
        return $this->db->delete('announcements', 'id = ? AND tenant_id = ?', [$id, $tenantId]) > 0;
    }

    public function getRecent(int $tenantId, int $limit = 3): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM announcements WHERE tenant_id = ? AND is_published = 1
             ORDER BY priority DESC, created_at DESC LIMIT ?',
            [$tenantId, $limit]
        );
    }
}
