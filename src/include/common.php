<?php

error_reporting(E_ALL);

if (!defined('ROOT')) {
    define('ROOT', dirname(__dir__));
}

define('INCLUDE_PATH',  ROOT . '/include');
define('CONFIG_PATH',   ROOT . '/config');

include ROOT . '/include/app.lib.php';
include ROOT . '/include/logger.lib.php';
include ROOT . '/include/service.lib.php';
include ROOT . '/services/command.service.php';

App::bootstrap();