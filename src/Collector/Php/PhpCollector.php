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
    private ?string $endpointUrl;

    public function __construct(?string $endpointUrl = null)
    {
        $this->endpointUrl = $endpointUrl;
    }

    public function collect(): array
    {
        if ($this->endpointUrl) {
            return $this->collectFromUrl();
        }

        return [
            'version' => phpversion(),
            'sapi_name' => php_sapi_name(),
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

    public function getName(): string
    {
        return 'php';
    }

    public function getVersion(): int
    {
        return 1;
    }
    private function collectFromUrl(): array
    {
        $metrics = file_get_contents($this->endpointUrl);

        return json_decode($metrics, true);
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

        if (!function_exists('\opcache_get_configuration')) {
            return [];
        }

        $status = \opcache_get_status(false);
        $config = \opcache_get_configuration();

        if ($status === false || $config === false) {
            return [];
        }

        unset($status['preload_statistics']);

        return [
            'config' => $config,
            'status' => $status,
        ];
    }

    private function getFpm(): array
    {
        if (function_exists('fpm_get_status')) {
            return fpm_get_status();
        }

        return [];
    }
}
