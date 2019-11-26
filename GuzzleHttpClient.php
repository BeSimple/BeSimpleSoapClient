<?php

namespace BeSimple\SoapClient;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RentTrack\HttpClient\EventBehavior\CompleteRequestEvent;
use RentTrack\HttpClient\EventBehavior\CompleteRequestEventHandlerInterface;
use RentTrack\HttpClient\HttpClient;

class GuzzleHttpClient implements HttpClientInterface, CompleteRequestEventHandlerInterface
{
    /**
     * HTTP User Agent.
     *
     * @var string
     */
    const USER_AGENT = 'PHP-SOAP/\BeSimple\Guzzle\SoapClient';

    /**
     * Maximum number of location headers to follow.
     *
     * @var int
     */
    private $followLocationMaxRedirects;

    /**
     * @var HttpClient
     */
    protected $client;

    /**
     * @var array
     */
    protected $requestHeaders;

    /**
     * @var array
     */
    protected $requestOptions;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * @var RequestException
     */
    protected $error;

    /**
     * Constructor.
     *
     * @param HttpClient $client
     * @param array $options                    Options array from SoapClient constructor
     * @param int   $followLocationMaxRedirects Redirection limit for Location header
     */
    public function __construct(HttpClient $client, array $options = [], $followLocationMaxRedirects = 10)
    {
        $this->client = $client;

        if (false == isset($options['user_agent'])) {
            $options['user_agent'] = self::USER_AGENT;
        }

        $this->followLocationMaxRedirects = $followLocationMaxRedirects;

        $requestHeaders = [
            'User-Agent' => $options['user_agent'], // CURLOPT_USERAGENT => $options['user_agent']
            'Accept' => '*/*',
            'Accept-Encoding' => 'deflate, gzip',   // CURLOPT_ENCODING => ''
        ];

        $requestOptions = [
            RequestOptions::VERIFY => false,            // CURLOPT_SSL_VERIFYPEER => false
            RequestOptions::HTTP_ERRORS => false,       // CURLOPT_FAILONERROR => false
            RequestOptions::VERSION => '1.1',           // CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
            RequestOptions::ALLOW_REDIRECTS => false,
        ];

        if (defined('CURLOPT_TCP_KEEPALIVE')) {
            $requestOptions['curl'][CURLOPT_TCP_KEEPALIVE] = 1;
            $requestOptions['curl'][CURLOPT_TCP_KEEPIDLE] = 180;
            $requestOptions['curl'][CURLOPT_TCP_KEEPINTVL] = 60;
        }

        if (isset($options['compression']) && !($options['compression'] & SOAP_COMPRESSION_ACCEPT)) {
            $requestHeaders['Accept-Encoding'] = 'identity';
            $requestOptions[RequestOptions::DECODE_CONTENT] = false;
        }

        if (isset($options['connection_timeout'])) {
            $requestOptions[RequestOptions::CONNECT_TIMEOUT] = $options['connection_timeout'];
        }

        if (isset($options['proxy_host'])) {
            $parts = parse_url($options['proxy_host']);

            // parse_url decodes single host as path
            if (1 === count($parts) && isset($parts['path'])) {
                $parts['host'] = $parts['path'];
                unset($parts['path']);
            }

            if (false == isset($parts['host'])) {
                $parts['host'] = '127.0.0.1';
            }

            if (false == isset($parts['schema'])) {
                $parts['schema'] = 'http';
            }

            if (isset($options['proxy_port'])) {
                $parts['port'] = $options['proxy_port'];
            }

            if (false == $parts['port']) {
                $parts['port'] = '8080';
            }

            if (isset($options['proxy_login'])) {
                $parts['user'] = $options['proxy_login'];
            }

            if (isset($options['proxy_password'])) {
                $parts['pass'] = $options['proxy_password'];
            }

            $requestOptions[RequestOptions::PROXY] = (string) Uri::fromParts($parts);
        }

        if (isset($options['login'])) {
            $auth = [$options['login'], $options['password']];

            if (isset($options['extra_options']['http_auth'])) {
                switch ($options['extra_options']['http_auth']) {
                    case CURLAUTH_BASIC:
                        break;
                    case CURLAUTH_DIGEST:
                        $auth[] = 'digest';
                        break;
                    case CURLAUTH_NTLM:
                        $auth[] = 'ntlm';
                        break;
                    default:
                        throw new \LogicException(sprintf(
                            '[GuzzleHttpClient] Auth method is not supported: %s',
                            $options['extra_options']['http_auth']
                        ));
                }
            }

            $requestOptions[RequestOptions::AUTH] = $auth;
        }

        if (isset($options['local_cert'])) {
            $requestOptions[RequestOptions::CERT] = isset($options['passphrase'])
                ? [$options['local_cert'], $options['passphrase']]
                : $options['local_cert']
            ;
        }

        if (isset($options['ca_info'])) {
            $requestOptions[RequestOptions::VERIFY] = $options['ca_info'];
        }

        if (isset($options['ca_path'])) {
            $requestOptions['curl'][CURLOPT_CAPATH] = $options['ca_path'];
        }

        if (isset($options['ssl_key'])) {
            $requestOptions[RequestOptions::SSL_KEY] = isset($options['ssl_keypasswd'])
                ? [$options['ssl_key'], $options['ssl_keypasswd']]
                : $options['ssl_key']
            ;
        }
        
        $this->client->addCompleteRequestEventHandler($this);

        $this->requestHeaders = $requestHeaders;
        $this->requestOptions = $requestOptions;
    }

