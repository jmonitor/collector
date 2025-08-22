<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Jmonitor\Collector\Php;

use Jmonitor\Collector\AbstractCollector;

class PhpCollector extends AbstractCollector
{
    public function collect(): array
    {
        return [
            'version' => phpversion(),
            'ini_file' => php_ini_loaded_file(),
            'ini_files' => $this->getIniFiles(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'date.timezone' => ini_get('date.timezone'),
            'loaded_extensions' => get_loaded_extensions(),
            'opcache' => $this->getOpcacheInfos(),
            'fpm' => $this->getFpm(),
        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    /**
     * @return string[]
     */
    private function getIniFiles(): array
    {
        $files = php_ini_scanned_files();

        if (empty($files)) {
            return [];
        }

        return array_map('trim', explode(',', $files));
    }

    private function getOpcacheInfos(): array
    {
        if (!function_exists('\opcache_get_status')) {
            return [];
        }

        $status = \opcache_get_status(false);

        if ($status === false) {
            return [
                'enabled' => ini_get('opcache.enable'),
                'enabled_cli' => ini_get('opcache.enable_cli'),
            ];
        }

        return [
            'enabled' => ini_get('opcache.enable'),
            'enabled_cli' => ini_get('opcache.enable_cli'),
            'cache_full' => $status['cache_full'],
            'memory_usage' => $status['memory_usage'] ?? [],
            'interned_strings_usage' => $status['interned_strings_usage'] ?? [],
            'statistics' => $status['opcache_statistics'] ?? [],
            'jit' => $status['jit'] ?? [],
        ];
    }

    public function getName(): string
    {
        return 'php';
    }

    private function getFpm(): array
    {
        if (!function_exists('fpm_get_status')) {
            return [];
        }

        return fpm_get_status();
    }
}
