<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\ValidSign\Tests\Placeholder;

use LaSouris\DocumentSigner\Sdk\Placeholder\PlaceholderParser;
use LaSouris\DocumentSigner\ValidSign\Placeholder\ValidSignPlaceholderReplacer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidSignPlaceholderReplacerTest extends TestCase
{
    #[Test]
    public function it_emits_native_esl_text_tags_using_the_signer_role_map(): void
    {
        $html = '<p>{[signature:counterparty:sig]} on {[date:counterparty:signdate]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $replacer = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['counterparty' => 'Signer1']);

        $prepared = $replacer->replace($html, $parsed);

        self::assertCount(2, $prepared->fields);
        self::assertSame(
            '{{esl_sig:Signer1:Signature:size(200,50)}}',
            $prepared->fields[0]->anchorString,
        );
        self::assertSame(
            '{{esl_signdate:Signer1:SigningDate:size(120,20)}}',
            $prepared->fields[1]->anchorString,
        );
        self::assertStringContainsString('{{esl_sig:Signer1:Signature:size(200,50)}}', $prepared->html);
        self::assertStringContainsString('{{esl_signdate:Signer1:SigningDate:size(120,20)}}', $prepared->html);
    }

    #[Test]
    public function it_marks_text_and_checkbox_fields_as_required(): void
    {
        $html = '<p>{[text:s1:name]}{[checkbox:s1:agree]}{[signature:s1:sig]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['s1' => 'Signer1'])
            ->replace($html, $parsed);

        self::assertSame('{{*esl_name:Signer1:TextField:size(200,20)}}', $prepared->fields[0]->anchorString);
        self::assertSame('{{*esl_agree:Signer1:Checkbox:size(20,20)}}', $prepared->fields[1]->anchorString);
        // Signatures are implicitly required per ValidSign; no `*` prefix.
        self::assertSame('{{esl_sig:Signer1:Signature:size(200,50)}}', $prepared->fields[2]->anchorString);
    }

    #[Test]
    public function positional_signer_roles_map_correctly_for_multiple_signers(): void
    {
        $html = '<p>{[signature:customer:sig]} and {[signature:salesrep:sig]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['customer' => 'Signer1', 'salesrep' => 'Signer2'])
            ->replace($html, $parsed);

        self::assertStringContainsString('Signer1:Signature', $prepared->fields[0]->anchorString);
        self::assertStringContainsString('Signer2:Signature', $prepared->fields[1]->anchorString);
    }

    #[Test]
    public function html_wrapper_does_not_escape_the_curly_braces(): void
    {
        // The text-tag detector on ValidSign's server looks for literal `{{esl…}}`
        // in the extracted PDF text. If we HTML-escaped `{` and `}` here we'd
        // break server-side detection.
        $html = '<p>{[signature:s1:sig]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['s1' => 'Signer1'])
            ->replace($html, $parsed);

        self::assertStringContainsString('{{esl_sig:Signer1:Signature:size(200,50)}}', $prepared->html);
        self::assertStringNotContainsString('&#123;', $prepared->html);
        self::assertStringNotContainsString('&#125;', $prepared->html);
    }

    #[Test]
    public function optional_signatures_use_the_question_mark_prefix(): void
    {
        $html = '<p>{[?signature:s1:sig]}{[?initials:s1:i]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['s1' => 'Signer1'])
            ->replace($html, $parsed);

        self::assertSame('{{?esl_sig:Signer1:Signature:size(200,50)}}', $prepared->fields[0]->anchorString);
        self::assertSame('{{?esl_i:Signer1:initials:size(100,30)}}', $prepared->fields[1]->anchorString);
    }

    #[Test]
    public function optional_text_and_checkbox_drop_the_required_asterisk(): void
    {
        $html = '<p>{[?text:s1:notes]}{[?checkbox:s1:opt_in]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['s1' => 'Signer1'])
            ->replace($html, $parsed);

        self::assertSame('{{esl_notes:Signer1:TextField:size(200,20)}}', $prepared->fields[0]->anchorString);
        self::assertSame('{{esl_opt_in:Signer1:Checkbox:size(20,20)}}', $prepared->fields[1]->anchorString);
    }

    #[Test]
    public function date_ignores_the_required_flag_because_it_is_auto_populated(): void
    {
        $html = '<p>{[?date:s1:d]}{[date:s1:d2]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new ValidSignPlaceholderReplacer())
            ->withSignerRoleMap(['s1' => 'Signer1'])
            ->replace($html, $parsed);

        self::assertStringStartsWith('{{esl_', $prepared->fields[0]->anchorString, 'no ? prefix for date');
        self::assertStringStartsWith('{{esl_', $prepared->fields[1]->anchorString, 'no * prefix for date');
    }
}
