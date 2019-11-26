<?php

namespace BeSimple\SoapClient;

use RentTrack\HttpClient\HttpClient;

class HttpClientFactory
{
    const CURL = 'curl';
    const GUZZLE = 'guzzle';

    /**
     * @var HttpClient
     */
    protected $guzzleClient;

    /**
     * @param HttpClient $guzzleClient
     */
    public function __construct(HttpClient $guzzleClient)
    {
        $this->guzzleClient = $guzzleClient;
    }

    /**
     * @param string $name
     * @param array $options
     *
     * @return HttpClientInterface
     */
    public function getHttpClient($name, array $options)
    {
        switch ($name) {
            case self::CURL:
                return $this->getCurlHttpClient($options);
            case self::GUZZLE:
                return $this->getGuzzleHttpClient($options);
            default:
                throw new \LogicException(sprintf('Unsupported http client: ""%s', $name));
        }
    }

    /**
     * @param array $options
     *
     * @return Curl
     */
    protected function getCurlHttpClient(array $options)
    {
        return new Curl($options);
    }

    /**
     * @param array $options
     *
     * @return GuzzleHttpClient
     */
    protected function getGuzzleHttpClient(array $options)
    {
        return new GuzzleHttpClient($this->guzzleClient, $options);
    }
}
