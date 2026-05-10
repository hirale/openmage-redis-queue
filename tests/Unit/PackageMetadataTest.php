<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PackageMetadataTest extends TestCase
{
    public function testComposerMetadataAdvertisesMahoAndOpenMageCompatibilityBoundaries(): void
    {
        $composer = $this->readJson('composer.json');

        $this->assertSame('magento-module', $composer['type']);
        $this->assertContains('maho', $composer['keywords']);
        $this->assertContains('mahoecommerce', $composer['keywords']);
        $this->assertSame('<25.9', $composer['conflict']['mahocommerce/maho']);
        $this->assertSame('<20.17', $composer['conflict']['openmage/magento-lts']);
        $this->assertSame('^3.0 || ^4.0 || ^99.99', $composer['require']['magento-hackathon/magento-composer-installer']);
    }

    public function testModuleDeclarationKeepsCommunityScopeForLocalOverrides(): void
    {
        $moduleXml = simplexml_load_file($this->rootPath('src/app/etc/modules/Hirale_Queue.xml'));

        $this->assertSame('community', (string) $moduleXml->modules->Hirale_Queue->codePool);
    }

    public function testQueueWorkerIsDisabledByDefault(): void
    {
        $configXml = simplexml_load_file($this->rootPath('src/app/code/community/Hirale/Queue/etc/config.xml'));

        $this->assertSame('0', (string) $configXml->default->hirale_queue->settings->enabled);
        $this->assertSame('', (string) $configXml->default->hirale_queue->settings->dsn);
        $this->assertSame('hirale_queue_worker', (string) $configXml->default->hirale_queue->settings->consumer);
    }

    public function testPhpUnitDevDependencySupportsPhp81Runtime(): void
    {
        $composer = $this->readJson('composer.json');

        $this->assertSame('^10.5', $composer['require-dev']['phpunit/phpunit']);
    }

    public function testSystemConfigUsesHiraleTabAndQueueSection(): void
    {
        $systemXml = simplexml_load_file($this->rootPath('src/app/code/community/Hirale/Queue/etc/system.xml'));

        $this->assertSame('Hirale', (string) $systemXml->tabs->hirale->label);
        $this->assertSame('Queue', (string) $systemXml->sections->hirale_queue->label);
        $this->assertSame('hirale', (string) $systemXml->sections->hirale_queue->tab);
        $this->assertTrue(isset($systemXml->sections->hirale_queue->groups->settings));
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($this->rootPath($path));
        $this->assertIsString($contents);

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function rootPath(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }
}
