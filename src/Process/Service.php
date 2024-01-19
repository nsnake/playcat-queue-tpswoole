<?php

namespace Playcat\Queue\Tpswoole\Process;

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
