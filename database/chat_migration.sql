-- ── Chat System Migration ─────────────────────────────────────────
-- Run once. Creates internal messaging tables for org users.

CREATE TABLE IF NOT EXISTS chat_conversations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    title       VARCHAR(255) DEFAULT NULL    COMMENT 'Optional group title; NULL for 1-on-1',
    created_by  INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_org (org_id),
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_participants (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id         INT NOT NULL,
    joined_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_read_at    TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_conv_user (conversation_id, user_id),
    INDEX idx_user (user_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id       INT NOT NULL,
    message         TEXT NOT NULL,
    is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conv (conversation_id),
    INDEX idx_sender (sender_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
