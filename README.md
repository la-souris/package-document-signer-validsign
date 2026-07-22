# ValidSign implementation of the document signer SDK.

[ValidSign](https://www.validsign.eu/) implementation of the
[`SignatureProvider`](https://github.com/la-souris/package-document-signer-sdk/blob/main/src/Provider/SignatureProvider.php) contract from
[`la-souris/document-signer-sdk`](https://github.com/la-souris/package-document-signer-sdk).

## Install

```bash
composer require la-souris/document-signer-validsign
```

## Quick start

```php
use LaSouris\DocumentSigner\Sdk\Document\Document;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;
use LaSouris\DocumentSigner\ValidSign\ValidSignConfig;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;

$provider = new ValidSignProvider(new ValidSignConfig(
    apiKey:  getenv('VALIDSIGN_API_KEY'),
    baseUrl: 'https://my.validsign.nl/api',
));

$receipt = $provider->send(new Envelope(
    name:         'NDA',
    documents:    [new Document(
        id:   'nda',
        name: 'NDA',
        html: '<p>{[signature:counterparty:sig]} on {[date:counterparty:signdate]}</p>',
    )],
    signers:      [new Signer(key: 'counterparty', name: 'Jane Doe', email: 'jane@example.com')],
    emailSubject: 'Please sign the NDA',
));

echo $receipt->provider;           // "validsign" (ValidSignProvider::NAME)
echo $receipt->providerEnvelopeId; // ValidSign packageId
```

## What it does

For every document in the envelope, this package:

1. Parses `{[type:signer:name]}` placeholders out of the HTML.
2. Substitutes each one with a hidden [ValidSign text-tag](https://validsign.zendesk.com/hc/nl/articles/360037747091-Text-tags-gebruiken-binnen-documenten)
   — e.g. `{{esl_sig:Signer1:Signature:size(200,50)}}`. The SDK translates the
   arbitrary signer key from the envelope (`counterparty`, `customer`, …) into
   ValidSign's positional `Signer1`, `Signer2`, … tokens.
3. Renders the HTML to PDF via the SDK's `PdfRenderer` (Browsershot by default).
4. POSTs the PDFs + a `package` JSON to `POST /packages` with `documents[].extract = true`.
   ValidSign discovers the text-tags in the PDF server-side and places the matching
   fields on the corresponding signer — no per-field configuration in the API payload.
5. Returns an `EnvelopeReceipt` containing the ValidSign package id and a
   normalised `EnvelopeStatus`.

## Downloads

`downloadSigned()`, `downloadSignedDocument()`, and `downloadAudit()` all
write to a temp file and hand you an `\SplFileInfo` — check the extension:

```php
$archive = $provider->downloadSigned($packageId);
// $archive->getExtension() === 'zip'
// A ZIP with one signed PDF per document in the package (endpoint: /packages/{id}/documents/zip)

$pdf = $provider->downloadSignedDocument($packageId, 'nda');
// $pdf->getExtension() === 'pdf'
// The signed PDF for a single document (endpoint: /packages/{id}/documents/{documentId})
// $documentId is the same id you set on Document::$id when calling send().

$audit = $provider->downloadAudit($packageId);
// $audit->getExtension() === 'pdf'
// The Evidence Summary Report (endpoint: /packages/{id}/evidence/summary)
```

Callers own the file lifecycle — copy or `@unlink()` when done.

## Webhook events

`ValidSign\Webhook\EventType` is a string-backed enum covering every
callback event ValidSign can emit — `PACKAGE_COMPLETE`, `PACKAGE_DECLINE`,
`SIGNER_COMPLETE`, `KBA_FAILURE`, and so on. Values match ValidSign's
vocabulary verbatim.

```php
use LaSouris\DocumentSigner\ValidSign\Webhook\EventType;
use LaSouris\DocumentSigner\Laravel\Events\DocumentSignerWebhookReceived;

public function handle(DocumentSignerWebhookReceived $event): void
{
    if ($event->provider::NAME !== 'validsign') {
        return;
    }

    $type = EventType::tryFromPayload($event->payload);
    match ($type) {
        EventType::PackageComplete => $this->onCompleted($event->payload),
        EventType::PackageDecline  => $this->onDeclined($event->payload),
        EventType::KbaFailure      => $this->onKbaFailure($event->payload),
        EventType::Unknown         => Log::info('unknown ValidSign event', ['payload' => $event->payload]),
        default                    => null, // don't care about this event
    };
}
```

`tryFromPayload()` scans the payload for the first recognised event key
(`name`, `event`, `type`, `eventName`, `eventType`) — ValidSign has been
observed to key it under different names across tenants — and resolves
anything it doesn't recognise to `EventType::Unknown` (it never returns
`null`).

The enum implements the SDK's
[`WebhookEvent`](https://github.com/la-souris/package-document-signer-sdk/blob/main/src/Webhook/WebhookEvent.php)
interface, so listeners can also dispatch on the semantic category
(`isCompleted()`, `isDeclined()`, `isFailure()`, `isProgress()`) without
matching on individual cases — useful when the same handler needs to serve
multiple providers.

## Field mapping

Each SDK `FieldType` becomes a ValidSign text-tag with a default size. The
optional `*` prefix marks a field as required — signatures and initials are
implicitly required per ValidSign, so no prefix is applied for them.

| SDK `FieldType` | Emitted text-tag |
| --- | --- |
| `Signature` | `{{esl_<name>:SignerN:Signature:size(200,50)}}` |
| `Initials`  | `{{esl_<name>:SignerN:initials:size(100,30)}}` |
| `Text`      | `{{*esl_<name>:SignerN:TextField:size(200,20)}}` |
| `Date`      | `{{esl_<name>:SignerN:SigningDate:size(120,20)}}` |
| `Checkbox`  | `{{*esl_<name>:SignerN:Checkbox:size(20,20)}}` |

`<name>` is your placeholder's field name; `SignerN` is the signer's positional
index in `Envelope::$signers` (1-based).

## Requirements

- PHP 8.3
- `la-souris/document-signer-sdk`
- A ValidSign tenant + API key
- Node.js + Puppeteer (for the default Browsershot renderer)

## Documentation

The full provider guide — credentials, endpoint mapping, status mapping,
sequential signing, injecting a custom HTTP client, troubleshooting — lives in
the SDK's docs:

- [ValidSign provider guide](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/providers/validsign.md)
- [Placeholder syntax](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/placeholders.md)
- [PDF rendering](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/pdf-rendering.md)
- [Architecture overview](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/architecture.md)
