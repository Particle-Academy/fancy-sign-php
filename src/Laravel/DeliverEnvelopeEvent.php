<?php

declare(strict_types=1);

namespace FancySign\Laravel;

use FancySign\Events\EnvelopeEventRecorded;
use FancySign\Listener\SignListener;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one committed chain event to the host: the Laravel event
 * {@see EnvelopeEventRecorded}, then the bound {@see SignListener} if any.
 *
 * Queued and retried, so delivery is at least once. A failure is RETHROWN so
 * the queue retries it; the request that recorded the event finished long
 * before (QueuedSignListener dispatches it after the commit and, on the sync
 * driver, catches and reports instead).
 */
final class DeliverEnvelopeEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public function __construct(public readonly EnvelopeEventRecorded $event, int $tries = 5)
    {
        $this->tries = max(1, $tries);
    }

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(Dispatcher $events, Container $app): void
    {
        $events->dispatch($this->event);
        if ($app->bound(SignListener::class)) {
            $app->make(SignListener::class)->recorded($this->event);
        }
    }
}
