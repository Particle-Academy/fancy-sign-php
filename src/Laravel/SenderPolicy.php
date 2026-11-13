<?php

declare(strict_types=1);

namespace FancySign\Laravel;

use Illuminate\Http\Request;

/**
 * Who may see and act on an envelope through the sender routes. The default,
 * {@see CreatorOnlyPolicy}, lets a sender reach only the envelopes they
 * created. A host with teams or organisations binds its own.
 */
interface SenderPolicy
{
    /** The actor string recorded in the audit trail, or null when nobody is signed in. */
    public function actor(Request $request): ?string;

    /** The organisation new envelopes belong to (null for none). */
    public function orgId(Request $request): ?string;

    /** @param array<string, mixed> $envelope wire shape */
    public function allows(Request $request, array $envelope): bool;
}
