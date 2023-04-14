# netsvr-business

这是一个可以快速开发websocket业务的包，它基于[https://github.com/buexplain/netsvr](https://github.com/buexplain/netsvr)
进行工作。

## 使用步骤

1. 下载并启动网关服务：https://github.com/buexplain/netsvr/releases
2. 在hyperf项目里面安装本包以及protobuf包：
   > composer require buexplain/netsvr-business
   > 
   > php bin/hyperf.php vendor:publish buexplain/netsvr-business  
   > 
   > composer require google/protobuf

3. 新增路由文件`routes-websocket.php`

```php
<?php

declare(strict_types=1);

use App\Controller\WebsocketController;
use Hyperf\Context\ApplicationContext;
use Netsvr\Cmd;

$dispatcher = ApplicationContext::getContainer()->get(\NetsvrBusiness\Contract\DispatcherInterface::class);

$dispatcher->addRoute(Cmd::ConnOpen, [WebsocketController::class, 'onOpen']);
$dispatcher->addRoute(Cmd::Transfer, [WebsocketController::class, 'onMessage']);
$dispatcher->addRoute(Cmd::ConnClose, [WebsocketController::class, 'onClose']);
```

4.新增控制器文件

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NetsvrBusiness\Socket\WorkerSocketManager;
use NetsvrBusiness\Contract\ClientRouterInterface;
use Netsvr\Broadcast;
use Netsvr\Cmd;
use Netsvr\ConnClose;
use Netsvr\ConnOpen;
use Netsvr\Router;
use Netsvr\Transfer;

class WebsocketController
{
    /**
     * 处理用户连接打开信息
     * @param WorkerSocketManager $manager
     * @param ConnOpen $connOpen
     * @return void
     */
    public function onOpen(WorkerSocketManager $manager, ConnOpen $connOpen): void
    {
        //构造一个广播对象
        $broadcast = new Broadcast();
        $broadcast->setData("有新用户进来 --> " . $connOpen->getUniqId());
        //构造一个路由对象
        $router = new Router();
        //设置命令为广播
        $router->setCmd(Cmd::Broadcast);
        //将广播对象序列化到路由对象上
        $router->setData($broadcast->serializeToString());
        //将路由对象序列化后发给网关
        $data = $router->serializeToString();
        $manager->send($data);
        echo '连接打开：' . $connOpen->serializeToJsonString(), PHP_EOL;
    }

    /**
     * 处理用户发来的信息
     * @param WorkerSocketManager $manager
     * @param Transfer $transfer
     * @return void
     */
    public function onMessage(WorkerSocketManager $manager, Transfer $transfer, ClientRouterInterface $clientRouter): void
    {
        $broadcast = new Broadcast();
        $broadcast->setData($transfer->getUniqId() . '：' . $transfer->getData());
        $router = new Router();
        $router->setCmd(Cmd::Broadcast);
        $router->setData($broadcast->serializeToString());
        $data = $router->serializeToString();
        $manager->send($data);
        echo '收到消息：' . $clientRouter->getCmd() . ' --> ' . $transfer->getData(), PHP_EOL;
    }

    /**
     * 处理用户连接关闭的信息
     * @param WorkerSocketManager $manager
     * @param ConnClose $connClose
     * @return void
     */
    public function onClose(WorkerSocketManager $manager, ConnClose $connClose): void
    {
        $broadcast = new Broadcast();
        $broadcast->setData("有用户退出 --> " . $connClose->getUniqId());
        $router = new Router();
        $router->setCmd(Cmd::Broadcast);
        $router->setData($broadcast->serializeToString());
        $data = $router->serializeToString();
        $manager->send($data);
        echo '连接关闭：' . $connClose->serializeToJsonString(), PHP_EOL;
    }
}
```

5. 修改配置文件`business.php`，把里面的网关ip、port改正确
6. 执行启动命令：`php bin/hyperf.php business:start`