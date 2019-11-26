<?php

namespace BeSimple\SoapClient;

interface HttpClientInterface
{
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
    public function exec($location, $request = null, $requestHeaders = array(), $requestOptions = array());

    /**
     * Gets the curl error message.
     *
     * @return string
     */
    public function getErrorMessage();

    /**
     * Gets the request headers as a string.
     *
     * @return string
     */
    public function getRequestHeaders();

    /**
     * Gets the whole response (including headers) as a string.
     *
     * @return string
     */
    public function getResponse();

    /**
     * Gets the response body as a string.
     *
     * @return string
     */
    public function getResponseBody();

    /**
     * Gets the response content type.
     *
     * @return string
     */
    public function getResponseContentType();

    /**
     * Gets the response headers as a string.
     *
     * @return string
     */
    public function getResponseHeaders();

    /**
     * Gets the response http status code.
     *
     * @return string
     */
    public function getResponseStatusCode();

    /**
     * Gets the response http status message.
     *
     * @return string
     */
    public function getResponseStatusMessage();
}
