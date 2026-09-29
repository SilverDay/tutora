-- MFA recovery codes (owner decision 3a): one-time codes with 80 bits of entropy, stored
-- only as SHA-256.
CREATE TABLE tutor_recovery_codes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    code_hash  BINARY(32)      NOT NULL,
    used_at    DATETIME(3)     NULL,
    created_at DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recovery_codes (tenant_id, code_hash),
    CONSTRAINT fk_recovery_codes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
