<?php
namespace lib\Task;

/** Only the actual scheduler may acknowledge a completed scheduling turn. */
final class Progress
{
    private $stream;
    public function __construct()
    {
        $this->stream = @fopen('php://fd/3', 'wb');
        if (!$this->stream) throw new \RuntimeException('调度进度管道未安装，请通过任务服务启动');
        stream_set_blocking($this->stream, false);
    }
    public function advance($state)
    {
        if (!in_array($state, ['running', 'draining', 'stopped'], true)) throw new \InvalidArgumentException('调度进度状态无效');
        $record = $state."\n";
        if (@fwrite($this->stream, $record) !== strlen($record)) throw new \RuntimeException('调度进度管道不可写，停止调度');
    }
}
