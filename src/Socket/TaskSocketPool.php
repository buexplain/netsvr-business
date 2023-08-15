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

namespace NetsvrBusiness\Socket;

use Netsvr\Constant;
use NetsvrBusiness\Contract\TaskSocketInterface;
use NetsvrBusiness\Contract\TaskSocketPoolInterface;
use Swoole\Coroutine\Channel;
use Throwable;
use RuntimeException;

class TaskSocketPool implements TaskSocketPoolInterface
{
    protected Channel $pool;
    protected int $num = 0;
    protected array $config = [];

    public function __construct(array $config)
    {
        $this->pool = new Channel($config['taskSocketPoolMaxConnections']);
        $this->config = $config;
        $this->config['pool'] = $this;
    }

    public function loopHeartbeat(): void
    {
        $ret = [];
        for ($i = 0; $i < $this->pool->capacity; $i++) {
            $socket = $this->pool->pop(0.01);
            if (!$socket instanceof TaskSocketInterface) {
                continue;
            }
            try {
                $id = spl_object_id($socket);
                if (!isset($ret[$id])) {
                    $ret[$id] = true;
                    $socket->send(Constant::PING_MESSAGE);
                    $socket->receive();
                }
            } catch (Throwable) {
            } finally {
                $socket->release();
            }
        }
    }

    /**
     * @return TaskSocketInterface
     * @throws Throwable
     */
    public function get(): TaskSocketInterface
    {
        $retry = true;
        loop:
        //代码的cpu执行权力从这里开始
        if ($this->pool->length() === 0 && $this->num < $this->pool->capacity) {
            try {
                //到下面一行为止，不能发生cpu执行权力让渡，否则会导致连接创建溢出
                ++$this->num;
                return \Hyperf\Support\make(TaskSocketInterface::class, $this->config);
            } catch (Throwable $throwable) {
                --$this->num;
                throw $throwable;
            }
        }
        if ($retry === false) {
            //也许此时有连接呢，快速拿一次，拿不到就抛异常
            $connection = $this->pool->length() > 0 ? $this->pool->pop(0.02) : false;
            if ($connection instanceof TaskSocketInterface) {
                return $connection;
            }
            throw new RuntimeException('TaskSocketPool pool exhausted. Cannot establish new connection before wait_timeout.');
        }
        $connection = $this->pool->pop($this->config['taskSocketPoolWaitTimeout']);
        if (!$connection instanceof TaskSocketInterface) {
            //从连接池内获取连接失败，再次检查是否可以构建新连接，如果可以，则再次尝试构建一个新的连接
            if ($this->pool->length() === 0 && $this->num < $this->pool->capacity) {
                $retry = false;
                goto loop;
            }
            throw new RuntimeException('TaskSocketPool pool exhausted. Cannot establish new connection before wait_timeout.');
        }
        return $connection;
    }

    public function release(TaskSocketInterface|null $socket): void
    {
        if ($socket instanceof TaskSocketInterface) {
            $this->pool->push($socket);
        } else {
            --$this->num;
        }
    }
}
