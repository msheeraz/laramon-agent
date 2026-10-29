<?php

namespace Laramon\Agent;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Laramon\Agent\Console\AgentCommand;
use Laramon\Agent\Console\SampleCommand;
use Laramon\Agent\Console\StatusCommand;
use Laramon\Agent\Console\TestCommand;
use Laramon\Agent\Http\RecordRequest;
use Laramon\Agent\Support\Clock;
use Laramon\Agent\Support\Deploy;
use Laramon\Agent\Support\Runtime;
use Laramon\Agent\Watchers\CacheWatcher;
use Laramon\Agent\Watchers\CommandWatcher;
use Laramon\Agent\Watchers\ExceptionWatcher;
use Laramon\Agent\Watchers\JobWatcher;
use Laramon\Agent\Watchers\LogWatcher;
use Laramon\Agent\Watchers\MailWatcher;
use Laramon\Agent\Watchers\NotificationWatcher;
use Laramon\Agent\Watchers\OutgoingRequestWatcher;
use Laramon\Agent\Watchers\QueryWatcher;
use Laramon\Agent\Watchers\RequestWatcher;
use Laramon\Agent\Watchers\ScheduleWatcher;
use Laramon\Agent\Watchers\UserWatcher;

class LaramonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // As close to process start as a provider gets: CPU baseline for the first request.
        $bootCpu = Clock::cpu();

        $this->mergeConfigFrom(__DIR__.'/../config/laramon.php', 'laramon');

        $this->app->singleton(Transport::class, function ($app) {
            $config = $app['config']['laramon'];

            return new Transport(
                $config['url'] ?? null,
                $config['token'] ?? null,
                (float) ($config['timeout'] ?? 2),
                (bool) ($config['gzip'] ?? true),
                [
                    'php' => PHP_VERSION,
                    'laravel' => $app->version(),
                    'server' => (string) ($config['server'] ?? gethostname()),
                    'deploy' => Deploy::detect($app->basePath(), $config['deploy'] ?? null),
                ],
                fn () => Runtime::snapshot($app),
            );
        });

        $this->app->singleton(Hooks::class);
        $this->app->singleton(Recorder::class, fn ($app) => new Recorder($app->make(Transport::class), $app['config']['laramon'], $app->make(Hooks::class)));
        $this->app->singleton(LaramonManager::class, fn ($app) => new LaramonManager($app->make(Recorder::class), $app->make(Hooks::class)));

        $this->app->singleton(RequestWatcher::class, fn ($app) => new RequestWatcher($app, $app->make(Recorder::class), $bootCpu));
        $this->app->singleton(ExceptionWatcher::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/laramon.php' => config_path('laramon.php')], 'laramon-config');

            $this->commands([AgentCommand::class, SampleCommand::class, TestCommand::class, StatusCommand::class]);
        }

        if (! $this->shouldRecord()) {
            return;
        }

        $recorder = $this->app->make(Recorder::class);
        $capture = (array) config('laramon.capture', []);

        $watchers = [
            RequestWatcher::class => true,
            ExceptionWatcher::class => true,
            UserWatcher::class => true,
            JobWatcher::class => true,
            CommandWatcher::class => true,
            ScheduleWatcher::class => true,
            LogWatcher::class => true, // also the fallback exception capture
            QueryWatcher::class => $capture['queries'] ?? true,
            CacheWatcher::class => $capture['cache'] ?? true,
            OutgoingRequestWatcher::class => $capture['outgoing'] ?? true,
            MailWatcher::class => $capture['mail'] ?? true,
            NotificationWatcher::class => $capture['notifications'] ?? true,
        ];

        foreach ($watchers as $class => $enabled) {
            if ($enabled) {
                $this->app->make($class)->register();
            }
        }

        $this->registerMiddleware();

        $requests = $this->app->make(RequestWatcher::class);
        register_shutdown_function(function () use ($requests, $recorder) {
            try {
                $requests->abandon();
                $recorder->flush();
            } catch (\Throwable) {
            }
        });
    }

    private function shouldRecord(): bool
    {
        if (! config('laramon.enabled') || empty(config('laramon.url')) || empty(config('laramon.token'))) {
            return false;
        }

        // Test suites stay silent unless explicitly opted in.
        return ! $this->app->runningUnitTests() || (bool) env('LARAMON_RECORD_TESTS', false);
    }

    private function registerMiddleware(): void
    {
        $prepend = function ($kernel) {
            if (method_exists($kernel, 'hasMiddleware') && $kernel->hasMiddleware(RecordRequest::class)) {
                return;
            }
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(RecordRequest::class);
            }
        };

        if ($this->app->resolved(HttpKernel::class)) {
            $prepend($this->app->make(HttpKernel::class));
        } else {
            $this->app->afterResolving(HttpKernel::class, $prepend);
        }
    }
}
