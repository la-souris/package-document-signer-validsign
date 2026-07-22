<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\ValidSign\Webhook;

use LaSouris\DocumentSigner\Sdk\Webhook\WebhookEvent;

/**
 * Every callback event ValidSign can emit for a package.
 *
 * String values match ValidSign's own enum verbatim so a raw payload value
 * like `"PACKAGE_COMPLETE"` translates via `EventType::from()`. Implements
 * {@see WebhookEvent} so application code can dispatch on the semantic
 * category (`isCompleted()`, `isDeclined()`, …) without knowing the
 * provider-native token.
 *
 * Consumers typically receive one of these on the Laravel package's
 * {@see \LaSouris\DocumentSigner\Laravel\Events\DocumentSignerWebhookReceived}
 * event and use {@see tryFromPayload()} to resolve the case from the
 * decoded JSON body.
 */
enum EventType: string implements WebhookEvent
{
    /** Signer viewed a single document within a package. */
    case DocumentViewed         = 'DOCUMENT_VIEWED';

    /** Signer completed signing a single document within a package. */
    case DocumentSigned         = 'DOCUMENT_SIGNED';

    /** Signer's notification email bounced. */
    case EmailBounce            = 'EMAIL_BOUNCE';

    /** Signer failed the knowledge-based authentication challenge. */
    case KbaFailure             = 'KBA_FAILURE';

    /** Package moved from draft/inactive to active (signable). */
    case PackageActivate        = 'PACKAGE_ACTIVATE';

    /** Package moved to the archive. */
    case PackageArchive         = 'PACKAGE_ARCHIVE';

    /** A signer uploaded an attachment tied to the package. */
    case PackageAttachment      = 'PACKAGE_ATTACHMENT';

    /** All signers finished; the package is complete. */
    case PackageComplete        = 'PACKAGE_COMPLETE';

    /** Package was created. */
    case PackageCreate          = 'PACKAGE_CREATE';

    /** Package was deactivated. */
    case PackageDeactivate      = 'PACKAGE_DEACTIVATE';

    /** A signer declined to sign; the package is stopped. */
    case PackageDecline         = 'PACKAGE_DECLINE';

    /** Package was deleted. */
    case PackageDelete          = 'PACKAGE_DELETE';

    /** Package hit its due date without completing. */
    case PackageExpire          = 'PACKAGE_EXPIRE';

    /** A signer opted out of signing. */
    case PackageOptOut          = 'PACKAGE_OPT_OUT';

    /** Every required signature is in, awaiting final completion step. */
    case PackageReadyForComplete = 'PACKAGE_READY_FOR_COMPLETE';

    /** Package was restored from trash / archive. */
    case PackageRestore         = 'PACKAGE_RESTORE';

    /** Package was moved to trash. */
    case PackageTrash           = 'PACKAGE_TRASH';

    /** A role was re-assigned from one signer to another. */
    case RoleReassign           = 'ROLE_REASSIGN';

    /** A signer finished every field they were responsible for. */
    case SignerComplete         = 'SIGNER_COMPLETE';

    /** A signer was locked out (e.g. too many KBA attempts). */
    case SignerLocked           = 'SIGNER_LOCKED';

    /** A template was created. */
    case TemplateCreate         = 'TEMPLATE_CREATE';

    /**
     * A verified callback whose token ValidSign sent but this enum doesn't
     * model. Synthetic — ValidSign never emits this value; {@see tryFromPayload()}
     * resolves to it so callers always get a non-null event. All four `is…()`
     * predicates are `false`; the raw body remains on the dispatched event.
     */
    case Unknown                = '__UNKNOWN__';

    public function value(): string
    {
        return $this->value;
    }

    public function isCompleted(): bool
    {
        return $this === self::PackageComplete;
    }

    public function isDeclined(): bool
    {
        return match ($this) {
            self::PackageDecline, self::PackageOptOut => true,
            default                                   => false,
        };
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::EmailBounce,
            self::KbaFailure,
            self::SignerLocked,
            self::PackageExpire => true,
            default             => false,
        };
    }

    public function isProgress(): bool
    {
        return match ($this) {
            self::DocumentViewed,
            self::DocumentSigned,
            self::SignerComplete,
            self::PackageReadyForComplete => true,
            default                       => false,
        };
    }

    /**
     * Best-effort resolution from a decoded callback body. ValidSign has been
     * observed to key the event under one of several field names depending on
     * tenant configuration, so we try each in turn and stop at the first
     * recognised value.
     *
     * Always returns a case: a token that matches no known event resolves to
     * {@see self::Unknown}, so callers never have to null-check the result.
     *
     * @param array<string, mixed> $payload
     */
    public static function tryFromPayload(array $payload): self
    {
        foreach (['name', 'event', 'type', 'eventName', 'eventType'] as $key) {
            $raw = $payload[$key] ?? null;
            if (is_string($raw)) {
                $case = self::tryFrom($raw);
                if ($case !== null) {
                    return $case;
                }
            }
        }
        return self::Unknown;
    }
}
