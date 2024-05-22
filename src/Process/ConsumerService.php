<?php
/**
 *
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the LICENCE files.
 *
 * @author CGI.NET
 */

namespace Playcat\Queue\Tpswoole\Process;

use Swoole\Process;
use Swoole\Timer;
use Swoole\Coroutine;
use function Swoole\Coroutine\run;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Config;
use Playcat\Queue\Util\Container;
use Playcat\Queue\Exceptions\QueueDontRetry;
use Playcat\Queue\Protocols\ConsumerData;
use Playcat\Queue\Protocols\ProducerData;
use Playcat\Queue\Tpswoole\Manager;
use Exception;
use Playcat\Queue\Log\Log;

class ConsumerService extends ProcessManager
{

    private $pull_timing;
    private $config;

    public function configure(): void
    {
        $this->setName('playcatqueue:consumerservice')
            ->addArgument('action', Argument::OPTIONAL, 'start|stop|reload', 'start')
            ->setDescription('');
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return void
     */
    protected function initialize(Input $input, Output $output)
    {
        $this->config = Config::get('playcatqueue.ConsumerService');
        if (!is_dir($this->config['consumer_dir'])) {
            $this->output->error("Consumer directory not exists");
            return;
        }
        $this->setPidFile($this->config['pid_file']);
        Log::setLogHandle(\think\facade\Log::class);
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        $action = $this->input->getArgument('action');
        if (in_array($action, ['start', 'stop', 'reload'])) {
            $this->app->invokeMethod([$this, $action], [], true);
        } else {
            $this->output->error("Invalid argument action:{$action}, Expected start|stop|reload");
        }
    }

    /**
     * @return void
     */
    private function start(): void
    {
        if ($this->processIsRunning()) {
            $this->output->error('Playcat queue server process is already running.');
            return;
        }

        $this->output->writeln('Starting playcat queue server...');
        $this->output->writeln('You can exit with <info>`CTRL-C`</info>');
        $this->startServer($this->config);
    }


    /**
     * @return void
     */
    private function reload(): void
    {
        $this->output->info('Reload playcat queue server...');
        $this->reloadProcess();
    }

    /**
     * @return void
     */
    private function stop(): void
    {
        $this->output->info('Stop playcat queue server...');
        $this->stopProcess();
    }

    /**
     * @param array $config
     * @return void
     */
    protected function startServer(array $config): void
    {
        @cli_set_process_title('playcatQueue: master process');
        $this->setPid(posix_getpid());
        $pool = new Process\Pool($config['count'], SWOOLE_IPC_NONE, 0, true);
        $pool->on('workerStart', function (Process\Pool $pool, int $workerId) use ($config) {
            @cli_set_process_title('playcatQueue: worker process');
            $running = true;
            Process::signal(SIGTERM, function () use (&$running) {
                $running = false;
            });
            $manager = Manager::getInstance();
            //当前进程id
            $manager->setIconicId(posix_getpid());

            try {
                $consumers = $this->loadWorkTask($config['consumer_dir']);
            } catch (Exception $e) {
                $message = 'Error while loading consumers: ' . $e->getMessage();
                Log::emergency($message);
                $this->output->error($message);
                return;
            }
            $manager->subscribe(array_keys($consumers));
            Log::info('Start Playcat Queue Consumer Service!');

            $this->pull_timing = Timer::tick(100, function () use ($manager, $consumers, $config, &$running, $pool) {
                //进程退出消息
                if (!$running) {
                    $cstats = Coroutine::stats();
                    //等待协程执行完在退出
                    if ($cstats['coroutine_num'] <= 1) {
                        $this->output->writeln(posix_getpid() . ' exit');
                        ($pool->getProcess())->exit(0);
                    }
                    return;
                }
                $payload = $manager->shift();
                if (($payload instanceof ConsumerData)) {
                    if (!empty($consumers[$payload->getChannel()])) {
                        try {
                            Coroutine::create(function () use ($consumers, $payload) {
                                call_user_func([$consumers[$payload->getChannel()], 'consume'], $payload);
                            });
                        } catch (QueueDontRetry $e) {
                            Log::alert('Caught an exception but not need retry it!', $payload->getQueueData());
                        } catch (Exception $e) {
                            if (isset($config['max_attempts'])
                                && $config['max_attempts'] > 0
                                && $config['max_attempts'] > $payload->getRetryCount()) {
                                $producer_data = new ProducerData();
                                $producer_data->setChannel($payload->getChannel());
                                $producer_data->setQueueData($payload->getQueueData());
                                $producer_data->setRetryCount($payload->getRetryCount() + 1);
                                $producer_data->setDelayTime(
                                    pow($config['retry_seconds'], $producer_data->getRetryCount())
                                );
                                $manager->push($producer_data);
                            }
                        } finally {
                            $manager->consumerFinished();
                        }
                    }
                }
            });
        });

        $pool->on('WorkerStop', function (Process\Pool $pool, $workerId) {
            Timer::clearAll();
        });
        $pool->start();
    }

    /**
     * @param string $dir
     * @return array
     * @throws Exception
     */
    protected function loadWorkTask(string $dir = ''): array
    {
        $consumers = [];
        foreach (glob($dir . '/*.php') as $file) {
            $class = str_replace('/', "\\", substr(substr($file, strlen(root_path())), 0, -4));
            if (is_a($class, 'Playcat\Queue\Protocols\ConsumerInterface', true)) {
                $consumer = Container::instance()->get($class);
                $channel = $consumer->queue;
                $consumers[$channel] = $consumer;
                Log::debug('Load task name:' . $channel);
            }
        }
        return $consumers;
    }
}
