ALTER TABLE production_reports
    ADD COLUMN direct_on_roll INT NOT NULL DEFAULT 0 AFTER unit_carder,
    ADD COLUMN direct_present INT NOT NULL DEFAULT 0 AFTER direct_on_roll;
