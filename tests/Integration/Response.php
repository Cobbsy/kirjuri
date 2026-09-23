<?php

namespace Kirjuri\Tests\Integration;

final class Response
{
    public function __construct(public int $status, public string $headers, public string $body)
    {
    }

    /** The Location header path, without scheme and host, or null. */
    public function location(): ?string
    {
        if (!preg_match('/^Location:\s*(.+)$/mi', $this->headers, $match)) {
            return null;
        }
        return ltrim(preg_replace('#^https?://[^/]+#', '', trim($match[1])), '/');
    }

    public function header(string $name): ?string
    {
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $this->headers, $match) ? trim($match[1]) : null;
    }

    /** The value of the first hidden input with this name, e.g. the CSRF token. */
    public function inputValue(string $name): ?string
    {
        return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $this->body, $match) ? html_entity_decode($match[1]) : null;
    }
}
