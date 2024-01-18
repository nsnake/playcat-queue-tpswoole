<?php
return [
    'ConsumerService' => [
        // 消费进程数量
        'count' => swoole_cpu_num() * 2,
        // 消费类目录
        'consumer_dir' => app_path() . 'playcat/queue',
        // 消费失败后重试次数
        'max_attempts' => 3,
        // 重试间隔。单位秒
        'retry_seconds' => 60,
        'pid_file' => '/tmp/playcat_queue.pid'
    ],
    'TimerServer' => [
//        'bind_ip' => 'unix:/tmp/playcat_timeserver.sock',
        'bind_ip' => '127.0.0.1',
        'bind_port' => 6678,
        'pid_file' => '/tmp/playcat_timeserver.pid',
        'storage' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    // 数据库类型
                    'type' => 'sqlite',
                    // 数据库名
                    'database' => 'playcatqueue',
                ],
                'mysql' => [
                    // 数据库类型
                    'type' => 'mysql',
                    // 服务器地址
                    'hostname' => '127.0.0.1',
                    // 数据库名
                    'database' => 'playcatqueue',
                    // 数据库用户名
                    'username' => 'root',
                    // 数据库密码
                    'password' => '',
                    // 数据库连接端口
                    'hostport' => '',
                    // 数据库编码默认采用utf8
                    'charset' => 'utf8',
                ]
            ]
        ]
    ],
    'Manager' => [
        /**
         * 选择使用的队列驱动，默认使用redis
         * 使用前确保其对应的配置正确
         * 可选如下:
         * Rediscluster,Kafka,RabbitMQ
         */
        'driver' => \Playcat\Queue\Driver\Redis::class,
        //TimerServer服务地址
        'timerserver' => '127.0.0.1:6678'
    ],
    //Kafka配置
    'Kafka' => [
        'host' => '127.0.0.1:9092',
        'options' => []
    ],
    //Rabbitmq配置
    'Rabbitmq' => [
        'host' => '127.0.0.1:9092',
        'options' => [
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/'
        ]
    ],
    //redis配置
    'Redis' => [
        'host' => '127.0.0.1:6379',
        'options' => [
            'auth' => '',       // 密码，字符串类型，可选参数
        ]
    ],
    //redis集群配置
    'Rediscluster' => [
        'host' => [
            '127.0.0.1:7000',
            '127.0.0.1:7001',
            '127.0.0.1:7002'
        ],
        'options' => [
            'auth' => '',       // 密码，字符串类型，可选参数
        ]
    ]
];
