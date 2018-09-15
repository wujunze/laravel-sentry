<?php

namespace Leap\LaravelSentry;

use Leap\LaravelSentry\Processors\HttpRequestPayloadProcessor;
use Leap\LaravelSentry\Processors\SanitizeCookiesProcessor;
use Closure;
use Exception;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Jenssegers\Agent\Agent;
use Leap\LaravelSentry\Xml\XmlParser\XmlParser;
use Raven_Client;
use Raven_Processor_SanitizeDataProcessor;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use SentryReportable;

class LaravelSentry
{
    public const DEFAULT_LEVEL = Raven_Client::ERROR;

    protected const ARRAY_INDEX_LOGGER         = 0;
    protected const ARRAY_INDEX_DATA_COLLECTOR = 1;
    protected const ARRAY_INDEX_TAG_GENERATOR  = 2;
    protected const LABEL_HEADERS              = 'Headers';
    protected const LABEL_METHOD               = 'Method';
    protected const LABEL_PAYLOAD              = 'Payload';
    protected const LABEL_STATUS_CODE          = 'Status code';
    protected const LABEL_URL                  = 'URL';

    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /** @var \Raven_Client|null */
    protected $client = null;

    /** @var string[] [processor_class_name] */
    protected $data_processors = [];

    /** @var string[] [processor_class_name => [option1, option2]] */
    protected $data_processor_options = [];

    /** @var string */
    protected $default_logger;

    /** @var string|null */
    protected $dsn;

    /** @var array[] [exception_class => [logger, data_collector, tag_generator]] */
    protected $exception_processors;

    /** @var string|null */
    protected $release_file;

    /** @var \Closure */
    protected $tag_context_provider;

    /** @var \Closure */
    protected $user_context_provider;

    public function __construct($app, array $config)
    {
        $this->app                    = $app;
        $this->dsn                    = $config['dsn'];
        $this->data_processors        = $config['data_processors'];
        $this->data_processor_options = $config['data_processor_options'];
        $this->default_logger         = $config['default_logger'];
        $this->release_file           = $config['release_file'];
    }

    private function getClient(): ?Raven_Client
    {
        if (null === $this->client) {
            if ($this->dsn) {
                $fqdn = trim(shell_exec('hostname -f'));

                $client = new Raven_Client($this->dsn, [
                    'curl_method'      => 'async',
                    'environment'      => $this->app->environment(),
                    'name'             => $fqdn ?: gethostname(),
                    'prefixes'         => [$this->app->basePath()],
                    'app_path'         => $this->app->path(),
                    'processors'       => array_merge([
                        HttpRequestPayloadProcessor::class,
                    ], $this->data_processors, [
                        SanitizeCookiesProcessor::class,
                        Raven_Processor_SanitizeDataProcessor::class, // Raven_Processor_SanitizeDataProcessor should always be the last one
                    ]),
                    'processorOptions' => $this->data_processor_options,
                ]);

                try {
                    /** @var \Illuminate\Contracts\Auth\Guard $auth */
                    $auth = $this->app['auth'];

                    if (!$this->app->environment('testing') && !$this->app->runningInConsole()) {
                        $user_context = [
                            'ip_address' => $this->app['request']->getClientIp(),
                        ];

                        if ($auth->check()) {
                            /** @var \Illuminate\Contracts\Auth\Authenticatable $user */
                            $user = $auth->user();

                            $user_context += [
                                'id' => $user->getAuthIdentifier(),
                            ];

                            if (isset($user->email)) {
                                $user_context += ['email' => $user->email];
                            }

                            if ($this->user_context_provider) {
                                $user_context += call_user_func($this->user_context_provider, $user);
                            }
                        }

                        $client->user_context($user_context);
                    }

                    if ($this->tag_context_provider) {
                        $client->tags_context(call_user_func($this->tag_context_provider, $auth->user()));
                    }

                    if ($this->release_file) {
                        $release = $this->app['cache']->rememberForever('sentry.release', function (): string {
                            $release_file = base_path($this->release_file);

                            return trim(strval(@file_get_contents($release_file)));
                        });

                        if ($release) {
                            $client->setRelease($release);
                        }
                    }
                } catch (Exception $e) {
                    // Swallow
                }

                $this->client = $client;
            }
        }

        return $this->client;
    }

