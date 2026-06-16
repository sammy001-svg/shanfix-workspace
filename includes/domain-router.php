<?php
/**
 * OrbitDesk — Custom Domain / Subdomain Router
 *
 * Maps a request HOST to an organization by:
 *  1. Exact match on organizations.custom_domain
 *  2. Subdomain prefix match on *.orbitdesk.co → slug lookup
 *  3. Health portal custom domain (health_settings key=custom_domain)
 *
 * Include ONCE near the top of config/database.php (after $pdo is set).
 * Sets globals: $detectedOrgId (int), $detectedOrgSlug (string)
 *              $detectedHealthPortalOrgId (int), $detectedHealthPortalOrgSlug (string)
 *
 * Usage in pages: if (!empty($detectedOrgId)) { ... }
 */

/**
 * Detect the organization from the current HTTP_HOST.
 * Returns the org row array, or null if not found.
 * Results are cached in a static variable for the request lifetime.
 */
function detectOrgFromDomain(PDO $pdo): ?array {
    static $cache = false;
    if ($cache !== false) return $cache ?: null;

    $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
    // Strip port
    if (strpos($host, ':') !== false) {
        $host = explode(':', $host)[0];
    }
    if (!$host || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        $cache = null;
        return null;
    }

    try {
        // 1. Exact custom domain match
        $stmt = $pdo->prepare("SELECT * FROM organizations WHERE custom_domain=? AND status='active' LIMIT 1");
        $stmt->execute([$host]);
        $org = $stmt->fetch();
        if ($org) {
            _applyDetectedOrg($org);
            $cache = $org;
            return $org;
        }

        // 2. Subdomain match — e.g. "acme" from "acme.orbitdesk.co"
        $baseDomain = defined('APP_BASE_DOMAIN') ? APP_BASE_DOMAIN : 'orbitdesk.co';
        if (str_ends_with($host, '.' . $baseDomain)) {
            $sub  = substr($host, 0, strlen($host) - strlen('.' . $baseDomain));
            // slug starts with sub (e.g. "acme-abc123" matches sub="acme")
            $stmt = $pdo->prepare("SELECT * FROM organizations WHERE slug LIKE ? AND status='active' ORDER BY id LIMIT 1");
            $stmt->execute([$sub . '%']);
            $org  = $stmt->fetch();
            if ($org) {
                _applyDetectedOrg($org);
                $cache = $org;
                return $org;
            }
        }

        // 3. Health portal custom domain (stored in health_settings, not organizations table)
        $stmt = $pdo->prepare("
            SELECT hs.org_id, o.slug
            FROM health_settings hs
            JOIN organizations o ON o.id = hs.org_id AND o.status = 'active'
            WHERE hs.setting_key = 'custom_domain' AND LOWER(hs.setting_value) = ?
            LIMIT 1
        ");
        $stmt->execute([$host]);
        $hRow = $stmt->fetch();
        if ($hRow) {
            $GLOBALS['detectedHealthPortalOrgId']   = (int)$hRow['org_id'];
            $GLOBALS['detectedHealthPortalOrgSlug'] = $hRow['slug'] ?? '';
        }
    } catch (Exception $e) {
        error_log('[domain-router] ' . $e->getMessage());
    }

    $cache = null;
    return null;
}

function _applyDetectedOrg(array $org): void {
    $_SERVER['ORG_SLUG'] = $org['slug'] ?? '';
    $_SERVER['ORG_ID']   = (string)($org['id'] ?? 0);
    $GLOBALS['detectedOrgId']   = (int)$org['id'];
    $GLOBALS['detectedOrgSlug'] = $org['slug'] ?? '';
}

/**
 * If the current request is on a custom domain, redirect unauthenticated users
 * to the org's branded login page (org-login.php?org=SLUG) instead of the default login.
 * Call from requireLogin() or at the top of public pages.
 */
function redirectToOrgLoginIfCustomDomain(PDO $pdo): void {
    if (!empty($GLOBALS['detectedOrgSlug']) && !isLoggedIn()) {
        $slug = $GLOBALS['detectedOrgSlug'];
        $url  = APP_URL . '/auth/org-login.php?org=' . rawurlencode($slug);
        if (strpos($_SERVER['REQUEST_URI'] ?? '', 'org-login.php') === false) {
            header('Location: ' . $url);
            exit;
        }
    }
}

// ── Auto-detect on include ────────────────────────────────────────
if (isset($pdo) && $pdo instanceof PDO) {
    // Ensure session is available for portal mode checks before any page logic runs
    if (session_status() === PHP_SESSION_NONE) session_start();

    detectOrgFromDomain($pdo);

    // Health portal redirect: when on a health portal custom domain, enforce portal login
    if (!empty($GLOBALS['detectedHealthPortalOrgId'])) {
        $reqPath = strtolower(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

        // Pages that must always be reachable (the auth flow itself)
        $isPortalAuth = in_array($reqPath, [
            '/modules/health/portal-login.php',
            '/modules/health/portal-logout.php',
        ]);

        // Allow any /modules/health/* path only when already logged into THIS portal org
        $isHealthModule     = str_starts_with($reqPath, '/modules/health/');
        $isLoggedInToPortal = !empty($_SESSION['health_portal_mode'])
                              && (int)($_SESSION['health_portal_org_id'] ?? 0) === (int)$GLOBALS['detectedHealthPortalOrgId'];

        if (!$isPortalAuth && !($isHealthModule && $isLoggedInToPortal)) {
            $slug  = $GLOBALS['detectedHealthPortalOrgSlug'] ?? '';
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $url   = $proto . '://' . $_SERVER['HTTP_HOST'] . '/modules/health/portal-login.php'
                   . ($slug ? '?org=' . rawurlencode($slug) : '');
            header('Location: ' . $url, true, 302);
            exit;
        }
    }
}
