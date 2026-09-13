-- Testers, runs, results and transcripts. All timestamps are UTC.

-- A tester is identified by the profile URL they signed in with.
CREATE TABLE testers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    me            VARCHAR(512) NOT NULL,
    me_hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at    DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY testers_me_hash (me_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A server or client someone tests, which can be published as a report.
CREATE TABLE implementations (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tester_id  INT UNSIGNED NOT NULL,
    kind       ENUM('server', 'client') NOT NULL,
    name       VARCHAR(255) NOT NULL,
    url        VARCHAR(1000) NULL,
    target     VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY implementations_tester (tester_id),
    CONSTRAINT implementations_tester_fk FOREIGN KEY (tester_id) REFERENCES testers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One pass through a test suite. public_id appears in the run URL; run_key
-- appears in URLs other servers see (client_id documents, callbacks, client
-- test profiles), so the two are never the same value.
CREATE TABLE runs (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id         CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run_key           CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tester_id         INT UNSIGNED NULL,
    implementation_id INT UNSIGNED NULL,
    kind              ENUM('server', 'client') NOT NULL,
    target_url        VARCHAR(1000) NULL,
    mode              ENUM('browser', 'cli') NOT NULL DEFAULT 'browser',
    options_json      TEXT NULL,
    consent_pin_hash  VARCHAR(255) NULL,
    status            ENUM('running', 'waiting', 'complete', 'abandoned') NOT NULL DEFAULT 'running',
    state_json        MEDIUMTEXT NULL,
    suite_version     VARCHAR(40) NULL,
    created_at        DATETIME NOT NULL,
    updated_at        DATETIME NOT NULL,
    finished_at       DATETIME NULL,
    expires_at        DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY runs_public_id (public_id),
    UNIQUE KEY runs_run_key (run_key),
    KEY runs_tester (tester_id),
    KEY runs_implementation (implementation_id),
    CONSTRAINT runs_tester_fk FOREIGN KEY (tester_id) REFERENCES testers (id) ON DELETE SET NULL,
    CONSTRAINT runs_implementation_fk FOREIGN KEY (implementation_id) REFERENCES implementations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The latest outcome of each test in a run.
CREATE TABLE results (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id       INT UNSIGNED NOT NULL,
    test_id      VARCHAR(8) CHARACTER SET ascii NOT NULL,
    outcome      VARCHAR(16) CHARACTER SET ascii NOT NULL,
    summary      VARCHAR(1000) NOT NULL DEFAULT '',
    details_json TEXT NULL,
    step         VARCHAR(64) CHARACTER SET ascii NULL,
    answered_by  ENUM('auto', 'human', 'driver') NOT NULL DEFAULT 'auto',
    recorded_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY results_run_test (run_id, test_id),
    CONSTRAINT results_run_fk FOREIGN KEY (run_id) REFERENCES runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- HTTP transcripts, outbound (our requests) and inbound (requests made to us),
-- with secrets redacted before they are stored.
CREATE TABLE exchanges (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id                INT UNSIGNED NOT NULL,
    step                  VARCHAR(64) CHARACTER SET ascii NULL,
    direction             ENUM('outbound', 'inbound') NOT NULL,
    method                VARCHAR(10) CHARACTER SET ascii NOT NULL,
    url                   VARCHAR(2000) NOT NULL,
    request_headers_json  TEXT NULL,
    request_body          TEXT NULL,
    status                SMALLINT UNSIGNED NULL,
    response_headers_json TEXT NULL,
    response_body         MEDIUMTEXT NULL,
    duration_ms           INT UNSIGNED NULL,
    remote_ip             VARCHAR(64) NULL,
    error                 VARCHAR(1000) NULL,
    created_at            DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY exchanges_run_step (run_id, step),
    CONSTRAINT exchanges_run_fk FOREIGN KEY (run_id) REFERENCES runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A published implementation report: a snapshot of one run's results.
CREATE TABLE reports (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    implementation_id INT UNSIGNED NOT NULL,
    run_id            INT UNSIGNED NOT NULL,
    summary_json      MEDIUMTEXT NOT NULL,
    published_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY reports_implementation (implementation_id),
    CONSTRAINT reports_implementation_fk FOREIGN KEY (implementation_id) REFERENCES implementations (id) ON DELETE CASCADE,
    CONSTRAINT reports_run_fk FOREIGN KEY (run_id) REFERENCES runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
