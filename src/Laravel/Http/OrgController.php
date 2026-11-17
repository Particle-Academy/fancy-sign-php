<?php

declare(strict_types=1);

namespace FancySign\Laravel\Http;

use FancySign\Laravel\OrgAdminPolicy;
use FancySign\Laravel\SenderPolicy;
use FancySign\SignError;
use FancySign\Signing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The organisation's signing settings: any sender of the org reads them (the
 * setup screen shows the lock), only an admin named by {@see OrgAdminPolicy}
 * changes them. The org is always the sender's own, from {@see SenderPolicy};
 * it is never taken from the request.
 */
final class OrgController extends Controller
{
    public function __construct(Signing $signing, private readonly SenderPolicy $senders, private readonly OrgAdminPolicy $admins)
    {
        parent::__construct($signing);
    }

    public function show(Request $request): Response
    {
        return $this->run(fn () => new JsonResponse($this->signing->orgSettings($this->org($request)[1])));
    }

    public function update(Request $request): Response
    {
        return $this->run(function () use ($request) {
            [$actor, $org] = $this->org($request);
            if (! $this->admins->isAdmin($request, $org)) {
                throw new SignError('forbidden', 'Only an organisation admin can change these settings.');
            }

            return new JsonResponse($this->signing->setOrgSettings($org, (array) $request->json()->all(), $actor));
        });
    }

    /** @return array{0: string, 1: string} actor, org id */
    private function org(Request $request): array
    {
        $actor = $this->senders->actor($request) ?? throw new SignError('session_required', 'Sign in to manage signing settings.');
        $org = $this->senders->orgId($request) ?? throw new SignError('not_found', 'You are not in an organisation.');

        return [$actor, $org];
    }
}
