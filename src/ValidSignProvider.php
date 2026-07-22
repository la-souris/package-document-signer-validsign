<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\ValidSign;

use LaSouris\DocumentSigner\Sdk\Document\Document;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Exception\ProviderException;
use LaSouris\DocumentSigner\Sdk\Exception\ProviderNotFoundException;
use LaSouris\DocumentSigner\Sdk\Exception\SignedDocumentUnavailableException;
use LaSouris\DocumentSigner\Sdk\Pdf\BrowsershotPdfRenderer;
use LaSouris\DocumentSigner\Sdk\Pdf\PageDecoration;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\Sdk\Placeholder\PlaceholderParser;
use LaSouris\DocumentSigner\Sdk\Placeholder\PreparedField;
use LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LaSouris\DocumentSigner\Sdk\Provider\FieldValue;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;
use LaSouris\DocumentSigner\Sdk\Signer\SigningOrder;
use LaSouris\DocumentSigner\Sdk\Support\TempFile;
use LaSouris\DocumentSigner\ValidSign\Http\ValidSignClient;
use LaSouris\DocumentSigner\ValidSign\Placeholder\ValidSignPlaceholderReplacer;

final class ValidSignProvider implements SignatureProvider
{
    public const string NAME = 'validsign';

    private readonly ValidSignConfig $config;
    private readonly ValidSignClient $client;
    private readonly PdfRenderer $pdfRenderer;
    private readonly ValidSignPlaceholderReplacer $replacer;
    private readonly PlaceholderParser $parser;

    public function __construct(
        ValidSignConfig $config,
        ?ValidSignClient $client = null,
        ?PdfRenderer $pdfRenderer = null,
        ?ValidSignPlaceholderReplacer $replacer = null,
        ?PlaceholderParser $parser = null,
    ) {
        $this->config      = $config;
        $this->client      = $client      ?? new ValidSignClient($config);
        $this->pdfRenderer = $pdfRenderer ?? new BrowsershotPdfRenderer();
        $this->replacer    = $replacer    ?? new ValidSignPlaceholderReplacer();
        $this->parser      = $parser      ?? new PlaceholderParser();
    }

    public function send(Envelope $envelope): EnvelopeReceipt
    {
        $files = [];
        $apiDocuments = [];
        $docIndex = 0;

        $replacer = $this->replacer->withSignerRoleMap($this->buildSignerRoleMap($envelope));

        foreach ($envelope->documents as $document) {
            $prepared = $replacer->replace($document->html, $this->parser->parse($document->html));
            $this->assertFieldsResolvable($envelope, $document, $prepared->fields);

            $pdf = $this->pdfRenderer->render($prepared->html, new PageDecoration(
                headerHtml: $document->headerHtml,
                footerHtml: $document->footerHtml,
                headerPlacement: $document->headerPlacement,
                footerPlacement: $document->footerPlacement,
            ));
            $files[] = [
                'name'     => $this->fileName($document),
                'contents' => $pdf,
            ];

            // No `approvals` block — ValidSign auto-detects the `{{esl:…}}` text-tags in the PDF
            // during package creation and builds the approval/field graph server-side. See:
            // https://validsign.zendesk.com/hc/nl/articles/360037747091-Text-tags-gebruiken-binnen-documenten
            $apiDocuments[] = [
                'id'      => $document->id,
                'name'    => $document->name,
                'index'   => $docIndex++,
                'extract' => true,
            ];
        }

        $payload = [
            'name'         => $envelope->name,
            'type'         => 'PACKAGE',
            'status'       => 'SENT',
            'language'     => $this->config->defaultLanguage,
            'emailMessage' => $envelope->emailMessage ?? '',
            'description'  => $envelope->emailSubject,
            'due'          => $envelope->expiresAt?->format(\DateTimeInterface::ATOM),
            'roles'        => $this->buildRoles($envelope),
            'documents'    => $apiDocuments,
            'data'         => $envelope->metadata ?: new \stdClass(),
        ];

        $response = $this->client->createPackage($payload, $files);

        $packageId = $response['id'] ?? null;
        if (!is_string($packageId) || $packageId === '') {
            throw new ProviderException(
                'ValidSign did not return a package id in the create-package response.',
                providerBody: json_encode($response),
            );
        }

        try {
            return new EnvelopeReceipt(
                provider: self::NAME,
                providerEnvelopeId: $packageId,
                status: EnvelopeStatus::Sent,
                signerUrls: [],
                raw: $response,
            );
        } catch (ProviderException $e) {
            throw $e->withProviderEnvelopeId($packageId);
        } catch (\Throwable $e) {
            throw new ProviderException(
                message: 'ValidSign package was created but the SDK failed to build the receipt: ' . $e->getMessage(),
                previous: $e,
                providerEnvelopeId: $packageId,
            );
        }
    }

