<?php

interface Hirale_Queue_Model_TaskHandlerInterface {
    public function handle($data);
}