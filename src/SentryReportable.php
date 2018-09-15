<?php

declare(strict_types=1);

namespace Leap\LaravelSentry;

interface SentryReportable
{
    public const SENTRY_ERROR_LEVEL = LaravelSentry::DEFAULT_LEVEL;

    public function getDataReportableToSentry(): array;
}
