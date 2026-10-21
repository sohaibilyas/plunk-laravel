<?php

namespace SohaibIlyas\PlunkLaravel;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use SohaibIlyas\PlunkLaravel\Services\PlunkService;
use SohaibIlyas\PlunkLaravel\Support\PlunkMailContext;
use SohaibIlyas\PlunkLaravel\Transport\PlunkTransport;

class PlunkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/plunk.php', 'plunk');
        $this->app->scoped(PlunkMailContext::class);
        $this->app->bind(PlunkService::class, fn (): PlunkService => new PlunkService(
            secretApiKey: config('plunk.secret_key') ?? '',
            publicApiKey: config('plunk.public_key'),
            baseUrl: config('plunk.base_api_url'),
            endpoint: config('plunk.endpoint'),
            timeout: config('plunk.timeout'),
            connectTimeout: config('plunk.connect_timeout'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/plunk.php' => config_path('plunk.php')], 'plunk-config');
        Mail::extend('plunk', fn (array $config = []): PlunkTransport => new PlunkTransport(
            array_replace(config('plunk'), $config),
            fn (): PlunkMailContext => app(PlunkMailContext::class),
        ));
        Queue::createPayloadUsing(fn (): array => ['plunk_created_at' => time()]);
        Queue::before(fn (JobProcessing $event) => app(PlunkMailContext::class)->begin($event->job));
        Queue::after(fn (JobProcessed $event) => app(PlunkMailContext::class)->end($event->job));
        Queue::exceptionOccurred(fn (JobExceptionOccurred $event) => app(PlunkMailContext::class)->end($event->job));
    }
}
