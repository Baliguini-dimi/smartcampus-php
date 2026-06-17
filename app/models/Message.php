<?php
declare(strict_types=1);

class Message
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getInbox(int $userId, int $tenantId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT m.*, u.first_name AS sender_first, u.last_name AS sender_last, u.profile_picture AS sender_pic
             FROM messages m
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.recipient_id = ? AND m.tenant_id = ?
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?',
            [$userId, $tenantId, $limit, $offset]
        );
    }

    public function getSent(int $userId, int $tenantId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT m.*, u.first_name AS recipient_first, u.last_name AS recipient_last
             FROM messages m
             LEFT JOIN users u ON u.id = m.recipient_id
             WHERE m.sender_id = ? AND m.tenant_id = ?
             ORDER BY m.created_at DESC
             LIMIT ? OFFSET ?',
            [$userId, $tenantId, $limit, $offset]
        );
    }

    public function findById(int $id, int $tenantId): ?array
    {
        return $this->db->fetchOne(
            'SELECT m.*,
                    s.first_name AS sender_first, s.last_name AS sender_last, s.profile_picture AS sender_pic,
                    r.first_name AS recipient_first, r.last_name AS recipient_last
             FROM messages m
             LEFT JOIN users s ON s.id = m.sender_id
             LEFT JOIN users r ON r.id = m.recipient_id
             WHERE m.id = ? AND m.tenant_id = ? LIMIT 1',
            [$id, $tenantId]
        );
    }

    public function send(array $data, int $tenantId): ?int
    {
        return $this->db->insert('messages', [
            'tenant_id'    => $tenantId,
            'sender_id'    => $data['sender_id'],
            'recipient_id' => $data['recipient_id'],
            'subject'      => trim($data['subject']),
            'content'      => trim($data['content']),
            'is_read'      => 0,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    public function reply(int $originalId, int $senderId, string $content, int $tenantId): ?int
    {
        $original = $this->findById($originalId, $tenantId);
        if (!$original) return null;

        // Marquer l'original comme lu
        $this->markAsRead($originalId, $tenantId);

        return $this->send([
            'sender_id'    => $senderId,
            'recipient_id' => $original['sender_id'],
            'subject'      => 'Re: ' . $original['subject'],
            'content'      => $content,
        ], $tenantId);
    }

    public function markAsRead(int $id, int $tenantId): void
    {
        $this->db->update('messages', ['is_read' => 1], 'id = ? AND tenant_id = ?', [$id, $tenantId]);
    }

    public function markAllAsRead(int $userId, int $tenantId): void
    {
        $this->db->execute(
            'UPDATE messages SET is_read = 1 WHERE recipient_id = ? AND tenant_id = ? AND is_read = 0',
            [$userId, $tenantId]
        );
    }

    public function countUnread(int $userId, int $tenantId): int
    {
        return (int)($this->db->fetchScalar(
            'SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND tenant_id = ? AND is_read = 0',
            [$userId, $tenantId]
        ) ?? 0);
    }

    public function delete(int $id, int $userId, int $tenantId): bool
    {
        return $this->db->delete(
            'messages',
            'id = ? AND (sender_id = ? OR recipient_id = ?) AND tenant_id = ?',
            [$id, $userId, $userId, $tenantId]
        ) > 0;
    }

    public function getRecentForNotification(int $userId, int $tenantId, int $limit = 5): array
    {
        return $this->db->fetchAll(
            'SELECT m.id, m.subject, m.is_read, m.created_at,
                    CONCAT(u.first_name, " ", u.last_name) AS sender_name
             FROM messages m
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE m.recipient_id = ? AND m.tenant_id = ?
             ORDER BY m.created_at DESC LIMIT ?',
            [$userId, $tenantId, $limit]
        );
    }

    // Statut en ligne (dernière connexion < 5 min)
    public function isUserOnline(int $userId): bool
    {
        $user = $this->db->fetchOne('SELECT last_login FROM users WHERE id = ?', [$userId]);
        if (!$user || !$user['last_login']) return false;
        return (time() - strtotime($user['last_login'])) < 300;
    }
}
