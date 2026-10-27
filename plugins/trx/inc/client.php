<?php

class trx_client
{
    const API_URL = 'https://apilist.tronscan.org/api/transaction';
    const INFO_URL = 'https://apilist.tronscan.org/api/transaction-info';

    public function fetchTransactions($address, $startTime, $endTime)
    {
        $result = []; $limit = 50;
        for ($start = 0; $start < 1000; $start += $limit) {
            $url = self::API_URL . '?' . http_build_query(['sort' => '-timestamp', 'count' => 'true', 'limit' => $limit, 'start' => $start, 'address' => $address]);
            $data = $this->request($url);
            if (!array_key_exists('data', $data) || !is_array($data['data'])) throw new RuntimeException('TRX 接口缺少交易列表');
            $rows = $data['data'];
            if (!$rows) break;
            $hasOlder = false;
            foreach ($rows as $row) {
                $timestamp = (int)floor((int)($row['timestamp'] ?? 0) / 1000);
                if ($timestamp > 0 && $timestamp < $startTime) { $hasOlder = true; continue; }
                if ($timestamp <= 0 || $timestamp > $endTime) continue;
                $transaction = $this->parseTransfer($row, $address);
                if ($transaction !== null) $result[] = $transaction;
            }
            if ($hasOlder || count($rows) < $limit) break;
        }
        return $result;
    }

    public function verifyTransaction($transaction)
    {
        $hash = strtolower(trim((string)($transaction['tx_id'] ?? '')));
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) return $this->unverified($transaction);
        $data = $this->request(self::INFO_URL . '?' . http_build_query(['hash' => $hash]));
        if (strtolower(trim((string)($data['hash'] ?? ''))) !== $hash || ($data['confirmed'] ?? null) !== true) return $this->unverified($transaction);
        if ((int)($data['contractType'] ?? 0) !== 1 || (string)($data['contractRet'] ?? '') !== 'SUCCESS' || !empty($data['revert'])) return $this->unverified($transaction);
        $contract = isset($data['contractData']) && is_array($data['contractData']) ? $data['contractData'] : [];
        $raw = $this->normalizeRaw($contract['amount'] ?? null);
        $to = (string)($contract['to_address'] ?? '');
        if ($raw === null || !hash_equals((string)($transaction['raw_amount'] ?? ''), $raw) || $to !== (string)($transaction['to'] ?? '')) return $this->unverified($transaction);
        return [
            'tx_id' => $hash, 'from' => (string)($contract['owner_address'] ?? ''), 'to' => $to,
            'raw_amount' => $raw, 'amount' => $this->formatRaw($raw, 6),
            'timestamp' => (int)floor((int)($data['timestamp'] ?? 0) / 1000),
            'token' => 'TRX', 'contract' => '', 'block_number' => (int)($data['block'] ?? 0),
            'confirmations' => max(0, (int)($data['confirmations'] ?? 0)), 'confirmed' => true, 'verified' => true,
        ];
    }

    private function parseTransfer($row, $address)
    {
        if (($row['confirmed'] ?? null) !== true || !empty($row['revert']) || (int)($row['contractType'] ?? 0) !== 1) return null;
        if ((string)($row['contractRet'] ?? '') !== 'SUCCESS' || (string)($row['result'] ?? '') !== 'SUCCESS') return null;
        $contract = isset($row['contractData']) && is_array($row['contractData']) ? $row['contractData'] : [];
        if ((string)($contract['to_address'] ?? '') !== (string)$address) return null;
        $hash = strtolower(trim((string)($row['hash'] ?? ''))); $raw = $this->normalizeRaw($contract['amount'] ?? null);
        $timestamp = (int)floor((int)($row['timestamp'] ?? 0) / 1000); $block = (int)($row['block'] ?? 0);
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1 || $raw === null || $raw === '0' || $timestamp <= 0 || $block <= 0) return null;
        return [
            'tx_id' => $hash, 'from' => (string)($contract['owner_address'] ?? ''), 'to' => (string)$contract['to_address'],
            'raw_amount' => $raw, 'amount' => $this->formatRaw($raw, 6), 'timestamp' => $timestamp,
            'token' => 'TRX', 'contract' => '', 'block_number' => $block,
            'confirmed' => true, 'verified' => false,
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
        if ($ch === false) throw new RuntimeException('无法初始化 TRX 网络请求');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Epay-TRX/1.1',
        ]);
        $body = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (!is_string($body)) throw new RuntimeException('TRX 网络请求失败：' . $error);
        if ($status < 200 || $status >= 300) throw new RuntimeException('TRX 接口返回 HTTP ' . $status);
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('TRX 接口数据异常');
        return $data;
    }
}
