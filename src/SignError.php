<?php

declare(strict_types=1);

namespace FancySign;

use RuntimeException;

/**
 * Every refusal the package makes, with a code from a CLOSED set. The wire
 * answer is `{"error": {"code", "message"}}` with `status`.
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

    /** @return array{error: array{code: string, message: string}} */
    public function toArray(): array
    {
        return ['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()]];
    }
}
