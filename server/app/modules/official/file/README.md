# File composition

The public `FileDocumentReferenceRegistry` is an application-composition contract.
The composition root constructs it from the selected, compiled provider's
`FileDocumentReferenceContributor` declaration and registers that instance in the
native ThinkPHP container. Requests cannot supply or replace its policy class.
An absent contribution rejects document-reference authorization; registry lookup
does not grant preview, original download or management permission. The selected
Document policy and File services retain their current authorization checks.

This export supports the existing host composition boundary. Other File services
and Models remain private unless separately declared as public module contracts.
