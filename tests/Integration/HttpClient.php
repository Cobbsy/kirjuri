<?php

namespace Kirjuri\Tests\Integration;

/** A small HTTP client with its own cookie jar, so each instance is a separate browser session. */
final class HttpClient
{
    private $curl;

    public function __construct(private string $baseUrl)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, array(
                CURLOPT_COOKIEFILE => '', // In-memory cookie jar.
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => 30,
            ));
    }

    /** Send this Referer header with the following requests. */
    public function setReferer(string $url): void
    {
        curl_setopt($this->curl, CURLOPT_REFERER, $url);
    }

    public function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    /** $fields may contain \CURLFile values, which sends a multipart upload. */
    public function post(string $path, array $fields = array(), bool $multipart = false): Response
    {
        return $this->request('POST', $path, $multipart ? $fields : http_build_query($fields));
    }

    public function request(string $method, string $path, $body = null): Response
    {
        curl_setopt($this->curl, CURLOPT_URL, $this->baseUrl . '/' . ltrim($path, '/'));
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->curl, CURLOPT_POST, $method === 'POST');
        if ($body !== null) {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        }
        $raw = curl_exec($this->curl);
        $headerSize = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        return new Response(
            curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE),
            substr($raw, 0, $headerSize),
            substr($raw, $headerSize)
        );
    }
}
