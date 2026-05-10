<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

if (!class_exists('Mage_Core_Helper_Abstract', false)) {
    class Mage_Core_Helper_Abstract
    {
    }
}

if (!class_exists('Mage', false)) {
    class Mage
    {
        /** @var array<string, mixed> */
        public static array $config = [];

        /** @var array<string, mixed> */
        public static array $helpers = [];

        /** @var array<string, mixed> */
        public static array $models = [];

        /** @var array<int, array{message: mixed, level: mixed, file: string}> */
        public static array $logs = [];

        /** @var list<Throwable> */
        public static array $exceptions = [];

        public static function resetTestState(): void
        {
            self::$config = [];
            self::$helpers = [];
            self::$models = [];
            self::$logs = [];
            self::$exceptions = [];
        }

        /**
         * @param mixed $value
         */
        public static function setStoreConfig(string $path, $value): void
        {
            self::$config[$path] = $value;
        }

        /**
         * @return mixed
         */
        public static function getStoreConfig(string $path)
        {
            return self::$config[$path] ?? null;
        }

        public static function getStoreConfigFlag(string $path): bool
        {
            $value = self::getStoreConfig($path);
            $value = is_string($value) ? strtolower($value) : $value;

            return !empty($value) && $value !== 'false';
        }

        /**
         * @param mixed $helper
         */
        public static function setHelper(string $alias, $helper): void
        {
            self::$helpers[$alias] = $helper;
        }

        /**
         * @return mixed
         */
        public static function helper(string $alias)
        {
            return self::$helpers[$alias] ?? null;
        }

        /**
         * @param mixed $model
         */
        public static function setModel(string $alias, $model): void
        {
            self::$models[$alias] = $model;
        }

        /**
         * @return mixed
         */
        public static function getModel(string $alias)
        {
            return self::$models[$alias] ?? null;
        }

        /**
         * @return mixed
         */
        public static function getSingleton(string $alias)
        {
            return self::getModel($alias);
        }

        /**
         * @param mixed $message
         * @param mixed $level
         */
        public static function log($message, $level = null, string $file = '', bool $forceLog = false): void
        {
            self::$logs[] = [
                'message' => $message,
                'level' => $level,
                'file' => $file,
            ];
        }

        public static function logException(Throwable $throwable): void
        {
            self::$exceptions[] = $throwable;
        }
    }
}

require_once __DIR__ . '/../src/app/code/community/Hirale/Queue/Helper/Data.php';
require_once __DIR__ . '/../src/app/code/community/Hirale/Queue/Helper/Platform.php';
require_once __DIR__ . '/../src/app/code/community/Hirale/Queue/Model/TaskHandlerInterface.php';
require_once __DIR__ . '/../src/app/code/community/Hirale/Queue/Model/ConfigMigrator.php';
require_once __DIR__ . '/../src/app/code/community/Hirale/Queue/Model/Platform/AdapterInterface.php';
require_once __DIR__ . '/../src/app/code/community/Hirale/Queue/Model/Platform/Maho.php';
require_once __DIR__ . '/../src/app/code/community/Hirale/Queue/Model/Platform/Openmage.php';
require_once __DIR__ . '/../src/app/code/community/Hirale/Queue/Model/Platform/Factory.php';
require_once __DIR__ . '/../src/app/code/community/Hirale/Queue/Model/Task.php';
require_once __DIR__ . '/Support/FakeRedis.php';
require_once __DIR__ . '/Support/FakeConfigConnection.php';
require_once __DIR__ . '/Support/QueueFixtures.php';
