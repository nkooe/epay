<?php

class polygon_client
{
    const API_URL = 'https://api.etherscan.io/v2/api';
    const USDT_CONTRACT = '0xc2132D05D31c914a87C6611C10748AEb04B58e8F';
    const MIN_CONFIRMATIONS = 3;

    private $apiKey;

    public function __construct($apiKey) { $this->apiKey = trim((string)$apiKey); }

    public function fetchTransactions($address, $startTime, $endTime)
    {
        $params = $this->baseParams($address);
        $params['page'] = 1; $params['offset'] = 100; $params['sort'] = 'desc';
        $result = [];
        for ($page = 1; $page <= 10; $page++) {
            $params['page'] = $page;
            $rows = $this->transactionRows($params, true);
            if (!$rows) break;
            $hasOlder = false;
            foreach ($rows as $row) {
                $timestamp = (int)($row['timeStamp'] ?? 0);
                if ($timestamp > 0 && $timestamp < $startTime) { $hasOlder = true; continue; }
                if ($timestamp <= 0 || $timestamp > $endTime) continue;
                $transaction = $this->parseTransfer($row, $address);
                if ($transaction !== null && $transaction['confirmed']) $result[] = $transaction;
            }
            if ($hasOlder || count($rows) < (int)$params['offset']) break;
        }
        return $result;
    }

    public function verifyTransaction($transaction)
    {
        $hash = strtolower(trim((string)($transaction['tx_id'] ?? '')));
        $address = trim((string)($transaction['to'] ?? ''));
        $block = (int)($transaction['block_number'] ?? 0);
        if (preg_match('/^0x[0-9a-f]{64}$/', $hash) !== 1 || $address === '' || $block <= 0) return $this->unverified($transaction);

        $params = $this->baseParams($address);
        $params['startblock'] = $block; $params['endblock'] = $block;
        $params['page'] = 1; $params['offset'] = 1000; $params['sort'] = 'asc';
        foreach ($this->transactionRows($params, false) as $row) {
            if (strtolower(trim((string)($row['hash'] ?? ''))) !== $hash) continue;
            $verified = $this->parseTransfer($row, $address);
            if ($verified === null || !$verified['confirmed']) continue;
            if (!hash_equals((string)$transaction['raw_amount'], (string)$verified['raw_amount'])) continue;
            $verified['verified'] = true;
            return $verified;
        }
        return $this->unverified($transaction);
    }

    private function baseParams($address)
    {
        return [
            'chainid' => 137, 'module' => 'account', 'action' => 'tokentx',
            'contractaddress' => self::USDT_CONTRACT, 'address' => $address,
            'startblock' => 0, 'endblock' => 99999999, 'apikey' => $this->apiKey,
        ];
    }

    private function transactionRows($params, $allowEmpty)
    {
        $data = $this->request(self::API_URL . '?' . http_build_query($params));
        if ((string)($data['status'] ?? '0') !== '1') {
            $message = trim((string)($data['message'] ?? '') . ' ' . (is_string($data['result'] ?? null) ? $data['result'] : ''));
            if ($allowEmpty && stripos($message, 'no transactions') !== false) return [];
            throw new RuntimeException('Polygon 接口拒绝请求：' . ($message !== '' ? $message : '未知错误'));
        }
        if (!isset($data['result']) || !is_array($data['result'])) throw new RuntimeException('Polygon 接口缺少交易列表');
        return $data['result'];
    }

    private function parseTransfer($row, $address)
    {
        $hash = strtolower(trim((string)($row['hash'] ?? '')));
        $raw = $this->normalizeRaw($row['value'] ?? null);
        $decimals = (int)($row['tokenDecimal'] ?? -1);
        if (preg_match('/^0x[0-9a-f]{64}$/', $hash) !== 1 || $raw === null || $raw === '0' || $decimals !== 6) return null;
        if (strcasecmp((string)($row['to'] ?? ''), (string)$address) !== 0) return null;
        if (strcasecmp((string)($row['contractAddress'] ?? ''), self::USDT_CONTRACT) !== 0) return null;
        if ((string)($row['isError'] ?? '0') !== '0' || (isset($row['txreceipt_status']) && (string)$row['txreceipt_status'] !== '1')) return null;
        $timestamp = (int)($row['timeStamp'] ?? 0); $block = (int)($row['blockNumber'] ?? 0);
        if ($timestamp <= 0 || $block <= 0) return null;
        return [
            'tx_id' => $hash, 'from' => (string)($row['from'] ?? ''), 'to' => (string)$row['to'],
            'raw_amount' => $raw, 'amount' => $this->formatRaw($raw, 6), 'timestamp' => $timestamp,
            'token' => 'USDT', 'contract' => self::USDT_CONTRACT, 'block_number' => $block,
            'confirmations' => max(0, (int)($row['confirmations'] ?? 0)),
            'confirmed' => (int)($row['confirmations'] ?? 0) >= self::MIN_CONFIRMATIONS, 'verified' => false,
        ];
    }

    private function unverified($transaction)
    {
        $transaction['confirmed'] = false; $transaction['verified'] = false;
        return $transaction;
    }

    private function normalizeRaw($value)
    {
        if (is_int($value)) $value = (string)$value;
        if (!is_string($value) || preg_match('/^\d+$/', trim($value)) !== 1) return null;
        $value = ltrim(trim($value), '0');
        return $value === '' ? '0' : $value;
    }

    private function formatRaw($raw, $decimals)
    {
        $raw = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
        return substr($raw, 0, -$decimals) . '.' . substr($raw, -$decimals);
    }

    private function request($url)
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('无法初始化 Polygon 网络请求');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Epay-Polygon/1.1',
        ]);
        $body = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (!is_string($body)) throw new RuntimeException('Polygon 网络请求失败：' . $error);
        if ($status < 200 || $status >= 300) throw new RuntimeException('Polygon 接口返回 HTTP ' . $status);
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('Polygon 接口数据异常');
        return $data;
    }
}
