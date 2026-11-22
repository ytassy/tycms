<?php

namespace YTassy\Tycms;

use YTassy\Tycms\Logging\Logger;

use ReflectionClass;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidator;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\User\PendingMessageQueue;

class BaseApplicationItem  implements \JsonSerializable
{
    private Application $_app;

    public readonly bool $in_dev;
    public readonly bool $in_debug;

    private ?array $_classInfo = null;

    protected string $_classDir {
        get => $this->getClassInfo()['dir'];
    }

    protected string $_classFilePath {
        get => $this->getClassInfo()['path'];
    }

    protected string $_classFileName {
        get => $this->getClassInfo()['name'];
    }

    
    public function __construct(BaseApplicationItem|Application $app)
    {
        if ($app instanceof BaseApplicationItem) $this->_app = $app->getApp();
        else if ($app instanceof Application) $this->_app = $app;
        else throw new \Exception("Invalid app instance");

        $this->in_dev = $this->_app->getConf()->dev;
        $this->in_debug = $this->_app->getConf()->debug;
    }

    public function jsonSerialize(): array
    {
        return ['class' => basename(static::class)];
    }

    private function getClassInfo(): array
    {
        if ($this->_classInfo === null) {
            $path = (new ReflectionClass($this))->getFileName();
            $this->_classInfo = [
                'dir' => dirname($path),
                'path' => $path,
                'name' => basename($path),
            ];
        }

        return $this->_classInfo;
    }

    protected ?Request $request {
        get => $this->_app->getRequest();
    }

    protected Database $db {
        get => $this->_app->getDatabase();
    }

    protected Application $app {
        get => $this->_app;
    }

    protected Session $session {
        get => $this->_app->getSession();
    }

    protected Router $router {
        get => $this->_app->getRouter();
    }

    protected PendingMessageQueue $pendingMessageQueue {
        get => $this->_app->getPendingMessageQueue();
    }

    protected Logger $logger {
        get => $this->_app->getLogger();
    }

    /** Messages contrôlés par le code ; le contexte est filtré par le logger. */
    protected function logInfo(string $message, array $context = [], string $code = 'APPLICATION_INFO', string $channel = 'application'): void
    {
        $this->logger->log('INFO', $channel, $code, $message, $context);
    }

    protected function logWarning(string $message, array $context = [], string $code = 'APPLICATION_WARNING', string $channel = 'application'): void
    {
        $this->logger->log('WARNING', $channel, $code, $message, $context);
    }

    protected function logError(string $message, array $context = [], string $code = 'APPLICATION_ERROR', string $channel = 'application'): void
    {
        $this->logger->log('ERROR', $channel, $code, $message, $context);
    }

    protected function logException(\Throwable $exception): void
    {
        $this->logger->capture($exception);
    }

    protected ObjectValidator $objectValidator {
        get => $this->_app->getObjectValidator();
    }

    public function getApp(): Application
    {
        return $this->_app;
    }

    public function url(string $routeName, array $params = [], array $query = []){
        return $this->router->url($routeName, $params, $query);
    }

    public function getSharedExtensionsClass(string $className): self {
        return $this->app->getOrAddSharedExtensionsClass($className);
    }

}
