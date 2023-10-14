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

namespace App\Command;

use ErrorException;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Netsvr\Constant;
use NetsvrBusiness\NetBus;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

#[Command]
class WebsocketStressCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('websocket:stress');
    }

    public function configure()
    {
        parent::configure();
        $this->setDefinition([
            new InputOption('client', 'c', InputOption::VALUE_REQUIRED, 'client number.', 1000),
        ]);
        $this->setDescription('websocket stress command');
    }

    /**
     * 压测命令，逻辑是：开启N个websocket客户端，每个客户端间隔5秒发送1kb数据
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ErrorException
     */
    public function handle()
    {
        $config = \Hyperf\Config\config('business.netsvrWorkers')[0];
        $host = $config['host'];
        $port = intval($config['port']) - 1;
        $serverId = $config['serverId'];
        $workerId = $config['workerId'];
        $clientNum = intval($this->input->getOption('client'));
        $wg = new Coroutine\WaitGroup();
        for ($i = 0; $i < $clientNum; $i++) {
            $client = $this->getClient($host, $port, $serverId);
            if (!$client) {
                return;
            }
            $this->loopReceive($client);
            $this->loopHeartbeat($client);
            $this->loopSend($client, $workerId, $wg);
        }
        $this->info('create ' . $clientNum . ' client ok');
        $wg->wait();
    }

    protected function loopSend(Client $client, int $workerId, Coroutine\WaitGroup $wg)
    {
        $wg->add();
        Coroutine::create(function () use ($client, $workerId, $wg) {
            try {
                $s = str_pad((string)$workerId, 3, '0', STR_PAD_LEFT) . str_repeat('a', 1024);
                while (true) {
                    if ($client->push($s) === false) {
                        return;
                    }
                    Coroutine::sleep(5);
                }
            } catch (Throwable $throwable) {
                $this->error($throwable->getMessage());
            } finally {
                $wg->done();
            }
        });
    }

    protected function loopReceive(Client $client)
    {
        Coroutine::create(function () use ($client) {
            while (true) {
                $ret = $client->recv();
                if ($ret === false) {
                    return;
                }
            }
        });
    }

    protected function loopHeartbeat(Client $client)
    {
        Coroutine::create(function () use ($client) {
            $heartbeat = new Channel(1);
            try {
                while ($heartbeat->pop(25) === false) {
                    if ($client->push(Constant::PING_MESSAGE) === false) {
                        break;
                    }
                }
            } catch (Throwable $throwable) {
                $this->error($throwable->getMessage());
            } finally {
                $heartbeat->close();
            }
        });
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ErrorException
     */
    protected function getClient(string $host, int $port, int $serverId): ?Client
    {
        $client = new Client($host, $port);
        //如果网关支持连接的时候自定义uniqId，则务必保持uniqId的前两个字符是网关唯一id的16进制格式的字符
        //如果不保持这个规则，则你必须重新实现类 \NetsvrBusiness\Contract\ServerIdConvertInterface::class，确保uniqId转serverId正确
        $hex = ($serverId < 16 ? '0' . dechex($serverId) : dechex($serverId));
        $uniqId = $hex . uniqid();
        //获取自定义uniqId时，必须的token
        $token = NetBus::connOpenCustomUniqIdToken($serverId)['token'];
        if ($client->upgrade('/netsvr?uniqId=' . $uniqId . '&token=' . $token) === false) {
            $s = "ws://$host:$port" . '/netsvr?uniqId=' . $uniqId . '&token=' . $token;
            $this->error('连接到网关的websocket服务器失败: ' . $s);
            return null;
        }
        return $client;
    }
}
