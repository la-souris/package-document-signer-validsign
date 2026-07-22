<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\ValidSign;

final readonly class ValidSignConfig
{
    /**
     * @param string $apiKey         Sandbox/production API key issued by ValidSign.
     * @param string $baseUrl        Base URL of the ValidSign tenant, e.g. `https://my.validsign.nl/api`.
     * @param string $defaultLanguage IETF language tag (e.g. `nl`, `en`).
     * @param int    $timeoutSeconds  HTTP timeout for non-upload requests.
     * @param int    $uploadTimeoutSeconds HTTP timeout for envelope create / file upload.
     */
    public function __construct(
        public string $apiKey,
        public string $baseUrl = 'https://my.validsign.nl/api',
        public string $defaultLanguage = 'nl',
        public int    $timeoutSeconds = 15,
        public int    $uploadTimeoutSeconds = 60,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('ValidSign API key must be non-empty.');
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new \InvalidArgumentException("ValidSign base URL must be a full http(s) URL, got: '{$baseUrl}'");
        }
    }

    public function trimmedBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }
}
