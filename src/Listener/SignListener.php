<?php

declare(strict_types=1);

namespace FancySign\Listener;

use FancySign\Events\EnvelopeEventRecorded;

/**
 * The host hook: told about every event once it is committed to an envelope's
 * chain (every type in the closed set: `envelope.sent`, `document.opened`,
 * `signer.signed`, `signer.declined`, `envelope.completed`, `envelope.voided`,
 * and the rest). Optional; bind one to act on a signature, for example to
 * close a deal when `envelope.completed` arrives.
 *
 * Delivery is at least once: dedupe on `$event->dedupeKey()`. Under the
 * Laravel bridge it runs in a queued job after the database commit, with
 * retries, so a throw here retries the delivery and never touches the
 * request that recorded the event. Framework-free, `Signing` calls it right
 * after the event is stored and swallows anything it throws.
 */
interface SignListener
{
    public function recorded(EnvelopeEventRecorded $event): void;
}
