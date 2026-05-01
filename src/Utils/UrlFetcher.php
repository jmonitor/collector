<?php

declare(strict_types=1);

namespace Jmonitor\Utils;

class UrlFetcher
{
    private int $timeout;

    public function __construct(int $timeout = 3)
    {
        $this->timeout = $timeout;
    }

    public function fetch(string $url): string
    {
        $context = stream_context_create([
            'http' => ['timeout' => $this->timeout],
            'https' => ['timeout' => $this->timeout],
        ]);

        error_clear_last();
        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $error = error_get_last();
            throw new \RuntimeException(sprintf(
                'Failed to fetch "%s": %s',
                $url,
                $error['message'] ?? 'unknown error'
            ));
        }

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchJson(string $url): array
    {
        $content = $this->fetch($url);

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf(
                'Failed to decode JSON from "%s": %s',
                $url,
                json_last_error_msg()
            ));
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(
                'Expected JSON object from "%s", got %s',
                $url,
                gettype($data)
            ));
        }

        return $data;
    }
}
