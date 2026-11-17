<?php

declare(strict_types=1);

use FancySign\Laravel\OrgAdminPolicy;
use FancySign\Laravel\SenderPolicy;
use FancySign\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/*
 * The org admin's switch over HTTP: any sender of the org reads it (so the
 * setup screen can show the lock), only an admin the host names can change it.
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

/** Users 1 and 2 are in org-1; user 1 is its admin; user 3 has no org. */
function fsOrgApp(bool $withAdmins = true): void
{
    TestApp::boot();
    app()->instance(SenderPolicy::class, new class implements SenderPolicy
    {
        public function actor(Request $request): ?string
        {
            $id = $request->user()?->getAuthIdentifier();

            return $id === null ? null : 'user:'.$id;
        }

        public function orgId(Request $request): ?string
        {
            $id = $request->user()?->getAuthIdentifier();

            return in_array($id, [1, 2], true) ? 'org-1' : null;
        }

        public function allows(Request $request, array $envelope): bool
        {
            return $envelope['org_id'] !== null && $envelope['org_id'] === $this->orgId($request);
        }
    });
    if ($withAdmins) {
        app()->instance(OrgAdminPolicy::class, new class implements OrgAdminPolicy
        {
            public function isAdmin(Request $request, string $orgId): bool
            {
                return $request->user()?->getAuthIdentifier() === 1 && $orgId === 'org-1';
            }
        });
    }
}

it('lets any sender of the org read the setting', function () {
    fsOrgApp();
    $r = fsHttp('GET', '/org/settings', userId: 2);

    expect($r['status'])->toBe(200)->and($r['body']['org_id'])->toBe('org-1')->and($r['body']['per_page'])->toBe('none');
});

it('lets the org admin turn per-page initials on, and the next envelope carries them', function () {
    fsOrgApp();
    $r = fsHttp('PUT', '/org/settings', ['per_page' => 'initials'], userId: 1);
    expect($r['status'])->toBe(200)->and($r['body']['per_page'])->toBe('initials')->and($r['body']['updated_by'])->toBe('user:1');

    $env = fsHttp('POST', '/envelopes', fsHttpEnvelope(), userId: 2)['body'];
    expect($env['settings']['per_page'])->toBe('initials');
    $override = fsHttpEnvelope() + ['settings' => ['per_page' => 'none']];
    expect(fsHttp('POST', '/envelopes', $override, userId: 2)['body']['error']['code'])->toBe('invalid_request');
});

it('refuses a sender who is not an admin', function () {
    fsOrgApp();
    $r = fsHttp('PUT', '/org/settings', ['per_page' => 'initials'], userId: 2);

    expect($r['status'])->toBe(403)->and($r['body']['error']['code'])->toBe('forbidden');
    expect(fsHttp('GET', '/org/settings', userId: 2)['body']['per_page'])->toBe('none');
});

it('refuses everyone when the host names no admins (the default)', function () {
    fsOrgApp(withAdmins: false);

    expect(fsHttp('PUT', '/org/settings', ['per_page' => 'initials'], userId: 1)['body']['error']['code'])->toBe('forbidden');
});

it('answers not_found for a sender with no organisation, and session_required with nobody signed in', function () {
    fsOrgApp();

    expect(fsHttp('GET', '/org/settings', userId: 3)['body']['error']['code'])->toBe('not_found')
        ->and(fsHttp('PUT', '/org/settings', ['per_page' => 'initials'], userId: 3)['body']['error']['code'])->toBe('not_found')
        ->and(fsHttp('GET', '/org/settings', userId: null)['body']['error']['code'])->toBe('session_required');
});
