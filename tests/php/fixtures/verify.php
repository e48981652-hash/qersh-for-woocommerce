<?php
// Qersh webhook signature check (qersh.v1). PHP 7.4+. No dependencies.
// $header  = $_SERVER['HTTP_X_QERSH_SIGNATURE'];   $rawBody = file_get_contents('php://input');
// $secrets = [getenv('QERSH_WEBHOOK_SECRET')];     // add the previous secret here while rotating
function qersh_verify(string $header, string $rawBody, array $secrets, ?int $now = null, int $tolerance = 300): bool
{
    $now = $now ?? time();
    $t = null;
    $sigs = [];
    foreach (explode(',', $header) as $kv) {
        [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
        if ($k === 't' && ctype_digit($v)) $t = (int) $v;
        elseif ($k === 'v1') $sigs[] = strtolower($v);
    }
    if ($t === null || !$sigs || abs($now - $t) > $tolerance) return false;
    foreach ($secrets as $secret) {
        if ($secret === '' || $secret === false) continue;
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        foreach ($sigs as $sig) if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
