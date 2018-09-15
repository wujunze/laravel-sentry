<?php


namespace Leap\LaravelSentry;


use Illuminate\Support\Facades\Facade;

class Sentry extends Facade
{
    protected static function getFacadeAccessor()
    {
        return LaravelSentryServiceProvider::ABSTRACT;
    }
}