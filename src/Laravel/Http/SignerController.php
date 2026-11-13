<?php

declare(strict_types=1);

namespace FancySign\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signer's routes: no account. The link token is in the URL; after the
 * view code, the session travels in the `X-Fancy-Sign-Session` header.
 */
final class SignerController extends Controller
{
    public const SESSION_HEADER = 'X-Fancy-Sign-Session';

    public function otp(Request $request, string $token): Response
    {
        return $this->run(fn () => new JsonResponse(
            $this->signing->requestCode($token, (string) $request->input('purpose', ''), $this->ctx($request), $this->session($request)), 202,
        ));
    }

    public function verify(Request $request, string $token): Response
    {
        return $this->run(fn () => new JsonResponse(
            $this->signing->verifyCode($token, (string) $request->input('purpose', ''), $request->input('code'), $this->ctx($request)),
        ));
    }

    public function envelope(Request $request, string $token): Response
    {
        return $this->run(fn () => new JsonResponse($this->signing->signerView($token, $this->session($request))));
    }

    public function consent(Request $request, string $token): Response
    {
        return $this->run(fn () => new JsonResponse($this->signing->consent($token, $this->session($request), $request->input('version'), $this->ctx($request))));
    }

    public function document(Request $request, string $token): Response
    {
        return $this->run(fn () => $this->pdf($this->signing->document($token, $this->session($request), $this->ctx($request)), 'document.pdf'));
    }

    public function events(Request $request, string $token): Response
    {
        return $this->run(function () use ($request, $token) {
            if ($request->input('type') !== 'page.viewed') {
                throw new \FancySign\SignError('invalid_request', 'Only page.viewed may be reported.');
            }
            $this->signing->pageViewed($token, $this->session($request), $request->input('page'), $request->input('ms'), $this->ctx($request));

            return new JsonResponse(['recorded' => true], 202);
        });
    }

    public function sign(Request $request, string $token): Response
    {
        return $this->run(fn () => new JsonResponse($this->signing->sign($token, $this->session($request),
            $request->only(['fields', 'mark', 'adoption', 'code', 'passkey']), $this->ctx($request))));
    }

    public function decline(Request $request, string $token): Response
    {
        return $this->run(fn () => new JsonResponse($this->signing->decline($token, $this->session($request), $request->input('reason'), $this->ctx($request))));
    }

    private function session(Request $request): ?string
    {
        $s = $request->header(self::SESSION_HEADER);

        return is_string($s) && $s !== '' ? $s : null;
    }
}
