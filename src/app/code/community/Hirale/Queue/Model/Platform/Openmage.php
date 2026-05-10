<?php

declare(strict_types=1);

class Hirale_Queue_Model_Platform_Openmage implements Hirale_Queue_Model_Platform_AdapterInterface
{
    /**
     * Return the stable platform code represented by this adapter.
     */
    public function getCode(): string
    {
        return Hirale_Queue_Helper_Platform::CODE_OPENMAGE;
    }

    /**
     * OpenMage exposes entity reindexing on indexer resources, and method names
     * differ across indexers and versions.
     *
     * @param mixed $process OpenMage index process instance.
     */
    public function supportsProcessEntityReindex($process): bool
    {
        if (!is_object($process) || !method_exists($process, 'getIndexer')) {
            return false;
        }

        try {
            $resource = $process->getIndexer()->getResource();
        } catch (Throwable) {
            return false;
        }

        return method_exists($resource, 'reindexProductIds')
            || method_exists($resource, 'reindexEntities')
            || method_exists($resource, 'reindexProducts');
    }

    /**
     * Run the first compatible OpenMage resource-level reindex API.
     *
     * @param mixed $process OpenMage index process instance.
     * @param int|array<int|string, int|string> $entityIds Entity ID or list of IDs.
     */
    public function reindexProcessEntities($process, int|array $entityIds): bool
    {
        if (!$this->supportsProcessEntityReindex($process)) {
            return false;
        }

        $resource = $process->getIndexer()->getResource();
        if (method_exists($resource, 'reindexProductIds')) {
            $resource->reindexProductIds($entityIds);
            return true;
        }
        if (method_exists($resource, 'reindexEntities')) {
            $resource->reindexEntities($entityIds);
            return true;
        }
        if (method_exists($resource, 'reindexProducts')) {
            $resource->reindexProducts($entityIds);
            return true;
        }

        return false;
    }
}
