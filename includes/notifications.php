<?php
// Notification helpers — include after DB and functions are loaded

function createNotification(int $orgId, ?int $userId, string $title, string $message, string $type = 'info', string $link = ''): void {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO notifications (org_id, user_id, title, message, type, link) VALUES (?,?,?,?,?,?)")
            ->execute([$orgId, $userId ?: null, $title, $message, $type, $link]);
    } catch (Exception $e) {
        error_log('[Notification] ' . $e->getMessage());
    }
}

function getUnreadNotifications(int $userId, int $limit = 10): array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE (user_id=? OR user_id IS NULL) AND is_read=0 ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getUnreadCount(int $userId): int {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id=? OR user_id IS NULL) AND is_read=0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function markNotificationsRead(int $userId): void {
    global $pdo;
    try {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? OR user_id IS NULL")->execute([$userId]);
    } catch (Exception $e) {
        // Table may not exist yet — fail silently
    }
}
