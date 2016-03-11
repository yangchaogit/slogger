<?php

class App {

    public static function set_cli_process_title($title) {
        if (PHP_OS != 'Darwin') {
            @swoole_set_process_name($title);
        }
    }

    public static function get_time() {
        return time();
    }

    public static function get_date($format, $timestamp = 0) {
        return date($format, $timestamp ? $timestamp : self::get_time());
    }

    /**
     * @return JZ_Data
     */
    public static function config() {
        static $_config = null;
        if (is_null($_config)) {
            $_config = new JZ_Data(include CONFIG_PATH . '/app.conf.php');
        }

        return $_config;
    }

    /**
     *
     */
    public static function bootstrap() {
        //时区设置
        date_default_timezone_set(self::config()->get('timezone'));
        //清理
        register_shutdown_function(array('App','request_shutdown_handle'));
        //错误
        set_error_handler(array('App','request_error_handle'));
        //异常
        set_exception_handler(array('App','request_exception_handle'));
    }

    /**
     * 清理
     *
     */
    public static function request_shutdown_handle() {
        try {
            $last_error = error_get_last();
            if ($last_error) {
                Logger::error("[" . $last_error['type'] . "]" . $last_error['message'] . " in " . $last_error['file'] . " at " . $last_error['line'] . " line");
            }

            //清理
            Logger::clear();
        } catch (Throwable $e) {
            error_log($e->getTraceAsString());
        }
    }

    /**
     *
     * @param int    $err_no   错误代码
     * @param string $err_msg  错误描述
     * @param string $err_file 所在文件
     * @param string $err_line 所在位置
     */
    public static function request_error_handle($err_no, $err_msg, $err_file, $err_line) {
        throw new ErrorException($err_msg, $err_no, 1, $err_file, $err_line);
    }

    /**
     *
     * @param Exception $e 异常
     */
    public static function request_exception_handle($e) {
        Logger::exception($e);
    }
}