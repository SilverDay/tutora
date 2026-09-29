-- Phase 8: AI summary of a Write block's responses. Generated only when the tutor asks;
-- shown to the tutor next to the raw responses; shared with participants only by an
-- explicit tutor action (shared_at). Plain text; always output-encoded when rendered.
CREATE TABLE write_summaries (
    session_block_id BIGINT UNSIGNED NOT NULL,
    session_id       BIGINT UNSIGNED NOT NULL,
    summary_text     TEXT            NOT NULL,
    responses_total  INT UNSIGNED    NOT NULL,
    responses_used   INT UNSIGNED    NOT NULL,
    generated_at     DATETIME(3)     NOT NULL,
    shared_at        DATETIME(3)     NULL,
    PRIMARY KEY (session_block_id),
    KEY ix_write_summaries_session (session_id),
    CONSTRAINT fk_write_summaries_block FOREIGN KEY (session_block_id, session_id)
        REFERENCES session_blocks (id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
