<?php

$base_path = '../../..';
$app_path = $base_path . '/app/playcat/queue';

function showLog($msg)
{
    echo $msg . "\r\n";
}

if (!is_dir($app_path)) {
    showLog('Can not found playcat queue task path, will create it!');
    mkdir($app_path, 0755, true);
}
