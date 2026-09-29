-- whiteboard_entities was part of earlier revisions of 0001 but is not used: board content
-- lives in the whiteboard sidecar's Yjs documents (owner decision, Phase 7). Dropping it here
-- keeps databases created from those earlier revisions consistent with fresh installs.
DROP TABLE IF EXISTS whiteboard_entities;
