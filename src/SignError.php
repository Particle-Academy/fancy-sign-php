<?php

declare(strict_types=1);

namespace FancySign;

use RuntimeException;

/**
 * Every refusal the package makes, with a code from a CLOSED set. On the wire
 * it is the kit-wide error envelope (CC-fancy-0031, fancy-conformance
 * `suites/http/SPEC.md` section 1), see {@see envelope()}.
 *
 * Redaction: an unknown, expired or wrong code all surface as `otp_invalid`,
 * so a caller cannot tell which; the precise reason is in the `otp.failed`
 * event. Only `otp_locked` is distinct, because the signer must request a new
 * code.
 */
final class SignError extends RuntimeException
{
    /** @var array<string, int> code => HTTP status */
    public const CODES = [
        'invalid_request' => 422,
        'not_found' => 404,
        'forbidden' => 403,
        'link_invalid' => 404,
        'otp_invalid' => 422,
        'otp_locked' => 423,
        'otp_rate_limited' => 429,
        'session_required' => 401,
        'consent_required' => 409,
        'not_viewed' => 409,
        'fields_incomplete' => 422,
        'mark_invalid' => 422,
        'envelope_not_open' => 409,
        'envelope_not_draft' => 409,
        'already_signed' => 409,
        'already_declined' => 409,
        'step_up_required' => 401,
        'limit_exceeded' => 429,
    ];

    public readonly int $status;

    public function __construct(public readonly string $errorCode, string $message)
    {
        if (! isset(self::CODES[$errorCode])) {
            throw new \InvalidArgumentException("Unknown sign error code {$errorCode}.");
        }
        $this->status = self::CODES[$errorCode];
        parent::__construct($message);
    }

    /**
     * The kit-wide error envelope. A 422 is `validation_failed` with this
     * error's code as its one item (the envelope keeps `errors` for 422s and
     * only for them); every other status carries the code at the top level.
     *
     * @return array<string, mixed>
     */
    public function envelope(string $requestId): array
    {
        if ($this->status === 422) {
            return ['error' => 'validation_failed', 'message' => $this->getMessage(),
                'errors' => [['error' => $this->errorCode, 'message' => $this->getMessage()]], 'requestId' => $requestId];
        }

        return ['error' => $this->errorCode, 'message' => $this->getMessage(), 'requestId' => $requestId];
    }
}
