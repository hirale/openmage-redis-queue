<?php

declare(strict_types=1);

class Hirale_Queue_Model_Platform_Maho implements Hirale_Queue_Model_Platform_AdapterInterface
{
    /**
     * Return the stable platform code represented by this adapter.
     */
    public function getCode(): string
    {
        return Hirale_Queue_Helper_Platform::CODE_MAHO;
    }

    /**
     * Maho exposes entity reindexing directly on the index process.
     *
     * @param mixed $process Maho index process instance.
     */
    public function supportsProcessEntityReindex($process): bool
    {
        return is_object($process) && method_exists($process, 'reindexEntity');
    }

    /**
     * Run Maho's process-level entity reindex API.
     *
     * @param mixed $process Maho index process instance.
     * @param int|array<int|string, int|string> $entityIds Entity ID or list of IDs.
     */
    public function reindexProcessEntities($process, int|array $entityIds): bool
    {
        if (!$this->supportsProcessEntityReindex($process)) {
            return false;
        }

        $process->reindexEntity($entityIds);
        return true;
    }
}
