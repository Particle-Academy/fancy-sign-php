<?php

declare(strict_types=1);

namespace FancySign\Laravel\Http;

use FancySign\SignError;
use FancySign\Signing;
use FancySign\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shared plumbing: every refusal becomes `{"error": {"code", "message"}}` with its status. */
abstract class Controller
{
    public function __construct(protected readonly Signing $signing) {}

    /** @param callable(): Response $fn */
    protected function run(callable $fn): Response
    {
        try {
            return $fn();
        } catch (SignError $e) {
            return new JsonResponse($e->toArray(), $e->status);
        }
    }

    protected function ctx(Request $request): Context
    {
        return new Context($request->ip(), $request->userAgent());
    }

    protected function pdf(string $bytes, string $name): Response
    {
        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
