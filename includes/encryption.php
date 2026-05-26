<?php
/**
 * includes/encryption.php
 * AES-256-CBC field-level encryption for PII at rest.
 *
 * Usage:
 *   $stored  = encrypt($plaintext);   // store this in DB
 *   $plain   = decrypt($stored);      // read back
 *   $val     = decryptField($row['phone']); // null-safe wrapper
 *
 * IMPORTANT: Back up ENCRYPTION_KEY securely. Losing it = losing all encrypted data.
 */

// ── Prefix marker so we can detect encrypted vs plaintext values ──
define('ENC_PREFIX', 'ENC::');

/**
 * Encrypt a plaintext string using AES-256-CBC.
 * Returns a base64-encoded string prefixed with ENC:: marker.
 */
function encrypt(?string $value): ?string {
    if ($value === null || $value === '') return $value;
    if (isEncrypted($value)) return $value; // already encrypted

    $key    = substr(hash('sha256', ENCRYPTION_KEY, true), 0, 32);
    $iv     = random_bytes(16);
    $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($cipher === false) {
        error_log('[Encryption] openssl_encrypt failed');
        return $value; // fail open — don't lose data
    }

    return ENC_PREFIX . base64_encode($iv . $cipher);
}

/**
 * Decrypt an AES-256-CBC encrypted value.
 * Returns the original plaintext, or the value as-is if not encrypted.
 */
function decrypt(?string $value): ?string {
    if ($value === null || $value === '') return $value;
    if (!isEncrypted($value)) return $value; // plaintext passthrough

    $key  = substr(hash('sha256', ENCRYPTION_KEY, true), 0, 32);
    $data = base64_decode(substr($value, strlen(ENC_PREFIX)));

    if ($data === false || strlen($data) < 17) {
        error_log('[Encryption] Invalid ciphertext');
        return '';
    }

    $iv     = substr($data, 0, 16);
    $cipher = substr($data, 16);
    $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return $plain === false ? '' : $plain;
}

/**
 * Null-safe decrypt — for use directly on DB row values.
 */
function decryptField(?string $value): ?string {
    if ($value === null) return null;
    return decrypt($value);
}

/**
 * Detect whether a string has already been encrypted by us.
 */
function isEncrypted(?string $value): bool {
    return $value !== null && str_starts_with($value, ENC_PREFIX);
}

/**
 * Decrypt all specified fields in a fetched DB row array.
 * Example: $row = decryptRow($row, ['phone', 'email', 'id_number']);
 */
function decryptRow(array $row, array $fields): array {
    foreach ($fields as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = decryptField($row[$field]);
        }
    }
    return $row;
}

/**
 * Decrypt all specified fields in an array of rows.
 * Example: $rows = decryptRows($rows, ['phone', 'email']);
 */
function decryptRows(array $rows, array $fields): array {
    return array_map(fn($row) => decryptRow($row, $fields), $rows);
}
