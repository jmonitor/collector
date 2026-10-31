<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\CloudflareException;

/**
 * Helpers shared by the blocks to read GraphQL rows.
 */
final class Rows
{
    public static function time(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * First day of the UTC month of $timestamp, at midnight.
     */
    public static function monthStart(int $timestamp): int
    {
        return (int) gmmktime(0, 0, 0, (int) gmdate('n', $timestamp), 1, (int) gmdate('Y', $timestamp));
    }

    /**
     * @param array<string, mixed> $viewer
     *
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public static function firstNode(array $viewer, string $key, string $operation): array
    {
        $node = $viewer[$key][0] ?? null;

        if (!is_array($node)) {
            throw new CloudflareException(CloudflareException::ERROR, sprintf('GraphQL %s: no "%s" node in the response', $operation, $key));
        }

        return $node;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function list(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Request count per value of a dimension, highest first.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    public static function countBy(array $rows, string $dimension): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $label = (string) ($row['dimensions'][$dimension] ?? '');
            $counts[$label] = ($counts[$label] ?? 0) + (int) ($row['count'] ?? 0);
        }

        arsort($counts);

        return $counts;
    }

    public static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
