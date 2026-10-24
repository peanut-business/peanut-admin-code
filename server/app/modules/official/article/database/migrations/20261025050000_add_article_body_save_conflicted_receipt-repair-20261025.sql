-- peanut-admin-repairs: official.article:20261025050000_add_article_body_save_conflicted_receipt
-- The native Article preservation callback verifies the already committed CHECK poststate.
-- Do not repeat the original ALTER or modify any receipt or original failure ledger row.
SELECT 1;
