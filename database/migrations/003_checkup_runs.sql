-- A checkup is one honest login walked through for the person whose site it is,
-- rather than a pass through a test suite. It keeps the same row so it gets
-- transcripts, an unguessable public id and pruning for free, but it is never
-- graded and never published, so no other table learns about it.

ALTER TABLE runs MODIFY kind ENUM('server', 'client', 'checkup') NOT NULL;
