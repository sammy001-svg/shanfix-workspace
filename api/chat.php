<?php
/**
 * api/chat.php — Internal chat API
 * Actions: list_conversations, get_messages, send_message, new_conversation, mark_read
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
    exit;
}

$user  = currentUser();
$uid   = (int)$user['id'];
$orgId = (int)$user['org_id'];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        // ── List conversations for this user ──────────────────────
        case 'list_conversations':
            $stmt = $pdo->prepare("
                SELECT cc.id, cc.title, cc.updated_at,
                       (SELECT m.message FROM chat_messages m WHERE m.conversation_id = cc.id ORDER BY m.created_at DESC LIMIT 1) AS last_message,
                       (SELECT u.name  FROM chat_messages m JOIN users u ON m.sender_id = u.id WHERE m.conversation_id = cc.id ORDER BY m.created_at DESC LIMIT 1) AS last_sender,
                       (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = cc.id AND m.created_at > COALESCE(cp.last_read_at, '2000-01-01') AND m.sender_id != ?) AS unread_count,
                       GROUP_CONCAT(DISTINCT u2.name ORDER BY u2.name SEPARATOR ', ') AS participants
                FROM chat_conversations cc
                JOIN chat_participants cp ON cc.id = cp.conversation_id AND cp.user_id = ?
                JOIN chat_participants cp2 ON cc.id = cp2.conversation_id
                JOIN users u2 ON cp2.user_id = u2.id AND cp2.user_id != ?
                WHERE cc.org_id = ?
                GROUP BY cc.id
                ORDER BY cc.updated_at DESC
                LIMIT 50
            ");
            $stmt->execute([$uid, $uid, $uid, $orgId]);
            $convos = $stmt->fetchAll();
            echo json_encode(['success' => true, 'conversations' => $convos]);
            break;

        // ── Get messages for a conversation ───────────────────────
        case 'get_messages':
            $convId = (int)($_GET['conv_id'] ?? 0);
            // Verify participant
            $chk = $pdo->prepare("SELECT id FROM chat_participants WHERE conversation_id=? AND user_id=?");
            $chk->execute([$convId, $uid]);
            if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Not a participant']); break; }

            $since = (int)($_GET['since_id'] ?? 0);
            $stmt  = $pdo->prepare("
                SELECT m.id, m.message, m.created_at, m.is_deleted,
                       u.id AS sender_id, u.name AS sender_name
                FROM chat_messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ? AND m.id > ?
                ORDER BY m.created_at ASC LIMIT 100
            ");
            $stmt->execute([$convId, $since]);
            $msgs = $stmt->fetchAll();

            // Mark read
            $pdo->prepare("UPDATE chat_participants SET last_read_at=NOW() WHERE conversation_id=? AND user_id=?")
                ->execute([$convId, $uid]);

            echo json_encode(['success' => true, 'messages' => $msgs, 'current_user_id' => $uid]);
            break;

        // ── Send a message ────────────────────────────────────────
        case 'send_message':
            $convId  = (int)($_POST['conv_id']  ?? 0);
            $message = trim($_POST['message'] ?? '');
            if (!$convId || !$message) {
                echo json_encode(['success' => false, 'message' => 'Missing fields']); break;
            }
            // Verify participant
            $chk = $pdo->prepare("SELECT id FROM chat_participants WHERE conversation_id=? AND user_id=?");
            $chk->execute([$convId, $uid]);
            if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Not a participant']); break; }

            $pdo->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message) VALUES (?,?,?)")
                ->execute([$convId, $uid, $message]);
            $msgId = (int)$pdo->lastInsertId();

            // Update conversation timestamp (scoped to org)
            $pdo->prepare("UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND org_id=?")->execute([$convId, $orgId]);

            // Mark sender as read
            $pdo->prepare("UPDATE chat_participants SET last_read_at=NOW() WHERE conversation_id=? AND user_id=?")
                ->execute([$convId, $uid]);

            echo json_encode(['success' => true, 'message_id' => $msgId]);
            break;

        // ── Start a new conversation ──────────────────────────────
        case 'new_conversation':
            $recipientId = (int)($_POST['recipient_id'] ?? 0);
            $firstMsg    = trim($_POST['message'] ?? '');

            if (!$recipientId || !$firstMsg) {
                echo json_encode(['success' => false, 'message' => 'Recipient and message required']); break;
            }

            // Verify recipient is in same org
            $rc = $pdo->prepare("SELECT id, name FROM users WHERE id=? AND org_id=?");
            $rc->execute([$recipientId, $orgId]);
            $recipient = $rc->fetch();
            if (!$recipient) { echo json_encode(['success' => false, 'message' => 'Recipient not found']); break; }

            // Check if 1-on-1 conversation already exists
            $existing = $pdo->prepare("
                SELECT cp1.conversation_id
                FROM chat_participants cp1
                JOIN chat_participants cp2 ON cp1.conversation_id = cp2.conversation_id AND cp2.user_id = ?
                JOIN chat_conversations cc ON cc.id = cp1.conversation_id AND cc.org_id = ? AND cc.title IS NULL
                WHERE cp1.user_id = ?
                LIMIT 1
            ");
            $existing->execute([$recipientId, $orgId, $uid]);
            $row = $existing->fetch();

            if ($row) {
                $convId = (int)$row['conversation_id'];
            } else {
                $pdo->prepare("INSERT INTO chat_conversations (org_id, created_by) VALUES (?,?)")->execute([$orgId, $uid]);
                $convId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO chat_participants (conversation_id, user_id) VALUES (?,?)")->execute([$convId, $uid]);
                $pdo->prepare("INSERT INTO chat_participants (conversation_id, user_id) VALUES (?,?)")->execute([$convId, $recipientId]);
            }

            // Post message
            $pdo->prepare("INSERT INTO chat_messages (conversation_id, sender_id, message) VALUES (?,?,?)")
                ->execute([$convId, $uid, $firstMsg]);
            $pdo->prepare("UPDATE chat_conversations SET updated_at=NOW() WHERE id=?")->execute([$convId]);
            $pdo->prepare("UPDATE chat_participants SET last_read_at=NOW() WHERE conversation_id=? AND user_id=?")
                ->execute([$convId, $uid]);

            echo json_encode(['success' => true, 'conversation_id' => $convId]);
            break;

        // ── List org users (for new conversation picker) ──────────
        case 'list_users':
            $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE org_id=? AND id != ? AND status='active' ORDER BY name LIMIT 100");
            $stmt->execute([$orgId, $uid]);
            echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    error_log('[api/chat.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
