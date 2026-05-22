<?php
/**
 * OrbitDesk Workspace — TOTP (Time-Based One-Time Password) — RFC 6238
 * No Composer required. Uses PHP's native hash_hmac() + base_convert().
 */

// ── Generate a random base32 secret (160-bit = 32 chars) ─────────
function totpGenerateSecret(): string
{
    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $bytes  = random_bytes(20); // 160 bits
    for ($i = 0; $i < 20; $i++) {
        $secret .= $chars[ord($bytes[$i]) & 31];
    }
    return $secret;
}

// ── Compute the current TOTP code ─────────────────────────────────
function totpCode(string $secret, int $timeStep = null): string
{
    $timeStep = $timeStep ?? (int)floor(time() / 30);
    $key      = _totpBase32Decode($secret);
    $msg      = pack('N*', 0) . pack('N*', $timeStep); // 8-byte big-endian
    $hash     = hash_hmac('sha1', $msg, $key, true);
    $offset   = ord($hash[19]) & 0x0F;
    $code     = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
         (ord($hash[$offset + 3]) & 0xFF)
    ) % 1_000_000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

// ── Verify a user-supplied code (±1 window = ±30 seconds) ────────
function totpVerify(string $secret, string $inputCode, int $window = 1): bool
{
    $inputCode = preg_replace('/\D/', '', $inputCode);
    if (strlen($inputCode) !== 6) return false;
    $step = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCode($secret, $step + $i), $inputCode)) return true;
    }
    return false;
}

// ── Build an otpauth:// URI for QR code scanners ─────────────────
function totpUri(string $secret, string $email, string $issuer = ''): string
{
    $issuer = $issuer ?: (defined('APP_NAME') ? APP_NAME : 'OrbitDesk');
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=6&period=30';
}

// ── Return a Google Charts QR image URL ──────────────────────────
function totpQrUrl(string $secret, string $email, string $issuer = '', int $size = 220): string
{
    $uri = totpUri($secret, $email, $issuer);
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . rawurlencode($uri);
}

// ── Internal: base32 decode ───────────────────────────────────────
function _totpBase32Decode(string $input): string
{
    $map    = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
    $input  = strtoupper(rtrim($input, '='));
    $bits   = '';
    foreach (str_split($input) as $char) {
        if (!isset($map[$char])) continue;
        $bits .= str_pad(decbin($map[$char]), 5, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $output .= chr(bindec($byte));
    }
    return $output;
}
