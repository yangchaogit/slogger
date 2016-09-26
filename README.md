# slogger

基于swoole实现的redis协议日志记录服务器


```php
<?php

class CommandService extends Service {

	/**
	 * @param int    $server_id
	 * @param string $file_name
	 * @param string $content
	 *
	 * @return string
	 */
	public function hset($server_id, $file_name, $content) {
		if (!is_numeric($server_id)
			|| !preg_match('/^[a-zA-Z0-9:_-]{1,}$/', $file_name)
			|| 0 == strlen($content)) {
			return 0;
		}

		$this->finish(1);

		file_put_contents(
			App::config()->get('log_path') . '/' . $server_id . '_' . $file_name . '.log',
			$content . "\n",
			FILE_APPEND | LOCK_EX
		);

		return 1;
	}
}
```
