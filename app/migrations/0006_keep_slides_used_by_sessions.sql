-- Owner decision 8: slide images stay available while any past session still uses them.
-- Deleting a workshop now detaches its imports (workshop_id = NULL) instead of deleting
-- them; SlideImportService::collectOrphans() removes detached imports (rows, then files)
-- once no session block references any of their assets.
ALTER TABLE slide_imports DROP FOREIGN KEY fk_slide_imports_workshop;
ALTER TABLE slide_imports MODIFY workshop_id BIGINT UNSIGNED NULL;
ALTER TABLE slide_imports ADD CONSTRAINT fk_slide_imports_workshop
    FOREIGN KEY (workshop_id) REFERENCES workshops (id) ON DELETE SET NULL;
