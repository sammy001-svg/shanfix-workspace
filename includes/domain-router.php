<?php
/**
 * OrbitDesk — Custom Domain / Subdomain Router
 *
 * Maps a request HOST to an organization by:
 *  1. Exact match on organizations.custom_domain
 *  2. Subdomain prefix match on *.orbitdesk.co → slug lookup
 *
 * Include ONCE near the top of config/database.php (after $pdo is set).
 * Sets globals: $detectedOrgId (int), $detectedOrgSlug (string)
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
    detectOrgFromDomain($pdo);
}
