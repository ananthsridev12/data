-- Lead Management Dashboard - core schema
-- Import via phpMyAdmin (or `mysql -u USER -p DBNAME < 001_schema.sql`) against an empty database.

SET NAMES utf8mb4;

CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin', 'member') NOT NULL DEFAULT 'member',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_batches (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename        VARCHAR(255) NOT NULL,
    stored_path     VARCHAR(500) NOT NULL,
    file_type       ENUM('xlsx', 'csv') NOT NULL,
    uploaded_by     INT UNSIGNED NOT NULL,
    status          ENUM('mapping_pending', 'processing', 'completed', 'failed', 'partial') NOT NULL DEFAULT 'mapping_pending',
    mapping_json    TEXT NULL,
    total_rows      INT UNSIGNED NOT NULL DEFAULT 0,
    next_offset     INT UNSIGNED NOT NULL DEFAULT 0,
    inserted_count  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count   INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count   INT UNSIGNED NOT NULL DEFAULT 0,
    error_count     INT UNSIGNED NOT NULL DEFAULT 0,
    notes           VARCHAR(500) NULL,
    started_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at     DATETIME NULL,
    CONSTRAINT fk_import_batches_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
    KEY idx_import_batches_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_field_mappings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    mapping_json    TEXT NOT NULL,
    created_by      INT UNSIGNED NOT NULL,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME NULL,
    CONSTRAINT fk_mapping_user FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uq_mapping_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    na_company_name          VARCHAR(255) NOT NULL,
    category                 VARCHAR(150) NOT NULL,
    products                 TEXT NOT NULL,
    first_name               VARCHAR(100) NOT NULL,
    last_name                VARCHAR(100) NOT NULL,
    title                    VARCHAR(255) NOT NULL,
    company_name_for_emails  VARCHAR(255) NOT NULL,
    email                    VARCHAR(190) NOT NULL,
    seniority                VARCHAR(100) NULL,
    departments              VARCHAR(255) NULL,
    sub_departments           VARCHAR(255) NULL,
    employee_count            VARCHAR(50) NULL,
    industry                  VARCHAR(150) NOT NULL,
    keywords                  TEXT NULL,
    person_linkedin_url       VARCHAR(500) NOT NULL,
    website                   VARCHAR(255) NOT NULL,
    company_linkedin_url      VARCHAR(500) NOT NULL,
    facebook_url               VARCHAR(500) NULL,
    twitter_url                VARCHAR(500) NULL,
    city                       VARCHAR(150) NULL,
    state                      VARCHAR(150) NULL,
    country                    VARCHAR(150) NULL,
    company_address            VARCHAR(500) NULL,
    company_city                VARCHAR(150) NULL,
    company_state                VARCHAR(150) NULL,
    company_country               VARCHAR(150) NOT NULL,
    company_phone                  VARCHAR(50) NULL,
    technologies                    TEXT NULL,
    annual_revenue                   VARCHAR(100) NULL,
    total_funding                     VARCHAR(100) NULL,
    latest_funding                     VARCHAR(100) NULL,
    latest_funding_amount               VARCHAR(100) NULL,
    last_raised_at                       VARCHAR(50) NULL,
    last_import_batch_id                  INT UNSIGNED NULL,
    created_at                             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_leads_email (email),
    KEY idx_leads_company (na_company_name),
    KEY idx_leads_title (title),
    KEY idx_leads_seniority (seniority),
    KEY idx_leads_departments (departments),
    KEY idx_leads_industry (industry),
    KEY idx_leads_country (country),
    KEY idx_leads_company_country (company_country),
    KEY idx_leads_website (website),
    CONSTRAINT fk_leads_import_batch FOREIGN KEY (last_import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
    FULLTEXT KEY ftx_leads_search (na_company_name, title, products, keywords)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_row_errors (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id   INT UNSIGNED NOT NULL,
    row_num           INT UNSIGNED NOT NULL,
    email             VARCHAR(190) NULL,
    reason            VARCHAR(500) NOT NULL,
    raw_row_json      TEXT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_row_errors_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE CASCADE,
    KEY idx_row_errors_batch (import_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaigns (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(150) NOT NULL,
    description             VARCHAR(500) NULL,
    saleshandy_sequence_id  VARCHAR(100) NULL,
    created_by              INT UNSIGNED NOT NULL,
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_campaigns_name (name),
    CONSTRAINT fk_campaigns_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_campaign_assignments (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id       BIGINT UNSIGNED NOT NULL,
    campaign_id   INT UNSIGNED NOT NULL,
    assigned_by   INT UNSIGNED NOT NULL,
    assigned_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status        ENUM('assigned', 'exported', 'pushed') NOT NULL DEFAULT 'assigned',
    exported_at   DATETIME NULL,
    UNIQUE KEY uq_lead_campaign (lead_id, campaign_id),
    CONSTRAINT fk_assignments_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignments_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignments_user FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
