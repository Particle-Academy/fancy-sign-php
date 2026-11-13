<?php

declare(strict_types=1);

namespace FancySign\StepUp;

/**
 * The optional step-up factor at accept (`settings.step_up = passkey`). The
 * host verifies a passkey assertion whose challenge binds the as-sent hash,
 * typically with fancy-passkeys, and returns evidence to record. This package
 * takes no WebAuthn dependency; with no verifier bound, `passkey` is refused
 * when an envelope is created.
 */
interface StepUpVerifier
{
    /**
     * @param  array<string, mixed>  $assertion  what the signer's browser produced
     * @return array<string, string|int|bool>|null evidence (no floats) on success, null on failure
     */
    public function verify(string $envelopeId, string $signerId, string $asSentSha256, array $assertion): ?array;
}
