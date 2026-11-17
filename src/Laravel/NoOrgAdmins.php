<?php

declare(strict_types=1);

namespace FancySign\Laravel;

use Illuminate\Http\Request;

/** The default: nobody may change an organisation's settings until the host says who can. */
final class NoOrgAdmins implements OrgAdminPolicy
{
    public function isAdmin(Request $request, string $orgId): bool
    {
        return false;
    }
}
