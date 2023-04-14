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

use NetsvrBusiness\Contract\ClientRouterInterface;
use Netsvr\Cmd;
use Netsvr\Transfer;

/**
 * 客户端发消息的路由
 */
class ClientRouter implements ClientRouterInterface
{
    /**
     * 返回客户发送过来的消息携带的cmd
     * @return int
     */
    public function getCmd(): int
    {
        //这里写死，具体需要使用者实现decode方法，从客户消息中解码出业务的cmd
        return Cmd::Transfer;
    }

    /**
     * 解析客户发送过来的消息
     * @param Transfer $transfer
     * @return void
     */
    public function decode(Transfer $transfer): void
    {

    }
}