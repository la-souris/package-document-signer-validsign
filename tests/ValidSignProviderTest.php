<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\ValidSign\Tests;

use LaSouris\DocumentSigner\Sdk\Document\Document;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Exception\ProviderException;
use LaSouris\DocumentSigner\Sdk\Exception\SignedDocumentUnavailableException;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;
use LaSouris\DocumentSigner\Sdk\Signer\SigningOrder;
use LaSouris\DocumentSigner\ValidSign\Http\ValidSignClient;
use LaSouris\DocumentSigner\ValidSign\ValidSignConfig;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ValidSignProviderTest extends TestCase
{
    #[Test]
    public function send_uploads_pdf_and_returns_receipt_with_provider_name(): void
    {
        $envelope = $this->envelopeWithOneSigner();

        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['id' => 'pkg-123'])),
        ]);

        $receipt = $provider->send($envelope);

        self::assertSame(ValidSignProvider::NAME, $receipt->provider);
        self::assertSame('validsign', $receipt->provider);
        self::assertSame('pkg-123', $receipt->providerEnvelopeId);
        self::assertSame(EnvelopeStatus::Sent, $receipt->status);

        self::assertCount(1, $history);
        /** @var RequestInterface $request */
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('packages', (string) $request->getUri());

        $body = (string) $request->getBody();
        self::assertStringContainsString('name="payload"', $body);
        self::assertStringContainsString('name="file"; filename="NDA.pdf"', $body);
        // Rendered PDF carries the ValidSign text-tag literal. Server-side detection
        // scans the extracted text for `{{esl…}}` sequences; we assert on that here.
        self::assertStringContainsString('{{esl_sig:Signer1:Signature:size(200,50)}}', $body);

        $payload = $this->extractPayload($body);
        self::assertSame('PACKAGE', $payload['type']);
        self::assertSame('SENT', $payload['status']);
        self::assertSame('Mutual NDA', $payload['name']);
        self::assertSame('s1', $payload['roles'][0]['id']);
        self::assertSame('Jane', $payload['roles'][0]['signers'][0]['firstName']);
        self::assertSame('Doe', $payload['roles'][0]['signers'][0]['lastName']);
        self::assertTrue($payload['documents'][0]['extract']);
        // With text-tags the API payload no longer needs to describe approvals/fields —
        // ValidSign discovers them from the PDF automatically.
        self::assertArrayNotHasKey('approvals', $payload['documents'][0]);
    }

    #[Test]
    public function send_throws_when_response_lacks_package_id(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['unexpected' => true])),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('did not return a package id');

        $provider->send($this->envelopeWithOneSigner());
    }

    #[Test]
    public function send_throws_a_validation_exception_with_the_provider_message_for_422_responses(): void
    {
        [$provider] = $this->buildProvider([
            new Response(422, [], json_encode([
                'code'       => 422,
                'messageKey' => 'error.validation.invalidEmail',
                'message'    => 'The email field must be a valid email',
            ])),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderValidationException.');
        } catch (\LaSouris\DocumentSigner\Sdk\Exception\ProviderValidationException $e) {
            self::assertSame(422, $e->httpStatus);
            self::assertSame('error.validation.invalidEmail', $e->providerCode);
            self::assertSame('The email field must be a valid email', $e->providerMessage);
            self::assertStringContainsString('ValidSign POST /packages', $e->getMessage());
            self::assertStringContainsString('[422 error.validation.invalidEmail]', $e->getMessage());
            self::assertStringContainsString('The email field must be a valid email', $e->getMessage());
            self::assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function send_carries_the_package_id_when_the_error_body_echoes_one(): void
    {
        [$provider] = $this->buildProvider([
            new Response(409, [], json_encode([
                'code'       => 409,
                'messageKey' => 'error.package.alreadySent',
                'message'    => 'Package already sent',
                'packageId'  => 'pkg-echo-999',
            ])),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderValidationException.');
        } catch (\LaSouris\DocumentSigner\Sdk\Exception\ProviderValidationException $e) {
            self::assertSame('pkg-echo-999', $e->providerEnvelopeId);
        }
    }

    #[Test]
    public function send_throws_a_transient_exception_for_5xx_responses(): void
    {
        [$provider] = $this->buildProvider([
            new Response(503, [], 'Service Unavailable'),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderTransientException.');
        } catch (\LaSouris\DocumentSigner\Sdk\Exception\ProviderTransientException $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertTrue($e->isRetryable());
        }
    }

    #[Test]
    public function send_throws_a_rate_limit_exception_that_carries_retry_after(): void
    {
        [$provider] = $this->buildProvider([
            new Response(429, ['Retry-After' => '13'], json_encode([
                'code' => 429, 'messageKey' => 'error.throttled', 'message' => 'Slow down',
            ])),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderRateLimitException.');
        } catch (\LaSouris\DocumentSigner\Sdk\Exception\ProviderRateLimitException $e) {
            self::assertSame(429, $e->httpStatus);
            self::assertSame(13, $e->retryAfterSeconds);
            self::assertTrue($e->isRetryable());
        }
    }

    #[Test]
    public function get_field_values_returns_the_filled_form_data(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode([
                [
                    'signerId'   => 'counterparty',
                    'documentId' => 'nda',
                    'fieldId'    => 'fld-1',
                    'fieldName'  => 'iban',
                    'fieldValue' => 'NL91ABNA0417164300',
                ],
                [
                    'signerId'   => 'counterparty',
                    'documentId' => 'nda',
                    'fieldId'    => 'fld-2',
                    'fieldName'  => 'fullname',
                    'fieldValue' => 'Jane Doe',
                ],
                [
                    // Optional field left blank
                    'signerId'   => 'counterparty',
                    'documentId' => 'nda',
                    'fieldId'    => 'fld-3',
                    'fieldName'  => 'phone',
                    // no fieldValue → null in DTO
                ],
            ])),
        ]);

        $values = $provider->getFieldValues('pkg-42');

        self::assertStringContainsString(
            'packages/pkg-42/fieldSummary',
            (string) $history[0]['request']->getUri(),
        );
        self::assertCount(3, $values);
        self::assertSame('NL91ABNA0417164300', $values[0]->value);
        self::assertSame('iban', $values[0]->fieldName);
        self::assertSame('nda', $values[0]->documentId);
        self::assertSame('counterparty', $values[0]->signerKey);
        self::assertNull($values[2]->value, 'optional field left blank comes back as null');
    }

    #[Test]
    public function send_rejects_placeholder_referencing_unknown_signer(): void
    {
        $envelope = new Envelope(
            name:         'env',
            documents:    [new Document('d', 'D', '<p>{[signature:ghost:sig]}</p>')],
            signers:      [new Signer('real', 'Jane Doe', 'jane@example.com')],
            emailSubject: 'subj',
            signingOrder: SigningOrder::Parallel,
        );

        [$provider] = $this->buildProvider([]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("unknown signer key 'ghost'");

        $provider->send($envelope);
    }

    #[Test]
    public function get_status_maps_provider_status_strings(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['status' => 'COMPLETED'])),
            new Response(200, [], json_encode(['status' => 'DRAFT'])),
            new Response(200, [], json_encode(['status' => 'EXPIRED'])),
            new Response(200, [], json_encode(['status' => 'whatever'])),
        ]);

        self::assertSame(EnvelopeStatus::Completed, $provider->getStatus('p1'));
        self::assertSame(EnvelopeStatus::Draft,     $provider->getStatus('p2'));
        self::assertSame(EnvelopeStatus::Expired,   $provider->getStatus('p3'));
        self::assertSame(EnvelopeStatus::Unknown,   $provider->getStatus('p4'));
    }

    #[Test]
    public function download_signed_returns_the_zip_file(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], 'PK-FAKE-ZIP-BYTES'),
        ]);

        $file = $provider->downloadSigned('pkg-42');

        self::assertInstanceOf(\SplFileInfo::class, $file);
        self::assertSame('zip', $file->getExtension());
        self::assertSame('PK-FAKE-ZIP-BYTES', file_get_contents($file->getPathname()));

        self::assertCount(1, $history);
        self::assertStringContainsString(
            'packages/pkg-42/documents/zip',
            (string) $history[0]['request']->getUri(),
        );

        @unlink($file->getPathname());
    }

    #[Test]
    public function download_signed_document_returns_the_pdf_for_a_single_document(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], '%PDF-SIGNED-NDA'),
        ]);

        $file = $provider->downloadSignedDocument('pkg-42', 'nda');

        self::assertSame('pdf', $file->getExtension());
        self::assertSame('%PDF-SIGNED-NDA', file_get_contents($file->getPathname()));

        self::assertStringContainsString(
            'packages/pkg-42/documents/nda',
            (string) $history[0]['request']->getUri(),
        );

        @unlink($file->getPathname());
    }

    #[Test]
    public function download_signed_document_translates_a_404_to_a_retryable_unavailable_exception(): void
    {
        // The document isn't finalized yet (or the id is unknown): ValidSign 404s.
        [$provider] = $this->buildProvider([
            new Response(404, [], json_encode(['messages' => ['Document not found']])),
        ]);

        try {
            $provider->downloadSignedDocument('pkg-42', 'nda');
            self::fail('Expected SignedDocumentUnavailableException.');
        } catch (SignedDocumentUnavailableException $e) {
            self::assertTrue($e->isRetryable());
            self::assertSame('pkg-42', $e->providerEnvelopeId);
            self::assertStringContainsString('nda', $e->getMessage());
        }
    }

    #[Test]
    public function download_signed_document_percent_encodes_the_document_id(): void
    {
        // ValidSign document IDs are usually opaque UUIDs, but the caller
        // controls Document::$id, so the client must be robust to punctuation.
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], 'x'),
        ]);

        $provider->downloadSignedDocument('pkg 1', 'doc/with?slash');

        self::assertStringContainsString(
            'packages/pkg%201/documents/doc%2Fwith%3Fslash',
            (string) $history[0]['request']->getUri(),
        );
    }

    #[Test]
    public function has_audit_trail_is_true_because_validsign_ships_the_evidence_summary(): void
    {
        [$provider] = $this->buildProvider([]);

        self::assertTrue($provider->hasAuditTrail());
    }

    #[Test]
    public function download_audit_returns_the_evidence_summary_pdf(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], '%PDF-EVIDENCE-SUMMARY'),
        ]);

        $file = $provider->downloadAudit('pkg-42');

        self::assertSame('pdf', $file->getExtension());
        self::assertSame('%PDF-EVIDENCE-SUMMARY', file_get_contents($file->getPathname()));

        self::assertStringContainsString(
            'packages/pkg-42/evidence/summary',
            (string) $history[0]['request']->getUri(),
        );

        @unlink($file->getPathname());
    }

    /**
     * @return array{0: ValidSignProvider, 1: \ArrayObject<int, array<string, mixed>>}
     */
    private function buildProvider(array $responses): array
    {
        $mock = new MockHandler($responses);
        $history = new \ArrayObject();
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);

        $config = new ValidSignConfig(apiKey: 'k', baseUrl: 'https://my.validsign.nl/api');
        $client = new ValidSignClient($config, $http);

        $provider = new ValidSignProvider(
            $config,
            client: $client,
            pdfRenderer: $this->fakePdfRenderer(),
        );

        return [$provider, $history];
    }

    private function envelopeWithOneSigner(): Envelope
    {
        return new Envelope(
            name:         'Mutual NDA',
            documents:    [new Document(
                id:   'nda',
                name: 'NDA',
                html: '<p>Signed: {[signature:s1:sig]}</p>',
            )],
            signers:      [new Signer('s1', 'Jane Doe', 'jane@example.com')],
            emailSubject: 'Please sign the NDA',
            signingOrder: SigningOrder::Parallel,
        );
    }

    private function fakePdfRenderer(): PdfRenderer
    {
        return new class implements PdfRenderer {
            public function render(string $html, ?\LaSouris\DocumentSigner\Sdk\Pdf\PageDecoration $decoration = null): string
            {
                return '%PDF-FAKE' . $html;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPayload(string $multipartBody): array
    {
        if (!preg_match('/name="payload".*?\r\n\r\n(\{.*?\})\r\n--/s', $multipartBody, $m)) {
            self::fail('Could not extract JSON payload from multipart body.');
        }
        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
