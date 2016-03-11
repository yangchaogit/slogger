<?php

class Service {

	const SUCCESS = 'OK';

	/**
	 *
	 * @param string        $service_prefix
	 * @param swoole_server $swoole_server_handle
	 *
	 * @return Service
	 */
	public static function instance($service_prefix, swoole_server $swoole_server_handle) {
		static $_instance = [];
		if (!isset($_instance[$service_prefix])) {
			$service_cls_name = ucfirst($service_prefix) . 'Service';
			$_instance[$service_prefix] = new $service_cls_name($swoole_server_handle);
		}

		return $_instance[$service_prefix];
	}

	/**
	 * @var swoole_server
	 */
	protected $_swoole_server_handle;

	protected $_client_info = [];

	private $_client_fd = 0;

	protected $_timestamp;

	private $_finished = false;

	/**
	 *
	 * @param swoole_server $swoole_server_handle
	 */
	public function __construct(swoole_server $swoole_server_handle) {
		$this->_swoole_server_handle = $swoole_server_handle;
		$this->_timestamp = App::get_time();
	}

	/**
	 * @param       $fd
	 * @param array $client_info
	 *
	 * @return $this
	 */
	public function refresh($fd, $client_info = []) {
		$this->_timestamp   = App::get_time();
		$this->_client_info = $client_info ? $client_info : $this->_swoole_server_handle->connection_info[$fd];
		$this->_client_fd   = $fd;
		$this->_finished	= false;

		return $this;
	}

	/**
	 * @param $data
	 * @param $is_ok
	 *
	 * @return string
	 */
	public function convert_to_result($data, $is_ok = true) {
		$response = [];
		if ($is_ok) {
			if (is_numeric($data)) {
				$response[] = ':' . $data;
			} elseif (is_string($data)) {
				$response[] = '+' . $data;
			} else {
				$response[] = '+' . json_encode($data, JSON_UNESCAPED_UNICODE);
			}
		} else {
			$response[] = is_scalar($data) ?  '-' . $data : '-' . json_encode($data);
		}

		return implode("\r\n", $response) . "\r\n";
	}

	/**
	 * @param      $data
	 * @param bool $is_ok
	 */
	public function finish($data, $is_ok = true) {
		$this->_finished = true;
		$this->_swoole_server_handle->finish($this->convert_to_result($data, $is_ok));
	}

	/**
	 * @return bool
	 */
	public function is_finished() {
		return $this->_finished;
	}

	/**
	 *
	 */
	public function reload() {
		$this->_swoole_server_handle->reload(true);

		return self::SUCCESS;
	}

	/**
	 *
	 * @param string $service_method
	 * @param array  $service_params
	 *
	 * @return mixed
	 * @throws ErrorException
	 */
	public function execute($service_method, $service_params) {
		if (!method_exists($this, $service_method)) {
			throw new ErrorException('not_found_service_method');
		}

		$result = call_user_func_array(array($this, $service_method), $service_params);

		return $this->_finished ? null : $this->convert_to_result($result, true);
	}

	/**
	 * @param int $db
	 */
	public function select($db = 0) {return Service::SUCCESS;}

	public function version() {return App::config()->get('version');}

	public function info() {return $this->_swoole_server_handle->stats();}
}