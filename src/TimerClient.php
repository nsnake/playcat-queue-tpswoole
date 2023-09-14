<?php

namespace Playcat\Queue\Tpswoole;

use Swoole\Coroutine\Client;
use Dotenv\Exception\ValidationException;
use Playcat\Queue\Exceptions\ConnectFailExceptions;
use Playcat\Queue\Protocols\ProducerData;
use Playcat\Queue\Protocols\TimerClientProtocols;
use think\facade\Config;

class TimerClient
{
    protected static $instance;
    protected static $client;
    private $config;

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

    /**
     * @return false|resource
     * @throws ConnectFailExceptions
     */
    private function client()
    {
        if (!self::$client) {
            $ts_host = 'localhost';
            $ts_port = 0;
            if (preg_match('/^unix:(.*)$/i', $this->config['timerserver'], $matches)) {
                $ts_host = $matches[1];
                self::$client = new Client(SWOOLE_SOCK_UNIX_STREAM);
            } else {
                preg_match('/^((\d+\.\d+\.\d+\.\d+)|\w+):(\d+)$/', $this->config['timerserver'], $matches);
                $ts_host = $matches[1];
                $ts_port = $matches[3];
                self::$client = new Client(SWOOLE_SOCK_TCP);
            }

            if (!self::$client->connect($ts_host, $ts_port, 1)) {
                throw new ConnectFailExceptions('Connect to playcat time server failed. ' . $errstr);
            }
        }
        return self::$client;
    }


    public function send(ProducerData $payload): string
    {
        try {
            $protocols = new TimerClientProtocols();
            $protocols->setCMD(TimerClientProtocols::CMD_PUSH);
            $protocols->setPayload($payload);
            $this->client()->send(serialize($protocols) . "\r\n");
            $result = $this->client()->recv();
            $result = json_decode($result, true);
            return $result['code'] == 200 ? $result['data'] : '';
        } catch (ConnectFailExceptions $e) {
            return '';
        }
    }

}
