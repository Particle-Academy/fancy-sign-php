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
            $this->input($request), $this->actor($request), $this->policy->orgId($request),
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

            return new JsonResponse($this->signing->update($id, $this->input($request), $this->actor($request)));
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

    /** Data mode: invite one signer, when the host decides. */
    public function invite(Request $request, string $id, string $signerId): Response
    {
        return $this->run(function () use ($request, $id, $signerId) {
            $this->mine($request, $id);

            return new JsonResponse($this->signing->invite($id, $signerId, $this->actor($request)));
        });
    }

    /** Data mode: every signature record so far, with its mark. */
    public function signatures(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id) {
            $this->mine($request, $id);

            return new JsonResponse($this->signing->signatures($id));
        });
    }

    /** Data mode, optional: register the host's final PDF hash, once, after completion. The PDF itself is never sent. */
    public function attachFinal(Request $request, string $id): Response
    {
        return $this->run(function () use ($request, $id) {
            $this->mine($request, $id);

            return new JsonResponse($this->signing->attachFinal($id, $request->input('sha256'), $request->input('bytes'), $this->actor($request)));
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
            if (($this->mine($request, $id)['document']['mode'] ?? 'pdf') === 'data') {
                return $this->data($this->signing->dataDocument($id));
            }

            return $this->pdf($this->signing->currentDocument($id), "{$id}.pdf");
        });
    }

    /**
     * The create/update input. `data` is read from the RAW body with JSON
     * objects kept as objects: $request->only() decodes with assoc: true, which
     * turns {} into [] and {"0": "a"} into ["a"], and the host's data_sha256
     * would no longer match. A non-object `data` is passed on as JSON text, so
     * it is refused for what it is.
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $input = $request->only(['title', 'document', 'signers', 'settings', 'fields', 'data_sha256', 'data_profile']);
        $raw = json_decode((string) $request->getContent(), false, 512);
        if ($raw instanceof \stdClass && property_exists($raw, 'data')) {
            $input['data'] = $raw->data instanceof \stdClass ? $raw->data : (string) json_encode($raw->data);
        }

        return $input;
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
