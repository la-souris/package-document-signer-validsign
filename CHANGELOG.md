# Changelog

All notable changes to `la-souris/document-signer-validsign` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-07-22

Initial public release.

### Added

- ValidSign implementation of the `la-souris/document-signer-sdk`
  `SignatureProvider` contract (`ValidSignProvider`, `ValidSignConfig`,
  `ValidSignClient`).
- ValidSign anchor-based placeholder replacement (`ValidSignPlaceholderReplacer`).
- Webhook `EventType` mapping for ValidSign callbacks.
- `downloadSignedDocument()` takes the caller's `Document::$id` verbatim; a
  "document not found" (HTTP 404) while the envelope is not yet finalized is
  surfaced as the SDK's retryable `SignedDocumentUnavailableException`.
- `downloadAudit()` returns ValidSign's Evidence Summary Report as a `.pdf`.
- Requires `la-souris/document-signer-sdk` ^1.0 and PHP 8.3+.
