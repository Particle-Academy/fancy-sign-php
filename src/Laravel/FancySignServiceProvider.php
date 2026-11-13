<?php

declare(strict_types=1);

namespace FancySign\Laravel;

use FancySign\Config;
use FancySign\Laravel\Http\PublicController;
use FancySign\Mail\SignMailer;
use FancySign\Repository\Repository;
use FancySign\Signing;
use FancySign\StepUp\StepUpVerifier;
use FancySign\Support\Clock;
use FancySign\Support\SystemClock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Wires the framework-free core into Laravel: the database repository, the
 * host's mailer, the routes and the migration. Bind your own SignMailer,
 * SenderPolicy, Clock or StepUpVerifier to change any of them.
 */
final class FancySignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/fancy-sign.php', 'fancy-sign');

        $this->app->singletonIf(Repository::class, fn (Application $app) => new DatabaseRepository($app['db']->connection($app['config']->get('fancy-sign.connection'))));
        $this->app->singletonIf(SignMailer::class, fn (Application $app) => new LaravelMailer($app['mailer']));
        $this->app->singletonIf(SenderPolicy::class, CreatorOnlyPolicy::class);
        $this->app->singletonIf(Clock::class, SystemClock::class);

        $this->app->singleton(Signing::class, function (Application $app): Signing {
            $config = $app['config'];
            $secret = $config->get('fancy-sign.seal.secret_key');
            if (! is_string($secret) || $secret === '') {
                throw new RuntimeException('fancy-sign needs a seal key: set FANCY_SIGN_SEAL_KEY (see config/fancy-sign.php for how to make one).');
            }
            $appKey = (string) $config->get('app.key');
            if (str_starts_with($appKey, 'base64:')) {
                $appKey = (string) base64_decode(substr($appKey, 7), true);
            }
            $url = $config->get('fancy-sign.signer_url') ?: rtrim((string) $config->get('app.url'), '/').'/sign/{token}';

            return new Signing(
                repo: $app->make(Repository::class),
                mailer: $app->make(SignMailer::class),
                sealSecretKey: $secret,
                appKey: $appKey,
                linkUrl: fn (string $token): string => str_replace('{token}', $token, (string) $url),
                config: new Config((array) $config->get('fancy-sign.limits', [])),
                clock: $app->make(Clock::class),
                stepUp: $app->bound(StepUpVerifier::class) ? $app->make(StepUpVerifier::class) : null,
            );
        });

        $this->app->when(PublicController::class)->needs('$maxBytes')->give(fn (Application $app) => (int) $app['config']->get('fancy-sign.verify_max_bytes'));
        $this->app->when(PublicController::class)->needs('$previousKeys')->give(fn (Application $app) => (array) $app['config']->get('fancy-sign.seal.previous_public_keys', []));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/fancy-sign.php');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->publishes([__DIR__.'/../../config/fancy-sign.php' => $this->app->configPath('fancy-sign.php')], 'fancy-sign-config');
    }
}
