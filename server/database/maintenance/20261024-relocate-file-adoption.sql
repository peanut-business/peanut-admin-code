-- Explicit development-only File adoption relocation assertion.
-- Selected only by schema:application --file-adoption-relocation; never seed/default upgrade.
-- The original adopted row, provenance and all business data remain unchanged.
CREATE TEMPORARY TABLE peanut_file_relocation_assertion (id TINYINT PRIMARY KEY);
INSERT INTO peanut_file_relocation_assertion VALUES (1);
START TRANSACTION;
SELECT id FROM pa_module_migration
 WHERE module_key='official.file' AND migration_key='official.file:20260823-unify-storage-service' FOR UPDATE;
INSERT INTO peanut_file_relocation_assertion SELECT 1 WHERE
 DATABASE()<>'peanut_dev_mt' OR (SELECT COUNT(*) FROM pa_module_migration
 WHERE module_key='official.file' AND migration_key='official.file:20260823-unify-storage-service'
 AND status='applied' AND resolution_kind='adopted'
 AND checksum='f79c35b2fe8414dc172a81a45183d2a52eadb05833a704149780b36e400ca2d4'
 AND SHA2(CAST(provenance_json AS CHAR),256)='ef6e5d8cd968a28a2d13d3ce3722275ef0720185049a94274a883868f3972def')<>1;
COMMIT;
DROP TEMPORARY TABLE peanut_file_relocation_assertion;
