
<h1 align="center">Playcat\Queue\Tpswoole</h1>

<p align="center">基于Thinkphp6+和Swoole4+协程的消息队列服务
支持 Redis、Kafka 和 RabbitMQ等多种驱动，自带延迟消息和异常重试，开发简单</p>

## 特性

- 支持Redis单机或集群 (redis >= 5.0)
- 支持Kafka
- 支持RabbitMQ
- 支持延迟消息数据持久化
- 自定义异常与重试流程

## 模块与版本

- PHP >= 7.2
- Swoole扩展 >= 4.8.13
- Redis扩展
- RdKafka扩展
- php-amqplib/php-amqplib扩展

## 安装
在Thinkphp项目下执行
```shell
$ composer require "playcat/queue-tpswoole"
```

## 使用方法

### 1.配置

#### 1.1
编辑TP目录下的*config\playcatqueue.php*文件,编辑相应内容为自己环境使用的配置。
如果你使用过1.4之前的版本需要手动对比下配置文件或使用新格式的重新配置一次。

#### 1.2 初始化数据库(只需一次)

```
php think playcatqueue:timerserver initdb
```

### 2.创建消费任务

#### 新建一个php的文件并且添加以下内容:

```php
<?php

namespace app\queue\playcat;

use Playcat\Queue\Protocols\ConsumerDataInterface;
use Playcat\Queue\Protocols\ConsumerInterface;

class playcatConsumer1 implements ConsumerInterface
{
    //任务名称，对应发布消息的名称
    public $queue = 'playcatqueue';

    public function consume(ConsumerData $payload)
    {
        //获取发布到队列时传入的内容
        $data = $payload->getQueueData();
        ...你自己的业务逻辑
        //休息10s,其它协程会继续自己的工作
        \Swoole\Coroutine\System::sleep(10);
        echo('done!');
    }
}

```

### ConsumerData方法

- getID: 当前消息的id
- getRetryCount(): 当前任务已经重试过的次数
- getQueueData():  当前任务传入的参数
- getChannel(): 当前所执行的任务名称
- - -

#### 将上面编写好的任务文件保存TP项目中'*app/queue/playcat/*'下(如果目录不存在则自己手动创建)


#### 启动服务:
队列服务分为2个主服务。一个为即时消费服务，另一个为定时消费服务。可以在一台机器上同时启用消费和计时服务,也可以只启用消费服务端而和其它机器使用共同的定时消费服务端。

#### 消费者服务

启动:
`php think playcatqueue:consumerservice start`

重载:可在不重启服务的情况下更新业务
`php think playcatqueue:consumerservice reload`

停止：
`php think playcatqueue:consumerservice stop`


#### 延迟任务服务

启动：
`php think playcatqueue:timerserver start`

停止：
`php think playcatqueue:timerserver stop`

如果没有错误出现则表示启动完成

### 添加任务并且提交到队列中

```php
use Playcat\Queue\Manager;
use Playcat\Queue\Protocols\ProducerData;
//使用协程的方式,如果需要并行数据发布需要自行实现Manager的连接池
go(function () {
  //即时消费消息
  $payload = new ProducerData();
  //对应消费队列里的任务名称
  $payload->setChannel('test');
  //对应消费队列里的任务使用的数据
  $payload->setQueueData([1,2,3,4]);
  //推入队列并且获取消息id
  $id = Manager::getInstance()->push($payload);

  //延迟消费消息
  $payload_delay = new ProducerData();
  $payload_delay->setChannel('test');
  $payload_delay->setQueueData([6,7,8,9]);
  //设置60秒后执行的任务
  $payload_delay->setDelayTime(60);
  //推入队列并且获取消息id
  $id = Manager::getInstance()->push($payload_delay);
  //取消延迟消息
  Manager::getInstance()->del($id);
 });
```

### ProducerData方法

- setChannel: 设置推入消息的队列名称
- setQueueData: 设置传入到消费任务的数据
- setDelayTime: 设置延迟时间(秒)
- - -

### 异常与重试机制

任务在执行过程中未抛出异常则默认执行成功，否则则进入重试阶段.
重试次数和时间由配置控制，重试间隔时间为当前重试次数的幂函数。
**Playcat\Queue\Exceptions\DontRetry**异常会忽略掉重试


### 其它

注意：所有执行的消费任务默认是开启协程并且不可关闭的。由于与常规模式执行有点区别,所以可能出现一些不是预期的情况（例如mysql和redis在协程下的复用问题）。如果没有接触过协程或开发过程中发现问题建议先看下swoole的相关文档。



基于webman的playcat queue
[playcat-queue ](https://github.com/nsnake/playcat-queue)

QQ:318274085

## License

MIT
