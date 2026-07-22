<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\ValidSign\Tests\Webhook;

use LaSouris\DocumentSigner\ValidSign\Webhook\EventType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class EventTypeTest extends TestCase
{
    #[Test]
    public function it_covers_the_documented_validsign_events(): void
    {
        // Guards against a case being dropped or renamed by accident. The
        // string values MUST match ValidSign's callback vocabulary verbatim.
        $expected = [
            'DOCUMENT_SIGNED',
            'DOCUMENT_VIEWED',
            'EMAIL_BOUNCE',
            'KBA_FAILURE',
            'PACKAGE_ACTIVATE',
            'PACKAGE_ARCHIVE',
            'PACKAGE_ATTACHMENT',
            'PACKAGE_COMPLETE',
            'PACKAGE_CREATE',
            'PACKAGE_DEACTIVATE',
            'PACKAGE_DECLINE',
            'PACKAGE_DELETE',
            'PACKAGE_EXPIRE',
            'PACKAGE_OPT_OUT',
            'PACKAGE_READY_FOR_COMPLETE',
            'PACKAGE_RESTORE',
            'PACKAGE_TRASH',
            'ROLE_REASSIGN',
            'SIGNER_COMPLETE',
            'SIGNER_LOCKED',
            'TEMPLATE_CREATE',
        ];

        // Unknown is a synthetic sentinel, not part of ValidSign's vocabulary.
        $realCases = array_filter(EventType::cases(), static fn (EventType $c) => $c !== EventType::Unknown);
        $actual = array_map(static fn (EventType $c) => $c->value, $realCases);

        sort($expected);
        sort($actual);

        self::assertSame($expected, array_values($actual));
    }

    #[Test]
    #[DataProvider('rawStrings')]
    public function raw_strings_resolve_via_from(string $raw, EventType $expected): void
    {
        self::assertSame($expected, EventType::from($raw));
    }

    /**
     * @return iterable<string, array{string, EventType}>
     */
    public static function rawStrings(): iterable
    {
        yield 'complete'            => ['PACKAGE_COMPLETE',            EventType::PackageComplete];
        yield 'decline'             => ['PACKAGE_DECLINE',             EventType::PackageDecline];
        yield 'expire'              => ['PACKAGE_EXPIRE',              EventType::PackageExpire];
        yield 'ready_for_complete'  => ['PACKAGE_READY_FOR_COMPLETE',  EventType::PackageReadyForComplete];
        yield 'signer_locked'       => ['SIGNER_LOCKED',               EventType::SignerLocked];
    }

    #[Test]
    #[TestWith(['name'])]
    #[TestWith(['event'])]
    #[TestWith(['type'])]
    #[TestWith(['eventName'])]
    #[TestWith(['eventType'])]
    public function try_from_payload_picks_up_the_first_recognised_key(string $key): void
    {
        $payload = [$key => 'PACKAGE_COMPLETE', 'packageId' => 'pkg-1'];

        self::assertSame(EventType::PackageComplete, EventType::tryFromPayload($payload));
    }

    #[Test]
    public function try_from_payload_returns_the_unknown_case_for_unrecognised_values(): void
    {
        self::assertSame(EventType::Unknown, EventType::tryFromPayload(['name' => 'MYSTERY_EVENT']));
        self::assertSame(EventType::Unknown, EventType::tryFromPayload(['name' => '']));
        self::assertSame(EventType::Unknown, EventType::tryFromPayload([]));
        self::assertSame(EventType::Unknown, EventType::tryFromPayload(['name' => 42])); // non-string
    }

    #[Test]
    public function the_unknown_case_is_semantically_inert(): void
    {
        self::assertFalse(EventType::Unknown->isCompleted());
        self::assertFalse(EventType::Unknown->isDeclined());
        self::assertFalse(EventType::Unknown->isFailure());
        self::assertFalse(EventType::Unknown->isProgress());
    }

    #[Test]
    public function try_from_payload_prefers_the_first_recognised_key(): void
    {
        // If both `name` and `event` are present, `name` wins because it's
        // checked first — matches the OneSpan-derived ValidSign convention.
        $payload = ['name' => 'PACKAGE_COMPLETE', 'event' => 'PACKAGE_CREATE'];

        self::assertSame(EventType::PackageComplete, EventType::tryFromPayload($payload));
    }

    #[Test]
    public function every_case_implements_the_sdk_webhook_event_interface(): void
    {
        foreach (EventType::cases() as $case) {
            self::assertInstanceOf(\LaSouris\DocumentSigner\Sdk\Webhook\WebhookEvent::class, $case);
            self::assertSame($case->value, $case->value());
        }
    }

    #[Test]
    #[TestWith([EventType::PackageComplete, true])]
    #[TestWith([EventType::PackageDecline, false])]
    #[TestWith([EventType::PackageExpire, false])]
    #[TestWith([EventType::SignerComplete, false])]
    public function is_completed_only_fires_for_package_complete(EventType $case, bool $expected): void
    {
        self::assertSame($expected, $case->isCompleted());
    }

    #[Test]
    #[TestWith([EventType::PackageDecline, true])]
    #[TestWith([EventType::PackageOptOut, true])]
    #[TestWith([EventType::PackageComplete, false])]
    #[TestWith([EventType::PackageExpire, false])]
    public function is_declined_covers_decline_and_opt_out(EventType $case, bool $expected): void
    {
        self::assertSame($expected, $case->isDeclined());
    }

    #[Test]
    #[TestWith([EventType::EmailBounce, true])]
    #[TestWith([EventType::KbaFailure, true])]
    #[TestWith([EventType::SignerLocked, true])]
    #[TestWith([EventType::PackageExpire, true])]
    #[TestWith([EventType::PackageComplete, false])]
    #[TestWith([EventType::PackageDecline, false])]
    public function is_failure_covers_technical_failure_cases(EventType $case, bool $expected): void
    {
        self::assertSame($expected, $case->isFailure());
    }

    #[Test]
    #[TestWith([EventType::DocumentViewed, true])]
    #[TestWith([EventType::DocumentSigned, true])]
    #[TestWith([EventType::SignerComplete, true])]
    #[TestWith([EventType::PackageReadyForComplete, true])]
    #[TestWith([EventType::PackageComplete, false])]
    #[TestWith([EventType::PackageCreate, false])]
    public function is_progress_covers_mid_flow_events(EventType $case, bool $expected): void
    {
        self::assertSame($expected, $case->isProgress());
    }

    #[Test]
    public function the_four_categorisers_are_non_overlapping(): void
    {
        // Any single case should match at most one is…() predicate. Guards
        // against a future edit that puts, say, PackageExpire in both
        // isFailure() and isDeclined().
        foreach (EventType::cases() as $case) {
            $hits = (int) $case->isCompleted()
                  + (int) $case->isDeclined()
                  + (int) $case->isFailure()
                  + (int) $case->isProgress();
            self::assertLessThanOrEqual(1, $hits, "Multiple predicates fire for {$case->value}");
        }
    }
}
