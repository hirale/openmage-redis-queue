<?php

/**
 * Admin grid container for hirale_queue_job. Adds the page-level action
 * buttons (Test Connection, Purge Finished) that wrap the grid.
 */
class Hirale_Queue_Block_Adminhtml_Job extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'hirale_queue';
        $this->_controller = 'adminhtml_job';
        $this->_headerText = Mage::helper('hirale_queue')->__('Hirale Queue Jobs');
        parent::__construct();

        $this->_removeButton('add');
        $this->_addButton('test_connection', [
            'label'   => Mage::helper('hirale_queue')->__('Test Connection'),
            'onclick' => "setLocation('" . $this->getUrl('*/*/testConnection') . "')",
            'class'   => 'task',
        ]);
        $this->_addButton('purge_finished', [
            'label'   => Mage::helper('hirale_queue')->__('Purge Finished'),
            'onclick' => "if (confirm('" . Mage::helper('hirale_queue')->__('Apply retention policy now?') . "')) setLocation('" . $this->getUrl('*/*/purge') . "')",
            'class'   => 'delete',
        ]);
    }
}
