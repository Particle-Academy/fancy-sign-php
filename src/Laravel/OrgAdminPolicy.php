<?php

declare(strict_types=1);

namespace FancySign\Laravel;

use Illuminate\Http\Request;

/**
 * Who may change an organisation's signing settings (per-page initials or
 * signatures). The default, {@see NoOrgAdmins}, names nobody: a host with
 * organisations binds its own, from its roles.
 */
interface OrgAdminPolicy
{
    public function isAdmin(Request $request, string $orgId): bool;
}
