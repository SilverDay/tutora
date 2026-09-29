-- Email verification before account creation (owner decision 4b). A signup only becomes a
-- tenant when its link is used together with the password chosen at signup, which prevents
-- account pre-hijacking. Only the SHA-256 of the link token is stored.
CREATE TABLE pending_signups (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(254)    NOT NULL,
    display_name  VARCHAR(100)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    token_hash    BINARY(32)      NOT NULL,
    expires_at    DATETIME(3)     NOT NULL,
    created_at    DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pending_signups_token (token_hash),
    KEY ix_pending_signups_email (email),
    KEY ix_pending_signups_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
