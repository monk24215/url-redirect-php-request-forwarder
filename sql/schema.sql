-- Optional: only needed if you use PdoLogger
CREATE TABLE IF NOT EXISTS request_forward_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_label    VARCHAR(64)  NULL,
    method          VARCHAR(10)  NOT NULL,
    target_url      VARCHAR(2048) NOT NULL,
    final_url       VARCHAR(2048) NOT NULL,
    request_headers MEDIUMTEXT NULL,
    request_body    MEDIUMTEXT NULL,
    response_status SMALLINT UNSIGNED NULL,
    response_headers MEDIUMTEXT NULL,
    response_body   MEDIUMTEXT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms     INT UNSIGNED NULL,
    ok              TINYINT(1) NOT NULL DEFAULT 0,
    error_message   TEXT NULL,
    client_ip       VARCHAR(45) NULL,
    INDEX idx_created (created_at),
    INDEX idx_source  (source_label),
    INDEX idx_status  (response_status),
    INDEX idx_ok      (ok)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
