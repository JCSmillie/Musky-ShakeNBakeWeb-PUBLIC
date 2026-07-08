-- ============================================================================
-- Musky / NoraDB - Inventory Tables
-- ----------------------------------------------------------------------------
-- Tracks part quantities by location + transaction history.
-- Uses existing Parts table elsewhere for PartID + description.
-- ============================================================================

CREATE TABLE IF NOT EXISTS inventory_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,        -- e.g. GHS_HDK, GMS_HDK, NONINV
  name VARCHAR(128) NOT NULL,              -- friendly name
  is_virtual ENUM('TRUE','FALSE') NOT NULL DEFAULT 'FALSE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_stock (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  part_id INT NOT NULL,                    -- references existing parts.PartID logically
  location_code VARCHAR(32) NOT NULL,      -- references inventory_locations.code logically
  qty INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_part_location (part_id, location_code),
  INDEX idx_location (location_code),
  INDEX idx_part (part_id)
);

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- What part was affected
  part_id INT NOT NULL,

  -- Location semantics:
  -- ADD:      from_location_code = NULL, to_location_code = <target>
  -- TRANSFER: from_location_code = <source>, to_location_code = <dest>
  -- ADJUST:   from_location_code = NULL, to_location_code = <target>
  from_location_code VARCHAR(32) NULL,
  to_location_code   VARCHAR(32) NULL,

  -- Deltas (always store BOTH so it is unambiguous in history)
  delta_from INT NOT NULL DEFAULT 0,   -- negative when removing from source
  delta_to   INT NOT NULL DEFAULT 0,   -- positive when adding to dest

  -- External reference (your “transactionID” coming from elsewhere)
  external_transaction_id VARCHAR(128) NOT NULL,

  -- Who/why
  action ENUM('ADD','TRANSFER','ADJUST','USE') NOT NULL DEFAULT 'ADJUST',
  note TEXT NULL,
  actor VARCHAR(128) NULL               -- email/username if available
);

-- Seed the 3 required locations
INSERT IGNORE INTO inventory_locations (code, name, is_virtual) VALUES
('GHS_HDK', 'GHS HDK', 'FALSE'),
('GMS_HDK', 'GMS HDK', 'FALSE'),
('NONINV',  'NonInventory Part Bin', 'TRUE');
