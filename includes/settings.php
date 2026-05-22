<?php
// ── System Settings Helpers ────────────────────────────────────
// Loaded automatically from config/database.php after the PDO connection.

function getSetting(string $key, string $default = ''): string
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM system_settings WHERE `key`=?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? (string)$val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function getSettings(array $keys): array
{
    global $pdo;
    if (empty($keys)) return [];
    try {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT `key`, `value` FROM system_settings WHERE `key` IN ($placeholders)");
        $stmt->execute($keys);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        return [];
    }
}

function saveSetting(string $key, string $value): void
{
    global $pdo;
    $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
        ->execute([$key, $value, $value]);
}

// ── Define SMTP constants from DB ──────────────────────────────
// Wrapped in try/catch so pages load even before the migration runs.
try {
    $__smtp = $pdo->query(
        "SELECT `key`, `value` FROM system_settings
         WHERE `key` IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_enc','mail_from','mail_from_name')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $__smtp = [];
}

if (!defined('SMTP_HOST'))      define('SMTP_HOST',      $__smtp['smtp_host']      ?? '');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      (int)($__smtp['smtp_port'] ?? 587));
if (!defined('SMTP_USER'))      define('SMTP_USER',      $__smtp['smtp_user']      ?? '');
if (!defined('SMTP_PASS'))      define('SMTP_PASS',      $__smtp['smtp_pass']      ?? '');
if (!defined('SMTP_ENC'))       define('SMTP_ENC',       $__smtp['smtp_enc']       ?? 'tls');
if (!defined('MAIL_FROM'))      define('MAIL_FROM',      $__smtp['mail_from']      ?? 'noreply@orbitdesk.co.ke');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', $__smtp['mail_from_name'] ?? APP_NAME);

unset($__smtp);
