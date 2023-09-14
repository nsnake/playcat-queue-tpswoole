<?php

namespace Playcat\Queue\Tpswoole;

use Playcat\Queue\Protocols\ConsumerDataInterface;
use Playcat\Queue\Protocols\DriverInterface;
use Playcat\Queue\Protocols\ProducerDataInterface;
use think\facade\Config;

class Manager implements DriverInterface
{
    protected static $instance;
    protected $driver;
    private $timer_client;

    public static function getInstance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $config = Config::get('playcatqueue.Manager');
        $driver_config = [];
        switch ($config['driver']) {
            case 'Playcat\Queue\Driver\Rediscluster':
                $driver_config = Config::get('playcatqueue.Rediscluster');
                break;
            case 'Playcat\Queue\Driver\Kafka':
                $driver_config = Config::get('playcatqueue.Kafka');
                break;
            case 'Playcat\Queue\Driver\RabbitMQ':
                $driver_config = Config::get('playcatqueue.Rabbitmq');
                break;
            default:
                $driver_config = Config::get('playcatqueue.Redis');
        }

        $this->driver = new $config['driver']($driver_config);
        $this->timer_client = TimerClient::getInstance([
            'timerserver' => $config['timerserver']]);
    }


    public function setIconicId(int $iconic_id = 0): void
    {
        $this->driver->setIconicId($iconic_id);
    }

    public function subscribe(array $channels): bool
    {
        return $this->driver->subscribe($channels);
    }


    public function shift(): ?ConsumerDataInterface
    {
        return $this->driver->shift();
    }

    public function push(ProducerDataInterface $payload): ?string
    {
        return $payload->getDelayTime() > 0
            ? $this->timer_client->send($payload) : $this->driver->push($payload);
    }

    public function consumerFinished(): bool
    {
        return $this->driver->consumerFinished();
    }

}
