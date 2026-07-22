<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\ValidSign\Http;

use LaSouris\DocumentSigner\Sdk\Exception\ProviderException;
use LaSouris\DocumentSigner\Sdk\Exception\ProviderTransientException;
use LaSouris\DocumentSigner\ValidSign\ValidSignConfig;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;

final class ValidSignClient
{
    private ClientInterface $http;

    public function __construct(
        private readonly ValidSignConfig $config,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => $this->config->trimmedBaseUrl() . '/',
            'timeout'  => $this->config->timeoutSeconds,
            'headers'  => [
                'Authorization' => 'Basic ' . $this->config->apiKey,
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * Create a package with one or more PDF documents attached as multipart parts.
     *
     * @param array<string, mixed>           $payload     Package JSON payload (roles, documents, ...).
     * @param array<array{name:string, contents:string}> $files Each entry contributes one `file` multipart part.
     * @return array<string, mixed> Parsed JSON response.
     */
    public function createPackage(array $payload, array $files): array
    {
        $multipart = [
            [
                'name'     => 'payload',
                'contents' => json_encode($payload, JSON_THROW_ON_ERROR),
                'headers'  => ['Content-Type' => 'application/json'],
            ],
        ];

        foreach ($files as $file) {
            $multipart[] = [
                'name'     => 'file',
                'filename' => $file['name'],
                'contents' => Utils::streamFor($file['contents']),
                'headers'  => ['Content-Type' => 'application/pdf'],
            ];
        }

        return $this->request('POST', 'packages', [
            'multipart' => $multipart,
            'timeout'   => $this->config->uploadTimeoutSeconds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPackage(string $packageId): array
    {
        return $this->request('GET', 'packages/' . rawurlencode($packageId));
    }

    public function downloadSignedZip(string $packageId): string
    {
        return $this->requestRaw('GET', 'packages/' . rawurlencode($packageId) . '/documents/zip');
    }

    public function downloadSignedDocument(string $packageId, string $documentId): string
    {
        return $this->requestRaw(
            'GET',
            'packages/' . rawurlencode($packageId) . '/documents/' . rawurlencode($documentId),
        );
    }

    public function downloadEvidenceSummary(string $packageId): string
    {
        return $this->requestRaw('GET', 'packages/' . rawurlencode($packageId) . '/evidence/summary');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFieldSummary(string $packageId): array
    {
        $body = $this->requestRaw('GET', 'packages/' . rawurlencode($packageId) . '/fieldSummary');
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException(
                'ValidSign returned non-JSON response for GET /packages/{id}/fieldSummary.',
                providerBody: $body,
                previous: $e,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function deletePackage(string $packageId): void
    {
        $this->request('DELETE', 'packages/' . rawurlencode($packageId));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $body = $this->requestRaw($method, $path, $options);
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException(
                "ValidSign returned non-JSON response for {$method} {$path}.",
                providerBody: $body,
                previous: $e,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function requestRaw(string $method, string $path, array $options = []): string
    {
        try {
            $response = $this->http->request($method, $path, $options);
        } catch (RequestException $e) {
            throw $this->translateHttpError($method, $path, $e);
        } catch (GuzzleException $e) {
            throw new ProviderTransientException(
                message: sprintf('ValidSign %s /%s transport error: %s', $method, ltrim($path, '/'), $e->getMessage()),
                previous: $e,
            );
        }

        return (string) $response->getBody();
    }

    private function translateHttpError(string $method, string $path, RequestException $e): ProviderException
    {
        $response = $e->getResponse();
        $status = $response?->getStatusCode();
        $body = $response?->getBody()?->getContents();

        [$providerCode, $providerMessage, $envelopeId] = $this->parseErrorPayload($body);

        if ($status === null) {
            // No response received — transport-level failure Guzzle wrapped in RequestException.
            return new ProviderTransientException(
                message: sprintf('ValidSign %s /%s transport error: %s', $method, ltrim($path, '/'), $e->getMessage()),
                providerBody: $body,
                previous: $e,
                providerEnvelopeId: $envelopeId,
            );
        }

        return ProviderException::fromHttpStatus(
            providerName: 'ValidSign',
            method: $method,
            path: $path,
            status: $status,
            providerCode: $providerCode,
            providerMessage: $providerMessage,
            providerBody: $body,
            previous: $e,
            retryAfterSeconds: $this->parseRetryAfter($response?->getHeaderLine('Retry-After')),
            providerEnvelopeId: $envelopeId,
        );
    }

    /**
     * ValidSign error responses look like `{ "code": 422, "messageKey": "...", "message": "..." }`;
     * some (typically post-creation failures) also echo a `packageId`.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} Tuple of (providerCode, providerMessage, providerEnvelopeId).
     */
    private function parseErrorPayload(?string $body): array
    {
        if (!is_string($body) || $body === '') {
            return [null, null, null];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, null, null];
        }

        if (!is_array($decoded)) {
            return [null, null, null];
        }

        $code = is_string($decoded['messageKey'] ?? null) ? $decoded['messageKey'] : null;
        $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : null;
        $envelopeId = is_string($decoded['packageId'] ?? null) && $decoded['packageId'] !== ''
            ? $decoded['packageId']
            : (is_string($decoded['id'] ?? null) && $decoded['id'] !== '' ? $decoded['id'] : null);

        return [$code, $message, $envelopeId];
    }

    private function parseRetryAfter(?string $header): ?int
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return (int) $header;
        }
        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }
        return max(0, $timestamp - time());
    }
}
