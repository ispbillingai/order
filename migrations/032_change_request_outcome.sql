-- Migration: 032_change_request_outcome
-- A guest's "change a dish" request now changes the order when the staff
-- applies it: how many of the dish (two alike, change one) and what came of
-- it (applied / declined), which the guest sees on their page.

ALTER TABLE table_requests
    ADD COLUMN quantity INT NULL DEFAULT NULL,
    ADD COLUMN outcome ENUM('applied', 'declined') NULL DEFAULT NULL;
