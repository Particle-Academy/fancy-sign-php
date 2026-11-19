<?php

declare(strict_types=1);

use FancySign\Events\EnvelopeEventRecorded;
use FancySign\Listener\SignListener;
use FancySign\Signing;
use FancySign\SignError;
use FancySign\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;

/*
 * The host hears envelope.completed and seal.applied only once the envelope is
 * SAVED as completed, with its completion. Under the sync queue the delivery
 * job runs inline, so a host listener that reacts to envelope.completed (for
 * example by calling attachFinal) used to run inside sign(), before the save,
 * and got envelope_not_completed. The chain's order is unchanged: only WHEN
 * the host is told moved.
 */

afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
});

/** The fdm* helpers' harness shape, over the Laravel bridge's Signing. */
function fhaHarness(): array
{
    return ['signing' => app(Signing::class), 'mailer' => TestApp::$mailer];
}

const FHA_PDF = "%PDF-1.7\n% the host's final quote\n%%EOF\n";

it('lets a host listener on envelope.completed call attachFinal under the sync queue (data mode)', function () {
    TestApp::boot();
    $h = fhaHarness();
    $attached = [];
    $errors = [];
    Event::listen(EnvelopeEventRecorded::class, function (EnvelopeEventRecorded $e) use (&$attached, &$errors): void {
        if ($e->eventType !== 'envelope.completed') {
            return;
        }
        try {
            $attached[] = app(Signing::class)->attachFinal($e->envelopeId, hash('sha256', FHA_PDF), strlen(FHA_PDF), 'user:1');
        } catch (SignError $err) {
            $errors[] = $err->errorCode;
        }
    });

    $id = fdmSent($h)['id'];
    fdmSignAs($h, $id, 'alice');

    expect($errors)->toBe([])
        ->and($attached)->toHaveCount(1)
        ->and($attached[0]['final_sha256'])->toBe(hash('sha256', FHA_PDF))
        ->and($h['signing']->envelope($id)['final'])->toBe($attached[0])
        // attachFinal ran after the completion, so its event comes after seal.applied.
        ->and(array_slice(fdmTypes($h, $id), -3))->toBe(['envelope.completed', 'seal.applied', 'document.finalized']);
});

it('shows a listener on envelope.completed a completed pdf envelope with its completion', function () {
    TestApp::boot();
    $seen = [];
    app()->instance(SignListener::class, new class($seen) implements SignListener
    {
        public function __construct(private array &$seen) {}

        public function recorded(EnvelopeEventRecorded $event): void
        {
            if (in_array($event->eventType, ['envelope.completed', 'seal.applied'], true)) {
                $env = app(Signing::class)->envelope($event->envelopeId);
                $this->seen[$event->eventType] = ['status' => $env['status'], 'completion' => $env['completion'] ?? null];
            }
        }
    });

    $h = fhaHarness();
    $id = fsSent($h)['id'];
    [$token, $session] = fsViewing($h);
    fsSign($h, $token, $session);
    $env = app(Signing::class)->envelope($id);

    expect(array_keys($seen))->toBe(['envelope.completed', 'seal.applied'])
        ->and($seen['envelope.completed']['status'])->toBe('completed')
        ->and($seen['envelope.completed']['completion'])->toBe($env['completion'])
        ->and($seen['envelope.completed']['completion'])->not->toBeNull();
});

it('delivers seal.applied after the save (data mode)', function () {
    TestApp::boot();
    $h = fhaHarness();
    $seen = null;
    Event::listen(EnvelopeEventRecorded::class, function (EnvelopeEventRecorded $e) use (&$seen): void {
        if ($e->eventType === 'seal.applied') {
            $env = app(Signing::class)->envelope($e->envelopeId);
            $seen = ['status' => $env['status'], 'token' => $env['completion']['token'] ?? null];
        }
    });

    $id = fdmSent($h)['id'];
    fdmSignAs($h, $id, 'alice');

    expect($seen)->toBe(['status' => 'completed', 'token' => $h['signing']->envelope($id)['completion']['token']]);
});

it('still delivers every chained event once, in chain order', function () {
    TestApp::boot();
    $h = fhaHarness();
    $seen = [];
    Event::listen(EnvelopeEventRecorded::class, function (EnvelopeEventRecorded $e) use (&$seen): void {
        $seen[] = [$e->seq, $e->eventType];
    });

    $id = fdmSent($h, bob: true)['id'];
    fdmSignAs($h, $id, 'alice');
    fdmSignAs($h, $id, 'bob');
    $chain = DB::table('fancy_sign_events')->where('envelope_id', $id)->orderBy('seq')->get();

    expect($seen)->toBe($chain->map(fn ($e) => [(int) $e->seq, $e->type])->all());
});

it('delivers nothing when the host rolls back the transaction a completion ran in', function () {
    TestApp::boot();
    $h = fhaHarness();
    $id = fdmSent($h)['id'];
    $token = fdmInvite($h, $id, 'alice');
    $session = fdmOpen($h, $token, 'alice');
    fdmRender($h, $token, $session);
    $seen = [];
    Event::listen(EnvelopeEventRecorded::class, function (EnvelopeEventRecorded $e) use (&$seen): void {
        $seen[] = $e->eventType;
    });

    DB::beginTransaction();
    fdmSign($h, $token, $session, 'alice');
    expect($seen)->toBe([]);
    DB::rollBack();

    expect($seen)->toBe([])
        ->and(fdmTypes($h, $id))->not->toContain('envelope.completed');
});

it('tells a framework-free SignListener about envelope.completed after the save too', function () {
    $keys = fsKeys();
    $mailer = new FancySign\Tests\Support\FakeMailer();
    $listener = new class implements SignListener
    {
        public ?Signing $signing = null;

        /** @var list<string> */
        public array $outcomes = [];

        public function recorded(EnvelopeEventRecorded $event): void
        {
            if ($event->eventType !== 'envelope.completed') {
                return;
            }
            try {
                $this->signing->attachFinal($event->envelopeId, hash('sha256', FHA_PDF), strlen(FHA_PDF), 'user:1');
                $this->outcomes[] = 'attached';
            } catch (SignError $e) {
                $this->outcomes[] = $e->errorCode;
            }
        }
    };
    $signing = new Signing(repo: new FancySign\Repository\InMemoryRepository(), mailer: $mailer, sealSecretKey: $keys['secret'],
        appKey: str_repeat('k', 32), linkUrl: fn (string $t): string => 'https://app.example.test/sign/'.$t,
        clock: new FancySign\Tests\Support\FrozenClock(), listener: $listener);
    $listener->signing = $signing;
    $h = ['signing' => $signing, 'mailer' => $mailer];

    $id = fdmSent($h)['id'];
    fdmSignAs($h, $id, 'alice');

    expect($listener->outcomes)->toBe(['attached'])
        ->and(array_slice(fdmTypes($h, $id), -3))->toBe(['envelope.completed', 'seal.applied', 'document.finalized']);
});
