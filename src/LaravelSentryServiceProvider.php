<?php


namespace Wujunze\LaravelSentry;


use Illuminate\Support\ServiceProvider;

class LaravelSentryServiceProvider extends ServiceProvider
{
    public const ABSTRACT = 'sentry';

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'config/laravel_sentry.php' => config_path('laravel_sentry.php'),
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