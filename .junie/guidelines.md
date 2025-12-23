### Project
The project is a php library that allows you to collect metrics then send them to Jmonitor.

### Build and Configuration

- **Requirements**: PHP ^7.4 or ^8.0.
- **Installation**: Use Composer to install dependencies.
  ```bash
  composer install
  ```
- **HTTP Client**: The library uses PSR-18. Ensure a compatible client and factory implementations are available (e.g., `symfony/http-client` and `nyholm/psr7`).

### Testing

#### Configuration and Running Tests
- **PHPUnit**: Tests are located in the `tests/` directory.
- **Running all tests**:
  ```bash
  composer phpunit
  ```
  Or directly:
  ```bash
  ./vendor/bin/phpunit tests
  ```
- **Running a specific test file**:
  ```bash
  ./vendor/bin/phpunit tests/Collector/Php/PhpCollectorTest.php
  ```

#### Adding New Tests
- New test classes should extend `PHPUnit\Framework\TestCase`.
- Place tests in the `tests/` directory, mirroring the `src/` directory structure.
- Example of a simple test:
  ```php
  <?php
  namespace Jmonitor\Tests;
  use PHPUnit\Framework\TestCase;

  class SimpleTest extends TestCase {
      public function testTrueIsTrue(): void {
          $this->assertTrue(true);
      }
  }
  ```

### Development Guidelines

#### Code Style and Linting
- **Standard**: Follows PSR-12/PER coding standards.
- **Strict Types**: Always include `declare(strict_types=1);` in PHP files.
- **Linting**: Use `php-cs-fixer` to check and fix code style.
  - Check: `composer lint:check`
  - Fix: `composer lint:fix`
- **Static Analysis**: Use PHPStan for static analysis.
  - Run: `composer phpstan`

#### Architectural Patterns
- **Collectors**: All collectors must implement `Jmonitor\Collector\CollectorInterface` (or extend `Jmonitor\Collector\AbstractCollector`).
- **Isolation**: Each collector's `collect()` method is executed within a try/catch block in `Jmonitor::collect()`.
- **Result Handling**: The `CollectionResult` object captures metrics, errors, and the HTTP response from the Jmonitor API.
