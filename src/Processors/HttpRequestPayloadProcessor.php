<?php

declare(strict_types=1);

namespace Wujunze\LaravelSentry\Processors;

use Raven_Processor;
use Raven_Serializer;

/**
 * 1) Report PUT payload
 * 2) Report POST payload when Content-Type is not 'application/x-www-form-urlencoded'
 * 3) Decode JSON payload
 */
class HttpRequestPayloadProcessor extends Raven_Processor
{
    public function process(&$data): void
    {
        if (isset($data['request']['method']) && in_array($data['request']['method'], ['PUT', 'POST'], true) && !isset($data['request']['data'])) {
            // Sanitizer is called before processors, so here we need to sanitize manually
            $serializer = new Raven_Serializer();

            if (!empty($_POST)) {
                $payload = $_POST;
            } else { // POST payload will be in php://input, when Content-Type is not 'application/x-www-form-urlencoded'
                $payload = file_get_contents('php://input');
            }

            if ($payload) {
                if (app('request')->isJson()) {
                    $json = json_decode($payload, true);

                    if (JSON_ERROR_NONE === json_last_error()) {
                        $payload = $json;
                    }
                }

                $data['request']['data'] = $serializer->serialize($payload);
            }
        }
    }
}
