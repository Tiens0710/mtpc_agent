<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://agent.mtpc.edu.vn');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function mtpc_live_respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mtpc_live_respond(405, array('error' => 'Method not allowed.'));
}

$apiKey = getenv('GEMINI_API_KEY');
$privateConfig = '/home/mtpc/private/gemini-config.php';
if (!$apiKey && is_file($privateConfig)) {
    require $privateConfig;
    $apiKey = isset($GEMINI_API_KEY) ? $GEMINI_API_KEY : '';
}
if (!$apiKey) {
    mtpc_live_respond(500, array('error' => 'GEMINI_API_KEY is not configured on the server.'));
}

$payload = json_encode(array(
    'uses' => 1,
    'expireTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
    'newSessionExpireTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/auth_tokens');
curl_setopt_array($curl, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'x-goog-api-key: ' . $apiKey),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30
));
$result = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$response = is_string($result) ? json_decode($result, true) : null;

if ($status < 200 || $status >= 300 || !is_array($response) || empty($response['name'])) {
    $upstreamMessage = is_array($response) && isset($response['error']['message']) ? $response['error']['message'] : 'Unknown upstream response.';
    mtpc_live_respond(502, array('error' => 'Gemini Live could not issue a session token.', 'upstreamStatus' => $status, 'upstreamMessage' => $upstreamMessage));
}

mtpc_live_respond(200, array(
    'token' => $response['name'],
    'model' => 'gemini-3.1-flash-live-preview'
));
