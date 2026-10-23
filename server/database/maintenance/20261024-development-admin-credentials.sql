-- Explicit local-development maintenance only; not part of the product upgrade chain.
-- The CLI binds the reviewed account, revisions, identifiers and private password hash.
-- The temporary assertion makes a stale prestate fail before the transaction commits.
CREATE TEMPORARY TABLE peanut_credential_maintenance_assertion (id TINYINT PRIMARY KEY);
INSERT INTO peanut_credential_maintenance_assertion VALUES (1);
START TRANSACTION;
UPDATE pa_credential SET identifier_normalized=@maintenance_identifier,
 secret_hash=@maintenance_hash,
 secret_changed_at=UTC_TIMESTAMP(3),revision=revision+1,updated_at=UTC_TIMESTAMP(3)
 WHERE id=@maintenance_credential AND account_id=@maintenance_account
 AND identifier_normalized=@maintenance_old_identifier AND revision=@maintenance_credential_revision
 AND kind='email_password' AND identifier_type='email' AND status='active'
 AND locked_until IS NULL AND failed_attempts=0 AND expires_at IS NULL;
INSERT INTO peanut_credential_maintenance_assertion SELECT 1 WHERE ROW_COUNT()<>1;
UPDATE pa_account SET security_revision=security_revision+1,updated_at=UTC_TIMESTAMP(3)
 WHERE id=@maintenance_account AND security_revision=@maintenance_security_revision AND status='active' AND locked_until IS NULL;
INSERT INTO peanut_credential_maintenance_assertion SELECT 1 WHERE ROW_COUNT()<>1;
UPDATE pa_tenant_session_token token JOIN pa_tenant_session session ON session.id=token.session_id
 SET token.status='revoked',token.revoked_at=UTC_TIMESTAMP(3)
 WHERE session.account_id=@maintenance_account AND token.status='active';
UPDATE pa_platform_session_token token JOIN pa_platform_session session ON session.id=token.session_id
 SET token.status='revoked',token.revoked_at=UTC_TIMESTAMP(3)
 WHERE session.account_id=@maintenance_account AND token.status='active';
UPDATE pa_tenant_session SET status='revoked',revoked_at=UTC_TIMESTAMP(3),revoke_reason='credential_changed',updated_at=UTC_TIMESTAMP(3)
 WHERE account_id=@maintenance_account AND status='active';
UPDATE pa_platform_session SET status='revoked',revoked_at=UTC_TIMESTAMP(3),revoke_reason='credential_changed',updated_at=UTC_TIMESTAMP(3)
 WHERE account_id=@maintenance_account AND status='active';
UPDATE pa_login_challenge SET status='revoked',revoked_at=UTC_TIMESTAMP(3)
 WHERE account_id=@maintenance_account AND status='active';
INSERT INTO pa_auth_security_event (audience,event_type,outcome,reason_code,account_id,credential_id,request_id,metadata_json,occurred_at)
 SELECT audience,'password_changed','success','maintenance_credential_recovery',@maintenance_account,@maintenance_credential,@maintenance_request,
 JSON_OBJECT('approval_ref',@maintenance_approval,'migration_key','20261024-development-admin-credentials','identifier_changed',TRUE),UTC_TIMESTAMP(3)
 FROM (SELECT 'tenant' AS audience UNION ALL SELECT 'platform') audiences;
INSERT INTO peanut_credential_maintenance_assertion SELECT 1 WHERE ROW_COUNT()<>2;
INSERT INTO pa_tenant_audit_event
 (tenant_id,event_type,action,outcome,actor_tenant_id,actor_type,target_resource_type,target_resource_id,target_count,authorization_basis_json,request_id,operation_id,before_json,after_json,metadata_json,occurred_at)
 VALUES (1,'identity.credential.recovered','identity.credential.recover.tenant-owner','success',1,'tenant_system','credential',CAST(@maintenance_credential AS CHAR),1,
 JSON_OBJECT('approval_ref',@maintenance_approval),@maintenance_request,@maintenance_request,@maintenance_before,
 JSON_OBJECT('identifier',@maintenance_identifier,'credential_revision',@maintenance_credential_revision+1,'security_revision',@maintenance_security_revision+1),
 JSON_OBJECT('approval_ref',@maintenance_approval,'password_ref',@maintenance_password_ref),UTC_TIMESTAMP(3));
INSERT INTO pa_platform_audit_event
 (event_type,action,outcome,target_type,target_id,request_id,operation_id,before_json,after_json,metadata_json,occurred_at)
 VALUES ('identity.credential.recovered','identity.credential.recover.platform-operator','success','credential',CAST(@maintenance_credential AS CHAR),@maintenance_request,@maintenance_request,@maintenance_before,
 JSON_OBJECT('identifier',@maintenance_identifier,'credential_revision',@maintenance_credential_revision+1,'security_revision',@maintenance_security_revision+1),
 JSON_OBJECT('approval_ref',@maintenance_approval,'password_ref',@maintenance_password_ref),UTC_TIMESTAMP(3));
INSERT INTO peanut_credential_maintenance_assertion SELECT 1 WHERE
 EXISTS (SELECT 1 FROM pa_tenant_session WHERE account_id=@maintenance_account AND status='active')
 OR EXISTS (SELECT 1 FROM pa_platform_session WHERE account_id=@maintenance_account AND status='active')
 OR EXISTS (SELECT 1 FROM pa_tenant_session_token token JOIN pa_tenant_session session ON session.id=token.session_id WHERE session.account_id=@maintenance_account AND token.status='active')
 OR EXISTS (SELECT 1 FROM pa_platform_session_token token JOIN pa_platform_session session ON session.id=token.session_id WHERE session.account_id=@maintenance_account AND token.status='active')
 OR EXISTS (SELECT 1 FROM pa_login_challenge WHERE account_id=@maintenance_account AND status='active')
 OR (SELECT COUNT(*) FROM pa_tenant_audit_event WHERE request_id=@maintenance_request AND event_type='identity.credential.recovered')<>1
 OR (SELECT COUNT(*) FROM pa_platform_audit_event WHERE request_id=@maintenance_request AND event_type='identity.credential.recovered')<>1;
COMMIT;
DROP TEMPORARY TABLE peanut_credential_maintenance_assertion;
