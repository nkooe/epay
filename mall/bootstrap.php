<?php

declare(strict_types=1);

$nosession = true;
require_once dirname(__DIR__) . '/includes/common.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function mallEscape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mallSession(bool $expired = false, bool $page = false): array
{
    try {
        return \lib\MallCheckout::token((string) ($_GET['t'] ?? ''), $expired);
    } catch (Throwable $error) {
        http_response_code(400);
        if ($page) {
            mallUnavailable($error->getMessage());
            exit;
        }
        exit(mallEscape($error->getMessage()));
    }
}
