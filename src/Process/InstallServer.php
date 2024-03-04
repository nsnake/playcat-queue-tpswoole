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

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Config;
use Playcat\Queue\Install\InitDB;

class InstallServer
{
    private $config;

    public function configure(): void
    {
        $this->setName('playcatqueue:initial')
            ->addArgument('action', Argument::OPTIONAL, 'start', 'start')
            ->setDescription('For playcat queue database initial!');
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return void
     */
    protected function initialize(Input $input, Output $output)
    {
        $this->config = Config::get('playcatqueue.TimerServer');
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        $action = $this->input->getArgument('action');
        if (in_array($action, ['start'])) {
            $this->app->invokeMethod([$this, $action], [], true);
        } else {
            $this->output->error("Invalid argument action:{$action}, Expected start");
        }
    }

    /**
     * @return void
     */
    private function start(): void
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