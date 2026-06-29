CREATE TABLE IF NOT EXISTS nora_config_store (
    ConfigID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ConfigGroup VARCHAR(100) NOT NULL,
    ConfigKey VARCHAR(120) NOT NULL,
    ConfigSet VARCHAR(100) NOT NULL DEFAULT 'DEFAULT',
    ValueType ENUM('text','json') NOT NULL DEFAULT 'text',
    ConfigValue LONGTEXT NOT NULL,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    IsSecret TINYINT(1) NOT NULL DEFAULT 0,
    DescriptionText VARCHAR(255) DEFAULT NULL,
    UpdatedBy VARCHAR(190) DEFAULT NULL,
    CreatedBy VARCHAR(190) DEFAULT NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ConfigID),
    UNIQUE KEY uq_nora_config_group_key_set (ConfigGroup, ConfigKey, ConfigSet),
    KEY idx_nora_config_group (ConfigGroup),
    KEY idx_nora_config_active (ConfigGroup, ConfigKey, IsActive),
    KEY idx_nora_config_secret (IsSecret)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blank Mosyle placeholders for DB-managed Nora/Musky credential checks.
-- ON DUPLICATE intentionally leaves ConfigValue alone so real secrets are not
-- wiped when this installer is rerun.
INSERT INTO nora_config_store
    (ConfigGroup, ConfigKey, ConfigSet, ValueType, ConfigValue, IsActive, IsSecret, DescriptionText, CreatedBy, UpdatedBy)
VALUES
    ('MOSYLE', 'API_KEY', 'DEFAULT', 'text', '', 1, 1, 'Mosyle API accessToken/API key for Nora and Musky Mosyle calls.', 'NoraWeb.ConfigStore.install', 'NoraWeb.ConfigStore.install'),
    ('MOSYLE', 'API_USERNAME', 'DEFAULT', 'text', '', 1, 1, 'Mosyle API username used to mint bearer tokens.', 'NoraWeb.ConfigStore.install', 'NoraWeb.ConfigStore.install'),
    ('MOSYLE', 'API_PASSWORD', 'DEFAULT', 'text', '', 1, 1, 'Mosyle API password used to mint bearer tokens.', 'NoraWeb.ConfigStore.install', 'NoraWeb.ConfigStore.install')
ON DUPLICATE KEY UPDATE
    ValueType = VALUES(ValueType),
    IsSecret = VALUES(IsSecret),
    DescriptionText = VALUES(DescriptionText),
    UpdatedBy = VALUES(UpdatedBy);
