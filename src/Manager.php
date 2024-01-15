<?php

namespace Playcat\Queue\Tpswoole;

use Playcat\Queue\Protocols\ConsumerDataInterface;
use Playcat\Queue\Driver\DriverInterface;
use Playcat\Queue\Protocols\ProducerDataInterface;
use think\facade\Config;

class Manager implements DriverInterface
{
    protected static $instance;
    private  $config;
    private  $driver;
    private  $timer_client;

    public static function getInstance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->config = Config::get('playcatqueue.Manager');
    }

    protected function getTimeClient(): TimerClient
    {
        if (!$this->timer_client) {
            $this->timer_client = TimerClient::getInstance(['timerserver' => $this->config['timerserver']]);
        }
        return $this->timer_client;
    }

    protected function getProducer(): DriverInterface
    {
        if (!$this->driver
            || !$this->driver instanceof DriverInterface) {
            $driver_name = [];
            switch ($this->config['driver']) {
                case 'Playcat\Queue\Driver\Rediscluster':
                    $driver_name = Config::get('playcatqueue.Rediscluster');
                    break;
                case 'Playcat\Queue\Driver\Kafka':
                    $driver_name = Config::get('playcatqueue.Kafka');
                    break;
                case 'Playcat\Queue\Driver\RabbitMQ':
                    $driver_name = Config::get('playcatqueue.Rabbitmq');
                    break;
                default:
                    $driver_name = Config::get('playcatqueue.Redis');
            }
            $this->driver = new $this->config['driver']($driver_name);
        }
        return $this->driver;
    }

    public function setIconicId(int $iconic_id = 0): void
    {
        $this->getProducer()->setIconicId($iconic_id);
    }

    public function subscribe(array $channels): bool
    {
        return $this->getProducer()->subscribe($channels);
    }


    public function shift(): ?ConsumerDataInterface
    {
        return $this->getProducer()->shift();
    }

    public function push(ProducerDataInterface $payload): ?string
    {
        return $payload->getDelayTime() > 0
            ? $this->getTimeClient()->send($payload) : $this->getProducer()->push($payload);
    }

    public function consumerFinished(): bool
    {
        return $this->getProducer()->consumerFinished();
    }

}
