<?php
/**
 * OrbitDesk Workspace — Notification Helpers
 * Provides in-app notification creation, retrieval, broadcast, and email triggers.
 */

// ── Core: create a single notification ───────────────────────────
function createNotification(int $orgId, ?int $userId, string $title, string $message, string $type = 'info', string $link = ''): int {
    global $pdo;
    try {
        // Check in-app preference (if table exists)
        if ($userId && !_notifPrefAllowed($userId, $type, 'inapp')) return 0;

        $stmt = $pdo->prepare(
            "INSERT INTO notifications (org_id, user_id, title, message, type, link) VALUES (?,?,?,?,?,?)"
        );
        $stmt->execute([$orgId, $userId ?: null, $title, $message, $type, $link]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('[Notification] ' . $e->getMessage());
        return 0;
    }
}

// ── Notify all users in an organisation ─────────────────────────
function notifyOrg(int $orgId, string $title, string $message, string $type = 'info', string $link = '', bool $sendEmail = false): int {
    global $pdo;
    $count = 0;
    try {
        $users = $pdo->prepare("SELECT id, name, email FROM users WHERE org_id=? AND role!='super_admin'");
        $users->execute([$orgId]);
        foreach ($users->fetchAll() as $u) {
            if (createNotification($orgId, (int)$u['id'], $title, $message, $type, $link)) {
                $count++;
            }
            if ($sendEmail && _notifPrefAllowed((int)$u['id'], $type, 'email')) {
                _sendNotifEmail($u['email'], $u['name'], $title, $message, $type, $link);
            }
        }
    } catch (Exception $e) {
        error_log('[notifyOrg] ' . $e->getMessage());
    }
    return $count;
}

// ── Broadcast to all active organisations ────────────────────────
function notifyAllOrgs(string $title, string $message, string $type = 'info', string $link = '', bool $sendEmail = false): int {
    global $pdo;
    $count = 0;
    try {
        $orgs = $pdo->query("SELECT id FROM organizations WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($orgs as $orgId) {
            $count += notifyOrg((int)$orgId, $title, $message, $type, $link, $sendEmail);
        }
    } catch (Exception $e) {
        error_log('[notifyAllOrgs] ' . $e->getMessage());
    }
    return $count;
}

// ── Create a system-wide notification (no user scope) ────────────
function createSystemNotification(int $orgId, string $title, string $message, string $type = 'info', string $link = ''): int {
    return createNotification($orgId, null, $title, $message, $type, $link);
}

// ── Fetch unread notifications for a user ────────────────────────
function getUnreadNotifications(int $userId, int $limit = 10): array {
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM notifications
             WHERE (user_id=? OR user_id IS NULL) AND is_read=0
             ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ── Count unread notifications ────────────────────────────────────
function getUnreadCount(int $userId): int {
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE (user_id=? OR user_id IS NULL) AND is_read=0"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ── Mark all notifications as read for a user ────────────────────
function markNotificationsRead(int $userId): void {
    global $pdo;
    try {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? OR user_id IS NULL")
            ->execute([$userId]);
    } catch (Exception $e) { /* fail silently */ }
}

// ── Mark a single notification as read ───────────────────────────
function markOneRead(int $notifId, int $userId): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND (user_id=? OR user_id IS NULL)");
        $stmt->execute([$notifId, $userId]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ── Delete a single notification ─────────────────────────────────
function deleteNotification(int $notifId, int $userId): bool {
    global $pdo;
    try {
        // Users may only delete their own or org-wide (user_id IS NULL)
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id=? AND (user_id=? OR user_id IS NULL)");
        $stmt->execute([$notifId, $userId]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ── Delete old notifications (cleanup cron) ───────────────────────
function cleanOldNotifications(int $daysOld = 90): int {
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$daysOld]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

// ── Get/save notification preferences ────────────────────────────
function getNotifPreferences(int $userId): array {
    global $pdo;
    $defaults = [
        'email_info' => 1, 'email_success' => 1, 'email_warning' => 1, 'email_danger' => 1,
        'inapp_info' => 1, 'inapp_success' => 1, 'inapp_warning' => 1, 'inapp_danger' => 1,
    ];
    try {
        $stmt = $pdo->prepare("SELECT * FROM notification_preferences WHERE user_id=?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? array_merge($defaults, $row) : $defaults;
    } catch (Exception $e) {
        return $defaults;
    }
}

function saveNotifPreferences(int $userId, int $orgId, array $prefs): void {
    global $pdo;
    $allowed = ['email_info','email_success','email_warning','email_danger',
                'inapp_info','inapp_success','inapp_warning','inapp_danger'];
    $cols = implode(',', $allowed);
    $vals = implode(',', array_fill(0, count($allowed), '?'));
    $upd  = implode(',', array_map(fn($c) => "$c=VALUES($c)", $allowed));
    $data = [$userId, $orgId];
    foreach ($allowed as $k) {
        $data[] = isset($prefs[$k]) ? 1 : 0;
    }
    try {
        $pdo->prepare(
            "INSERT INTO notification_preferences (user_id, org_id, $cols) VALUES (?,?,$vals)
             ON DUPLICATE KEY UPDATE $upd"
        )->execute($data);
    } catch (Exception $e) {
        error_log('[saveNotifPreferences] ' . $e->getMessage());
    }
}

// ── Internal helpers ─────────────────────────────────────────────

function _notifPrefAllowed(int $userId, string $type, string $channel): bool {
    global $pdo;
    $type = in_array($type, ['info','success','warning','danger']) ? $type : 'info';
    try {
        $stmt = $pdo->prepare(
            "SELECT {$channel}_{$type} FROM notification_preferences WHERE user_id=?"
        );
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        return $val === false || (int)$val === 1; // default allow if no preference row
    } catch (Exception $e) {
        return true; // table may not exist yet
    }
}

function _sendNotifEmail(string $toEmail, string $toName, string $title, string $message, string $type, string $link): void {
    try {
        require_once __DIR__ . '/mailer.php';
        $typeColors = [
            'info'    => '#3b82f6',
            'success' => '#1A8A4E',
            'warning' => '#f59e0b',
            'danger'  => '#ef4444',
        ];
        $color = $typeColors[$type] ?? '#0B2D4E';
        $linkBtn = $link
            ? "<p style='text-align:center;margin-top:20px'><a href='$link' style='background:$color;color:white;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600'>View Details</a></p>"
            : '';
        $html = "
        <div style='font-family:system-ui,sans-serif;max-width:600px;margin:0 auto'>
          <div style='background:{$color};padding:24px;border-radius:8px 8px 0 0'>
            <h2 style='color:white;margin:0;font-size:1.2rem'>" . htmlspecialchars($title) . "</h2>
          </div>
          <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px'>
            <p>Hi {$toName},</p>
            <p>" . nl2br(htmlspecialchars($message)) . "</p>
            {$linkBtn}
            <hr style='border:none;border-top:1px solid #e2e8f0;margin:20px 0'>
            <p style='font-size:.8rem;color:#94a3b8'>This is an automated notification from " . APP_NAME . ". You can manage your notification preferences from your account settings.</p>
          </div>
        </div>";
        mailer()->send($toEmail, $title . ' — ' . APP_NAME, $html);
    } catch (Exception $e) {
        error_log('[_sendNotifEmail] ' . $e->getMessage());
    }
}
