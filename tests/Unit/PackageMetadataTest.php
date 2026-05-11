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
        $this->assertSame('100', (string) $configXml->default->hirale_queue->settings->publish_limit);
        $this->assertSame('300', (string) $configXml->default->hirale_queue->settings->pending_idle_seconds);
    }

    public function testPhpUnitDevDependencySupportsPhp81Runtime(): void
    {
        $composer = $this->readJson('composer.json');

        $this->assertSame('^10.5', $composer['require-dev']['phpunit/phpunit']);
    }

    public function testComposerMetadataMapsPlatformNativeWorkerEntrypoints(): void
    {
        $composer = $this->readJson('composer.json');

        $this->assertContains(
            ['src/shell/hirale_queue_worker.php', 'shell/hirale_queue_worker.php'],
            $composer['extra']['map'],
        );
        $this->assertContains(
            ['src/lib/MahoCLI/Commands/HiraleQueueWork.php', 'lib/MahoCLI/Commands/HiraleQueueWork.php'],
            $composer['extra']['map'],
        );
    }

    public function testSystemConfigUsesHiraleTabAndQueueSection(): void
    {
        $systemXml = simplexml_load_file($this->rootPath('src/app/code/community/Hirale/Queue/etc/system.xml'));

        $this->assertSame('Hirale', (string) $systemXml->tabs->hirale->label);
        $this->assertSame('Queue', (string) $systemXml->sections->hirale_queue->label);
        $this->assertSame('hirale', (string) $systemXml->sections->hirale_queue->tab);
        $this->assertTrue(isset($systemXml->sections->hirale_queue->groups->settings));
        $this->assertTrue(isset($systemXml->sections->hirale_queue->groups->settings->fields->publish_limit));
    }

    public function testModuleVersionIsMajorTwoForDbBackedQueueLine(): void
    {
        $configXml = simplexml_load_file($this->rootPath('src/app/code/community/Hirale/Queue/etc/config.xml'));

        $this->assertSame('2.0.0', (string) $configXml->modules->Hirale_Queue->version);
    }

    public function testAdminBlocksAreRegisteredForQueuePage(): void
    {
        $configXml = simplexml_load_file($this->rootPath('src/app/code/community/Hirale/Queue/etc/config.xml'));

        $this->assertSame('Hirale_Queue_Block', (string) $configXml->global->blocks->hirale_queue->class);
    }

    public function testAdminMenuExposesQueueOperations(): void
    {
        $adminhtmlXml = simplexml_load_file($this->rootPath('src/app/code/community/Hirale/Queue/etc/adminhtml.xml'));
        $controllerPath = $this->rootPath('src/app/code/community/Hirale/Queue/controllers/Adminhtml/QueueController.php');
        $legacyControllerPath = $this->rootPath('src/app/code/community/Hirale/Queue/controllers/Adminhtml/Hirale/QueueController.php');

        $this->assertSame(
            'adminhtml/queue/index',
            (string) $adminhtmlXml->menu->system->children->tools->children->hirale_queue->action,
        );
        $this->assertTrue(isset($adminhtmlXml->acl->resources->admin->children->system->children->tools->children->hirale_queue));
        $this->assertFileExists($controllerPath);
        $this->assertStringContainsString(
            'class Hirale_Queue_Adminhtml_QueueController',
            (string) file_get_contents($controllerPath),
        );
        $this->assertFileExists($legacyControllerPath);
        $this->assertStringContainsString(
            'class Hirale_Queue_Adminhtml_Hirale_QueueController extends Hirale_Queue_Adminhtml_QueueController',
            (string) file_get_contents($legacyControllerPath),
        );
    }

    public function testWorkerEntrypointsExistForOpenMageAndMaho(): void
    {
        $shellPath = $this->rootPath('src/shell/hirale_queue_worker.php');
        $mahoCommandPath = $this->rootPath('src/lib/MahoCLI/Commands/HiraleQueueWork.php');

        $this->assertFileExists($shellPath);
        $this->assertStringContainsString(
            'class Hirale_Queue_Shell_Worker extends Mage_Shell_Abstract',
            (string) file_get_contents($shellPath),
        );
        $this->assertFileExists($mahoCommandPath);
        $this->assertStringContainsString(
            "name: 'hirale:queue:work'",
            (string) file_get_contents($mahoCommandPath),
        );
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
