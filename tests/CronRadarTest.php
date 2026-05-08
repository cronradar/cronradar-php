<?php

namespace CronRadar\Tests;

use CronRadar\CronRadar;
use PHPUnit\Framework\TestCase;

/**
 * CronRadar PHP SDK Tests
 */
class CronRadarTest extends TestCase
{
    private $originalApiKey;

    protected function setUp(): void
    {
        $this->originalApiKey = getenv('CRONRADAR_API_KEY');
        putenv('CRONRADAR_API_KEY=test-api-key-123');
    }

    protected function tearDown(): void
    {
        if ($this->originalApiKey !== false) {
            putenv("CRONRADAR_API_KEY={$this->originalApiKey}");
        } else {
            putenv('CRONRADAR_API_KEY');
        }
    }

    /**
     * Test that monitor() does not throw when API key is missing
     */
    public function testMonitorDoesNotThrowWhenApiKeyMissing(): void
    {
        putenv('CRONRADAR_API_KEY');

        // Should not throw
        CronRadar::monitor('test-job');
        $this->assertTrue(true);
    }

    /**
     * Test that startJob() does not throw when API key is missing
     */
    public function testStartJobDoesNotThrowWhenApiKeyMissing(): void
    {
        putenv('CRONRADAR_API_KEY');

        // Should not throw
        CronRadar::startJob('test-job');
        $this->assertTrue(true);
    }

    /**
     * Test that completeJob() does not throw when API key is missing
     */
    public function testCompleteJobDoesNotThrowWhenApiKeyMissing(): void
    {
        putenv('CRONRADAR_API_KEY');

        // Should not throw
        CronRadar::completeJob('test-job');
        $this->assertTrue(true);
    }

    /**
     * Test that failJob() does not throw when API key is missing
     */
    public function testFailJobDoesNotThrowWhenApiKeyMissing(): void
    {
        putenv('CRONRADAR_API_KEY');

        // Should not throw
        CronRadar::failJob('test-job', 'Test error');
        $this->assertTrue(true);
    }

    /**
     * Test that syncMonitor() does not throw when API key is missing
     */
    public function testSyncMonitorDoesNotThrowWhenApiKeyMissing(): void
    {
        putenv('CRONRADAR_API_KEY');

        // Should not throw
        CronRadar::syncMonitor('test-job', '* * * * *');
        $this->assertTrue(true);
    }

    /**
     * Test that wrap() executes the callable
     */
    public function testWrapExecutesCallable(): void
    {
        $executed = false;
        $wrappedFn = CronRadar::wrap('test-job', function() use (&$executed) {
            $executed = true;
            return 'result';
        });

        $result = $wrappedFn();

        $this->assertTrue($executed);
        $this->assertEquals('result', $result);
    }

    /**
     * Test that wrap() passes arguments to callable
     */
    public function testWrapPassesArguments(): void
    {
        $wrappedFn = CronRadar::wrap('test-job', function($a, $b) {
            return $a + $b;
        });

        $result = $wrappedFn(2, 3);

        $this->assertEquals(5, $result);
    }

    /**
     * Test that wrap() re-throws exceptions
     */
    public function testWrapRethrowsExceptions(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test error');

        $wrappedFn = CronRadar::wrap('test-job', function() {
            throw new \RuntimeException('Test error');
        });

        $wrappedFn();
    }

    /**
     * Test generateReadableName with kebab-case
     */
    public function testGenerateReadableNameKebabCase(): void
    {
        $reflection = new \ReflectionClass(CronRadar::class);
        $method = $reflection->getMethod('generateReadableName');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'check-overdue-pings');

        $this->assertEquals('Check Overdue Pings', $result);
    }

    /**
     * Test generateReadableName with snake_case
     */
    public function testGenerateReadableNameSnakeCase(): void
    {
        $reflection = new \ReflectionClass(CronRadar::class);
        $method = $reflection->getMethod('generateReadableName');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'check_overdue_pings');

        $this->assertEquals('Check Overdue Pings', $result);
    }

    /**
     * Test generateReadableName with PascalCase
     */
    public function testGenerateReadableNamePascalCase(): void
    {
        $reflection = new \ReflectionClass(CronRadar::class);
        $method = $reflection->getMethod('generateReadableName');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'CheckOverduePings');

        $this->assertStringContainsString('Check', $result);
        $this->assertStringContainsString('Overdue', $result);
    }

    /**
     * Test generateReadableName with empty string
     */
    public function testGenerateReadableNameEmptyString(): void
    {
        $reflection = new \ReflectionClass(CronRadar::class);
        $method = $reflection->getMethod('generateReadableName');
        $method->setAccessible(true);

        $result = $method->invoke(null, '');

        $this->assertEquals('', $result);
    }

    /**
     * Test generateReadableName with simple key
     */
    public function testGenerateReadableNameSimpleKey(): void
    {
        $reflection = new \ReflectionClass(CronRadar::class);
        $method = $reflection->getMethod('generateReadableName');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'backup');

        $this->assertEquals('Backup', $result);
    }

    /**
     * Test detectSource returns valid source
     */
    public function testDetectSourceReturnsValidSource(): void
    {
        $reflection = new \ReflectionClass(CronRadar::class);
        $method = $reflection->getMethod('detectSource');
        $method->setAccessible(true);

        $result = $method->invoke(null);

        $validSources = ['manual', 'laravel', 'symfony', 'codeigniter', 'laravel-direct', 'symfony-direct'];
        $this->assertContains($result, $validSources);
    }
}
