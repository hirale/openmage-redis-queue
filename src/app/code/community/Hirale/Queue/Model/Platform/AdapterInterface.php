<?php

declare(strict_types=1);

interface Hirale_Queue_Model_Platform_AdapterInterface
{
    /**
     * Return the stable platform code represented by this adapter.
     */
    public function getCode(): string;

    /**
     * Check whether the given index process supports entity-level reindexing.
     *
     * @param mixed $process Maho/OpenMage index process instance.
     */
    public function supportsProcessEntityReindex($process): bool;

    /**
     * Reindex one or more entities through the platform's available index API.
     *
     * @param mixed $process Maho/OpenMage index process instance.
     * @param int|array<int|string, int|string> $entityIds Entity ID or list of IDs.
     * @return bool True when the adapter handled the reindex request.
     */
    public function reindexProcessEntities($process, int|array $entityIds): bool;
}
