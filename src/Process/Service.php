<?php

namespace Playcat\Queue\Tpswoole\Process;
\Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);


class Service extends \think\Service
{

    public function boot()
    {
        $this->commands([
            ConsumerService::class,
            TimerServer::class
        ]);
    }

    public function makeDir()
    {
        $rootDir = getcwd();
        $paths = [

        ];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                mkdir($path, 0660, true);
            }
        }
    }

}
