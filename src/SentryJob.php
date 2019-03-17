<?php

namespace Wujunze\LaravelSentry;


use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Class DingTalkJob
 * @package Wujunze\DingTalkException
 */
class SentryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var LaravelSentry
     */
    private $laravelSentry;

    /**
     * @var \Throwable
     */
    private $e;

    /**
     * @var string
     */
    private $level;

    /**
     * @var array
     */
    private $extra;

    /**
     * @var array
     */
    private $options;

    /**
     * DingTalkJob constructor.
     * @param \Throwable $e
     * @param string|null $level
     * @param array $extra
     * @param array $options
     */
    public function __construct(\Throwable $e, string $level = null, array $extra = [], array $options = [])
    {
        $this->laravelSentry = app()->get('sentry');

        $this->e = $e;

        $this->level = $level;

        $this->extra = $extra;

        $this->options = $options;
    }


    public function handle()
    {
        try {
            $this->laravelSentry->captureException($this->e, $this->level, $this->extra, $this->options);
        } catch (\Exception $exception) {
            logger($exception->getMessage());
        }

    }
}
