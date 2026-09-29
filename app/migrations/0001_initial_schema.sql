-- Tutora initial schema (MariaDB 10.11+).
-- FK ON DELETE behaviour follows docs/IMPLEMENTATION_PLAN.md §5.
-- Composite FKs (child.session_id + child.<x>_id -> parent(id, session_id)) make it
-- structurally impossible for a row to reference a block/participant of another session.

-- ---------------------------------------------------------------------------
-- Tenants (v1: tenant = one individual tutor; tenants and tutors collapsed)
-- ---------------------------------------------------------------------------
CREATE TABLE tenants (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email               VARCHAR(254)    NOT NULL,
    display_name        VARCHAR(100)    NOT NULL,
    password_hash       VARCHAR(255)    NOT NULL,
    password_changed_at DATETIME(3)     NOT NULL,
    -- TOTP secret, encrypted at rest (libsodium secretbox, key from env)
    totp_secret_enc     VARBINARY(255)  NULL,
    totp_enabled_at     DATETIME(3)     NULL,
    -- last accepted TOTP time step, prevents code replay within its window
    totp_last_step      BIGINT UNSIGNED NULL,
    -- per-tenant override of RETENTION_DAYS_DEFAULT (NULL = use default)
    retention_days      SMALLINT UNSIGNED NULL,
    branding            JSON            NULL CHECK (branding IS NULL OR JSON_VALID(branding)),
    created_at          DATETIME(3)     NOT NULL,
    updated_at          DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Template side (persistent, editable)
-- ---------------------------------------------------------------------------
CREATE TABLE workshops (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(200)    NOT NULL,
    description TEXT            NULL,
    created_at  DATETIME(3)     NOT NULL,
    updated_at  DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_workshops_tenant (tenant_id),
    CONSTRAINT fk_workshops_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slide_imports (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    workshop_id       BIGINT UNSIGNED NOT NULL,
    original_filename VARCHAR(255)    NOT NULL,
    page_count        INT UNSIGNED    NULL,
    created_at        DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_slide_imports_tenant (tenant_id),
    KEY ix_slide_imports_workshop (workshop_id),
    CONSTRAINT fk_slide_imports_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id)   ON DELETE RESTRICT,
    CONSTRAINT fk_slide_imports_workshop FOREIGN KEY (workshop_id) REFERENCES workshops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slide_assets (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slide_import_id BIGINT UNSIGNED NOT NULL,
    page_number     INT UNSIGNED    NOT NULL,
    image_path      VARCHAR(512)    NOT NULL,
    width           INT UNSIGNED    NOT NULL,
    height          INT UNSIGNED    NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slide_assets_page (slide_import_id, page_number),
    CONSTRAINT fk_slide_assets_import FOREIGN KEY (slide_import_id) REFERENCES slide_imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workshop_blocks (
    id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    workshop_id    BIGINT UNSIGNED  NOT NULL,
    position       INT UNSIGNED     NOT NULL,
    block_type     ENUM('slide','whiteboard','annotate','quiz','poll','meter','rate','rank',
                        'word','plot','word_cloud','write','wall') NOT NULL,
    config         JSON             NOT NULL CHECK (JSON_VALID(config)),
    config_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- slide blocks reference a specific asset of a specific import
    slide_asset_id BIGINT UNSIGNED  NULL,
    created_at     DATETIME(3)      NOT NULL,
    updated_at     DATETIME(3)      NOT NULL,
    PRIMARY KEY (id),
    KEY ix_workshop_blocks_order (workshop_id, position),
    CONSTRAINT fk_workshop_blocks_workshop FOREIGN KEY (workshop_id)    REFERENCES workshops (id)    ON DELETE CASCADE,
    CONSTRAINT fk_workshop_blocks_asset    FOREIGN KEY (slide_asset_id) REFERENCES slide_assets (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversion_jobs (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    workshop_id        BIGINT UNSIGNED NOT NULL,
    slide_import_id    BIGINT UNSIGNED NOT NULL,
    source_path        VARCHAR(512)    NOT NULL,
    status             ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
    attempts           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error_message      VARCHAR(1000)   NULL,
    locked_at          DATETIME(3)     NULL,
    -- failed sources are kept at most 24h (spec: Slide Conversion Pipeline / Retention)
    source_purge_after DATETIME(3)     NULL,
    created_at         DATETIME(3)     NOT NULL,
    updated_at         DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_conversion_jobs_status (status, created_at),
    CONSTRAINT fk_conversion_jobs_tenant   FOREIGN KEY (tenant_id)       REFERENCES tenants (id)       ON DELETE RESTRICT,
    CONSTRAINT fk_conversion_jobs_workshop FOREIGN KEY (workshop_id)     REFERENCES workshops (id)     ON DELETE CASCADE,
    CONSTRAINT fk_conversion_jobs_import   FOREIGN KEY (slide_import_id) REFERENCES slide_imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Live session side (immutable execution snapshot)
-- ---------------------------------------------------------------------------
CREATE TABLE sessions (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- deviation: denormalised tenant_id so history survives workshop deletion (plan §2)
    tenant_id                BIGINT UNSIGNED NOT NULL,
    workshop_id              BIGINT UNSIGNED NULL,
    workshop_title_snapshot  VARCHAR(200)    NOT NULL,
    join_code                CHAR(6)         NOT NULL,
    -- equals join_code while the session is not ended, NULL afterwards:
    -- enforces "unique only among live sessions" at the database level
    active_join_code         CHAR(6)         NULL,
    status                   ENUM('lobby','live','ended') NOT NULL DEFAULT 'lobby',
    current_session_block_id BIGINT UNSIGNED NULL,
    session_revision         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    started_at               DATETIME(3)     NULL,
    ended_at                 DATETIME(3)     NULL,
    -- time after which the session's data is purged (ended_at + retention)
    expires_at               DATETIME(3)     NULL,
    created_at               DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_active_join_code (active_join_code),
    KEY ix_sessions_tenant (tenant_id),
    KEY ix_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id)   ON DELETE RESTRICT,
    CONSTRAINT fk_sessions_workshop FOREIGN KEY (workshop_id) REFERENCES workshops (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_blocks (
    id                       BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    session_id               BIGINT UNSIGNED  NOT NULL,
    source_workshop_block_id BIGINT UNSIGNED  NULL,
    position                 INT UNSIGNED     NOT NULL,
    block_type               ENUM('slide','whiteboard','annotate','quiz','poll','meter','rate','rank',
                                  'word','plot','word_cloud','write','wall') NOT NULL,
    config_snapshot          JSON             NOT NULL CHECK (JSON_VALID(config_snapshot)),
    config_version           SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_session_blocks_id_session (id, session_id),
    UNIQUE KEY uq_session_blocks_position (session_id, position),
    CONSTRAINT fk_session_blocks_session FOREIGN KEY (session_id)               REFERENCES sessions (id)        ON DELETE CASCADE,
    CONSTRAINT fk_session_blocks_source  FOREIGN KEY (source_workshop_block_id) REFERENCES workshop_blocks (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-column FK with SET NULL: a composite (block, session) FK here would form a
-- delete cycle with session_blocks' CASCADE. That the current block belongs to the same
-- session is enforced by SessionRepository::setCurrentBlock (WHERE session_id = ?).
ALTER TABLE sessions
    ADD CONSTRAINT fk_sessions_current_block
        FOREIGN KEY (current_session_block_id) REFERENCES session_blocks (id) ON DELETE SET NULL;

CREATE TABLE session_participants (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id              BIGINT UNSIGNED NOT NULL,
    -- opaque per-session identifier used for moderation (never a cross-session identifier)
    moderation_actor_id     BINARY(16)      NOT NULL,
    -- null by default; never required
    display_name            VARCHAR(40)     NULL,
    joined_at               DATETIME(3)     NOT NULL,
    last_activity_at        DATETIME(3)     NOT NULL,
    presence_renewed_at     DATETIME(3)     NOT NULL,
    -- SHA-256 of the >=128-bit resume token; the token itself is never stored
    resume_token_hash       BINARY(32)      NOT NULL,
    resume_token_expires_at DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_session_participants_id_session (id, session_id),
    UNIQUE KEY uq_session_participants_resume (resume_token_hash),
    UNIQUE KEY uq_session_participants_actor (session_id, moderation_actor_id),
    CONSTRAINT fk_session_participants_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE block_submissions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id          BIGINT UNSIGNED NOT NULL,
    session_block_id    BIGINT UNSIGNED NOT NULL,
    participant_id      BIGINT UNSIGNED NOT NULL,
    moderation_actor_id BINARY(16)      NOT NULL,
    payload             JSON            NOT NULL CHECK (JSON_VALID(payload)),
    submitted_at        DATETIME(3)     NOT NULL,
    updated_at          DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_block_submissions_one (session_id, session_block_id, participant_id),
    KEY ix_block_submissions_block (session_block_id),
    CONSTRAINT fk_block_submissions_session     FOREIGN KEY (session_id)                     REFERENCES sessions (id)                         ON DELETE CASCADE,
    CONSTRAINT fk_block_submissions_block       FOREIGN KEY (session_block_id, session_id)   REFERENCES session_blocks (id, session_id)       ON DELETE CASCADE,
    CONSTRAINT fk_block_submissions_participant FOREIGN KEY (participant_id, session_id)     REFERENCES session_participants (id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Quiz
-- ---------------------------------------------------------------------------
CREATE TABLE quiz_question_runs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id       BIGINT UNSIGNED NOT NULL,
    session_block_id BIGINT UNSIGNED NOT NULL,
    question_id      VARCHAR(64)     NOT NULL,
    status           ENUM('PENDING','OPEN','REVEALED') NOT NULL DEFAULT 'PENDING',
    started_at       DATETIME(3)     NULL,
    -- server-side deadline (started_at + time_limit_seconds); NULL = no time limit
    closes_at        DATETIME(3)     NULL,
    revealed_at      DATETIME(3)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quiz_question_runs (session_id, session_block_id, question_id),
    CONSTRAINT fk_quiz_runs_session FOREIGN KEY (session_id)                   REFERENCES sessions (id)                   ON DELETE CASCADE,
    CONSTRAINT fk_quiz_runs_block   FOREIGN KEY (session_block_id, session_id) REFERENCES session_blocks (id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_participant_question_starts (
    session_id       BIGINT UNSIGNED NOT NULL,
    session_block_id BIGINT UNSIGNED NOT NULL,
    participant_id   BIGINT UNSIGNED NOT NULL,
    question_id      VARCHAR(64)     NOT NULL,
    started_at       DATETIME(3)     NOT NULL,
    PRIMARY KEY (session_id, session_block_id, participant_id, question_id),
    CONSTRAINT fk_quiz_starts_session     FOREIGN KEY (session_id)                   REFERENCES sessions (id)                         ON DELETE CASCADE,
    CONSTRAINT fk_quiz_starts_block       FOREIGN KEY (session_block_id, session_id) REFERENCES session_blocks (id, session_id)       ON DELETE CASCADE,
    CONSTRAINT fk_quiz_starts_participant FOREIGN KEY (participant_id, session_id)   REFERENCES session_participants (id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_answers (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    session_id          BIGINT UNSIGNED  NOT NULL,
    session_block_id    BIGINT UNSIGNED  NOT NULL,
    participant_id      BIGINT UNSIGNED  NOT NULL,
    moderation_actor_id BINARY(16)       NOT NULL,
    question_id         VARCHAR(64)      NOT NULL,
    answer_payload      JSON             NOT NULL CHECK (JSON_VALID(answer_payload)),
    is_correct          TINYINT(1)       NOT NULL,
    points_awarded      INT              NOT NULL DEFAULT 0,
    submitted_at        DATETIME(3)      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_quiz_answers_one (session_id, session_block_id, participant_id, question_id),
    CONSTRAINT fk_quiz_answers_session     FOREIGN KEY (session_id)                   REFERENCES sessions (id)                         ON DELETE CASCADE,
    CONSTRAINT fk_quiz_answers_block       FOREIGN KEY (session_block_id, session_id) REFERENCES session_blocks (id, session_id)       ON DELETE CASCADE,
    CONSTRAINT fk_quiz_answers_participant FOREIGN KEY (participant_id, session_id)   REFERENCES session_participants (id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Wall
-- ---------------------------------------------------------------------------
CREATE TABLE wall_cards (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id          BIGINT UNSIGNED NOT NULL,
    session_block_id    BIGINT UNSIGNED NOT NULL,
    -- participant who created the card; NULL for tutor-created cards
    created_by          BIGINT UNSIGNED NULL,
    moderation_actor_id BINARY(16)      NOT NULL,
    text                VARCHAR(2000)   NOT NULL,
    column_id           VARCHAR(64)     NOT NULL,
    position            INT             NOT NULL,
    created_at          DATETIME(3)     NOT NULL,
    updated_at          DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_wall_cards_block (session_block_id, column_id, position),
    KEY ix_wall_cards_actor (session_id, moderation_actor_id),
    CONSTRAINT fk_wall_cards_session     FOREIGN KEY (session_id)                   REFERENCES sessions (id)                         ON DELETE CASCADE,
    CONSTRAINT fk_wall_cards_block       FOREIGN KEY (session_block_id, session_id) REFERENCES session_blocks (id, session_id)       ON DELETE CASCADE,
    CONSTRAINT fk_wall_cards_participant FOREIGN KEY (created_by, session_id)       REFERENCES session_participants (id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Whiteboard (presenter-mode entities + client-captured export snapshots).
-- Yjs operational persistence for the collaborative sidecar is added in Phase 7.
-- ---------------------------------------------------------------------------
CREATE TABLE whiteboard_entities (
    entity_uuid         BINARY(16)      NOT NULL,
    session_id          BIGINT UNSIGNED NOT NULL,
    session_block_id    BIGINT UNSIGNED NOT NULL,
    moderation_actor_id BINARY(16)      NOT NULL,
    entity_type         VARCHAR(16)     NOT NULL,
    data                JSON            NOT NULL CHECK (JSON_VALID(data)),
    created_at          DATETIME(3)     NOT NULL,
    PRIMARY KEY (entity_uuid),
    KEY ix_whiteboard_entities_block (session_block_id),
    KEY ix_whiteboard_entities_actor (session_id, moderation_actor_id),
    CONSTRAINT fk_wb_entities_session FOREIGN KEY (session_id)                   REFERENCES sessions (id)                   ON DELETE CASCADE,
    CONSTRAINT fk_wb_entities_block   FOREIGN KEY (session_block_id, session_id) REFERENCES session_blocks (id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whiteboard_snapshots (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id       BIGINT UNSIGNED NOT NULL,
    session_block_id BIGINT UNSIGNED NOT NULL,
    image_path       VARCHAR(512)    NOT NULL,
    captured_at      DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_whiteboard_snapshots_block (session_block_id, captured_at),
    CONSTRAINT fk_wb_snapshots_session FOREIGN KEY (session_id)                   REFERENCES sessions (id)                   ON DELETE CASCADE,
    CONSTRAINT fk_wb_snapshots_block   FOREIGN KEY (session_block_id, session_id) REFERENCES session_blocks (id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- AI (Write) usage and quota
-- ---------------------------------------------------------------------------
CREATE TABLE ai_usage (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id  BIGINT UNSIGNED NOT NULL,
    -- SET NULL: usage records must survive session purge (billing/cost fairness)
    session_id BIGINT UNSIGNED NULL,
    block_id   BIGINT UNSIGNED NULL,
    tokens_in  INT UNSIGNED    NOT NULL,
    tokens_out INT UNSIGNED    NOT NULL,
    created_at DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_ai_usage_tenant_time (tenant_id, created_at),
    KEY ix_ai_usage_session_time (session_id, created_at),
    CONSTRAINT fk_ai_usage_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_ai_usage_session FOREIGN KEY (session_id) REFERENCES sessions (id)       ON DELETE SET NULL,
    CONSTRAINT fk_ai_usage_block   FOREIGN KEY (block_id)   REFERENCES session_blocks (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_quota (
    tenant_id    BIGINT UNSIGNED NOT NULL,
    period_start DATETIME(3)     NOT NULL,
    period_end   DATETIME(3)     NOT NULL,
    tokens_used  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    calls_used   INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, period_start),
    CONSTRAINT fk_ai_quota_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Security support tables
-- ---------------------------------------------------------------------------
CREATE TABLE audit_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- NULL for events that cannot be attributed (e.g. login attempt for unknown email)
    tenant_id  BIGINT UNSIGNED NULL,
    event_type VARCHAR(64)     NOT NULL,
    ip_address VARBINARY(16)   NULL,
    details    JSON            NULL CHECK (details IS NULL OR JSON_VALID(details)),
    created_at DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_audit_events_tenant_time (tenant_id, created_at),
    CONSTRAINT fk_audit_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic throttling store (login backoff, join-attempt limiting, AI call caps).
-- bucket_key is SHA-256 of a namespaced key so raw IPs/emails are not stored here.
CREATE TABLE rate_limit_buckets (
    bucket_key    BINARY(32)   NOT NULL,
    failures      INT UNSIGNED NOT NULL DEFAULT 0,
    window_start  DATETIME(3)  NOT NULL,
    blocked_until DATETIME(3)  NULL,
    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
