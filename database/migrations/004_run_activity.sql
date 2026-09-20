-- The admin dashboards group runs by kind and day, which nothing else did, so
-- the table had no index that helps them.

ALTER TABLE runs ADD KEY runs_kind_created (kind, created_at);

-- And the failing-test tables group results by test across every run.
ALTER TABLE results ADD KEY results_test_outcome (test_id, outcome);
