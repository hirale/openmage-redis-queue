<?php

declare(strict_types=1);

class Hirale_Queue_Block_Adminhtml_Job extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_controller = 'adminhtml_job';
        $this->_blockGroup = 'hirale_queue';
        $this->_headerText = Mage::helper('hirale_queue')->__('Hirale Queue');
        parent::__construct();

        $this->_removeButton('add');
        $this->_addButton('test_connection', [
            'label' => Mage::helper('hirale_queue')->__('Test Redis Connection'),
            'onclick' => 'setLocation(\'' . $this->getUrl('*/*/testConnection', ['form_key' => $this->getFormKey()]) . '\')',
            'class' => 'save',
        ]);
        $this->_addButton('purge_finished', [
            'label' => Mage::helper('hirale_queue')->__('Purge Finished Jobs'),
            'onclick' => 'setLocation(\'' . $this->getUrl('*/*/purge', ['form_key' => $this->getFormKey()]) . '\')',
            'class' => 'delete',
        ]);
    }
}
