<?php

declare(strict_types=1);

use Monolog\Level;

class Hirale_Queue_Adminhtml_QueueController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'system/tools/hirale_queue';

    #[\Override]
    public function preDispatch()
    {
        $this->_setForcedFormKeyActions(['retry', 'cancel', 'purge', 'testConnection']);

        return parent::preDispatch();
    }

    public function indexAction(): void
    {
        $this->_title($this->__('System'))->_title($this->__('Tools'))->_title($this->__('Hirale Queue'));
        $this->loadLayout();
        $this->_setActiveMenu('system/tools/hirale_queue');
        $this->_addContent($this->getLayout()->createBlock('hirale_queue/adminhtml_job_stats'));
        $this->_addContent($this->getLayout()->createBlock('hirale_queue/adminhtml_job'));
        $this->renderLayout();
    }

    public function retryAction(): void
    {
        $jobId = (string) $this->getRequest()->getParam('job_id', '');
        if ($jobId !== '') {
            try {
                $this->_getQueue()->retry($jobId);
                $this->_getSession()->addSuccess($this->__('Queue job was queued for retry.'));
            } catch (Throwable $e) {
                $this->_logException($e);
                $this->_getSession()->addError($this->__('Queue job could not be queued for retry.'));
            }
        }

        $this->_redirect('*/*/index');
    }

    public function cancelAction(): void
    {
        $jobId = (string) $this->getRequest()->getParam('job_id', '');
        if ($jobId !== '') {
            try {
                $this->_getQueue()->cancel($jobId);
                $this->_getSession()->addSuccess($this->__('Queue job was canceled.'));
            } catch (Throwable $e) {
                $this->_logException($e);
                $this->_getSession()->addError($this->__('Queue job could not be canceled.'));
            }
        }

        $this->_redirect('*/*/index');
    }

    public function purgeAction(): void
    {
        $helper = Mage::helper('hirale_queue');
        $successDays = (int) $helper->getConfigValue('success_retention_days', 7);
        $failureDays = (int) $helper->getConfigValue('failure_retention_days', 30);
        try {
            $deleted = $this->_getQueue()->purgeByRetention(max(1, $successDays), max(1, $failureDays));
            $this->_getSession()->addSuccess($this->__('%d finished queue job(s) were purged.', $deleted));
        } catch (Throwable $e) {
            $this->_logException($e);
            $this->_getSession()->addError($this->__('Finished queue jobs could not be purged.'));
        }

        $this->_redirect('*/*/index');
    }

    public function testConnectionAction(): void
    {
        try {
            if ($this->_getQueue()->testRedisConnection()) {
                $this->_getSession()->addSuccess($this->__('Redis connection succeeded.'));
            } else {
                $this->_getSession()->addError($this->__('Redis did not return PONG.'));
            }
        } catch (Throwable $e) {
            $this->_logException($e);
            $this->_getSession()->addError($this->__('Redis connection test failed.'));
        }

        $this->_redirect('*/*/index');
    }

    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed(self::ADMIN_RESOURCE);
    }

    private function _getQueue(): Hirale_Queue_Model_Queue
    {
        $queue = Mage::getModel('hirale_queue/queue');
        if (!$queue instanceof Hirale_Queue_Model_Queue) {
            throw new RuntimeException('Hirale Queue service is unavailable.');
        }

        return $queue;
    }

    private function _logException(Throwable $e): void
    {
        Mage::log($e->getMessage(), Level::Error, 'exception.log');
    }
}
