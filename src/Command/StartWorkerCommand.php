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

namespace NetsvrBusiness\Command;

use Hyperf\Contract\StdoutLoggerInterface;
use NetsvrBusiness\Contract\BootstrapInterface;
use NetsvrBusiness\Contract\DispatcherFactoryInterface;
use Hyperf\Context\ApplicationContext;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swoole\Coroutine;
use Swoole\Process\Pool;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StartWorkerCommand extends WorkerCommand
{
    protected StdoutLoggerInterface $logger;
    protected ContainerInterface $container;

    public function __construct(string $name = null)
    {
        parent::__construct($name);
        $this->container = ApplicationContext::getContainer();
        $this->logger = $this->container->get(StdoutLoggerInterface::class);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this->setName('business:start')
            ->setDefinition([
                new InputOption('workers', 'w', InputOption::VALUE_REQUIRED, 'Specify the number of process.', swoole_cpu_num()),
            ])
            ->setDescription('Start business service.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        if ($this->isRun()) {
            $this->logger->info('The business service is running.');
            return 0;
        }
        if (!class_exists('\Google\Protobuf\Internal\Message')) {
            $this->logger->error('Class "Google\Protobuf\Internal\Message" not found, you can run command: composer require google/protobuf');
            return 1;
        }
        //获取一下调度器，提前把路由加载进来，避免真正处理数据的时候发生路由注册的错误
        $this->container->get(DispatcherFactoryInterface::class)->get();
        //开始启动多进程
        $workers = intval($input->getOption('workers'));
        $pool = new Pool($workers > 0 ? $workers : swoole_cpu_num());
        $pool->set(['enable_coroutine' => true]);
        $pool->on('WorkerStart', function (Pool $pool, $workerProcessId) {
            //记录主进程pid
            if ($workerProcessId === 0) {
                file_put_contents($this->pidFile, (string)$pool->master_pid);
            }
            $bootstrap = $this->container->get(BootstrapInterface::class);
            $bootstrap->setWorkerProcessId($workerProcessId);
            $bootstrap->setMasterPid($pool->master_pid);
            //注册失败，停止启动程序
            if (!$bootstrap->connect()) {
                $pool->shutdown();
            }
            //监听进程关闭信号
            Coroutine::create(function () use ($bootstrap) {
                while (true) {
                    if (Coroutine\System::waitSignal(SIGTERM) === true) {
                        break;
                    }
                    //Coroutine::sleep(3);break;
                }
                $bootstrap->stop();
            });
            //向网关发起注册请求
            $bootstrap->register();
            //开始处理网关发过来的数据
            $bootstrap->start();
        });
        $pool->start();
        //删除记录的主进程pid
        @unlink($this->pidFile);
        return 0;
    }
}
