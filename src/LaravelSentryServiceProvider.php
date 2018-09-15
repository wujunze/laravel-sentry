<?php


namespace Leap\LaravelSentry;


use Illuminate\Support\ServiceProvider;

class LaravelSentryServiceProvider extends ServiceProvider
{
    public const ABSTRACT = 'sentry';

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config.php' => config_path('sentry.php'),
        ]);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(static::ABSTRACT, function ($app) {
            return new LaravelSentry($app, config('sentry'));
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [static::ABSTRACT];
    }
}