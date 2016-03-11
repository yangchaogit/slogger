<?php

include './include/common.php';

$server_handle = new swoole_server(
    App::config()->get('ip'),
    App::config()->get('port'),
    SWOOLE_PROCESS,
    SWOOLE_SOCK_TCP
);

$server_handle->set([
    'daemonize'                => App::config()->get('daemonize'),
    'backlog'                  => 128,
    'reactor_num'              => swoole_cpu_num(),  //默认会启用CPU核数相同的数量
    'worker_num'               => 2 * swoole_cpu_num(), //设置为CPU的1-4倍最合理
    'dispatch_mode'            => 2,  //2 固定模式，根据连接的文件描述符分配worker。这样可以保证同一个连接发来的数据只会被同一个worker处理
    'task_ipc_mode'            => 1,  //1 使用unix socket通信，默认模式 2 使用消息队列通信 3使用消息队列通信，并设置为争抢模式
    'task_worker_num'          => 10,
    'log_file'                 => ROOT . '/logs/server.log', //指定错误日志文件
    'task_tmpdir'              => '/tmp',                    //设置task的数据临时目录，在swoole_server中，如果投递的数据超过8192字节，将启用临时文件来保存数据。这里的task_tmpdir就是用来设置临时文件保存的位置
    'heartbeat_check_interval' => 30,  //遍历所有连接，如果该连接在heartbeat_idle_time秒内，没有向服务器发送任何数据，此连接将被强制关闭
    'heartbeat_idle_time'      => 60, //连接最大允许空闲的时间
    'open_tcp_nodelay'         => 1,
    'enable_reuse_port '       => 1,
    'open_eof_check'           => 1,      //启用EOF自动分包 onReceive每次仅收到一个以EOF字串结尾的数据包。
    'package_eof'              => "\r\n", //
    'user'                     => 'www',
    'group'                    => 'www',
]);

//logger proccess
$logger_process_handle = new ProcessLogger($argv[0]);
$server_handle->addProcess($logger_process_handle->run());

$server_handle->logger_process_handle = $logger_process_handle;

//
$server_handle->on('start', function(swoole_server $server_handle) use ($argv) {
	App::set_cli_process_title("php {$argv[0]} master");
});

//
$server_handle->on('shutDown', function(swoole_server $server_handle) {
	App::request_shutdown_handle();
});

//
$server_handle->on('managerStart', function(swoole_server $server_handle) use ($argv) {
	App::set_cli_process_title("php {$argv[0]} manager");
});

//
$server_handle->on('managerStop', function(swoole_server $server_handle) {});

/**
 * 无论由客户端发起close还是服务器端主动调用$serv->close()关闭连接，都会触发此事件。因此只要连接关闭，就一定会回调此函数
 * 1.7.7+版本以后onClose中依然可以调用connection_info方法获取到连接信息，在onClose回调函数执行完毕后才会调用close关闭TCP连接
 *
 * 注意：这里回调onClose时表示客户端连接已经关闭，所以无需执行$server->close($fd)。代码中执行$serv->close($fd)会抛出PHP错误告警。
 *
 * $fd               是连接的文件描述符
 * $from_reactor_id  来自哪个reactor线程
 */
$server_handle->on('close', function(swoole_server $server_handle, int $fd, int $from_reactor_id) {
	$server_handle->logger_process_handle->info('close|%s|%s', $fd, json_encode($server_handle->connection_info($fd)));
    unset($server_handle->client_buffer[$fd]);
});

/**
 * 通过$worker_id参数的值来，判断worker是普通worker还是task_worker。$worker_id>= $serv->setting['worker_num'] 时表示这个进程是task_worker。
 * $worker_id是一个从0-$worker_num之间的数字，表示这个worker进程的ID
 * $worker_id和进程PID没有任何关系
 */
$server_handle->on('workerStart', function(swoole_server $server_handle, int $worker_id) use ($argv) {
	$server_handle->logger_process_handle->info('%s|%s', $server_handle->taskworker ? 'taskWorkerStart' : 'eventWorkerStart', $worker_id);
    try {
        if ($server_handle->taskworker) {
            //表示当前的进程是Task工作进程
            App::set_cli_process_title("php {$argv[0]} task worker");
        } else {
            //表示当前的进程是Worker进程
            App::set_cli_process_title("php {$argv[0]} event worker");
        }
    } catch (Throwable $e) {
        $server_handle->logger_process_handle->exception($e);
    }
});

$server_handle->on('workerStop', function(swoole_server $server_handle, int $worker_id) {
	$server_handle->logger_process_handle->info('workerStop|%s', $worker_id);
	App::request_shutdown_handle();
});

$server_handle->on('workerError', function(swoole_server $server_handle, int $worker_id, int $worker_pid, int $exit_code, int $signo) {
	$server_handle->logger_process_handle->info('workerError|%s,%s,%s,%s', $worker_id, $worker_pid, $exit_code, $signo);
});

/**
 * $fd              tcp连接的文件描述符，在swoole_server中是客户端的唯一标识符
 * $from_reactor_id 来自于哪个reactor线程
 */
$server_handle->on('connect', function(swoole_server $server_handle, int $fd, int $from_reactor_id) {
	$server_handle->logger_process_handle->info('connect|%s|%s', $fd, json_encode($server_handle->connection_info($fd)));
	//留意reload非taskWorker时会导致receive内失效
    $server_handle->client_buffer[$fd] = [
        'buffer'  => [],
        'chunk'   => 0,
        'command' => '',
        'params'  => [],
        'tmp_len' => 0,
        'tmp_val' => '',
    ];
});

/**
 * $fd              tcp连接的文件描述符，在swoole_server中是客户端的唯一标识符
 * $from_reactor_id 来自于哪个reactor线程
 *
 */