    public function captureException(Throwable $e, string $level = null, array $extra = [], array $options = []): ?string
    {
        $client          = $this->getClient();
        $exception_class = get_class($e);

        if (!$level && defined($e . '::SENTRY_ERROR_LEVEL')) {
            $level = $e::SENTRY_ERROR_LEVEL;
        }

        if ($client) {
            if ($e instanceof SentryReportable) {
                $extra += $e->getDataReportableToSentry();
            } elseif (isset($this->exception_processors[$exception_class][static::ARRAY_INDEX_DATA_COLLECTOR])) {
                $extra += call_user_func($this->exception_processors[$exception_class][static::ARRAY_INDEX_DATA_COLLECTOR], $e);
            }

            if ($e instanceof ValidationException) {
                $extra['Data'] = $e->validator->getData();
            }

            if ($e instanceof HttpException) {
                $status_code                      = $e->getStatusCode();
                $extra[static::LABEL_STATUS_CODE] = $status_code;

                if ($e->getHeaders()) {
                    $extra[static::LABEL_HEADERS] = $this->consolidateHeaders($e->getHeaders());
                }

                if (!$level && $status_code >= 400 && $status_code < 500) {
                    $level = Raven_Client::DEBUG;
                }
            }

            // GuzzleRequestException is not inherited from HttpException
            if ($e instanceof GuzzleRequestException) {
                $request = $e->getRequest();

                $extra['Request'] = [
                    static::LABEL_URL     => $request->getUri()->__toString(),
                    static::LABEL_METHOD  => $request->getMethod(),
                    static::LABEL_PAYLOAD => $request->getBody()->__toString(),
                ];

                if ($request->getHeaders()) {
                    $extra['Request'][static::LABEL_HEADERS] = $this->consolidateHeaders($request->getHeaders());
                }

                $response = $e->getResponse();

                if ($response) {
                    $extra['Response'] = [
                        static::LABEL_PAYLOAD     => $this->parseResponsePayload($response),
                        static::LABEL_STATUS_CODE => $response->getStatusCode(),
                    ];

                    if ($response->getHeaders()) {
                        $extra['Response'][static::LABEL_HEADERS] = $this->consolidateHeaders($response->getHeaders());
                    }
                }
            }

            $previous = $e->getPrevious();

            if ($previous) {
                if ($previous instanceof SentryReportable) {
                    $extra['Previous'] = $previous->getDataReportableToSentry();
                } elseif (isset($this->exception_processors[get_class($previous)][static::ARRAY_INDEX_DATA_COLLECTOR])) {
                    $extra['Previous'] = call_user_func($this->exception_processors[get_class($previous)][static::ARRAY_INDEX_DATA_COLLECTOR], $previous);
                }
            }

            $options += [
                'level' => $level ?: static::DEFAULT_LEVEL,
                'extra' => $extra,
            ];

            if (defined($e . '::SENTRY_LOGGER')) {
                $options['logger'] = $e::SENTRY_LOGGER;
            } elseif (isset($this->exception_processors[$exception_class][static::ARRAY_INDEX_LOGGER])) {
                $options['logger'] = $this->exception_processors[$exception_class][static::ARRAY_INDEX_LOGGER];
            } elseif (!isset($options['logger'])) {
                $options['logger'] = $this->default_logger;
            }

            if (isset($this->exception_processors[$exception_class][static::ARRAY_INDEX_TAG_GENERATOR])) {
                $options['tags'] = call_user_func($this->exception_processors[$exception_class][static::ARRAY_INDEX_TAG_GENERATOR], $e);
            }

            $this->addContentInterface($options);

            return $client->getIdent($client->captureException($e, $options));
        } else {
            $level = $level ?: static::DEFAULT_LEVEL;

            $this->app['log']->$level("Exception [$exception_class]: " . $e->getMessage());

            return null;
        }
    }

