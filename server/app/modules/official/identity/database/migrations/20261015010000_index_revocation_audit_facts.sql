-- Dedicated immutable negative fact references in existing Identity audit storage.
ALTER TABLE `pa_tenant_audit_event`
  ADD COLUMN `revocation_fact_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD COLUMN `revocation_fact_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD UNIQUE KEY `uk_identity_revocation_fact` (`revocation_fact_key`),
  ADD KEY `idx_identity_revocation_scan` (`tenant_id`, `event_type`, `id`);
