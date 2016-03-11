<?php

class Logger {

    const INFO   = 1;
    const EXCEPT = 2;
    const ERROR  = 3;

    static $log_list   = [];
	static $log_handle = [];

    public static function record($level, $message, $flush = true) {
        $level_label = [
            self::INFO   => 'info',
            self::EXCEPT => 'exception',
            self::ERROR  => 'error'
        ];

        $log_content = App::get_date('Y-m-d H:i:s') . '|' . $level_label[$level] . '|' . $message;

        if ($flush) {
			$log_file = App::config()->get('log_path') . '/' . App::get_date('Ymd') . '.log';
			if (!empty(self::$log_handle['file'])
				&& self::$log_handle['file'] != $log_file) {
				self::clear();
			}

			self::$log_handle = [
				'file' => $log_file,
				'fd'   => fopen($log_file, 'a+'),
			];

            fwrite(self::$log_handle['fd'], $log_content . "\n");
			fflush(self::$log_handle['fd']);
        } else {
            self::$log_list[] = $log_content;
        }
    }

    public static function clear() {
        if (self::$log_list) {
            file_put_contents(App::config()->get('log_path') . '/' . App::get_date('Ymd') . '.log', implode("\n", self::$log_list) . "\n", FILE_APPEND | LOCK_EX);
        }

		if (!empty(self::$log_handle['file'])
			&& isset(self::$log_handle['fd'])) {
			fclose(self::$log_handle['fd']);
		}

		self::$log_list   = [];
		self::$log_handle = [];
    }

    public static function info(...$p) {return self::record(self::INFO, self::parse_message($p));}
    public static function error(...$p) {return self::record(self::ERROR, self::parse_message($p));}
    public static function exception(...$p) {return self::record(self::EXCEPT, self::parse_message($p));}

	/**
	 * @param $arguments
	 *
	 * @return bool|float|int|mixed|string
	 */
    public static function parse_message($arguments) {
        if (1 == count($arguments)) {
            if (is_scalar($arguments[0])) {
                $message = $arguments[0];
            } elseif (is_array($arguments[0])) {
                $message = json_encode($arguments[0]);
            } elseif ($arguments[0] instanceof Throwable) {
                $message = $arguments[0]->getMessage() . ',FILE:' . $arguments[0]->getFile() . ',LINE:' . $arguments[0]->getLine();
            } elseif (is_object($arguments[0])) {
                $message = $arguments[0]->toString();
            }
        } else {
            $message = call_user_func_array('sprintf', $arguments);
        }

        return $message;
    }

    /**
     * @param $level
     * @param $message
     *
     * @return string
     */
    public static function pack_message($level, $message) {
        return json_encode([$level, utf8_encode(self::parse_message($message))]);
    }

    /**
     * @param $message
     *
     * @return array
     */
    public static function unpack_message($message) {
        $result = json_decode($message, 1);
        return [$result[0], utf8_decode($result[1])];
    }
}

class ProcessLogger {

	private $_cli_name = '';
	private $_swoole_process_handle;
	private $_enable_process_logger = false;

	public function __construct($cli_name) {
		$this->_cli_name = $cli_name;
		$this->_enable_process_logger = App::config()->get('enable_process_logger');
	}

	public function process(swoole_process $process_handle) {
		try {
			App::set_cli_process_title("php {$this->_cli_name} logger process");
			Logger::record(Logger::INFO, 'processStart|' . $process_handle->pid);
			while (1) {
				try {
					$message = $process_handle->read();
					if ($message
						&& $this->_enable_process_logger) {
						list ($level, $message) = Logger::unpack_message($message);
						Logger::record($level, $message);
					}
				} catch (Throwable $e) {
					Logger::exception($e);
				}
			}
		} catch (Throwable $e) {
			Logger::exception($e);
		} finally {
			$process_handle->close();
			$process_handle->exit();
			Logger::clear();
		}
	}

	public function run() {
		$this->_swoole_process_handle = new swoole_process(array($this, 'process'), false, 2);
		return $this->_swoole_process_handle;
	}

    public function info(...$p) {$this->record(Logger::INFO, $p);}
    public function error(...$p) {$this->record(Logger::ERROR, $p);}
    public function exception(...$p) {$this->record(Logger::EXCEPT, $p);}
    public function record($level, $p) {
		if (!$this->_enable_process_logger) {
			return;
		}

		$this->_swoole_process_handle->write(Logger::pack_message($level, $p));
	}
}