    /**
     * @param string      $message
     * @param string|null $level
     * @param array|null  $extra
     * @param array       $options
     *
     * @return string|null Event id or null
     */
    public function captureMessage(string $message, string $level = null, array $extra = null, array $options = []): ?string
    {
        $client = $this->getClient();
        $level  = $level ?: static::DEFAULT_LEVEL;

        if ($client) {
            $options += [
                'level' => $level,
                'extra' => $extra,
            ];

            $this->addContentInterface($options);

            return $client->getIdent($client->captureMessage($message, [], $options, true));
        } else {
            $this->app['log']->$level($message);

            return null;
        }
    }

    /**
     * @param string      $category
     * @param string      $message
     * @param array       $data
     * @param string|null $type     navigation, http, or default (null)
     * @param string      $level
     */
    public function addBreadscrumb(string $category, string $message, array $data = [], string $type = null, string $level = Raven_Client::INFO): void
    {
        $client = $this->getClient();

        if ($client) {
            $breadscrumb = [
                'message'  => $message,
                'data'     => $data,
                'category' => snake_case($category),
                'level'    => $level,
            ];

            if ($type) {
                $breadscrumb['type'] = $type;
            }

            $client->breadcrumbs->record($breadscrumb);
        }
    }

    /**
     * @param string[] $process_class_names
     */
    public function registerDataProcessor(string ...$process_class_names): void
    {
        $this->data_processors = array_merge($this->data_processors, $process_class_names);
    }

    /**
     * @param string $processor_class_name
     * @param array  $options
     */
    public function setDataProcessorOptions(string $processor_class_name, array $options): void
    {
        $this->data_processor_options[$processor_class_name] = $options;
    }

    /**
     * @param string   $exception_class
     * @param \Closure $data_collector
     */
    public function registerExceptionDataCollector(string $exception_class, Closure $data_collector): void
    {
        if ($exception_class instanceof SentryReportable) {
            throw new InvalidArgumentException("Cannot register [$exception_class] which has getDataReportableToSentry() method defined.");
        }

        $this->exception_processors[$exception_class][static::ARRAY_INDEX_DATA_COLLECTOR] = $data_collector;
    }

    /**
     * @param string $exception_class
     * @param string $logger
     */
    public function registerExceptionLogger(string $exception_class, string $logger): void
    {
        $this->exception_processors[$exception_class][static::ARRAY_INDEX_LOGGER] = $logger;
    }

    /**
     * @param string   $exception_class
     * @param \Closure $tag_generator
     */
    public function registerExceptionTagGenerator(string $exception_class, Closure $tag_generator): void
    {
        $this->exception_processors[$exception_class][static::ARRAY_INDEX_TAG_GENERATOR] = $tag_generator;
    }

    /**
     * @param \Closure $tag_context_provider
     */
    public function setTagContextProvider(Closure $tag_context_provider): void
    {
        $this->tag_context_provider = $tag_context_provider;
    }

    /**
     * @param \Closure $user_context_provider
     */
    public function setUserContextProvider(Closure $user_context_provider): void
    {
        $this->user_context_provider = $user_context_provider;
    }

    /**
     * @link https://docs.sentry.io/clientdev/interfaces/contexts/
     *
     * @param array $options
     */
    private function addContentInterface(array &$options): void
    {
        $agent = new Agent();
        $os    = $agent->platform();

        if ($os) {
            $options['contexts']['os'] = [
                'name'    => $os,
                'version' => str_replace('_', '.', $agent->version($os)),
            ];
        }

        $browser = $agent->browser();

        if ($browser) {
            $options['contexts']['browser'] = [
                'name'    => $browser,
                'version' => $agent->version($browser),
            ];
        }
    }

    /**
     * @param array $headers
     *
     * @return string[]
     */
    private function consolidateHeaders(array $headers): array
    {
        return array_map(function ($values) {
            return is_array($values) ? implode(',', $values) : $values;
        }, $headers);
    }

    /**
     * Try to parse response payload into JSON or XML, or return the original string
     *
     * @param \GuzzleHttp\Psr7\Response $response
     *
     * @return array|string
     */
    private function parseResponsePayload(Response $response)
    {
        $payload = $response->getBody()->__toString();
        $json    = json_decode($payload, true);

        if (JSON_ERROR_NONE === json_last_error()) {
            return $json;
        } else {
            try {
                return XmlParser::toArray($payload);
            } catch (Exception $e) {
                return strval($payload);
            }
        }
    }
}