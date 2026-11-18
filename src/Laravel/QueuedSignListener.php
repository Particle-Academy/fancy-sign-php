<?php

declare(strict_types=1);

namespace FancySign\Laravel;

use FancySign\Events\EnvelopeEventRecorded;
use FancySign\Listener\SignListener;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Throwable;

/**
 * What the Laravel bridge hands the core as its listener: each committed event
 * becomes a {@see DeliverEnvelopeEvent} job, dispatched AFTER the database
 * commit (so a transaction the host rolls back delivers nothing), on the
 * connection and queue in `config('fancy-sign.hook')`. When the host listens
 * for nothing (no Laravel listener on EnvelopeEventRecorded, no SignListener
 * bound), no job is queued at all.
 *
 * Nothing here can fail the request. On an async queue the job runs later and
 * retries on its own. On the `sync` driver it runs inline, so a throw is caught
 * here and reported through the host's exception handler.
 */
final class QueuedSignListener implements SignListener
{
    /** @param array{connection?: ?string, queue?: ?string, tries?: int} $config */
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly ExceptionHandler $exceptions,
        private readonly Events $events,
        private readonly Container $app,
        private readonly array $config = [],
    ) {}

    public function recorded(EnvelopeEventRecorded $event): void
    {
        try {
            if (! $this->events->hasListeners(EnvelopeEventRecorded::class) && ! $this->app->bound(SignListener::class)) {
                return;
            }
            $job = (new DeliverEnvelopeEvent($event, (int) ($this->config['tries'] ?? 5)))->afterCommit();
            if (is_string($this->config['connection'] ?? null) && $this->config['connection'] !== '') {
                $job->onConnection($this->config['connection']);
            }
            if (is_string($this->config['queue'] ?? null) && $this->config['queue'] !== '') {
                $job->onQueue($this->config['queue']);
            }
            $this->bus->dispatch($job);
        } catch (Throwable $e) {
            $this->exceptions->report($e);
        }
    }
}
