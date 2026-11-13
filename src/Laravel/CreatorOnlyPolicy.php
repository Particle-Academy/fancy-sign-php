<?php

declare(strict_types=1);

namespace FancySign\Laravel;

use Illuminate\Http\Request;

/** A sender reaches the envelopes they created, and nothing else. */
final class CreatorOnlyPolicy implements SenderPolicy
{
    public function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : 'user:'.$id;
    }

    public function orgId(Request $request): ?string
    {
        return null;
    }

    public function allows(Request $request, array $envelope): bool
    {
        $actor = $this->actor($request);

        return $actor !== null && ($envelope['created_by'] ?? null) === $actor;
    }
}
