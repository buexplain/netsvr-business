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

namespace App\Controller;

use Netsvr\ConnInfoUpdate;
use NetsvrBusiness\Contract\RouterInterface;
use Netsvr\ConnClose;
use Netsvr\ConnOpen;
use Netsvr\Transfer;
use NetsvrBusiness\NetBus;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

class WebsocketTestController
{
    /**
     * 处理用户连接打开信息
     * @param ConnOpen $connOpen
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface|Throwable
     */
    public function onOpen(ConnOpen $connOpen): void
    {
        //广播消息给所有人
        NetBus::broadcast("有新用户进来 --> " . $connOpen->getUniqId());
        //更新当前进来的用户的信息到网关服务里面，这个动作一般是校验过用户权限后执行的
        $update = new ConnInfoUpdate();
        $update->setData("欢迎登录，现在你的名字叫王富贵！"); //更新成功后下发给用户的数据
        $update->setNewSession("王富贵"); //用户存储在网关的session，用户每次发消息过来，这个值会原封不动的回传给我们
        $update->setNewTopics(["王富贵的私人频道"]); //用户订阅的一些主题，通过这个主题发送消息，则所有订阅了该主题的用户都能收到消息
        $update->setUniqId($connOpen->getUniqId());
        NetBus::connInfoUpdate($update);
    }

    /**
     * 处理用户发来的信息
     * @param Transfer $transfer 网关转发客户数据时，使用的对象
     * @param RouterInterface $clientRouter 客户发送业务数据时，使用路由
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface|Throwable
     */
    public function onMessage(Transfer $transfer, RouterInterface $clientRouter): void
    {
        $config = \Hyperf\Config\config('business');
        $workerId = $config['netsvrWorkers'][0]['workerId'];
        $workerId = str_pad((string)$workerId, 3, '0', STR_PAD_LEFT);
        $clientRouter->setData($workerId . $clientRouter->getData());
        //下面这两行代码之所以写的有点浪费，纯粹是为了配合压测命令： php bin/hyperf.php websocket:stress，目的是测试MainSocket与TaskSocket
        if (!empty(NetBus::checkOnline($transfer->getUniqId()))) { //这行代码走的是TaskSocket发送出去的
            NetBus::singleCast($transfer->getUniqId(), $clientRouter->getData()); //这行代码走的是MainSocket或TaskSocket发送出去的，优先走MainSocket
        }
    }

    /**
     * 处理用户连接关闭的信息
     * @param ConnClose $connClose
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface|Throwable
     */
    public function onClose(ConnClose $connClose): void
    {
        NetBus::broadcast("有用户退出 --> " . $connClose->getUniqId());
    }
}
