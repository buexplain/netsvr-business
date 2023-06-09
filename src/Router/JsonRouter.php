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

namespace NetsvrBusiness\Router;

use NetsvrBusiness\Contract\RouterInterface;
use NetsvrBusiness\Exception\RouterDecodeException;

/**
 * 客户端发消息的路由，这个路由实现是json编解码，json格式为：{"cmd":int, "data":"string"}
 */
class JsonRouter implements RouterInterface
{
    /**
     * 业务指令，类似于http的url path部分
     * @var int
     */
    protected int $cmd = 0;
    /**
     * 业务指令需要的数据，类似于http的body部分
     * @var string
     */
    protected string $data = '';

    public function encode(): string
    {
        return json_encode(['cmd' => $this->cmd, 'data' => $this->data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function decode(string $data): self
    {
        $tmp = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RouterDecodeException(json_last_error_msg(), 1);
        }
        if (!is_array($tmp) || !isset($tmp['cmd']) || !is_int($tmp['cmd']) || !isset($tmp['data']) || !is_string($tmp['data'])) {
            throw new RouterDecodeException('expected package format is: {"cmd":int, "data":"string"}', 2);
        }
        $this->cmd = $tmp['cmd'];
        $this->data = $tmp['data'];
        return $this;
    }

    public function getCmd(): int
    {
        return $this->cmd;
    }

    public function setCmd(int $var): self
    {
        $this->cmd = $var;
        return $this;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function setData(string $var): self
    {
        $this->data = $var;
        return $this;
    }
}