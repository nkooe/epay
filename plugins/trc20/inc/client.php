<?php

class trc20_client
{
    const API_URL = 'https://apilist.tronscanapi.com/api/token_trc20/transfers';
    const INFO_URL = 'https://apilist.tronscanapi.com/api/transaction-info';
    const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    public function fetchTransactions($address, $startTime, $endTime)
    {
        $result = []; $limit = 50;
        for ($start = 0; $start < 1000; $start += $limit) {
            $url = self::API_URL . '?' . http_build_query(['limit' => $limit, 'start' => $start, 'relatedAddress' => $address]);
            $data = $this->request($url);
            if (!array_key_exists('token_transfers', $data) || !is_array($data['token_transfers'])) throw new RuntimeException('TRC20 接口缺少交易列表');
            $rows = $data['token_transfers'];
            if (!$rows) break;
            $hasOlder = false;
            foreach ($rows as $row) {
                $timestamp = (int)floor((int)($row['block_ts'] ?? 0) / 1000);
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
        if ((string)($data['contractRet'] ?? '') !== 'SUCCESS' || !empty($data['revert'])) return $this->unverified($transaction);

        $transfers = isset($data['trc20TransferInfo']) && is_array($data['trc20TransferInfo']) ? $data['trc20TransferInfo'] : [];
        if (!$transfers && isset($data['tokenTransferInfo']) && is_array($data['tokenTransferInfo'])) $transfers = [$data['tokenTransferInfo']];
        foreach ($transfers as $transfer) {
            $raw = $this->normalizeRaw($transfer['amount_str'] ?? null);
            if ($raw === null || !hash_equals((string)($transaction['raw_amount'] ?? ''), $raw)) continue;
            if ((string)($transfer['to_address'] ?? '') !== (string)($transaction['to'] ?? '')) continue;
            if ((string)($transfer['contract_address'] ?? '') !== self::USDT_CONTRACT) continue;
            if (strcasecmp((string)($transfer['symbol'] ?? ''), 'USDT') !== 0 || (int)($transfer['decimals'] ?? -1) !== 6) continue;
            if (isset($transfer['status']) && (int)$transfer['status'] !== 0) continue;
            return [
                'tx_id' => $hash, 'from' => (string)($transfer['from_address'] ?? ''), 'to' => (string)$transfer['to_address'],
                'raw_amount' => $raw, 'amount' => $this->formatRaw($raw, 6),
                'timestamp' => (int)floor((int)($data['timestamp'] ?? 0) / 1000),
                'token' => 'USDT', 'contract' => self::USDT_CONTRACT,
                'block_number' => (int)($data['block'] ?? 0), 'confirmations' => max(0, (int)($data['confirmations'] ?? 0)),
                'confirmed' => true, 'verified' => true,
            ];
        }
        return $this->unverified($transaction);
    }

    private function parseTransfer($row, $address)
    {
        if (($row['confirmed'] ?? null) !== true || !empty($row['revert'])) return null;
        if ((string)($row['contractRet'] ?? '') !== 'SUCCESS' || (string)($row['finalResult'] ?? '') !== 'SUCCESS') return null;
        if (strcasecmp((string)($row['event_type'] ?? ''), 'Transfer') !== 0) return null;
        if ((string)($row['to_address'] ?? '') !== (string)$address || (string)($row['contract_address'] ?? '') !== self::USDT_CONTRACT) return null;
        $token = isset($row['tokenInfo']) && is_array($row['tokenInfo']) ? $row['tokenInfo'] : [];
        if (strcasecmp((string)($token['tokenAbbr'] ?? ''), 'USDT') !== 0 || (int)($token['tokenDecimal'] ?? -1) !== 6) return null;
        $hash = strtolower(trim((string)($row['transaction_id'] ?? ''))); $raw = $this->normalizeRaw($row['quant'] ?? null);
        $timestamp = (int)floor((int)($row['block_ts'] ?? 0) / 1000); $block = (int)($row['block'] ?? 0);
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1 || $raw === null || $raw === '0' || $timestamp <= 0 || $block <= 0) return null;
        return [
            'tx_id' => $hash, 'from' => (string)($row['from_address'] ?? ''), 'to' => (string)$row['to_address'],
            'raw_amount' => $raw, 'amount' => $this->formatRaw($raw, 6), 'timestamp' => $timestamp,
            'token' => 'USDT', 'contract' => self::USDT_CONTRACT, 'block_number' => $block,
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
        if ($ch === false) throw new RuntimeException('无法初始化 TRC20 网络请求');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Epay-TRC20/1.1',
        ]);
        $body = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (!is_string($body)) throw new RuntimeException('TRC20 网络请求失败：' . $error);
        if ($status < 200 || $status >= 300) throw new RuntimeException('TRC20 接口返回 HTTP ' . $status);
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('TRC20 接口数据异常');
        return $data;
    }
}
