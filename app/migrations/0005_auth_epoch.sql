-- Per-account counter of credential changes. Every tutor session stores the value it was
-- established with; a password change, admin MFA reset or recovery code regeneration
-- increments it, which ends all other sessions of that account on their next request.
ALTER TABLE tenants ADD COLUMN auth_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_last_step;
