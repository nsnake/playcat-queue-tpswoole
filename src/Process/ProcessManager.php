<?php

namespace Playcat\Queue\Tpswoole\Process;

use Swoole\Process;
use think\console\Command;

class ProcessManager extends Command
{
    /** @var string */
    protected $file;

    public function setPidFile(string $file): void
    {
        $this->file = $file;
    }

    /**
     * @param int $pid
     * @return bool
     */
    public function setPid(int $pid): bool
    {
        return file_put_contents($this->file, $pid);
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        if (is_readable($this->file)) {
            return (int)file_get_contents($this->file);
        }

        return -1;
    }

    /**
     * 是否运行中
     * @return bool
     */
    protected function processIsRunning(): bool
    {
        $pid = $this->getPid();
        if ($pid > 0) {
            if (Process::kill($pid, 0)) {
                return true;
            }
            @unlink($this->file);
        }

        return false;
    }

    /**
     * @return bool
     */
    protected function reloadProcess(): bool
    {
        return $this->killProcess(SIGUSR1);
    }

    /**
     * @return bool
     */
    protected function stopProcess(): bool
    {
        return $this->killProcess(SIGTERM);
    }

    /**
     *
     * @param int $sig
     * @param int $wait
     *
     * @return bool
     */
    protected function killProcess($sig): bool
    {
        $pid = $this->getPid();
        $pid > 0 && Process::kill($pid, $sig);
        return $this->processIsRunning();
    }
}
