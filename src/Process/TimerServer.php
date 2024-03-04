<?php

namespace Playcat\Queue\Tpswoole\Process;

use Playcat\Queue\Install\InitDB;
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
use Playcat\Queue\TimerClient\TimerClientProtocols;
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
            ->addArgument('action', Argument::OPTIONAL, 'start|stop|initdb', 'start')
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
        if (in_array($action, ['start', 'stop', 'initdb'])) {
            $this->app->invokeMethod([$this, $action], [], true);
        } else {
            $this->output->error("Invalid argument action:{$action}, Expected start|stop|initdb");
        }
    }

    /**
     * @return void
     */
    private function start(): void
    {
        if ($this->processIsRunning()) {
            $this->output->error('Playcat queue timerserver process is already running.');
            return;
        }

        $this->output->writeln('Starting playcat timerserver...');
        $this->output->writeln('You can exit with <info>`CTRL-C`</info>');
        $this->startServer($this->config);
    }

    /**
     * @return void
     */
    private function reload(): void
    {
        $this->output->info('Reload playcat queue timerserver...');
        $this->reloadProcess();
    }

    /**
     * @return void
     */
    private function stop(): void
    {
        $this->output->info('Stop playcat queue timerserver...');
        $this->stopProcess();
    }

    /**
     * @param array $config
     * @return void
     */
    protected function startServer(array $config): void
    {
        @cli_set_process_title('playcatQueueTimerServer: master process');
        $this->setPid(posix_getpid());
        $pool = new Process\Pool(1);
        $pool->set([
            'enable_coroutine' => true
        ]);
        $pool->on('workerStart', function ($pool, $workerid) use ($config) {
            @cli_set_process_title('playcatQueueTimerServer: worker process');
            $this->iconic_id = $workerid;
            $this->loadUndoJobs();
            $server = new \Swoole\Coroutine\Server($config['bind_ip'], $config['bind_port'], false, true);
            $server->set([
                'open_eof_check' => true,
                'package_eof' => "\r\n"
            ]);
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
        $result = 1;
        if ($jid && $jid > 0) {
            $db_data = $this->storage->getDataById($jid);
            if ($db_data && $db_data['timerid']) {
                Timer::clear($db_data['timerid']);
                $result = $this->storage->delData($jid);
            }
        }
        return $result;
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

    /**
     * @return void
     */
    private function loadUndoJobs(): void
    {
        $jobs = $this->storage->getHistoryJobs();
        foreach ($jobs as $job) {
            $left_time = $job['expiration'] - time();
            $payload = $job['data'];
            if ($left_time < 5) {
                $payload->setDelayTime();
                $this->manager->push($payload);
            } else {
                $payload->setDelayTime($left_time);
                $this->cmdPush($payload);
            }
            $this->storage->delData($job['jid']);
        }
    }

    /**
     * @return void
     */
    private function initdb(): void
    {
        $this->output->writeln('Starting playcat queue database initial...');
        $this->output->writeln('You can exit with <info>`CTRL-C`</info>');
        $db = new InitDB($this->config['storage']);
        $result = false;
        if ($this->config['storage']['type'] == strtolower('mysql')) {
            $result = $db->initMysql();
        } elseif ($this->config['storage']['type'] == strtolower('sqlite')) {
            $result = $db->initSqlite();
        } else {
            $this->output->error("Unsupported database");
            return;
        }
        if ($result) {
            $this->output->writeln('Initialized successfully！');
        } else {
            $this->output->error("Initialized failed！");
        }
    }
}
