<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Psr\Log\LoggerInterface;

/**
 * R2 storage and month-to-date usage. Usage comes from GraphQL, which is free, rather than from the bucket usage
 * endpoint, which is itself a billable class B operation.
 */
final class R2Block implements BlockInterface
{
    private const QUERY = <<<'GRAPHQL'
        query R2($accountTag: string!, $monthStart: Time!, $to: Time!, $fromStorage: Time!) { viewer { accounts(filter: {accountTag: $accountTag}) {
          r2Storage: r2StorageAdaptiveGroups(limit: 1000, filter: {datetime_geq: $fromStorage, datetime_lt: $to}, orderBy: [datetime_DESC]) { dimensions { bucketName storageClass datetime } max { payloadSize metadataSize objectCount uploadCount } }
          r2Ops: r2OperationsAdaptiveGroups(limit: 500, filter: {datetime_geq: $monthStart, datetime_lt: $to}) { dimensions { bucketName actionType } sum { requests } }
          r2Bandwidth: r2BandwidthUsageAdaptiveGroups(limit: 100, filter: {datetime_geq: $monthStart, datetime_lt: $to}) { dimensions { bucketName } sum { bytesUpload bytesDownload } }
        } } }
        GRAPHQL;

    // Storage samples can lag by up to an hour.
    private const STORAGE_LOOKBACK = 7200;

    private const DEFAULT_STORAGE_CLASS = 'Standard';

    // Cloudflare's pricing lists. Anything else (deletes, aborts...) is free and ignored.
    private const CLASS_A = [
        'ListBuckets', 'PutBucket', 'ListObjects', 'PutObject', 'CopyObject', 'CompleteMultipartUpload',
        'CreateMultipartUpload', 'LifecycleStorageTierTransition', 'ListMultipartUploads', 'UploadPart',
        'UploadPartCopy', 'ListParts', 'PutBucketEncryption', 'PutBucketCors', 'PutBucketLifecycleConfiguration',
    ];

    private const CLASS_B = [
        'HeadBucket', 'HeadObject', 'GetObject', 'UsageSummary', 'ReportUsageSummary', 'GetBucketEncryption',
        'GetBucketLocation', 'GetBucketCors', 'GetBucketLifecycleConfiguration',
    ];

    public function getName(): string
    {
        return 'r2';
    }

    public function getRefreshKey(): string
    {
        return 'storage';
    }

