<?php
/**
 * Copyright 2023 buexplain@qq.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace NetsvrBusiness;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use NetsvrBusiness\Contract\BootstrapInterface;
use NetsvrBusiness\Contract\DispatcherFactoryInterface;
use NetsvrBusiness\Contract\MainSocketInterface;
use NetsvrBusiness\Contract\MainSocketManagerInterface;
use NetsvrBusiness\Event\ServerStart;
use NetsvrBusiness\Event\ServerStop;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Coroutine;
use Throwable;

class Bootstrap implements BootstrapInterface
{
    protected StdoutLoggerInterface $logger;
    protected ContainerInterface $container;
    protected int $workerProcessId;
    protected int $masterPid;
    protected bool $stopStatus = true;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct()
    {
        $this->container = ApplicationContext::getContainer();
        $this->logger = $this->container->get(StdoutLoggerInterface::class);
    }

    public function setWorkerProcessId(int $workerProcessId)
    {
        $this->workerProcessId = $workerProcessId;
    }

    public function setMasterPid(int $masterPid)
    {
        $this->masterPid = $masterPid;
    }

    /**
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function connect(): bool
    {
        $eventDispatcher = $this->container->get(EventDispatcherInterface::class);
        $manager = $this->container->get(MainSocketManagerInterface::class);
        $manager->loggerPrefix = "Business#$this->workerProcessId ";
        $eventDispatcher->dispatch(new ServerStart($this->workerProcessId, $this->masterPid));
        //并发的连接所有的网关机器
        $config = (array)\Hyperf\Config\config('business.netsvrWorkers', []);
        if (empty($config)) {
            $this->logger->error('The business service config business.php not found, may be not run command： php bin/hyperf.php vendor:publish buexplain/netsvr-business');
            return false;
        }
        $retCh = new Coroutine\Channel(count($config));
        foreach ($config as $item) {
            Coroutine::create(function () use ($retCh, $item, $manager) {
                try {
                    $socket = \Hyperf\Support\make(MainSocketInterface::class, $item);
                    $socket->loggerPrefix = "Business#$this->workerProcessId ";
                    $socket->connect();
                    $manager->add($socket);
                    $retCh->push(true);
                } catch (Throwable $throwable) {
                    $message = sprintf("Business#%d Socket %s:%s %s", $this->workerProcessId, $item['host'], $item['port'], $throwable->getMessage());
                    $this->logger->error($message);
                    $retCh->push(false);
                }
            });
        }
        //接收连接结果
        for ($i = count($config); $i > 0; $i--) {
            if ($retCh->pop() === false) {
                return false;
            }
        }
        $retCh->close();
        return true;
    }

    /**
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function register(): bool
    {
        $manager = $this->container->get(MainSocketManagerInterface::class);
        //统一向网关发起注册
        if ($manager->register() === false) {
            //注册失败
            return false;
        }
        $this->logger->info("Business#$this->workerProcessId started.");
        return true;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function start(): void
    {
        $this->stopStatus = false;
        $manager = $this->container->get(MainSocketManagerInterface::class);
        //不断的从各个网关读取数据，并分发到对应的控制器方法
        while (true) {
            $router = $manager->receive();
            if ($router === false) {
                break;
            }
            //收到新数据，开一个协程去处理
            Coroutine::create(function () use ($router) {
                try {
                    ApplicationContext::getContainer()->get(DispatcherFactoryInterface::class)->get()->dispatch($router);
                } catch (Throwable $throwable) {
                    $message = sprintf(
                        "%d --> %s in %s on line %d\nThrowable: %s\nStack trace:\n%s",
                        $throwable->getCode(),
                        $throwable->getMessage(),
                        $throwable->getFile(),
                        $throwable->getLine(),
                        get_class($throwable),
                        $throwable->getTraceAsString()
                    );
                    $this->logger->error($message);
                }
            });
        }
        $this->logger->info("Business#$this->workerProcessId stopped.");
        $eventDispatcher = ApplicationContext::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(new ServerStop($this->workerProcessId, $this->masterPid));
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        if ($this->stopStatus) {
            return;
        }
        try {
            $manager = $this->container->get(MainSocketManagerInterface::class);
            $this->logger->info("Business#$this->workerProcessId starting unregister.");
            $manager->unregister();
            $this->logger->info("Business#$this->workerProcessId starting disconnect.");
            $manager->close();
        } catch (Throwable) {
        } finally {
            $this->stopStatus = true;
        }
    }
}