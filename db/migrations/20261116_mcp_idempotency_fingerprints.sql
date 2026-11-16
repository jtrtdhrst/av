-- MCP: wiązanie kluczy idempotencji z treścią zapisu i decyzji.
-- Stare bezpośrednie wpisy bez fingerprintu pozostają fail-closed; replay legacy
-- decyzji propozycji może odtworzyć fingerprint po walidacji zapisanego dowodu.

SET @has_mcp_idem_payload_hash = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'mcp_idempotency' AND column_name = 'payload_hash'
);
SET @mcp_idem_payload_hash_sql = IF(
  @has_mcp_idem_payload_hash = 0,
  'ALTER TABLE mcp_idempotency ADD COLUMN payload_hash CHAR(64) NULL AFTER tool',
  'DO 0'
);
PREPARE mcp_idem_payload_hash_stmt FROM @mcp_idem_payload_hash_sql;
EXECUTE mcp_idem_payload_hash_stmt;
DEALLOCATE PREPARE mcp_idem_payload_hash_stmt;

SET @has_mcp_decision_hash = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'mcp_proposals' AND column_name = 'decision_hash'
);
SET @mcp_decision_hash_sql = IF(
  @has_mcp_decision_hash = 0,
  'ALTER TABLE mcp_proposals ADD COLUMN decision_hash CHAR(64) NULL AFTER decision_key',
  'DO 0'
);
PREPARE mcp_decision_hash_stmt FROM @mcp_decision_hash_sql;
EXECUTE mcp_decision_hash_stmt;
DEALLOCATE PREPARE mcp_decision_hash_stmt;

-- Klucze są nieprzezroczystymi tokenami: porównuj je case-sensitive.
ALTER TABLE mcp_proposals
  MODIFY COLUMN idempotency_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  MODIFY COLUMN decision_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL;

ALTER TABLE mcp_idempotency
  MODIFY COLUMN idem_key VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
