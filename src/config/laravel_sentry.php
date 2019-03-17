<?php

declare(strict_types=1);

return [
    'dsn'                    => env('SENTRY_DSN'),
    'data_processors'        => [
    ],
    'data_processor_options' => [
    ],
    'default_logger'         => 'php',
    'release_file'           => 'revision.txt',
];
