<?php

declare(strict_types=1);

namespace Wujunze\LaravelSentry\Processors;

use Raven_Client;
use Raven_Processor;

/**
 * @see \Raven_Processor_SanitizeHttpHeadersProcessor
 * @see \Raven_Processor_RemoveCookiesProcessor
 */
class SanitizeCookiesProcessor extends Raven_Processor
{
    /**
     * @var string[] The list of HTTP cookies to sanitize
     */
    private $patterns = [];

    public function __construct(Raven_Client $client)
    {
        parent::__construct($client);
    }

    public function setProcessorOptions(array $options): void
    {
        $this->patterns = $options['patterns'] ?? [];

        if (is_string(config('session.cookie'))) {
            $this->patterns[] = config('session.cookie'); /** @see \Raven_Processor_SanitizeDataProcessor::sanitizeHttp() */
        }
    }

    public function process(&$data): void
    {
        if (isset($data['request']['cookies']) && is_array($data['request']['cookies'])) {
            foreach ($data['request']['cookies'] as $cookie => &$value) {
                foreach ($this->patterns as $pattern) {
                    if (is_string($pattern) && strlen($pattern)) {
                        if (substr($pattern, 0, 1) === '/' && preg_match($pattern, $cookie)) {
                            $value = self::STRING_MASK;
                        } elseif (substr($pattern, 0, 1) !== '/' && strcasecmp($pattern, $cookie) === 0) {
                            $value = self::STRING_MASK;
                        }
                    }
                }
            }
        }
    }
}
