<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');
requireLogin();
$user = currentUser();
$uid  = (int)$user['id'];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'count') {
    echo json_encode(['count' => getUnreadCount($uid)]);
} elseif ($action === 'list') {
    $notes = getUnreadNotifications($uid, 10);
    echo json_encode(['notifications' => $notes]);
} elseif ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    markNotificationsRead($uid);
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['error' => 'Invalid action']);
}
