<?php

declare(strict_types=1);

namespace FancySign\Mail;

/**
 * The host's mailer. This package sends nothing itself and takes no mail
 * dependency: Laravel's bridge implements this with `Mail`; any other host
 * implements these two methods.
 *
 * A code is passed here and NOWHERE else: it is never logged and never written
 * to an event.
 */
interface SignMailer
{
    public function sendLink(string $email, string $name, string $title, string $url): void;

    /** @param 'view'|'accept' $purpose */
    public function sendCode(string $email, string $name, string $title, string $code, string $purpose, int $expiresInSeconds): void;
}