    public function getStatus(string $providerEnvelopeId): EnvelopeStatus
    {
        $response = $this->client->getPackage($providerEnvelopeId);
        $status = is_string($response['status'] ?? null) ? strtoupper($response['status']) : '';

        return match ($status) {
            'DRAFT'                      => EnvelopeStatus::Draft,
            'SENT'                       => EnvelopeStatus::Sent,
            'COMPLETED', 'ARCHIVED'      => EnvelopeStatus::Completed,
            'DECLINED', 'OPTED_OUT'      => EnvelopeStatus::Declined,
            'EXPIRED'                    => EnvelopeStatus::Expired,
            default                      => EnvelopeStatus::Unknown,
        };
    }

    public function downloadSigned(string $providerEnvelopeId): \SplFileInfo
    {
        return TempFile::fromBytes(
            bytes: $this->client->downloadSignedZip($providerEnvelopeId),
            prefix: 'validsign-signed-',
            extension: 'zip',
        );
    }

    public function downloadSignedDocument(string $providerEnvelopeId, string $documentId): \SplFileInfo
    {
        // ValidSign keys documents on the caller's own Document::$id (we send it
        // verbatim as `documents[].id`), so it maps straight onto the endpoint.
        try {
            $bytes = $this->client->downloadSignedDocument($providerEnvelopeId, $documentId);
        } catch (ProviderNotFoundException $e) {
            // 404 here means "no such document on this package (yet)" rather than
            // "the package is gone" — surface the uniform, retryable signal so
            // callers polling for a freshly-signed document can back off.
            throw SignedDocumentUnavailableException::for(
                providerName: self::NAME,
                providerEnvelopeId: $providerEnvelopeId,
                documentId: $documentId,
                previous: $e,
            );
        }

        return TempFile::fromBytes(
            bytes: $bytes,
            prefix: 'validsign-signed-doc-',
            extension: 'pdf',
        );
    }

    public function hasAuditTrail(): bool
    {
        return true;
    }

    public function downloadAudit(string $providerEnvelopeId): \SplFileInfo
    {
        return TempFile::fromBytes(
            bytes: $this->client->downloadEvidenceSummary($providerEnvelopeId),
            prefix: 'validsign-evidence-',
            extension: 'pdf',
        );
    }

    public function getFieldValues(string $providerEnvelopeId): array
    {
        $summary = $this->client->getFieldSummary($providerEnvelopeId);

        $out = [];
        foreach ($summary as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $out[] = new FieldValue(
                documentId: is_string($entry['documentId'] ?? null) ? $entry['documentId'] : '',
                signerKey:  is_string($entry['signerId']   ?? null) ? $entry['signerId']   : '',
                fieldName:  is_string($entry['fieldName']  ?? null) ? $entry['fieldName']  : '',
                value:      is_string($entry['fieldValue'] ?? null) ? $entry['fieldValue'] : null,
            );
        }
        return $out;
    }

    public function cancel(string $providerEnvelopeId, ?string $reason = null): void
    {
        $this->client->deletePackage($providerEnvelopeId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRoles(Envelope $envelope): array
    {
        $sequential = $envelope->signingOrder === SigningOrder::Sequential;
        $roles = [];

        foreach ($envelope->signers as $signer) {
            [$first, $last] = $this->splitName($signer->name);

            $roles[] = [
                'id'    => $signer->key,
                'name'  => $signer->name,
                'type'  => 'SIGNER',
                'index' => $sequential ? max(0, $signer->order - 1) : 0,
                'signers' => [[
                    'id'        => $signer->key,
                    'email'     => $signer->email,
                    'firstName' => $first,
                    'lastName'  => $last,
                    'language'  => $signer->language ?? $this->config->defaultLanguage,
                ]],
            ];
        }

        return $roles;
    }

    /**
     * ValidSign text-tags reference signers positionally as `Signer1`, `Signer2`, …
     * in the order they appear in `roles[]`. Build a map from the SDK's arbitrary
     * signer keys to these positional tokens so the placeholder replacer can emit
     * the right role in each tag.
     *
     * @return array<string, string>
     */
    private function buildSignerRoleMap(Envelope $envelope): array
    {
        $map = [];
        $i = 1;
        foreach ($envelope->signers as $signer) {
            $map[$signer->key] = 'Signer' . $i++;
        }
        return $map;
    }

    /**
     * @param PreparedField[] $fields
     */
    private function assertFieldsResolvable(Envelope $envelope, Document $document, array $fields): void
    {
        foreach ($fields as $field) {
            if (!$envelope->signerByKey($field->signerKey) instanceof Signer) {
                throw new ProviderException(sprintf(
                    "Document '%s' references unknown signer key '%s' in field '%s'.",
                    $document->id,
                    $field->signerKey,
                    $field->fieldName,
                ));
            }
        }
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function splitName(string $fullName): array
    {
        $trim = trim($fullName);
        $pos = strpos($trim, ' ');
        if ($pos === false) {
            return [$trim, $trim];
        }
        return [substr($trim, 0, $pos), trim(substr($trim, $pos + 1))];
    }

    private function fileName(Document $document): string
    {
        $base = preg_replace('/[^A-Za-z0-9._\-]/', '_', $document->name) ?? $document->id;
        return $base . '.pdf';
    }
}
