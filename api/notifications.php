<?php
/**
 * OrbitDesk Workspace — Notifications API
 * Endpoints: count, list, mark_read, mark_one, delete_one, dismiss
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');

requireLogin();
$user  = currentUser();
$uid   = (int)$user['id'];
$orgId = (int)($user['org_id'] ?? 0);

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // Unread count
    case 'count':
        echo json_encode(['count' => getUnreadCount($uid)]);
        break;

    // Last N unread (for dropdown)
    case 'list':
        $limit = max(1, min(50, (int)($input['limit'] ?? $_GET['limit'] ?? 10)));
        $notes = getUnreadNotifications($uid, $limit);
        echo json_encode(['notifications' => $notes, 'count' => getUnreadCount($uid)]);
        break;

    // Mark all as read
    case 'mark_read':
        markNotificationsRead($uid);
        echo json_encode(['ok' => true, 'count' => 0]);
        break;

    // Mark single notification as read
    case 'mark_one':
        $id = (int)($input['id'] ?? $_POST['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); break; }
        $ok = markOneRead($id, $uid);
        echo json_encode(['ok' => $ok, 'count' => getUnreadCount($uid)]);
        break;

    // Delete a single notification
    case 'delete_one':
        $id = (int)($input['id'] ?? $_POST['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); break; }
        $ok = deleteNotification($id, $uid);
        echo json_encode(['ok' => $ok, 'count' => getUnreadCount($uid)]);
        break;

    // Dismiss — mark read + return updated count (used by bell dropdown on open)
    case 'dismiss':
        markNotificationsRead($uid);
        echo json_encode(['ok' => true, 'count' => 0]);
        break;

    // Poll for real-time count updates (called by header JS every 60s)
    case 'poll':
        $count = getUnreadCount($uid);
        // Return newest unread IDs so client can detect new arrivals
        try {
            $since = (int)($input['since'] ?? 0);
            if ($since) {
                $stmt = $pdo->prepare(
                    "SELECT id, title, type FROM notifications
                     WHERE (user_id=? OR user_id IS NULL) AND id > ? AND is_read=0
                     ORDER BY created_at DESC LIMIT 5"
                );
                $stmt->execute([$uid, $since]);
                $newItems = $stmt->fetchAll();
            } else {
                $newItems = [];
            }
        } catch (Exception $e) {
            $newItems = [];
        }
        echo json_encode(['count' => $count, 'new' => $newItems]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
