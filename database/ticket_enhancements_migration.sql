-- ── Support Ticket Enhancements Migration ────────────────────────
-- Run once. Adds file attachments and internal notes to support tickets.

-- Internal-note flag on replies (0 = visible to client, 1 = admin-only)
ALTER TABLE ticket_replies
    ADD COLUMN IF NOT EXISTS is_internal TINYINT(1) NOT NULL DEFAULT 0;

-- File attachments table
CREATE TABLE IF NOT EXISTS ticket_attachments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id     INT          NOT NULL,
    reply_id      INT          NULL,          -- NULL = attached to original ticket message
    org_id        INT          NOT NULL,
    uploaded_by   INT          NOT NULL,
    filename      VARCHAR(255) NOT NULL,       -- stored filename on disk
    original_name VARCHAR(255) NOT NULL,       -- original filename from user
    file_size     INT          NOT NULL DEFAULT 0,
    mime_type     VARCHAR(100) NOT NULL DEFAULT '',
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
