Jmonitor
=========

Simple monitoring for PHP applications and web servers.

Jmonitor.io is a SaaS monitoring service that provides insights, alerting and premade dashboards from multiple sources commonly found in PHP web project stack (MySQL, Redis, Apache, Nginx, etc.).

This package provides collectors that gather metrics and send them to Jmonitor.io.

- Website: https://jmonitor.io
- Symfony integration: https://github.com/jmonitor/jmonitor-bundle

## Requirements
- PHP 7.4
- A project using [Composer](https://getcomposer.org/)

## Installation

```bash
composer require jmonitor/collector
```

Quick Start
---------------
Create a project in [jmonitor.io](https://jmonitor.io) and get your API key.

Then, create a separate script and start collecting metrics:

```php
use Jmonitor\Jmonitor;
use Jmonitor\Collector\Apache\ApacheCollector;

$jmonitor = new Jmonitor('apiKey');

// Add some collectors... see the documentation below for more collectors
$jmonitor->addCollector(new ApacheCollector('https://example.com/server-status'));
$jmonitor->addCollector(new SystemCollector());
// ... 

// send metrics to Jmonitor (see "Scheduling" section)
$jmonitor->collect();
```

### HTTP Client (PSR-18)
You can inject any PSR-18 HTTP client (e.g., Symfony HttpClient via Psr18Client, Guzzle via an adapter, etc.). Example :

```bash
composer require symfony/http-client nyholm/psr7
```

```php
use Symfony\Component\HttpClient\Psr18Client;

$httpClient = ... // create or retrieve your Symfony HttpClient instance
$client = new Psr18Client()->withOptions(...);

$jmonitor = new Jmonitor('apiKey', $client);
```

Scheduling
-----------
**Do not call `$jmonitor->collect()` on every web request**. Create a specific script and run it in a separate scheduled process, like a cron job.

The minimum time between collections is 15 seconds (subject to change as Jmonitor evolves).

Debugging and Error Handling
-----------------------------
Each collector is isolated and executed within a try/catch block.  
Use the CollectionResult returned by `collect()` method to inspect outcomes.

By default, collect() call send metrics to the server.  
You can disable this by passing `send: false`

By default, collect() throws InvalidServerResponseException when the server response status code is >= 400.  
You can disable this by passing `throwOnFailure: false`

```php
use Psr\Http\Message\ResponseInterface;
use Jmonitor\CollectionResult;


/**
 * Send metrics, you can :
 * - Disable throwing an exception on error
 * - Disable sending metrics to the server
 */
$result = $jmonitor->collect(throwOnFailure: false);
// Or disable completely the sending of metrics to the server
$result = $jmonitor->collect(send: false);

/**
 * Use $result to inspect
 */
// Human-readable summary (string)
$conclusion = $result->getConclusion(); 

// List of Exceptions if any (\Throwable[])
$errors = $result->getErrors(); 

// The raw response from jmonitor, if any (ResponseInterface|null)
$response = $result->getResponse(); 

// All metrics collected (mixed[])
$metrics = $result->getMetrics();
```

Collectors
-----------

- [System](#system)
- [Apache](#apache)
- [Nginx (todo)](#nginx)
- [Mysql](#mysql)
- [Php](#php)
- [Redis](#redis)
- [FrankenPHP](#frankenphp)
- [Caddy (todo)](#caddy)

- ### System <a name="system"></a>
  Collects system metrics like CPU usage, memory usage, disk usage, etc.
  Linux only for now. Feel free to open an issue if you need other OS support.

  ```php
  use Jmonitor\Collector\System\SystemCollector;
  
  $collector = new SystemCollector();
  
  // There is actually a "RandomAdapter" you can use on a Windows OS for testing purposes
  $collector = new SystemCollector(new RandomAdapter());
  ```

- ### Apache <a name="apache"></a> 
  Collects metrics from Apache server-status. Enable mod_status and expose a status URL.
  There are some resources to help you with that:
  - Apache docs :https://httpd.apache.org/docs/current/mod/mod_status.html.
  - Guide (EN) : https://statuslist.app/apache/apache-status-page-simple-setup-guide/
  - Guide (FR) : https://www.blog.florian-bogey.fr/activer-et-configurer-le-server-status-apache-mod_status.html  

  ```php
  use Jmonitor\Collector\Apache\ApacheCollector;
  
  $collector = new ApacheCollector('http://localhost/server-status');
  ```

- ### Nginx <a name="nginx"></a>
  Planned.

- ### Mysql <a name="mysql"></a>
  Collects MySQL variables and status. Connect via PDO or Doctrine DBAL (open an issue if you need other drivers, e.g., mysqli).
    
  ```php
  use Jmonitor\Collector\Mysql\MysqlCollector;
  use Jmonitor\Collector\Mysql\Adapter\PdoAdapter;
  use Jmonitor\Collector\Mysql\Adapter\DoctrineAdapter;
  use Jmonitor\Collector\Mysql\MysqlStatusCollector;
  use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
  use Jmonitor\Collector\Mysql\MysqlQueriesCountCollector;
  
  // Using PDO
  $adapter = new PdoAdapter($pdo); // your \PDO instance
  
  // or using Doctrine DBAL
  $adapter = new DoctrineAdapter($connection); // your Doctrine\DBAL\Connection instance
  
  // Mysql has multiple collectors, use the same adapter for all of them
  $collector = new MysqlStatusCollector($adapter);
  $collector = new MysqlVariablesCollector($adapter);
  $collector = new MysqlQueriesCountCollector($adapter, 'your_db_name');
  ```

- ### PHP <a name="php"></a>
  Collects PHP metrics (loaded extensions, some ini keys, FPM, opcache, etc.).  
  
> [!IMPORTANT]
>
> PHP configuration can differ significantly between CLI and web server SAPIs.  
> If you need web‑context metrics from a CLI script, expose an HTTP endpoint that returns these metrics as JSON (see below).

  - Collect CLI-context metrics
    ```php
    use Jmonitor\Collector\Php\PhpCollector;
   
    $collector = new PhpCollector();
    ```

  - Collect web-context metrics from CLI  
    Expose a metrics endpoint (and **make sure it is properly secured**). You can reuse php-exposer.php from this repo or create your own:
    ```php
    <?php
  
    use Jmonitor\Collector\Php\PhpCollector;
  
    require __DIR__ . '/../vendor/autoload.php';

    header('Content-Type: application/json');
  
    echo json_encode((new PhpCollector())->collect(), JSON_THROW_ON_ERROR);
    ```

  Then, in your CLI script, point the collector to that URL:    
    ```php
    use Jmonitor\Collector\Php\PhpCollector;

    $collector = new PhpCollector('https://localhost/php-metrics.php');
    ```

- ### Redis <a name="redis"></a>
  Collects Redis metrics from the INFO command.
  
  ```php
  use Jmonitor\Collector\Redis\RedisCollector;
  
  // Any client supporting INFO: PhpRedis, Predis, RedisArray, RedisCluster, Relay...
  $redis = new \Redis([...]);
  
  $collector = new RedisCollector($redis);
  ```

- ### Frankenphp <a name="frankenphp"></a>
  Collects from the [FrankenPHP](https://frankenphp.dev/docs/metrics/) metrics endpoint.

  ```php
  use Jmonitor\Collector\Frankenphp\FrankenphpCollector
  
  $collector = new FrankenphpCollector('http://localhost:2019/metrics');
  ```

  If you also use Caddy collector, you can share the same provider between collectors, avoiding an extra HTTP request.
  ```php
  use Jmonitor\Collector\Frankenphp\CaddyCollector
  use Jmonitor\Collector\Frankenphp\FrankenphpCollector
  use Jmonitor\Prometheus\PrometheusMetricsProvider;
  
  $provider = new PrometheusMetricsProvider('http://localhost:2019/metrics');
  $frankenCollector = new FrankenphpCollector($provider);
  $caddyCollector = new CaddyCollector($provider); 
  ```

- ### Caddy <a name="caddy"></a>
  Collects from the [Caddy](https://caddyserver.com/docs/metrics) metrics endpoint.

  ```php
  use Jmonitor\Collector\Caddy\CaddyCollector
  
  $collector = new CaddyCollector('http://localhost:2019/metrics');
  ```

  If you also use Frankenphp collector, you can share the same provider between collectors, avoiding an extra HTTP request.
  ```php
  use Jmonitor\Collector\Frankenphp\CaddyCollector
  use Jmonitor\Collector\Frankenphp\FrankenphpCollector
  use Jmonitor\Prometheus\PrometheusMetricsProvider;
  
  $provider = new PrometheusMetricsProvider('http://localhost:2019/metrics');
  $frankenCollector = new FrankenphpCollector($provider);
  $caddyCollector = new CaddyCollector($provider); 
  ```
  
Integrations
------------
- Symfony: https://github.com/jmonitor/jmonitor-bundle
- Laravel: planned

Roadmap
-------
- Nginx
- Memcached
- Laravel integration
- Custom metrics collection

---

Need help?
- Open an issue on this repo https://github.com/jmonitor/collector/issues
- Open a discussion on https://github.com/orgs/jmonitor/discussions
