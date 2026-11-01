<?php
namespace lib\Task;

/** Collector forwards real loop progress only after successfully draining logs. */
final class Watchdog
{
    public const SECONDS = 30;
    private $socket;
    private $ready = false;
    private $last;
    public function __construct()
    {
        $path = getenv('NOTIFY_SOCKET');
        if (!is_string($path) || !in_array(substr($path, 0, 1), ['/', '@'], true) ||
            (int)getenv('WATCHDOG_USEC') !== self::SECONDS * 1000000 || (int)getenv('WATCHDOG_PID') !== getmypid()) {
            throw new \RuntimeException('systemd 看门狗配置不匹配，请重新安装任务服务');
        }
        if ($path[0] === '@') $path = "\0".substr($path, 1);
        $this->socket = @stream_socket_client('udg://'.$path, $code, $message, 1, STREAM_CLIENT_CONNECT);
        if (!$this->socket) throw new \RuntimeException('无法连接 systemd 通知套接字');
        stream_set_blocking($this->socket, false);
        $this->last = hrtime(true);
    }
    public function advance($state)
    {
        if (!in_array($state, ['running', 'draining', 'stopped'], true)) throw new \RuntimeException('调度进度协议错误');
        $text = ($this->ready ? '' : "READY=1\n")."WATCHDOG=1\nSTATUS=".
            ['running'=>'调度正常', 'draining'=>'停止中，等待在途任务完成', 'stopped'=>'已排空'][$state];
        if (@fwrite($this->socket, $text) !== strlen($text)) throw new \RuntimeException('无法提交 systemd 调度进度');
        $this->ready = true; $this->last = hrtime(true);
    }
    public function check()
    {
        if (hrtime(true) - $this->last >= self::SECONDS * 1000000000) throw new \RuntimeException('调度主循环进度超时（30秒），停止并交由 systemd 恢复');
    }
}
