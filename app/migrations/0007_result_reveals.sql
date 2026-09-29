-- Owner decision 11: per-block "hide results until I reveal them". A row means the tutor
-- revealed the results of that session block; until then participants get no aggregate
-- (state endpoint and relay broadcasts are tutor-only). Blocks with results = live
-- (the default) never need a row.
CREATE TABLE session_block_result_reveals (
    session_block_id BIGINT UNSIGNED NOT NULL,
    session_id       BIGINT UNSIGNED NOT NULL,
    revealed_at      DATETIME(3)     NOT NULL,
    PRIMARY KEY (session_block_id),
    KEY ix_result_reveals_session (session_id),
    CONSTRAINT fk_result_reveals_block FOREIGN KEY (session_block_id, session_id)
        REFERENCES session_blocks (id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
