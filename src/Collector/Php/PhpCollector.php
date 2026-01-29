<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Php;

use Jmonitor\Collector\AbstractCollector;

/**
 * I guess INI-related values could be cached in a property cache, but is the performance impact real?
 */
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
            'expose_php' => $this->getIniValue('expose_php', 'bool'),
            'memory_limit' =>  $this->getIniValue('memory_limit'), // string conservé pour les unités (ex 128M)
            'max_execution_time' => $this->getIniValue('max_execution_time', 'int'),
            'max_input_time' => $this->getIniValue('max_input_time', 'int'),
            'max_input_vars' => $this->getIniValue('max_input_vars', 'int'),
            'realpath_cache_size' => realpath_cache_size(),
            'post_max_size' => $this->getIniValue('post_max_size'),
            'upload_max_filesize' => $this->getIniValue('upload_max_filesize'),
            'display_errors' => $this->getIniValue('display_errors'),
            'display_startup_errors' => $this->getIniValue('display_startup_errors', 'bool'),
            'log_errors' => $this->getIniValue('log_errors', 'bool'),
            'error_log' => $this->getIniValue('error_log'),
            'error_reporting' => $this->getIniValue('error_reporting', 'int'),
            'date.timezone' => $this->getIniValue('date.timezone'),
            'loaded_extensions' => get_loaded_extensions(),
            'opcache' => $this->getOpcacheInfos(),
            'apcu' => $this->getApcuInfos(),
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
        $metrics = @file_get_contents($this->endpointUrl);

        return json_decode($metrics, true);
    }

    /**
     * @return mixed
     */
    private function getIniValue(string $key, string $type = 'string')
    {
        $value = ini_get($key);

        if ($value === false) {
            return null;
        }

        switch ($type) {
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'int':
                return (int) $value;
            default:
                return $value;
        }
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

    private function getApcuInfos(): array
    {
        if (!function_exists('\apcu_cache_info') || !function_exists('\apcu_sma_info') || !function_exists('\apcu_enabled')) {
            return [];
        }

        // enabled in current context ?
        $enabled = apcu_enabled();

        return [
            'config' => [
                'apc.enabled' => $this->getIniValue('apc.enabled', 'bool'),
                'apc.enable_cli' => $this->getIniValue('apc.enable_cli', 'bool'),
                'apc.shm_size' => $this->getIniValue('apc.shm_size'),
                'apc.shm_segments' => $this->getIniValue('apc.shm_segments', 'int'),
                'apc.ttl' => $this->getIniValue('apc.ttl', 'int'),
            ],
            'cache_info' => $enabled ? \apcu_cache_info(true) : [],
            'sma_info' => $enabled ? \apcu_sma_info(true) : [],
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
