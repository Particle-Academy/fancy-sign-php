<?php

declare(strict_types=1);

namespace FancySign\Laravel\Http;

use FancySign\Laravel\SenderPolicy;
use FancySign\SignError;
use FancySign\Signing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sender's routes. The host authenticates (sender_middleware); the
 * {@see SenderPolicy} decides which envelopes this sender reaches. Someone
 * else's envelope answers `not_found`, never `forbidden`, so ids cannot be probed.
 */
final class SenderController extends Controller
{
    public function __construct(Signing $signing, private readonly SenderPolicy $policy)
    {
        parent::__construct($signing);
    }

    public function index(Request $request): Response
    {
        return $this->run(fn () => new JsonResponse(array_values(array_filter(
            $this->signing->listEnvelopes($this->policy->orgId($request)),
            fn (array $e) => $this->policy->allows($request, $e),
        ))));
    }

    public function store(Request $request): Response
    {
        return $this->run(fn () => new JsonResponse($this->signing->create(
            $request->only(['title', 'document', 'signers', 'settings']), $this->actor($request), $this->policy->orgId($request),
        ), 201));
    }

    public function show(Request $request, string $id): Response
    {
        return $this->run(fn () => new JsonResponse($this->mine($request, $id)));
    }

    public function update(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id) {
            $this->mine($request, $id);

            return new JsonResponse($this->signing->update($id, $request->only(['title', 'document', 'signers', 'settings']), $this->actor($request)));
        });
    }

    public function send(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id) {
            $this->mine($request, $id);

            return new JsonResponse($this->signing->send($id, $this->actor($request)));
        });
    }

    public function void(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id) {
            $this->mine($request, $id);

            return new JsonResponse($this->signing->void($id, (string) $request->input('reason', ''), $this->actor($request)));
        });
    }

    public function remind(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id) {
            $this->mine($request, $id);

            return new JsonResponse($this->signing->remind($id, $this->actor($request)));
        });
    }

    public function events(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id) {
            $this->mine($request, $id);

            return new JsonResponse($this->signing->events($id));
        });
    }

    public function certificate(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id) {
            $this->mine($request, $id);
            if ($request->query('format') === 'json') {
                return new JsonResponse($this->signing->certificate($id, 'json'));
            }

            return $this->pdf((string) $this->signing->certificate($id, 'pdf'), "certificate-{$id}.pdf");
        });
    }

    public function document(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id) {
            $this->mine($request, $id);

            return $this->pdf($this->signing->currentDocument($id), "{$id}.pdf");
        });
    }

    /** @return array<string, mixed> */
    private function mine(Request $request, string $id): array
    {
        $env = $this->signing->envelope($id);
        if (! $this->policy->allows($request, $env)) {
            throw new SignError('not_found', 'No such envelope.');
        }

        return $env;
    }

    private function actor(Request $request): string
    {
        return $this->policy->actor($request) ?? throw new SignError('session_required', 'Sign in to manage envelopes.');
    }
}
