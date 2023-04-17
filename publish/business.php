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

return [
    //支持配置多个网关服务
    [
        //网关地址
        'host' => '127.0.0.1',
        //网关的worker服务的端口
        'port' => 6061,
        //连接到网关的超时时间，单位秒
        'connectTimeout' => 1,
        //网关服务唯一编号，取值范围是 uint8，会成为网关分配给每个客户连接的uniqId的前缀，占两个字符
        'serverId' => 1,
        //当前业务进程的服务编号，取值区间是：[1,999]，业务层自己规划安排
        //所有发给网关的消息，如果需要当前业务进程处理，则必须是以该配置开头，因为网关是根据这个workerId来转发客户数据到业务进程的
        //客户发送的数据示例：001{"cmd":1,"data":"我的好朋友，你在吃什么？"}，其中001就是workerId，不足三位，前面补0
        'workerId' => 1,
        //该参数表示接下来，需要网关的worker服务器开启多少协程来处理本连接的请求
        'processCmdGoroutineNum' => 10,
        //保持连接活跃状态的心跳间隔，单位秒
        'heartbeatInterval' => 60,
        //限制tcp数据包大小的最大值
        'packageMaxLength' => 2 * 2 * 1024,
    ],
];
