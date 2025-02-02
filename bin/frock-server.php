<?php

use FrockDev\ToolsForLaravel\Support\AppModeResolver;
use FrockDev\ToolsForLaravel\Support\FrockLaravelStartSupport;
use FrockDev\ToolsForLaravel\Swow\ContextStorage;
use FrockDev\ToolsForLaravel\Swow\Processes\ProcessesRegistry;
use Swow\Channel;

define('LARAVEL_START', microtime(true));

include dirname($GLOBALS['_composer_autoload_path']).'/psr/container/src/ContainerInterface.php';
include dirname($GLOBALS['_composer_autoload_path']).'/laravel/framework/src/Illuminate/Contracts/Container/Container.php';
include dirname($GLOBALS['_composer_autoload_path']).'/frock-dev/tools-for-laravel/src/LaravelHack/Illuminate/Container/Container.php';
include dirname($GLOBALS['_composer_autoload_path']).'/frock-dev/tools-for-laravel/src/LaravelHack/Basis/Nats/Connection.php';
file_put_contents(
    dirname($GLOBALS['_composer_autoload_path']).'/../artisan',
    '#!/usr/bin/env php' . PHP_EOL . '<?php' . PHP_EOL . 'require_once __DIR__ . \'/vendor/bin/frock.php\';' . PHP_EOL);

require_once $GLOBALS['_composer_autoload_path'];

$appModeResolver = new AppModeResolver();
$startSupport = new FrockLaravelStartSupport(
    $appModeResolver
);

$exitControlChannel = new Channel(1);
ContextStorage::setSystemChannel('exitChannel', $exitControlChannel);
ContextStorage::setCurrentRoutineName('main');
$laravelApp = $startSupport->initializeLaravel(realpath(dirname($GLOBALS['_composer_autoload_path']).'/../'));

$startSupport->registerProcesses(); //load services depends on mode

ProcessesRegistry::runRegisteredInitProcesses();
ProcessesRegistry::runRegisteredProcesses();

\Swow\Coroutine::run(static function () use ($exitControlChannel): void {
    \Swow\Signal::wait(\Swow\Signal::INT);
    $exitControlChannel->push(\Swow\Signal::TERM);
});
\Swow\Coroutine::run(static function () use ($exitControlChannel): void {
    \Swow\Signal::wait(\Swow\Signal::TERM);
    $exitControlChannel->push(\Swow\Signal::TERM);
});

$exitCode = $exitControlChannel->pop();
if (!getenv('FROCK_DEV_SERVER')) {
    sleep(2);
}

echo 'Exited: ' . $exitCode . PHP_EOL;
exit($exitCode);
