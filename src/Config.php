<?php

declare(strict_types=1);

namespace FancySign;

/**
 * Every limit is a setting with a secure default. A host overrides any of
 * them (Laravel: `config/fancy-sign.php`); nothing below is fixed in code.
 */
final class Config
{
    public const DEFAULTS = [
        'otp' => [
            'ttl_seconds' => 600,          // a code lives 10 minutes
            'max_attempts' => 5,           // then it locks
            'sends_per_window' => 5,       // per signer and purpose
            'sends_per_ip' => 30,          // per IP, across signers
            'window_seconds' => 3600,
        ],
        'session_ttl_seconds' => 1800,     // the signer session a view code opens
        'max_signers' => 50,
        'max_title' => 200,
        'max_name' => 200,
        'max_decline_reason' => 1000,
        'max_document_blocks' => 5000,
        'mark' => [
            'max_strokes' => 64,
            'max_points' => 2048,
            'max_typed' => 128,
        ],
        'page_views' => [
            'per_page' => 20,              // browser-reported views kept per signer and page
            'per_signer' => 1000,
            'max_ms' => 86400000,
        ],
        'consent' => [
            'version' => '1',
            'text' => 'I agree to use electronic records and signatures for this document. I can ask the sender for a paper copy, and I can withdraw this consent before I sign by declining.',
        ],
        'adoption_text' => 'I adopt this as my signature, and I intend to sign this document.',
        'signature_page_title' => 'Signatures',
    ];

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $overrides */
    public function __construct(array $overrides = [])
    {
        $this->values = array_replace_recursive(self::DEFAULTS, $overrides);
    }

    public function get(string $dotted): mixed
    {
        $v = $this->values;
        foreach (explode('.', $dotted) as $key) {
            if (! is_array($v) || ! array_key_exists($key, $v)) {
                throw new \InvalidArgumentException("Unknown fancy-sign setting {$dotted}.");
            }
            $v = $v[$key];
        }

        return $v;
    }

    public function int(string $dotted): int
    {
        $v = $this->get($dotted);
        if (! is_int($v) || $v < 0) {
            throw new \InvalidArgumentException("fancy-sign setting {$dotted} must be a non-negative integer.");
        }

        return $v;
    }

    public function consentVersion(): string
    {
        return (string) $this->get('consent.version');
    }

    public function consentText(): string
    {
        return (string) $this->get('consent.text');
    }

    public function adoptionText(): string
    {
        return (string) $this->get('adoption_text');
    }
}
