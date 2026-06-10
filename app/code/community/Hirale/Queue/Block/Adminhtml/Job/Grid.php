<?php

/**
 * Admin grid for hirale_queue_job. Columns match v2's UX so operators have a
 * familiar surface, with `message_class` replacing v2's `handler` column.
 */
class Hirale_Queue_Block_Adminhtml_Job_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('hirale_queue_job_grid');
        $this->setDefaultSort('updated_at');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(false);
    }

    protected function _prepareCollection(): Mage_Adminhtml_Block_Widget_Grid
    {
        /** @var Mage_Core_Model_Resource_Db_Collection_Abstract $collection */
        $collection = Mage::getResourceModel('hirale_queue/job_collection');
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns(): Mage_Adminhtml_Block_Widget_Grid
    {
        $helper = Mage::helper('hirale_queue');

        $this->addColumn('job_id', [
            'header' => $helper->__('Job ID'),
            'index'  => 'job_id',
            'type'   => 'text',
            'width'  => '260',
        ]);
        $this->addColumn('message_class', [
            'header' => $helper->__('Message'),
            'index'  => 'message_class',
            'type'   => 'text',
        ]);
        $this->addColumn('queue_name', [
            'header' => $helper->__('Queue'),
            'index'  => 'queue_name',
            'type'   => 'text',
            'width'  => '110',
        ]);
        $this->addColumn('status', [
            'header'   => $helper->__('Status'),
            'index'    => 'status',
            'type'     => 'options',
            'options'  => Hirale_Queue_Model_Job::statuses(),
            'width'    => '90',
        ]);
        $this->addColumn('attempt', [
            'header' => $helper->__('Attempt'),
            'index'  => 'attempt',
            'type'   => 'number',
            'width'  => '70',
        ]);
        $this->addColumn('max_attempts', [
            'header' => $helper->__('Max'),
            'index'  => 'max_attempts',
            'type'   => 'number',
            'width'  => '50',
        ]);
        $this->addColumn('last_error', [
            'header'           => $helper->__('Last Error'),
            'index'            => 'last_error',
            'type'             => 'text',
            'filter'           => false,
            'sortable'         => false,
            'string_limit'     => 120,
        ]);
        $this->addColumn('available_at', [
            'header' => $helper->__('Available At'),
            'index'  => 'available_at',
            'type'   => 'datetime',
            'width'  => '150',
        ]);
        $this->addColumn('updated_at', [
            'header' => $helper->__('Updated'),
            'index'  => 'updated_at',
            'type'   => 'datetime',
            'width'  => '150',
        ]);
        $this->addColumn('action', [
            'header'   => $helper->__('Action'),
            'width'    => '100',
            'type'     => 'action',
            'getter'   => 'getJobId',
            'filter'   => false,
            'sortable' => false,
            'actions'  => [
                [
                    'caption' => $helper->__('View'),
                    'url'     => ['base' => '*/*/view'],
                    'field'   => 'job_id',
                ],
                [
                    'caption' => $helper->__('Retry'),
                    'url'     => ['base' => '*/*/retry'],
                    'field'   => 'job_id',
                ],
                [
                    'caption' => $helper->__('Cancel'),
                    'url'     => ['base' => '*/*/cancel'],
                    'field'   => 'job_id',
                    'confirm' => $helper->__('Cancel this job?'),
                ],
            ],
        ]);

        return parent::_prepareColumns();
    }

    public function getRowUrl($row): string
    {
        return $this->getUrl('*/*/view', ['job_id' => $row->getJobId()]);
    }
}
