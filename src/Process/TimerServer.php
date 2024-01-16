<?php

namespace Playcat\Queue\Tpswoole\Process;

use Swoole\Timer;
use Swoole\Process;
use Swoole\Coroutine;
use Swoole\Coroutine\Server\Connection;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\Exception;
use think\facade\Config;
use ErrorException;
use Playcat\Queue\Protocols\ProducerData;
use Playcat\Queue\Protocols\TimerClientProtocols;
use Playcat\Queue\Tpswoole\Process\ProcessManager;
use Playcat\Queue\Tpswoole\Manager;
use Playcat\Queue\TimerServer\Storage;

class TimerServer extends ProcessManager
{
    private $manager;
    private $storage;
    private $iconic_id;

    public function configure(): void
    {
        $this->setName('playcatqueue:timerserver')
            ->addArgument('action', Argument::OPTIONAL, 'start|stop', 'start')
            ->setDescription('');
    }

    protected function initialize(Input $input, Output $output)
    {
        $this->config = Config::get('playcatqueue.TimerServer');
        $this->setPidFile($this->config['pid_file']);
        $this->manager = Manager::getInstance();
        $this->storage = new Storage();
        $this->storage->setDriver($this->config['storage']);
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        $action = $this->input->getArgument('action');
        if (in_array($action, ['start', 'stop'])) {
            $this->app->invokeMethod([$this, $action], [], true);
        } else {
            $this->output->error("Invalid argument action:{$action}, Expected start|stop");
        }
    }

    /**
     * @return void
     */
    private function start(): void
    {
        if ($this->processIsRunning()) {
            $this->output->error('Playcat queue timer server process is already running.');
            return;
        }

        $this->output->writeln('Starting playcat timer server...');
        $this->output->writeln('You can exit with <info>`CTRL-C`</info>');
        $this->startServer($this->config);
    }

    /**
     * @return void
     */
    private function reload(): void
    {
        $this->output->info('Reload playcat queue timer server...');
        $this->reloadProcess();
    }

    /**
     * @return void
     */
    private function stop(): void
    {
        $this->output->info('Stop playcat queue timer server...');
        $this->stopProcess();
    }

    /**
     * @param array $config
     * @return void
     */
    protected function startServer(array $config): void
    {
        @cli_set_process_title('playcatQueueTimerServer: master process');
        $this->iconic_id = posix_getpid();
        $this->setPid($this->iconic_id);
        $pool = new Process\Pool($config['count']);
        $pool->set([
            'enable_coroutine' => true,
            'open_eof_check' => true,
            'package_eof' => "\r\n"
        ]);
        $pool->on('workerStart', function ($pool, $id) use ($config) {
            @cli_set_process_title('playcatQueueTimerServer: worker process');
            $server = new \Swoole\Coroutine\Server($config['bind_ip'], $config['bind_port'], false, true);
            Process::signal(SIGTERM, function () use ($server) {
                $server->shutdown();
            });

            $server->handle(function (Connection $connection) {
                while (true) {
                    $data = $connection->recv();
                    $result = '';
                    if ($data === '' || $data === false) {
                        $connection->send($this->resultData($result, 500, socket_strerror(swoole_last_error())));
                        $connection->close();
                        break;
                    }

                    try {
                        $protocols = unserialize($data);
                    } catch (Exception $e) {
                        $connection->send($this->resultData($result, 401, 'Unsupported protocols!'));
                        $connection->close();
                        break;
                    }
                    if ($protocols instanceof TimerClientProtocols) {
                        switch ($protocols->getCMD()) {
                            case TimerClientProtocols::CMD_PUSH:
                                $result = $this->cmdPush($protocols->getPayload());
                                break;
                            case TimerClientProtocols::CMD_DEL:
                                $result = $this->cmdDel($protocols->getPayload());
                                break;
                        }

                    }
                    $connection->send($this->resultData($result));
                    Coroutine::sleep(1);
                }
            });
            $server->start();
        });
        $pool->start();

    }

    /**
     * @param ProducerData $payload
     * @return int
     */
    private function cmdPush(ProducerData $payload): int
    {
        $jid = $this->storage->addData($this->iconic_id, $payload->getDelayTime(), $payload);
        $timer_id = Timer::after($payload->getDelayTime() * 1000, function (int $jid, Storage $storage) {
            $db_data = $storage->getDataById($jid);
            $payload = $db_data['data'];
            $payload->setDelayTime();
            if ($this->manager->push($payload)) {
                $storage->delData($jid);
            }
        }, $jid, $this->storage);
        $this->storage->upData($jid, $timer_id);
        return $jid;
    }

    /**
     * @param int $jid
     * @return int
     */
    private function cmdDel(ProducerData $payload): int
    {
        $jid = intval($payload->getID());
        $db_data = $this->storage->getDataById($jid);
        if ($db_data && $db_data['timerid']) {
            Timer::clear($db_data['timerid']);
            return $this->storage->delData($jid);
        }
        return 1;
    }

    /**
     * @param int $code
     * @param string $msg
     * @param string $data
     * @return string
     */
    private function resultData(string $data = '', int $code = 200, string $msg = 'ok'): string
    {
        return json_encode(['code' => $code, 'msg' => $msg, 'data' => $data]) . "\r\n";
    }
}
