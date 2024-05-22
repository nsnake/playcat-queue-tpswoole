<?php

namespace Playcat\Queue\Tpswoole;

use Playcat\Queue\Log\Log;
use Playcat\Queue\Protocols\ConsumerDataInterface;
use Playcat\Queue\Driver\DriverInterface;
use Playcat\Queue\Protocols\ProducerDataInterface;
use Playcat\Queue\TimerClient\TimerClientInterface;
use think\facade\Config;
use Playcat\Queue\TimerClient\SwooleScoket;
use Playcat\Queue\Manager\Base;

class Manager extends Base
{
    final public static function getInstance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->manager_config = Config::get('playcatqueue.Manager');
        Log::setLogHandle(\think\facade\Log::class);
    }

    protected function getTimeClient(): SwooleScoket
    {
        if (!$this->tc) {
            $this->tc = new SwooleScoket([
                'timerserver' => $this->manager_config['timerserver']
            ]);
        }
        return $this->tc;
    }

}
