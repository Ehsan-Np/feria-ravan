-- ============================================================
-- Feria Ravan v3.5.2 Migration: Test Custom Fields & Conditional Logic
-- ============================================================

CREATE TABLE IF NOT EXISTS test_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NULL DEFAULT NULL,
    field_key VARCHAR(64) NOT NULL,
    label VARCHAR(255) NOT NULL,
    description TEXT NULL,
    field_type ENUM('text', 'textarea', 'select', 'checkbox', 'radio', 'tel', 'number') NOT NULL DEFAULT 'text',
    options_json TEXT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    placeholder VARCHAR(255) NULL,
    default_value VARCHAR(255) NULL,
    validation_regex VARCHAR(255) NULL,
    validation_message VARCHAR(255) NULL,
    condition_rules_json TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_test_id (test_id),
    INDEX idx_field_key (field_key),
    INDEX idx_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_custom_field_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    field_id INT NOT NULL,
    field_key VARCHAR(64) NOT NULL,
    field_label VARCHAR(255) NOT NULL,
    field_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempt_id (attempt_id),
    INDEX idx_field_id (field_id),
    CONSTRAINT fk_attempt_custom_field FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
