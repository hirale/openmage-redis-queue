<?php

declare(strict_types=1);

class Hirale_Queue_Block_Adminhtml_Job_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('hirale_queue_job_grid');
        $this->setDefaultSort('entity_id');
        $this->setDefaultDir('desc');
        $this->setSaveParametersInSession(true);
    }

    protected function _prepareCollection()
    {
        $collection = new Varien_Data_Collection();
        foreach ($this->_getRepository()->search($this->_getFilters(), 200) as $row) {
            $collection->addItem(new Varien_Object($row));
        }
        $this->setCollection($collection);

        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $this->addColumn('job_id', [
            'header' => Mage::helper('hirale_queue')->__('Job ID'),
            'index' => 'job_id',
            'type' => 'text',
        ]);
        $this->addColumn('status', [
            'header' => Mage::helper('hirale_queue')->__('Status'),
            'index' => 'status',
            'type' => 'options',
            'options' => array_combine(Hirale_Queue_Model_Job::statuses(), Hirale_Queue_Model_Job::statuses()),
        ]);
        $this->addColumn('queue_name', [
            'header' => Mage::helper('hirale_queue')->__('Queue'),
            'index' => 'queue_name',
            'type' => 'text',
        ]);
        $this->addColumn('handler', [
            'header' => Mage::helper('hirale_queue')->__('Handler'),
            'index' => 'handler',
            'type' => 'text',
        ]);
        $this->addColumn('attempt', [
            'header' => Mage::helper('hirale_queue')->__('Attempt'),
            'index' => 'attempt',
            'type' => 'number',
        ]);
        $this->addColumn('max_attempts', [
            'header' => Mage::helper('hirale_queue')->__('Max Attempts'),
            'index' => 'max_attempts',
            'type' => 'number',
        ]);
        $this->addColumn('last_error', [
            'header' => Mage::helper('hirale_queue')->__('Last Error'),
            'index' => 'last_error',
            'type' => 'text',
            'truncate' => 120,
        ]);
        $this->addColumn('available_at', [
            'header' => Mage::helper('hirale_queue')->__('Available At'),
            'index' => 'available_at',
            'type' => 'datetime',
        ]);
        $this->addColumn('updated_at', [
            'header' => Mage::helper('hirale_queue')->__('Updated At'),
            'index' => 'updated_at',
            'type' => 'datetime',
        ]);
        $this->addColumn('action', [
            'header' => Mage::helper('hirale_queue')->__('Action'),
            'type' => 'action',
            'getter' => 'getJobId',
            'actions' => [
                [
                    'caption' => Mage::helper('hirale_queue')->__('Retry'),
                    'url' => ['base' => '*/*/retry'],
                    'field' => 'job_id',
                ],
                [
                    'caption' => Mage::helper('hirale_queue')->__('Cancel'),
                    'url' => ['base' => '*/*/cancel'],
                    'field' => 'job_id',
                ],
            ],
            'filter' => false,
            'sortable' => false,
        ]);

        return parent::_prepareColumns();
    }

    public function getRowUrl($row)
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function _getFilters(): array
    {
        $filters = [];
        $gridFilter = $this->getParam($this->getVarNameFilter(), null);
        if (!is_string($gridFilter) || $gridFilter === '') {
            return $filters;
        }

        $decoded = Mage::helper('adminhtml')->prepareFilterString($gridFilter);
        if (!empty($decoded['status'])) {
            $filters['status'] = $decoded['status'];
        }
        if (!empty($decoded['handler'])) {
            $filters['handler'] = $decoded['handler'];
        }
        if (!empty($decoded['queue_name'])) {
            $filters['queue'] = $decoded['queue_name'];
        }

        return $filters;
    }

    private function _getRepository(): Hirale_Queue_Model_JobRepository
    {
        $repository = Mage::getModel('hirale_queue/jobRepository');
        if (!$repository instanceof Hirale_Queue_Model_JobRepository) {
            throw new RuntimeException('Hirale Queue job repository is unavailable.');
        }

        return $repository;
    }
}
