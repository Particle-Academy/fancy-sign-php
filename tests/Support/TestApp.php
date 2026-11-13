<?php

declare(strict_types=1);

namespace FancySign\Tests\Support;

use FancySign\Mail\SignMailer;
use FancySign\Support\Clock;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\ViewServiceProvider;

/**
 * A hand-built Laravel kernel (no orchestra/testbench), as change-council's.
 * In-memory SQLite, the array mailer, blank provider keys: nothing leaves the
 * process.
 */
final class TestApp
{
    public static ?FakeMailer $mailer = null;

    public static ?FrozenClock $clock = null;

    /** @param array<string, mixed> $config dotted keys merged over the defaults */
    public static function boot(array $config = [], bool $fakeMailer = true): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-sign-tests';
        if (! is_dir($base.'/config')) {
            mkdir($base.'/config', 0777, true);
        }
        $app = new Application($base);
        $app->useConfigPath($base.'/config');
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $keys = fsKeys();
        $repository = new Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'env' => 'testing', 'debug' => false, 'url' => 'https://app.example.test'],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]],
            'mail' => ['default' => 'array', 'mailers' => ['array' => ['transport' => 'array']], 'from' => ['address' => 'sign@example.test', 'name' => 'Sign']],
            'view' => ['paths' => [], 'compiled' => $base],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]],
            'auth' => ['defaults' => ['guard' => 'web'], 'guards' => [], 'providers' => []],
            'services' => ['anthropic' => ['key' => ''], 'openai' => ['key' => '']],
            'fancy-sign' => [
                'sender_middleware' => [],
                'seal' => ['secret_key' => $keys['secret'], 'previous_public_keys' => []],
                'verify_max_bytes' => 5 * 1024 * 1024,
            ],
        ]);
        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }
        $app->instance('config', $repository);

        self::$clock = new FrozenClock();
        $app->instance(Clock::class, self::$clock);
        if ($fakeMailer) {
            self::$mailer = new FakeMailer();
            $app->instance(SignMailer::class, self::$mailer);
        }

        foreach ([EventServiceProvider::class, FilesystemServiceProvider::class, DatabaseServiceProvider::class, ViewServiceProvider::class,
            MailServiceProvider::class, AuthServiceProvider::class, RoutingServiceProvider::class,
            \FancySign\Laravel\FancySignServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_13_000001_create_fancy_sign_tables.php';
        $migration->up();
        $app->boot();

        return $app;
    }
}
