<?php

declare(strict_types=1);

namespace FancySign\Tests\Support;

use FancySign\Mail\SignMailer;

/** Captures what would be mailed, so a test can act as the signer's inbox. */
final class FakeMailer implements SignMailer
{
    /** @var list<array{kind: string, to: string, name: string, title: string, url?: string, code?: string, purpose?: string}> */
    public array $sent = [];

    public function sendLink(string $email, string $name, string $title, string $url): void
    {
        $this->sent[] = ['kind' => 'link', 'to' => $email, 'name' => $name, 'title' => $title, 'url' => $url];
    }

    public function sendCode(string $email, string $name, string $title, string $code, string $purpose, int $expiresInSeconds): void
    {
        $this->sent[] = ['kind' => 'code', 'to' => $email, 'name' => $name, 'title' => $title, 'code' => $code, 'purpose' => $purpose];
    }

    /** The token in the latest link mailed to `$email`. */
    public function token(string $email): string
    {
        foreach (array_reverse($this->sent) as $m) {
            if ($m['kind'] === 'link' && $m['to'] === $email) {
                return substr((string) $m['url'], strrpos((string) $m['url'], '/') + 1);
            }
        }
        throw new \RuntimeException("no link mailed to {$email}");
    }

    public function code(string $email, string $purpose): string
    {
        foreach (array_reverse($this->sent) as $m) {
            if ($m['kind'] === 'code' && $m['to'] === $email && $m['purpose'] === $purpose) {
                return (string) $m['code'];
            }
        }
        throw new \RuntimeException("no {$purpose} code mailed to {$email}");
    }
}
