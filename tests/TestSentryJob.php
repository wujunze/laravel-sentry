<?php

namespace Wujunze\LaravelSentry\Tests;


use Wujunze\LaravelSentry\Sentry;

class TestSentryJob extends TestCase
{

    public function testReport()
    {

        $sentry = new Sentry();

        $this->assertInstanceOf(Sentry::class, $sentry);
    }


}