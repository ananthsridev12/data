-- Admin-definable custom fields (free text), stored EAV-style so new
-- fields never require a schema migration. Importable/viewable/editable
-- like the built-in fields, but not yet wired into dashboard filtering/
-- column display (see lead_view.php for view/edit, import mapping for
-- import support).

CREATE TABLE custom_fields (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field_key   VARCHAR(50) NOT NULL,
    label       VARCHAR(150) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_by  INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_custom_fields_key (field_key),
    CONSTRAINT fk_custom_fields_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_custom_values (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id          BIGINT UNSIGNED NOT NULL,
    custom_field_id  INT UNSIGNED NOT NULL,
    value            TEXT NULL,
    UNIQUE KEY uq_lead_custom_field (lead_id, custom_field_id),
    CONSTRAINT fk_lcv_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_lcv_field FOREIGN KEY (custom_field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
