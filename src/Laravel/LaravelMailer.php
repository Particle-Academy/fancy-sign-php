<?php

declare(strict_types=1);

namespace FancySign\Laravel;

use FancySign\Mail\SignMailer;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;

/**
 * Plain-text mail through the host's own mailer. A host wanting branded mail
 * binds its own {@see SignMailer}.
 */
final class LaravelMailer implements SignMailer
{
    public function __construct(private readonly Mailer $mailer) {}

    public function sendLink(string $email, string $name, string $title, string $url): void
    {
        $body = "Hello {$name},\n\nYou have been asked to review and sign \"{$title}\".\n\nOpen it here: {$url}\n\n"
            ."We will email you a one-time code when you open the link. If you did not expect this, you can ignore this email.\n";
        $this->mailer->raw($body, fn (Message $m) => $m->to($email, $name)->subject("Please sign: {$title}"));
    }

    public function sendCode(string $email, string $name, string $title, string $code, string $purpose, int $expiresInSeconds): void
    {
        $minutes = max(1, intdiv($expiresInSeconds, 60));
        $what = $purpose === 'accept' ? 'sign' : 'view';
        $body = "Your code to {$what} \"{$title}\" is {$code}.\n\nIt expires in {$minutes} minutes. Never share it: nobody from the sender will ask you for it.\n";
        $this->mailer->raw($body, fn (Message $m) => $m->to($email, $name)->subject("Your code to {$what}: {$title}"));
    }
}
