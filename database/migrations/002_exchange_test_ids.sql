-- The tests a recorded request was made for, comma-separated, so a test's page
-- shows only its own requests. NULL means the request belongs to its whole step:
-- a landing on the callback, or anything recorded before this column existed.

ALTER TABLE exchanges ADD COLUMN test_ids VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER step;
