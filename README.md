Jmonitor
=========

Easy monitoring for PHP web server.  
[Jmonitor.io](https://jmonitor.io) is a simple monitoring sass for PHP applications and web servers that provides insights and alerting from various sources like MySQL, Redis, Apache, Nginx...

This package provide the collectors which send metrics to Jmonitor.io.

## Requirements
- PHP 7.4
- Having a project with a [Composer](https://getcomposer.org/) dependency manager

## Installation

```bash
composer require jmonitor/collector
```

Getting Started
---------------
Create a project in [jmonitor.io](https://jmonitor.io) and get your API key.

Then, in your project, create a `Jmonitor` instance and add some collectors.

```php
use Jmonitor\Jmonitor;
use Jmonitor\Collector\Apache\ApacheCollector;

$jmonitor = new Jmonitor('apiKey');

// Add some collectors 
$jmonitor->addCollector(new ApacheCollector('https://example.com/server-status'));
$jmonitor->addCollector(new SystemCollector());
// see the documentation below for more collectors

// send metrics periodically to jmonitor (ex. every 15 seconds)
$jmonitor->collect();
```

You can customize your HttpClient, for example, if you want to use the [Symfony HttpClient](https://symfony.com/doc/current/http_client.html#psr-18-and-psr-17) or Guzzle.

```bash
composer require symfony/http-client nyholm/psr7
```

```php
use Symfony\Component\HttpClient\Psr18Client;

$httpClient = ... // create or retrieve your Symfony HttpClient instance
$client = new Psr18Client()->withOptions(...);

$jmonitor = new Jmonitor('apiKey', $client);
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
  Only Linux is supported for now, feel free to open an issue if you need support for another OS.

  ```php
  use Jmonitor\Collector\System\SystemCollector;
    
  $collector = new SystemCollector();
  ```

- ### Apache <a name="apache"></a> 
  Collects Apache server metrics from a server status URL.  
  You'll need to enable the `mod_status` module in Apache and set up a server status URL.
  There are some resources to help you with that:
  - Apache doc :https://httpd.apache.org/docs/current/mod/mod_status.html.
  - Blogpost in English : https://statuslist.app/apache/apache-status-page-simple-setup-guide/
  - Blogpost in French : https://www.blog.florian-bogey.fr/activer-et-configurer-le-server-status-apache-mod_status.html  

  Then you'll be able to use the `ApacheCollector` class to collect metrics from the server status URL.

  ```php
  use Jmonitor\Collector\Apache\ApacheCollector;
  
  $collector = new ApacheCollector('http://localhost/server-status');
  ```

- ### Mysql <a name="mysql"></a>
    Collects MySQL server variables and status.  
    You'll need to use PDO or Doctrine to connect to your MySQL database. If you need support for other drivers, like Mysqli, please open an issue.
    
  ```php
  use Jmonitor\Collector\Mysql\MysqlCollector;
  use Jmonitor\Collector\Mysql\Adapter\PdoAdapter;
  use Jmonitor\Collector\Mysql\Adapter\DoctrineAdapter;
  use Jmonitor\Collector\Mysql\MysqlStatusCollector;
  use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
  use Jmonitor\Collector\Mysql\MysqlQueriesCountCollector;
  
  // with PDO
  $adapter = new PdoAdapter($pdo); // retrieve your \PDO connection
  
  // or Doctrine DBAL
  $adapter = new DoctrineAdapter($connection) /* retrieve your Doctrine\DBAL\Connection connection*/ );
  
  // Mysql has multiple collectors, use the same adapter for all of them
  $collector = new MysqlStatusCollector($adapter);
  $collector = new MysqlVariablesCollector($adapter);
  $collector = new MysqlQueriesCountCollector($adapter, 'your_db_name');
  ```

- ### Php <a name="php"></a>
  Collects PHP metrics like loaded extensions, some ini settings, opcache status, etc.  
  Php FPM status URL support is coming soon.

  ```php
  use Jmonitor\Collector\Php\PhpCollector
  
  $collector = new PhpCollector();
  ```

- ### Redis <a name="redis"></a>
  Collects Redis metrics from info command.
  
  ```php
  use Jmonitor\Collector\Redis\RedisCollector;
  
  // You can use any Redis client that supports the info command, like Predis or PhpRedis.
  $redisClient = new \Redis([...]);
  $redisClient = new Predis\Client();
  // also support \RedisArray, \RedisCluster, Relay... feel free to open an issue if you need support for another client.
  
  $collector = new RedisCollector($redis);
  ```

- ### Frankenphp <a name="frankenphp"></a>
  Collects metrics from [FrankenPHP](https://frankenphp.dev/docs/metrics/) metrics endpoint (which is a Caddy endpoint actually).

  ```php
  use Jmonitor\Collector\Frankenphp\FrankenphpCollector
  
  $collector = new FrankenphpCollector('http://localhost:2019/metrics');
  ```

- ### Caddy <a name="caddy"></a>
  Collects metrics from [Caddy](https://caddyserver.com/docs/metrics) metrics endpoint.

  ```php
  use Jmonitor\Collector\Caddy\CaddyCollector
  
  $collector = new CaddyCollector('http://localhost:2019/metrics');
  ```
