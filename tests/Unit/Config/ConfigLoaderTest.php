<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Config;

use Cartwright\ImapMcp\Config\ConfigException;
use Cartwright\ImapMcp\Config\ConfigLoader;
use Cartwright\ImapMcp\Tests\Support\TempDirectory;
use Cartwright\ImapMcp\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
    }

    protected function tearDown(): void
    {
        putenv(ConfigLoader::ENV_VAR);
        $this->dir->remove();
    }

    public function testLoadsAPrivateConfigWithoutWarnings(): void
    {
        $path = $this->write(self::raw(['runtime' => null]), 0600);

        $config = ConfigLoader::load($path);

        self::assertSame($this->dir->path . '/var', $config->runtime->storageDir);
        self::assertSame([], $config->warnings);
    }

    public function testWarnsWhenOthersCanReadIt(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions only');
        }
        $path = $this->write(self::raw(), 0644);

        self::assertStringContainsString('chmod 600', implode("\n", ConfigLoader::load($path)->warnings));
    }

    public function testWarnsWhenConfigOrStorageIsInsideTheDocumentRoot(): void
    {
        $path = $this->write(self::raw(['runtime' => null]), 0600);

        $warnings = ConfigLoader::load($path, $this->dir->path)->warnings;

        self::assertCount(2, $warnings);
        self::assertStringContainsString('config.php is inside the public document root', $warnings[0]);
        self::assertStringContainsString('storage_dir is inside the public document root', $warnings[1]);
    }

    public function testNoDocumentRootWarningForASiblingDirectory(): void
    {
        $public = $this->dir->path . '/public_html';
        mkdir($public);
        $path = $this->write(self::raw(['runtime' => null]), 0600);

        self::assertSame([], ConfigLoader::load($path, $public)->warnings);
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('not found');

        ConfigLoader::load($this->dir->path . '/missing.php');
    }

    public function testBrokenPhpIsRefusedWithoutDetails(): void
    {
        $path = $this->dir->path . '/config.php';
        file_put_contents($path, "<?php\nreturn [;\n");

        try {
            ConfigLoader::load($path);
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertSame(['config.php could not be loaded (ParseError)'], $e->errors);
        }
    }

    public function testEnvironmentVariableOverridesTheDefaultPath(): void
    {
        self::assertSame('/default/config.php', ConfigLoader::resolvePath('/default/config.php'));

        putenv(ConfigLoader::ENV_VAR . '=/from/env/config.php');

        self::assertSame('/from/env/config.php', ConfigLoader::resolvePath('/default/config.php'));
    }

    /**
     * TestConfig without its public client, so no warning is expected by default.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function raw(array $overrides = []): array
    {
        $raw = TestConfig::raw($overrides);
        $clients = $raw['auth']['oauth']['clients'];
        $raw['auth']['oauth']['clients'] = [$clients[0]];

        return $raw;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function write(array $raw, int $mode): string
    {
        $path = $this->dir->path . '/config.php';
        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($raw, true) . ";\n");
        chmod($path, $mode);

        return $path;
    }
}