$server_handle->on('receive', function(swoole_server $server_handle, int $fd, int $from_reactor_id, string $data) {
	$client_info = json_encode($server_handle->connection_info($fd));
	//log
	$server_handle->logger_process_handle->info('receive|%s|%s|%s', $fd, $client_info, str_replace("\r\n", '\r\n', $data));
    //追加包数据
    $server_handle->client_buffer[$fd]['buffer'] = array_merge($server_handle->client_buffer[$fd]['buffer'], explode("\r\n", trim($data, "\r\n")));
    //拆包
    if (0 == $server_handle->client_buffer[$fd]['chunk']) {
        $package_header = array_shift($server_handle->client_buffer[$fd]['buffer']);
        if ($package_header[0] != '*') {
            $server_handle->close($fd);
            return;
        }
        $package_chunk = substr($package_header, 1);
        if (!is_numeric($package_chunk)
            || $package_chunk <= 0) {
            $server_handle->close($fd);
            return;
        }
        $server_handle->client_buffer[$fd]['chunk'] = $package_chunk;
    }

    while ($server_handle->client_buffer[$fd]['chunk'] > 0
        && $server_handle->client_buffer[$fd]['buffer']
        && count($server_handle->client_buffer[$fd]['buffer']) % 2 == 0) {
        if (empty($server_handle->client_buffer[$fd]['tmp_val'])) {
            //len
            $package_header = array_shift($server_handle->client_buffer[$fd]['buffer']);
            if ($package_header[0] != '$') {
                $server_handle->close($fd);
                return;
            }

            $package_len = substr($package_header, 1);
            //content
            $package_val = array_shift($server_handle->client_buffer[$fd]['buffer']);
            if (strlen($package_val) == $package_len) {
                if ($server_handle->client_buffer[$fd]['command']) {
                    $server_handle->client_buffer[$fd]['params'][] = $package_val;
                } else {
                    $server_handle->client_buffer[$fd]['command'] = $package_val;
                }
            } else {
                $server_handle->client_buffer[$fd]['tmp_len'] = $package_len;
                $server_handle->client_buffer[$fd]['tmp_val'] = $package_val;
            }
        } else {
            //content
            $package_val = array_shift($server_handle->client_buffer[$fd]['buffer']);
            //避免内容包含\r\n被拆分
            if (0 == strlen($package_val)) {
                $package_val = "\r\n";
            }
            $server_handle->client_buffer[$fd]['tmp_val'] .= $package_val;
            $current_len = strlen($server_handle->client_buffer[$fd]['tmp_val']);
            if ($current_len > $server_handle->client_buffer[$fd]['tmp_len']) {
                $server_handle->close($fd);
                return;
            } elseif ($current_len == $server_handle->client_buffer[$fd]['tmp_len']) {
                $server_handle->client_buffer[$fd]['params'][] = $server_handle->client_buffer[$fd]['tmp_val'];
                //reset
                $server_handle->client_buffer[$fd]['tmp_len'] = 0;
                $server_handle->client_buffer[$fd]['tmp_val'] = '';
            }
        }
    }

    if ($server_handle->client_buffer[$fd]['command']
        && count($server_handle->client_buffer[$fd]['params']) == $server_handle->client_buffer[$fd]['chunk'] - 1) {
        try {
            //post task
            $result = $server_handle->taskwait(json_encode([
                                                               'fd'      => $fd,
                                                               'command' => $server_handle->client_buffer[$fd]['command'],
                                                               'params'  => $server_handle->client_buffer[$fd]['params'],
                                                           ]), 30);
        } catch (Throwable $e) {
            $server_handle->logger_process_handle->exception('%s|%s|%s', $e->getMessage(), $client_info, json_encode($server_handle->client_buffer[$fd]));
            $result = "-" . $e->getMessage() . "\r\n";
        } finally {
			//send
			if (is_null($result)
				|| false === $result
				|| "" === $result) {
				$result = "-no_reply\r\n";
			}
			$server_handle->send($fd, $result);
			$server_handle->logger_process_handle->info('send|%s|%s|%s', $client_info, json_encode($server_handle->client_buffer[$fd]), str_replace("\r\n", '\r\n', $result));
		}
        //reset loop
        $server_handle->client_buffer[$fd]['chunk']   = 0;
        $server_handle->client_buffer[$fd]['command'] = '';
        $server_handle->client_buffer[$fd]['params']  = [];
    }
});

/**
 * $task_id        是任务ID，由swoole扩展内自动生成，用于区分不同的任务。$task_id和$from_id组合起来才是全局唯一的，不同的worker进程投递的任务ID可能会有相同
 * $from_worker_id 来自于哪个worker进程
 * $data           任务的内容
 */
$server_handle->on('task', function(swoole_server $server_handle, int $task_id, int $from_worker_id, string $data){
	//解包
	$package_data = json_decode($data, 1);
	//连接信息
	$client_info  = $server_handle->connection_info($package_data['fd']);
	//log
	$server_handle->logger_process_handle->info('task|%s|%s|%s', $package_data['fd'], "123", $data);
	//execute
    $service_handle = Service::instance('Command', $server_handle);
	try {
		$result = $service_handle->refresh($package_data['fd'], $client_info)->execute($package_data['command'], $package_data['params']);
	} catch (Throwable $e) {
		$server_handle->logger_process_handle->exception($e);
		$result = $service_handle->convert_to_result($e->getMessage(), false);
	} finally {
		if (!$service_handle->is_finished()) {
			return $result;
		}
	}
});

/**
 * $task_id 是任务的ID
 * $data    是任务处理的结果内容
 */
$server_handle->on('finish', function(swoole_server $server_handle, int $task_id, string $data){});

$server_handle->start();
