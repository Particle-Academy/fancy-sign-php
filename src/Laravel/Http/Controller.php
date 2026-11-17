<?php

declare(strict_types=1);

namespace FancySign\Laravel\Http;

use FancySign\SignError;
use FancySign\Signing;
use FancySign\Support\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared plumbing: every refusal becomes the kit-wide error envelope
 * (CC-fancy-0031) with its status, and its request id in the body and the
 * request-id header. The id is the one fancy-http-guard put on the request
 * when the host runs it, otherwise a fresh UUID v4.
 */
abstract class Controller
{
    public function __construct(protected readonly Signing $signing) {}

    /** @param callable(): Response $fn */
    protected function run(callable $fn): Response
    {
        try {
            return $fn();
        } catch (SignError $e) {
            $request = request();
            $id = $request->attributes->get('request_id');
            if (! is_string($id) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $id) !== 1) {
                $id = self::uuid4();
                $request->attributes->set('request_id', $id);
            }
            $header = strtolower((string) config('fancy-http-guard.request_id_header', 'x-request-id'));

            return new JsonResponse($e->envelope($id), $e->status, [$header => $id]);
        }
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20);
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
