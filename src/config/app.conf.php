<?php

$config['timezone'] = 'Asia/Shanghai';
$config['version'] = '0.0.2';

$config['daemonize'] = 0;

$config['ip']   = '0.0.0.0';
$config['port'] = 6381;

$config['enable_process_logger'] = true;
$config['log_path'] = ROOT . '/logs';

return $config;