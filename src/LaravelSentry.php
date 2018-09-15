<?php


namespace Lead\LaravelSentry;


class LaravelSentry
{
    public function __construct($app, array $config)
    {
        $this->app                    = $app;
        $this->dsn                    = $config['dsn'];
        $this->data_processors        = $config['data_processors'];
        $this->data_processor_options = $config['data_processor_options'];
        $this->default_logger         = $config['default_logger'];
        $this->release_file           = $config['release_file'];
    }
}