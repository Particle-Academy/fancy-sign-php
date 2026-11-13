<?php

declare(strict_types=1);

namespace FancySign\Support;

/**
 * Who a request came from, as the host observed it. Personal data: kept in
 * the audit trail because the evidence asks for it, and listed in the host's
 * privacy notice. The user agent is capped, since a client chooses it.
 */
final class Context
{
    public const MAX_USER_AGENT = 512;

    public readonly ?string $ip;

    public readonly ?string $userAgent;

    public function __construct(?string $ip = null, ?string $userAgent = null)
    {
        $this->ip = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
        $ua = $userAgent === null ? null : (string) preg_replace('/[\x00-\x1F\x7F]/', '', mb_convert_encoding($userAgent, 'UTF-8', 'UTF-8'));
        $this->userAgent = $ua === null || $ua === '' ? null : mb_substr($ua, 0, self::MAX_USER_AGENT, 'UTF-8');
    }

    public static function none(): self
    {
        return new self();
    }
}
