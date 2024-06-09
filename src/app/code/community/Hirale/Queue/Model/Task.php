<?php
use Predis\Response\ServerException;

class Hirale_Queue_Model_Task
{
    protected $_redis;
    protected $_count;
    protected $_streamKey = 'hirale_queue_stream';

    public function __construct()
    {
        $this->_redis = Mage::helper('hirale_queue')->getRedis();
    }

    /**
     * Adds a task to the queue.
     *
     * @param string $handler The handler for the task.
     * @param mixed $data The data for the task.
     * @param int $retryCount The number of times to retry the task if it fails. Default is 3.
     * @param int $retryDelay The delay in seconds between task retries. Default is 60.
     * @param int $timeout The timeout in seconds for the task. Default is 60.
     * @throws ServerException If there is an error adding the task to the queue.
     * @return void
     */
    public function addTask($handler, $data, $retryCount = 3, $retryDelay = 60, $timeout = 60)
    {
        try {
            $taskId = uniqid();
            $task = [
                'id' => $taskId,
                'handler' => $handler,
                'data' => json_encode($data),
                'retry_count' => $retryCount,
                'retry_delay' => $retryDelay,
                'timeout' => $timeout,
            ];
            $this->_redis->xadd($this->_streamKey, $task);
        } catch (ServerException $e) {
            Mage::log($e->getMessage(), Zend_Log::ERR, 'exception.log');
        }

    }

    /**
     * Fetches tasks from the Redis stream.
     *
     * This function retrieves tasks from the Redis stream specified by the `$_streamKey` property.
     * It uses the `XREAD` command to fetch a maximum of 10 messages from the stream, with a block
     * timeout of 5000 milliseconds. The function then iterates over the fetched messages and
     * extracts the necessary information (message ID, handler, data, retry count, retry delay,
     * and timeout) to construct an array of tasks.
     *
     * @return array|null  Returns an array of tasks, where each task is represented as an associative
     *              array containing the keys 'id', 'handler', 'data', 'retry_count', 'retry_delay',
     *              and 'timeout'.
     */
    public function fetchTasks()
    {
        if (!$this->_count) {
            $this->_count = Mage::getStoreConfig('system/hirale_queue/count') ?: 10;
        }
        $tasks = [];
        $results = $this->_redis->executeRaw([
            'XREAD',
            'COUNT',
            $this->_count,
            'BLOCK',
            '5000',
            'STREAMS',
            $this->_streamKey,
            '0'
        ]);

        if (empty($results)) {
            return null;
        }
        foreach ($results as $streamData) {
            $messages = $streamData[1];
            foreach ($messages as $message) {
                $payload = $message[1];
                $tasks[] = [
                    'id' => $message[0],
                    'handler' => $payload[3],
                    'data' => json_decode($payload[5], true),
                    'retry_count' => $payload[7],
                    'retry_delay' => $payload[9],
                    'timeout' => $payload[11],
                ];
            }
        }
        return $tasks;
    }

    /**
     * Processes the tasks by fetching them from the Redis stream and processing each task.
     *
     * This function fetches tasks from the Redis stream specified by the `$_streamKey` property.
     * It uses the `XREAD` command to fetch a maximum of 10 messages from the stream, with a block
     * timeout of 5000 milliseconds. The function then iterates over the fetched messages and
     * extracts the necessary information (message ID, handler, data, retry count, retry delay,
     * and timeout) to construct an array of tasks.
     *
     * If no tasks are found, the function returns early. Otherwise, it processes each task by
     * calling the `processTask` method with the task as an argument.
     *
     * If an exception occurs during the processing of tasks, it is logged using the `Mage::logException`
     * method.
     *
     * @throws Exception If an exception occurs during the processing of tasks.
     * @return void
     */
    public function process()
    {
        try {
            $tasks = $this->fetchTasks();
            if (empty($tasks)) {
                return;
            }
            foreach ($tasks as $task) {
                $this->processTask($task);
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Process a task.
     *
     * This function processes a task. It first checks if the task is empty and returns if it is.
     * It then creates a lock key based on the task ID and checks if the lock is set. If the lock
     * is already set, the function returns.
     *
     * The function sets the expiration time of the lock and dispatches an event to register
     * handlers. It then gets the handler for the task and calls its handle method.
     *
     * If an exception occurs during the processing of the task, the function logs the error
     * and decrements the retry count. If the retry count is greater than 0, the function adds
     * the task back to the stream and sleeps for the specified retry delay.
     *
     * @param array $task The task to be processed.
     * @throws \Exception If an error occurs during the processing of the task.
     * @return void
     */
    public function processTask($task)
    {
        if (empty($task)) {
            return;
        }
        $lockKey = "hirale_queue_lock:" . $task['id'];
        $retryCount = $task['retry_count'];

        if (!$this->_redis->setnx($lockKey, 1)) {
            return;
        }

        $this->_redis->expire($lockKey, $task['timeout'] + 10);

        try {
            $handler = new $task['handler']();
            $handler->handle($task);
            $this->_redis->del($lockKey);
            $this->_redis->executeRaw(['XACK', $this->_streamKey, $task['id']]);
            $this->_redis->xdel($this->_streamKey, $task['id']);
        } catch (\Exception $e) {
            Mage::log('Failed to process task: ' . $task['handler'] . ', Error: ' . $e->getMessage(), Zend_Log::ERR, 'exception.log');
            $retryCount--;

            if ($retryCount > 0) {
                $task['retry_count'] = $retryCount;
                $this->_redis->xadd($this->_streamKey, $task);
                sleep($task['retry_delay']);
            } else {
                Mage::log('Failed to process task: ' . print_r($task, true) . ', Error: ' . $e->getMessage(), Zend_Log::ERR, 'exception.log');
            }
            $this->_redis->del($lockKey);
        }
    }
}
