<?php

class aptos_client
{
    const API_URL = 'https://api.mainnet.aptoslabs.com/v1/graphql';
    const LEDGER_URL = 'https://api.mainnet.aptoslabs.com/v1';
    const REST_URL = 'https://api.mainnet.aptoslabs.com/v1/transactions/by_version/';
    const ASSET_TYPE = '0x357b0b74bc833e95a115ad22604854d6b0fca151cecd94111770e5d6ffc9dc2b';
    const MIN_CONFIRMATIONS = 1;

    public function fetchTransactions($address, $startTime, $endTime)
    {
        $address = strtolower((string)$address);
        $query = 'query AccountTransactionsData($address: String, $limit: Int, $offset: Int) {'
            . ' account_transactions(where: {account_address: {_eq: $address}}, order_by: {transaction_version: desc}, limit: $limit, offset: $offset) {'
            . ' transaction_version fungible_asset_activities(where: {asset_type: {_eq: "' . self::ASSET_TYPE . '"}, is_transaction_success: {_eq: true}}) {'
            . ' amount asset_type is_transaction_success type owner_address } user_transaction { timestamp } } }';
        $result = []; $limit = 100;
        for ($offset = 0; $offset < 1000; $offset += $limit) {
            $payload = json_encode(['query' => $query, 'variables' => ['address' => $address, 'limit' => $limit, 'offset' => $offset], 'operationName' => 'AccountTransactionsData'], JSON_UNESCAPED_SLASHES);
            $data = $this->request(self::API_URL, $payload);
            if (!isset($data['data']) || !is_array($data['data']) || !array_key_exists('account_transactions', $data['data']) || !is_array($data['data']['account_transactions'])) throw new RuntimeException('Aptos 接口缺少交易列表');
            $rows = $data['data']['account_transactions'];
            if (!$rows) break;
            $hasOlder = false;
            foreach ($rows as $row) {
                $version = trim((string)($row['transaction_version'] ?? ''));
                $timestamp = $this->indexerTimestamp($row['user_transaction']['timestamp'] ?? '');
                if ($timestamp > 0 && $timestamp < $startTime) { $hasOlder = true; continue; }
                if (preg_match('/^\d+$/', $version) !== 1 || $timestamp <= 0 || $timestamp > $endTime) continue;
                $activities = isset($row['fungible_asset_activities']) && is_array($row['fungible_asset_activities']) ? $row['fungible_asset_activities'] : [];
                foreach ($activities as $activity) {
                    if (stripos((string)($activity['type'] ?? ''), '::Deposit') === false || ($activity['is_transaction_success'] ?? null) !== true) continue;
                    if (strcasecmp((string)($activity['owner_address'] ?? ''), $address) !== 0 || strcasecmp((string)($activity['asset_type'] ?? ''), self::ASSET_TYPE) !== 0) continue;
                    $raw = $this->normalizeRaw($activity['amount'] ?? null);
                    if ($raw === null || $raw === '0') continue;
                    $result[] = [
                        'tx_id' => 'aptos-v' . $version, 'transaction_version' => $version,
                        'from' => $this->sender($activities, $activity), 'to' => $address,
                        'raw_amount' => $raw, 'amount' => $this->formatRaw($raw, 6), 'timestamp' => $timestamp,
                        'token' => 'USDT', 'contract' => self::ASSET_TYPE,
                        'confirmed' => true, 'verified' => false,
                    ];
                }
            }
            if ($hasOlder || count($rows) < $limit) break;
        }
        return $result;
    }

    public function verifyTransaction($transaction)
    {
        $version = trim((string)($transaction['transaction_version'] ?? ''));
        if (preg_match('/^\d+$/', $version) !== 1) return $this->unverified($transaction);
        $data = $this->request(self::REST_URL . rawurlencode($version));
        $hash = strtolower(trim((string)($data['hash'] ?? '')));
        if (($data['success'] ?? null) !== true || (string)($data['version'] ?? '') !== $version || preg_match('/^0x[0-9a-f]{64}$/', $hash) !== 1) return $this->unverified($transaction);
        $timestamp = $this->ledgerTimestamp($data['timestamp'] ?? null);
        if ($timestamp <= 0) return $this->unverified($transaction);

        $ledger = $this->request(self::LEDGER_URL);
        $latest = trim((string)($ledger['ledger_version'] ?? ''));
        if (preg_match('/^\d+$/', $latest) !== 1 || (int)$latest < (int)$version || ((int)$latest - (int)$version + 1) < self::MIN_CONFIRMATIONS) return $this->unverified($transaction);

        $transaction['tx_id'] = $hash;
        $transaction['timestamp'] = $timestamp;
        $transaction['confirmations'] = (int)$latest - (int)$version + 1;
        $transaction['confirmed'] = true; $transaction['verified'] = true;
        return $transaction;
    }

    private function sender($activities, $deposit)
    {
        foreach ($activities as $activity) {
            if (stripos((string)($activity['type'] ?? ''), '::Withdraw') === false) continue;
            if ((string)($activity['amount'] ?? '') !== (string)($deposit['amount'] ?? '')) continue;
            if (strcasecmp((string)($activity['asset_type'] ?? ''), (string)($deposit['asset_type'] ?? '')) !== 0) continue;
            return trim((string)($activity['owner_address'] ?? ''));
        }
        return '';
    }

    private function indexerTimestamp($value)
    {
        if ($value === '') return 0;
        try { return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))->getTimestamp(); }
        catch (Throwable $e) { return 0; }
    }

    private function ledgerTimestamp($value)
    {
        $value = trim((string)$value);
        return preg_match('/^\d+$/', $value) === 1 ? intdiv((int)$value, 1000000) : 0;
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

    private function request($url, $payload = null)
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('无法初始化 Aptos 网络请求');
        $options = [
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Epay-Aptos/1.1',
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = $payload;
            $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/json'];
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (!is_string($body)) throw new RuntimeException('Aptos 网络请求失败：' . $error);
        if ($status < 200 || $status >= 300) throw new RuntimeException('Aptos 接口返回 HTTP ' . $status);
        $data = json_decode($body, true);
        if (!is_array($data) || !empty($data['errors'])) throw new RuntimeException('Aptos 接口数据异常');
        return $data;
    }
}