    public function __destruct()
    {
    }

    /**
     * Execute HTTP request.
     * Returns true if request was successfull.
     *
     * @param string $location       HTTP location
     * @param string $request        Request body
     * @param array  $requestHeaders Request header strings
     * @param array  $requestOptions An array of request options
     *
     * @return bool
     */
    public function exec($location, $request = null, $requestHeaders = [], $requestOptions = [])
    {
        $this->request = null;
        $this->response = null;
        $this->error = null;

        $headers = array_replace($this->requestHeaders, $requestHeaders);
        $options = array_replace($this->requestOptions, $requestOptions);

        try {
            $this->response = $request
                ? $this->client->send('POST', $location, $headers, $request, $options)
                : $this->client->send('GET', $location, $headers, null, $options)
            ;

            $this->error = null;

            $this->execManualRedirect($request, $requestHeaders, $requestOptions);
        } catch (RequestException $e) {
            $this->error = $e;
            $this->response = null;
        }

        return (bool) $this->response;
    }

    /**
     * Custom curl_exec wrapper that allows to follow redirects when specific
     * http response code is set. SOAP only allows 307.
     *
     * @param int $redirects Current redirection count
     *
     * @return bool
     */
    protected function execManualRedirect($request = null, $requestHeaders = [], $requestOptions = [], $redirects = 0)
    {
        if ($redirects > $this->followLocationMaxRedirects) {

            // TODO Redirection limit reached, aborting
            return false;
        }

        if ($this->response->getStatusCode() === 307) {
            $this->exec(
                $this->response->getHeaderLine('Location'),
                $request,
                $requestHeaders,
                $requestOptions,
                ++$redirects
            );
        }

        return true;
    }

    /**
     * Gets the curl error message.
     *
     * @return string
     */
    public function getErrorMessage()
    {
        if (null === $this->error) {
            return '';
        }

        if (($context = $this->error->getHandlerContext()) && isset($context['error'])) {
            return $context['error'];
        }

        return '[GuzzleHttpClient] unknown error';
    }

    /**
     * Gets the request headers as a string.
     *
     * @return string
     */
    public function getRequestHeaders()
    {
        if (null === $this->request) {
            return '';
        }

        $headers = $this->request->getMethod().' '.$this->request->getRequestTarget()
            .' HTTP/'.$this->request->getProtocolVersion().PHP_EOL;

        foreach ($this->request->getHeaders() as $key => $value) {
            $headers .= $key .': '.$this->request->getHeaderLine($key).PHP_EOL;
        }

        return $headers.PHP_EOL;
    }

    /**
     * Gets the whole response (including headers) as a string.
     *
     * @return string
     */
    public function getResponse()
    {
       if (null === $this->response) {
           return '';
       }

       $response = $this->getResponseHeaders();
       $response .= (string) $this->response->getBody();

       return $response;
    }

    /**
     * Gets the response headers as a string.
     *
     * @return string
     */
    public function getResponseHeaders()
    {
        if (null === $this->response) {
            return '';
        }

        $headers = 'HTTP/'.$this->response->getProtocolVersion().' '.$this->response->getStatusCode().' '.
            $this->response->getReasonPhrase().PHP_EOL
        ;

        foreach ($this->response->getHeaders() as $key => $value) {
            $headers .= $key.': '.$this->response->getHeaderLine($key).PHP_EOL;
        }

        return $headers.PHP_EOL;
    }

    /**
     * Gets the response http status code.
     *
     * @return int|null
     */
    public function getResponseStatusCode()
    {
        if (null === $this->response) {
            return null;
        }

        return $this->response->getStatusCode();
    }

    /**
     * Gets the response content type.
     *
     * @return string
     */
    public function getResponseContentType()
    {
        if (null === $this->response) {
            return '';
        }

        return $this->response->getHeaderLine('Content-Type');
    }

    /**
     * Gets the response body as a string.
     *
     * @return string
     */
    public function getResponseBody()
    {
        if (null === $this->response) {
            return '';
        }

        return (string) $this->response->getBody();
    }

    /**
     * Gets the response http status message.
     *
     * @return string
     */
    public function getResponseStatusMessage()
    {
        if (null === $this->response) {
            return '';
        }

        return $this->response->getReasonPhrase();
    }

    /**
     * {@inheritDoc}
     */
    public function onComplete(CompleteRequestEvent $event)
    {
        $this->request = $event->getRequest();
    }
}