    public function fetch(CloudflareApi $api, CloudflareZone $zone, int $to, LoggerInterface $logger): array
    {
        $buckets = $this->listBuckets($api, $zone);

        if ($buckets === []) {
            throw new CloudflareException(CloudflareException::NOT_CONFIGURED, 'No R2 bucket on the account.');
        }

        $viewer = $api->graphql(self::QUERY, [
            'accountTag' => $zone->accountId,
            'monthStart' => Rows::time(Rows::monthStart($to)),
            'to' => Rows::time($to),
            'fromStorage' => Rows::time($to - self::STORAGE_LOOKBACK),
        ]);

        $node = Rows::firstNode($viewer, 'accounts', 'R2');
        $storage = $this->latestStorage(Rows::list($node['r2Storage'] ?? null));
        [$operations, $operationTotals] = $this->operations(Rows::list($node['r2Ops'] ?? null));
        [$bandwidth, $bandwidthTotals] = $this->bandwidth(Rows::list($node['r2Bandwidth'] ?? null));

        $bucketsData = [];

        foreach ($buckets as $bucket) {
            $name = (string) $bucket['name'];
            $createdAt = isset($bucket['creation_date']) ? strtotime((string) $bucket['creation_date']) : false;
            $bucketStorage = $storage[$name] ?? self::emptyStorage();
            $bucketOperations = $operations[$name] ?? self::emptyOperations();
            $bucketBandwidth = $bandwidth[$name] ?? self::emptyBandwidth();

            $bucketsData[] = [
                'name' => $name,
                'storage_class' => $bucketStorage['storage_class'],
                'created_at' => $createdAt === false ? null : $createdAt,
                'object_count' => $bucketStorage['object_count'],
                'payload_bytes' => $bucketStorage['payload_bytes'],
                'metadata_bytes' => $bucketStorage['metadata_bytes'],
                'class_a_operations' => $bucketOperations['class_a_operations'],
                'class_b_operations' => $bucketOperations['class_b_operations'],
                'upload_bytes' => $bucketBandwidth['upload_bytes'],
                'download_bytes' => $bucketBandwidth['download_bytes'],
            ];
        }

        return [
            'month' => gmdate('Y-m', $to),
            'class_a_operations' => $operationTotals['class_a_operations'],
            'class_b_operations' => $operationTotals['class_b_operations'],
            'upload_bytes' => $bandwidthTotals['upload_bytes'],
            'download_bytes' => $bandwidthTotals['download_bytes'],
            'buckets' => $bucketsData,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws CloudflareException
     */
    private function listBuckets(CloudflareApi $api, CloudflareZone $zone): array
    {
        $query = ['per_page' => 100];
        $buckets = [];
        $seenCursors = [];

        while (true) {
            $body = $api->get('/accounts/' . $zone->accountId . '/r2/buckets', $query);

            foreach (Rows::list($body['result']['buckets'] ?? null) as $bucket) {
                if (isset($bucket['name'])) {
                    $buckets[] = $bucket;
                }
            }

            $cursor = $body['result_info']['cursor'] ?? null;

            if (!is_string($cursor) || $cursor === '' || isset($seenCursors[$cursor])) {
                return $buckets;
            }

            $seenCursors[$cursor] = true;
            $query['cursor'] = $cursor;
        }
    }

    /**
     * Storage is sampled per bucket and class: keep each class's latest sample, then sum the classes.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, array{storage_class: string, object_count: int, payload_bytes: int, metadata_bytes: int}>
     */
    private function latestStorage(array $rows): array
    {
        $latest = [];

        foreach ($rows as $row) {
            $bucket = (string) ($row['dimensions']['bucketName'] ?? '');
            $class = (string) ($row['dimensions']['storageClass'] ?? self::DEFAULT_STORAGE_CLASS);
            $time = strtotime((string) ($row['dimensions']['datetime'] ?? '')) ?: 0;

            if (!isset($latest[$bucket][$class]) || $time > $latest[$bucket][$class]['time']) {
                $latest[$bucket][$class] = ['time' => $time, 'max' => (array) ($row['max'] ?? [])];
            }
        }

        $storage = [];

        foreach ($latest as $bucket => $classes) {
            $data = self::emptyStorage();
            $mostObjects = 0;

            foreach ($classes as $class => $sample) {
                $objects = (int) ($sample['max']['objectCount'] ?? 0);

                $data['object_count'] += $objects;
                $data['payload_bytes'] += (int) ($sample['max']['payloadSize'] ?? 0);
                $data['metadata_bytes'] += (int) ($sample['max']['metadataSize'] ?? 0);

                if ($objects > $mostObjects) {
                    $mostObjects = $objects;
                    $data['storage_class'] = (string) $class;
                }
            }

            $storage[(string) $bucket] = $data;
        }

        return $storage;
    }

    /**
     * Operations without a bucket (ListBuckets...) count in the account totals only.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{array<string, array{class_a_operations: int, class_b_operations: int}>, array{class_a_operations: int, class_b_operations: int}}
     */
    private function operations(array $rows): array
    {
        $perBucket = [];
        $totals = self::emptyOperations();

        foreach ($rows as $row) {
            $action = (string) ($row['dimensions']['actionType'] ?? '');
            $key = match (true) {
                in_array($action, self::CLASS_A, true) => 'class_a_operations',
                in_array($action, self::CLASS_B, true) => 'class_b_operations',
                default => null,
            };

            if ($key === null) {
                continue;
            }

            $requests = (int) ($row['sum']['requests'] ?? 0);
            $bucket = (string) ($row['dimensions']['bucketName'] ?? '');

            $totals[$key] += $requests;

            if ($bucket !== '') {
                $perBucket[$bucket] ??= self::emptyOperations();
                $perBucket[$bucket][$key] += $requests;
            }
        }

        return [$perBucket, $totals];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{array<string, array{upload_bytes: int, download_bytes: int}>, array{upload_bytes: int, download_bytes: int}}
     */
    private function bandwidth(array $rows): array
    {
        $perBucket = [];
        $totals = self::emptyBandwidth();

        foreach ($rows as $row) {
            $bucket = (string) ($row['dimensions']['bucketName'] ?? '');
            $upload = (int) ($row['sum']['bytesUpload'] ?? 0);
            $download = (int) ($row['sum']['bytesDownload'] ?? 0);

            $totals['upload_bytes'] += $upload;
            $totals['download_bytes'] += $download;

            if ($bucket !== '') {
                $perBucket[$bucket] ??= self::emptyBandwidth();
                $perBucket[$bucket]['upload_bytes'] += $upload;
                $perBucket[$bucket]['download_bytes'] += $download;
            }
        }

        return [$perBucket, $totals];
    }

    /**
     * @return array{storage_class: string, object_count: int, payload_bytes: int, metadata_bytes: int}
     */
    private static function emptyStorage(): array
    {
        return ['storage_class' => self::DEFAULT_STORAGE_CLASS, 'object_count' => 0, 'payload_bytes' => 0, 'metadata_bytes' => 0];
    }

    /**
     * @return array{class_a_operations: int, class_b_operations: int}
     */
    private static function emptyOperations(): array
    {
        return ['class_a_operations' => 0, 'class_b_operations' => 0];
    }

    /**
     * @return array{upload_bytes: int, download_bytes: int}
     */
    private static function emptyBandwidth(): array
    {
        return ['upload_bytes' => 0, 'download_bytes' => 0];
    }
}
