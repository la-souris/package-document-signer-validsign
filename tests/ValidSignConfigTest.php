<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\ValidSign\Tests;

use LaSouris\DocumentSigner\ValidSign\ValidSignConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidSignConfigTest extends TestCase
{
    #[Test]
    public function it_accepts_a_complete_config(): void
    {
        $config = new ValidSignConfig(
            apiKey: 'k',
            baseUrl: 'https://my.validsign.nl/api/',
        );

        self::assertSame('k', $config->apiKey);
        self::assertSame('https://my.validsign.nl/api', $config->trimmedBaseUrl());
    }

    #[Test]
    public function it_rejects_empty_api_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ValidSignConfig(apiKey: '');
    }

    #[Test]
    public function it_rejects_non_http_base_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ValidSignConfig(apiKey: 'k', baseUrl: 'tcp://example');
    }
}
