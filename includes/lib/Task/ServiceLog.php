<?php
namespace lib\Task;

/** The console's actual log files. No journal replay or deletion markers. */
final class ServiceLog
{
    public const DAY_BYTES = 4194304;
    public const TOTAL_BYTES = 33554432;
    public const DAYS = 14;
    public const LOCK_SECONDS = 1;
    private $directory;

    public static function directory(array $config)
    {
        if (!is_string($config['helper'] ?? null) || $config['helper'] === '') throw new \RuntimeException('缺少日志目录配置');
        // Installed beside the site-specific helper, outside the public website.
        return dirname($config['helper']).'/logs';
    }
    public function __construct(array $config) { $this->directory = self::directory($config); }
    private function locked(callable $operation)
    {
        if (is_link($this->directory) || !is_dir($this->directory)) throw new \RuntimeException('服务日志目录未安装，请重新运行任务服务安装器');
        $path = $this->directory.'/.lock';
        if (is_link($path)) throw new \RuntimeException('日志锁不能为符号链接');
        $lock = fopen($path, 'c+b');
        if (!$lock) throw new \RuntimeException('无法打开服务日志锁');
        try {
            if (!chmod($path, 0600)) throw new \RuntimeException('无法设置服务日志锁权限');
            $deadline = hrtime(true) + self::LOCK_SECONDS * 1000000000;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) throw new \RuntimeException('服务日志锁等待超时（1秒），请稍后重试');
                usleep(10000);
            }
            clearstatcache();
            return $operation();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private function path($day)
    {
        $path = $this->directory.'/'.SystemdController::validateLogDate($day).'.log';
        if (is_link($path) || (file_exists($path) && !is_file($path))) throw new \RuntimeException('日志文件类型不正确');
        return $path;
    }
    private function files()
    {
        $files = [];
        $entries = scandir($this->directory);
        if ($entries === false) throw new \RuntimeException('无法读取日志目录');
        foreach ($entries as $name) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})\.log$/D', $name, $match)) continue;
            $path = $this->path($match[1]);
            if (is_file($path)) $files[$match[1]] = $path;
        }
        krsort($files, SORT_STRING);
        return $files;
    }
    public function days() { return $this->locked(function () { return array_keys($this->files()); }); }
    public function delete($day)
    {
        SystemdController::validateLogDate($day);
        $this->locked(function () use ($day) {
            $path = $this->path($day);
            if (!is_file($path)) throw new \lib\AdminApp\ApiError('日志日期不存在', 404);
            if (!unlink($path)) throw new \RuntimeException('删除日志文件失败');
            clearstatcache(true, $path);
            if (file_exists($path)) throw new \RuntimeException('日志文件未删除');
        });
    }
    private static function source($source)
    {
        if (!is_string($source) || !preg_match('/^(?:worker|job:[a-f0-9]{32}|plugin:[a-f0-9]{32}g[1-9][0-9]{0,8})$/D', $source)) throw new \InvalidArgumentException('日志来源无效');
        return $source;
    }
    public function append($source, $text, \DateTimeImmutable $now = null)
    {
        self::source($source);
        if (!is_string($text)) throw new \InvalidArgumentException('日志内容必须为文本');
        if ($text === '') return;
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $prefix = $now->format('Y-m-d\TH:i:sP').' ['.$source.'] ';
        $text = SystemdController::redact(mb_convert_encoding($text, 'UTF-8', 'UTF-8'));
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", rtrim($text, "\n"));
        $record = $prefix.implode("\n".$prefix, $lines)."\n";
        $this->locked(function () use ($now, $record) {
            $path = $this->path($now->format('Y-m-d'));
            // Open and close for each append while holding the same lock as delete.
            // A live process never keeps writing to a deleted inode or yesterday's file.
            $stream = fopen($path, 'c+b');
            if (!$stream) throw new \RuntimeException('无法写入服务日志');
            try {
                if (!chmod($path, 0600)) throw new \RuntimeException('无法设置日志权限');
                $size = fstat($stream)['size'];
                if ($size + strlen($record) > self::DAY_BYTES) {
                    $keep = max(0, self::DAY_BYTES - min(strlen($record), self::DAY_BYTES));
                    $tail = '';
                    if ($keep > 0 && $size > 0) {
                        fseek($stream, max(0, $size - $keep));
                        $tail = stream_get_contents($stream);
                        $newline = strpos($tail, "\n");
                        $tail = $newline === false ? '' : substr($tail, $newline + 1);
                    }
                    $record = $tail.substr($record, -self::DAY_BYTES);
                    if (!ftruncate($stream, 0) || !rewind($stream)) throw new \RuntimeException('日志轮转失败');
                } else { fseek($stream, 0, SEEK_END); }
                for ($offset = 0, $length = strlen($record); $offset < $length; $offset += $written) {
                    $written = fwrite($stream, substr($record, $offset));
                    if ($written === false || $written === 0) throw new \RuntimeException('服务日志写入不完整');
                }
                if (!fflush($stream)) throw new \RuntimeException('服务日志刷盘失败');
            } finally { fclose($stream); }
            $cutoff = $now->modify('-'.(self::DAYS - 1).' days')->format('Y-m-d');
            $total = 0;
            clearstatcache();
            foreach ($this->files() as $day => $file) {
                $size = filesize($file);
                if ($size === false) throw new \RuntimeException('无法读取日志大小');
                if ($day < $cutoff || $total + $size > self::TOTAL_BYTES) {
                    if (!unlink($file)) throw new \RuntimeException('过期服务日志清理失败');
                } else { $total += $size; }
            }
        });
    }
    public function read($day, $source = null, $limit = 131072)
    {
        SystemdController::validateLogDate($day);
        if ($source !== null) self::source($source);
        return $this->locked(function () use ($day, $source, $limit) {
            $path = $this->path($day);
            if (!is_file($path)) throw new \lib\AdminApp\ApiError('日志日期不存在', 404);
            $stream = fopen($path, 'rb');
            if (!$stream) throw new \RuntimeException('无法读取服务日志');
            try {
                $size = fstat($stream)['size'];
                $offset = max(0, $size - ($source === null ? $limit : self::DAY_BYTES));
                fseek($stream, $offset);
                if ($offset > 0) fgets($stream);
                $text = stream_get_contents($stream);
                if ($text === false) throw new \RuntimeException('服务日志读取失败');
            } finally { fclose($stream); }
            if ($source !== null) {
                $lines = array_filter(explode("\n", $text), static function ($line) use ($source) {
                    return preg_match('/^\S+ \['.preg_quote($source, '/').'\] /', $line);
                });
                $text = implode("\n", $lines);
            }
            if (strlen($text) > $limit) {
                $text = substr($text, -$limit);
                $newline = strpos($text, "\n");
                $text = $newline === false ? '' : substr($text, $newline + 1);
            }
            return SystemdController::redact(mb_convert_encoding($text, 'UTF-8', 'UTF-8'));
        });
    }
    public function recent($source = null)
    {
        foreach ($this->days() as $day) {
            try { $text = $this->read($day, $source, 32768); }
            catch (\lib\AdminApp\ApiError $e) { if ($e->status === 404) continue; throw $e; }
            if ($text !== '') return $text;
        }
        return '';
    }
}
