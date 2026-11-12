# Content Tools evidence boundary

`ContentCapabilitiesCompositionTest.php` and `ContentToolCompositionTest.php` exercise the host's native provider/container bindings and reject invalid contributions before publishing a registry. The Module application owns the capability tests for adapter IDs, verified MIME, provider/service availability and independent view/edit/download flags. The trusted context is constructed only after File/Document authorization; no HTTP hydration or user-owned ACL exists in this module.

Frontend tests mount the actual image/PDF/audio/video viewing components and enforce controlled callback binding, derivative-only access without original-download permission, expired/unsafe access rejection and identity cleanup. DOM tests do not prove browser PDF/media decoding or byte-level File authorization.

File/Document integration must still demonstrate current tenant/object authorization, permission revocation, immutable byte/version association and independently authorized download. Those checks need the owning modules' real controlled resource environment; this source-only suite does not claim DB/browser or overall product qualification.
