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

namespace NetsvrBusiness\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;
use NetsvrBusiness\Contract\BootstrapInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swoole\Coroutine;
use Throwable;

/**
 * 打开与网关的tcp连接
 */
class StartListener implements ListenerInterface
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function listen(): array
    {
        return [
            BeforeWorkerStart::class,
        ];
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function process(object $event): void
    {
        /**
         * @var $event BeforeWorkerStart
         */
        if ($event->server->taskworker) {
            return;
        }
        $bootstrap = $this->container->get(BootstrapInterface::class);
        $bootstrap->setWorkerProcessId($event->workerId);
        $bootstrap->setMasterPid($event->server->master_pid);
        if ($bootstrap->connect() === false) {
            return;
        }
        if ($bootstrap->register() === false) {
            return;
        }
        Coroutine::create(function () use ($bootstrap) {
            try {
                $bootstrap->start();
            } catch (Throwable) {
            }
        });
    }
}
