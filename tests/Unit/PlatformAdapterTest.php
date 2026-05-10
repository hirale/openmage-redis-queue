<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use HiraleQueue\Tests\Support\EntitiesResource;
use HiraleQueue\Tests\Support\MahoProcess;
use HiraleQueue\Tests\Support\ProductIdsResource;
use HiraleQueue\Tests\Support\ProductsResource;
use HiraleQueue\Tests\Support\ResourceIndexer;
use HiraleQueue\Tests\Support\ResourceIndexerProcess;
use HiraleQueue\Tests\Support\ThrowingProcess;
use Hirale_Queue_Helper_Platform;
use Hirale_Queue_Model_Platform_Factory;
use Hirale_Queue_Model_Platform_Maho;
use Hirale_Queue_Model_Platform_Openmage;
use Mage;
use PHPUnit\Framework\TestCase;
use stdClass;

class PlatformAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        Mage::resetTestState();
    }

    public function testPlatformHelperDefaultsToOpenMageWhenMahoClassesAreAbsent(): void
    {
        $helper = new Hirale_Queue_Helper_Platform();

        $this->assertSame(Hirale_Queue_Helper_Platform::CODE_OPENMAGE, $helper->getCode());
        $this->assertFalse($helper->isMaho());
        $this->assertTrue($helper->isOpenMage());
    }

    public function testFactoryResolvesOpenMageAdapterFromRuntimeDetection(): void
    {
        Mage::setHelper('hirale_queue/platform', new Hirale_Queue_Helper_Platform());
        Mage::setModel('hirale_queue/platform_openmage', new Hirale_Queue_Model_Platform_Openmage());

        $adapter = (new Hirale_Queue_Model_Platform_Factory())->getAdapter();

        $this->assertInstanceOf(Hirale_Queue_Model_Platform_Openmage::class, $adapter);
        $this->assertSame(Hirale_Queue_Helper_Platform::CODE_OPENMAGE, $adapter->getCode());
    }

    public function testMahoAdapterCallsProcessLevelEntityReindex(): void
    {
        $adapter = new Hirale_Queue_Model_Platform_Maho();
        $process = new MahoProcess();

        $this->assertTrue($adapter->supportsProcessEntityReindex($process));
        $this->assertTrue($adapter->reindexProcessEntities($process, [10, 20]));
        $this->assertSame([10, 20], $process->entityIds);
    }

    public function testMahoAdapterReturnsFalseForUnsupportedProcess(): void
    {
        $adapter = new Hirale_Queue_Model_Platform_Maho();

        $this->assertFalse($adapter->supportsProcessEntityReindex(new stdClass()));
        $this->assertFalse($adapter->reindexProcessEntities(new stdClass(), 10));
    }

    public function testOpenMageAdapterPrefersProductIdReindexResourceMethod(): void
    {
        $resource = new ProductIdsResource();
        $process = new ResourceIndexerProcess(new ResourceIndexer($resource));
        $adapter = new Hirale_Queue_Model_Platform_Openmage();

        $this->assertTrue($adapter->supportsProcessEntityReindex($process));
        $this->assertTrue($adapter->reindexProcessEntities($process, [10, 20]));
        $this->assertSame([10, 20], $resource->entityIds);
    }

    public function testOpenMageAdapterFallsBackToEntityReindexResourceMethod(): void
    {
        $resource = new EntitiesResource();
        $process = new ResourceIndexerProcess(new ResourceIndexer($resource));

        $this->assertTrue((new Hirale_Queue_Model_Platform_Openmage())->reindexProcessEntities($process, 10));
        $this->assertSame(10, $resource->entityIds);
    }

    public function testOpenMageAdapterFallsBackToProductsReindexResourceMethod(): void
    {
        $resource = new ProductsResource();
        $process = new ResourceIndexerProcess(new ResourceIndexer($resource));

        $this->assertTrue((new Hirale_Queue_Model_Platform_Openmage())->reindexProcessEntities($process, [30]));
        $this->assertSame([30], $resource->entityIds);
    }

    public function testOpenMageAdapterReturnsFalseWhenIndexerCannotBeLoaded(): void
    {
        $adapter = new Hirale_Queue_Model_Platform_Openmage();

        $this->assertFalse($adapter->supportsProcessEntityReindex(new ThrowingProcess()));
        $this->assertFalse($adapter->reindexProcessEntities(new stdClass(), 10));
    }
}
