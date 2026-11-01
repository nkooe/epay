<?php
namespace lib\Task;

/** Drain both child streams into daily files; systemd still owns the whole cgroup. */
final class LogProcess
{
    private static function append(ServiceLog $logs, $source, $text)
    {
        // A stuck filesystem must not pin the collector indefinitely. systemd
        // removes the complete cgroup if its main collector exits abnormally.
        $previous = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, static function () { throw new \RuntimeException('服务日志操作超时（5秒）'); });
        pcntl_alarm(5);
        try { $logs->append($source, $text); }
        finally { pcntl_alarm(0); pcntl_signal(SIGALRM, $previous); }
    }
    public static function run(ServiceLog $logs, array $command, $source)
    {
        pcntl_async_signals(true);
        $watchdog = $source === 'worker' ? new Watchdog() : null;
        self::append($logs, $source, '进程启动');
        $descriptors = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
        if ($watchdog) $descriptors[3] = ['pipe','w'];
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) throw new \RuntimeException('无法启动服务进程');
        fclose($pipes[0]); unset($pipes[0]);
        $buffers = [1=>'', 2=>'', 3=>'']; $discard = [1=>false, 2=>false]; $exit = null; $drainUntil = null;
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        // KillMode=control-group delivers the signal to the child as well. Keep
        // draining during its graceful shutdown; do not send a duplicate signal.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () {});
            pcntl_signal(SIGINT, static function () {});
        }
        try {
            while (true) {
                if ($watchdog) $watchdog->check();
                $progress = null;
                $status = proc_get_status($process);
                if (!$status['running'] && $exit === null) {
                    $exit = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
                    $drainUntil = microtime(true) + 1;
                }
                if (!$pipes && !$status['running']) break;
                // A descendant can inherit stdout after the actual service dies.
                // Do not let its open pipe keep this unit falsely alive forever.
                if ($drainUntil !== null && microtime(true) >= $drainUntil) break;
                if (!$pipes) { usleep(20000); continue; }
                $read = array_values($pipes); $write = null; $except = null;
                // Signals can interrupt select; the next iteration continues draining.
                $ready = @stream_select($read, $write, $except, 0, 200000);
                if ($ready === false) {
                    $last = error_get_last();
                    if (!isset($last['message']) || stripos($last['message'], 'interrupted') === false) throw new \RuntimeException('无法读取服务输出流');
                    continue;
                }
                foreach ($pipes as $id => $pipe) {
                    if (in_array($pipe, $read, true)) {
                        $chunk = fread($pipe, 8192);
                        if ($chunk === false) throw new \RuntimeException('读取服务输出失败');
                        if ($id === 3) {
                            $buffers[3] .= $chunk;
                            if (strlen($buffers[3]) > 4096) throw new \RuntimeException('调度进度管道超出限制');
                            while (($end = strpos($buffers[3], "\n")) !== false) {
                                $progress = substr($buffers[3], 0, $end);
                                if (!in_array($progress, ['running','draining','stopped'], true)) throw new \RuntimeException('调度进度协议错误');
                                $buffers[3] = substr($buffers[3], $end + 1);
                            }
                            if (feof($pipe)) { fclose($pipe); unset($pipes[$id]); }
                            continue;
                        }
                        if ($discard[$id]) {
                            $end = strpos($chunk, "\n");
                            if ($end !== false) { $discard[$id] = false; $chunk = substr($chunk, $end + 1); }
                            else { $chunk = ''; }
                        }
                        $buffers[$id] .= $chunk;
                        $complete = '';
                        while (($end = strpos($buffers[$id], "\n")) !== false) {
                            $complete .= substr($buffers[$id], 0, $end + 1);
                            $buffers[$id] = substr($buffers[$id], $end + 1);
                        }
                        if ($complete !== '') self::append($logs, $source, $complete);
                        if (strlen($buffers[$id]) > 16384) {
                            self::append($logs, $source, substr($buffers[$id], 0, 16384).' [日志行过长，剩余内容已截断]');
                            $buffers[$id] = ''; $discard[$id] = true;
                        }
                    }
                    if (feof($pipe)) {
                        if ($buffers[$id] !== '') self::append($logs, $source, $buffers[$id]);
                        fclose($pipe); unset($pipes[$id]);
                    }
                }
                if ($progress !== null && $status['running']) $watchdog->advance($progress);
            }
        } catch (\Throwable $e) {
            // Logging failure cannot leave an untracked child doing business.
            proc_terminate($process, 9);
            throw $e;
        } finally {
            foreach ($pipes as $pipe) fclose($pipe);
            $closed = proc_close($process);
        }
        $exit = $exit !== null && $exit >= 0 ? $exit : $closed;
        if ($exit < 0) throw new \RuntimeException('无法取得服务退出状态');
        self::append($logs, $source, '进程结束，退出码 '.$exit);
        return $exit;
    }
}
