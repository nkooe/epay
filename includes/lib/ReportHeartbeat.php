<?php
namespace lib;

/** Select one heartbeat plugin; authentication and state remain inside that plugin. */
final class ReportHeartbeat
{
    const DEFAULT_X_CLIENT = '易监控';

    static public function clientName(array $config)
    {
        $value = $config['client_name'] ?? '';
        $name = is_string($value) ? trim($value) : '';
        return $name === '' ? self::DEFAULT_X_CLIENT : $name;
    }

    static public function matchesX($db, $name)
    {
        if (!is_string($name) || $name === '') return false;
        // Include stopped channels so disabling a channel cannot reroute its traffic to PC.
        $rows = $db->getAll("SELECT config FROM pre_channel WHERE plugin='jktongyx'");
        if (!is_array($rows)) throw new \RuntimeException('心跳配置读取失败', 500);
        foreach ($rows as $row) {
            $config = json_decode((string)$row['config'], true);
            if (self::clientName(is_array($config) ? $config : []) === $name) return true;
        }
        return false;
    }

    static private function payload()
    {
        $query = $_GET;
        unset($query['s']);
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'POST'], true)) throw new \RuntimeException('只接受 GET 或 POST 请求', 405);
        $body = [];
        if ($method === 'POST') {
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 131072) throw new \RuntimeException('请求正文过大', 413);
            $raw = (string)file_get_contents('php://input');
            if (strlen($raw) > 131072) throw new \RuntimeException('请求正文过大', 413);
            $body = $_POST;
            if (strpos(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json') !== false || preg_match('/^\s*\{/', $raw)) {
                $body = json_decode($raw, true);
                if (!is_array($body) || !preg_match('/^\s*\{/', $raw)) throw new \RuntimeException('请求正文不是 JSON 对象', 400);
            } elseif (!$body && $raw !== '') {
                parse_str($raw, $body);
            }
        }
        foreach ([$query, $body] as $fields) foreach ($fields as $value) {
            if (!is_string($value) && !is_int($value) && $value !== null) throw new \RuntimeException('上报字段必须是字符串或整数，ext_data 请传 JSON 字符串', 400);
        }
        foreach ($query as $key => $value) {
            if (array_key_exists($key, $body) && (string)$body[$key] !== (string)$value) throw new \RuntimeException('查询参数与正文参数冲突', 400);
        }
        $payload = array_merge($query, $body);
        $name = $payload['client_name'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen($name) > 200) throw new \RuntimeException('心跳需要有效 client_name', 400);
        return $payload;
    }

    static public function dispatch($db, $path)
    {
        try {
            $payload = self::payload();
            $plugin = self::matchesX($db, $payload['client_name']) ? 'jktongyx' : 'jktongypc';
            $file = PLUGIN_ROOT . $plugin . '/' . $plugin . '_plugin.php';
            if (!is_file($file)) throw new \RuntimeException('对应的心跳插件未安装', 404);
            require_once $file;
            $class = '\\' . $plugin . '_plugin';
            $class::apiEndpoint($path);
        } catch (\RuntimeException $e) {
            self::error($e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500, $e->getMessage());
        } catch (\Throwable $e) {
            self::error(500, '心跳处理失败，请稍后重试');
        }
    }

    static private function error($code, $message)
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        http_response_code($code);
        echo json_encode(['code' => $code, 'message' => $message, 'data' => null], JSON_UNESCAPED_UNICODE);
    }
}
