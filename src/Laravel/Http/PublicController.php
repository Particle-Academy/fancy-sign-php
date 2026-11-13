<?php

declare(strict_types=1);

namespace FancySign\Laravel\Http;

use FancySign\SignError;
use FancySign\Signing;
use FancySign\Verify\Verifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anyone may verify a final PDF against its completion record. The upload is
 * hostile: its size is capped before it is read, the record is parsed as JSON
 * only, and the PDF is only hashed (never parsed) here.
 */
final class PublicController extends Controller
{
    /** @param array<string, string> $previousKeys */
    public function __construct(Signing $signing, private readonly int $maxBytes, private readonly array $previousKeys = [])
    {
        parent::__construct($signing);
    }

    public function publicKey(): Response
    {
        return new JsonResponse($this->signing->publicKey());
    }

    public function verify(Request $request): Response
    {
        return $this->run(function () use ($request) {
            $pdf = $this->part($request, 'pdf');
            $record = json_decode($this->part($request, 'completion') ?? '', true, 64);
            $eventsRaw = $this->part($request, 'events');
            $events = $eventsRaw === null ? null : json_decode($eventsRaw, true, 64);
            if ($pdf === null || ($eventsRaw !== null && ! is_array($events))) {
                throw new SignError('invalid_request', 'Send pdf and completion, and events as a JSON list when present.');
            }
            $key = $this->signing->publicKey();
            $verifier = new Verifier([$key['key_id'] => $key['public_key']] + $this->previousKeys);

            return new JsonResponse($verifier->verify($pdf, $record, $events === null ? null : array_values($events)));
        });
    }

    private int $read = 0;

    /** A file part or a string field, counted against the cap before it is read. */
    private function part(Request $request, string $name): ?string
    {
        $file = $request->file($name);
        $size = $file instanceof UploadedFile ? (int) $file->getSize() : strlen((string) $request->input($name, ''));
        $this->read += $size;
        if ($this->read > $this->maxBytes) {
            throw new SignError('limit_exceeded', 'The upload is larger than this server verifies.');
        }
        if ($file instanceof UploadedFile) {
            return (string) file_get_contents($file->getRealPath());
        }
        $v = $request->input($name);

        return is_string($v) && $v !== '' ? $v : null;
    }
}
