-- Chef Daily Order — database schema (design §3). MySQL / MariaDB, utf8mb4.

CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(40)  NOT NULL,
    email         VARCHAR(190) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','chef') NOT NULL DEFAULT 'chef',
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL,
    last_login_at DATETIME     NULL,
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed logins per username (also for unknown names, so the answer never reveals which exist).
CREATE TABLE login_attempts (
    username     VARCHAR(40) NOT NULL PRIMARY KEY,
    failures     INT         NOT NULL DEFAULT 0,
    locked_until DATETIME    NULL,
    updated_at   DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    token_hash   CHAR(64)     NOT NULL,
    user_id      INT          NOT NULL,
    created_at   DATETIME     NOT NULL,
    expires_at   DATETIME     NOT NULL,
    last_seen_at DATETIME     NOT NULL,
    user_agent   VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_sessions_token (token_hash),
    KEY ix_sessions_user (user_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    user_id    INT      NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    UNIQUE KEY uq_resets_token (token_hash),
    KEY ix_resets_user (user_id, created_at),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name_es    VARCHAR(80) NOT NULL,
    name_en    VARCHAR(80) NOT NULL DEFAULT '',
    icon       VARCHAR(16) NOT NULL DEFAULT '',
    sort       INT         NOT NULL DEFAULT 0,
    deleted_at DATETIME    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    category_id     INT          NOT NULL,
    name_es         VARCHAR(120) NOT NULL,
    name_en         VARCHAR(120) NOT NULL DEFAULT '',
    unit            VARCHAR(16)  NOT NULL,
    price           INT          NOT NULL DEFAULT 0,
    price_source    ENUM('market','manual') NOT NULL DEFAULT 'manual',
    market_keywords VARCHAR(255) NOT NULL DEFAULT '',
    market_price    INT          NULL,
    market_prev     INT          NULL,
    market_date     DATE         NULL,
    market_quote    VARCHAR(200) NOT NULL DEFAULT '',
    sort            INT          NOT NULL DEFAULT 0,
    deleted_at      DATETIME     NULL,
    updated_by      INT          NULL,
    updated_at      DATETIME     NOT NULL,
    KEY ix_items_category (category_id, sort),
    CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT fk_items_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE item_changes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    item_id     INT          NULL,
    category_id INT          NULL,
    user_id     INT          NULL,
    action      VARCHAR(16)  NOT NULL,
    before_json TEXT         NULL,
    after_json  TEXT         NULL,
    at          DATETIME     NOT NULL,
    KEY ix_changes_item (item_id, at),
    CONSTRAINT fk_changes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    uid         VARCHAR(64)  NOT NULL,
    order_no    VARCHAR(32)  NOT NULL DEFAULT '',
    user_id     INT          NOT NULL,
    order_date  DATE         NOT NULL,
    order_time  CHAR(5)      NOT NULL DEFAULT '',
    total       BIGINT       NOT NULL DEFAULT 0,
    item_count  INT          NOT NULL DEFAULT 0,
    price_note  VARCHAR(200) NOT NULL DEFAULT '',
    report_text TEXT         NOT NULL,
    created_at  DATETIME     NOT NULL,
    UNIQUE KEY uq_orders_uid (uid),
    KEY ix_orders_user (user_id, id),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT           NOT NULL,
    item_id         INT           NULL,
    category_name   VARCHAR(170)  NOT NULL DEFAULT '',
    name_es         VARCHAR(120)  NOT NULL DEFAULT '',
    name_en         VARCHAR(120)  NOT NULL DEFAULT '',
    qty             DECIMAL(12,3) NOT NULL,
    unit            VARCHAR(16)   NOT NULL DEFAULT '',
    price           INT           NOT NULL DEFAULT 0,
    line_total      BIGINT        NOT NULL DEFAULT 0,
    synced_at       DATETIME      NULL,
    sync_token      CHAR(32)      NULL,
    sync_claimed_at DATETIME      NULL,
    KEY ix_order_items_order (order_id),
    KEY ix_order_items_sync (synced_at),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    name  VARCHAR(40)  NOT NULL PRIMARY KEY,
    value VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (name, value) VALUES
    ('restaurant', 'Mirchi'),
    ('whatsapp', ''),
    ('sheet_id', '1LSLSQgAOYBJdhAkgYRG8xZFo6bi4Gpg-po1u7MKKaP0'),
    ('sheet_tab', 'Orders'),
    ('market_date', ''),
    ('market_updated', ''),
    ('sheet_last_error', '');
