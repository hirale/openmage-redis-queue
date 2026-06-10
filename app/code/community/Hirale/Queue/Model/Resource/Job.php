<?php

class Hirale_Queue_Model_Resource_Job extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct(): void
    {
        $this->_init('hirale_queue/job', 'entity_id');
    }
}
