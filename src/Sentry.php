<?php


namespace Leap\LaravelSentry;


use Illuminate\Support\Facades\Facade;
use Lead\LaravelSentry\LaravelSentryServiceProvider;

class Sentry extends Facade
{
    protected static function getFacadeAccessor()
    {
        return LaravelSentryServiceProvider::ABSTRACT;
    }
}