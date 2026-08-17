<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://agent.mtpc.edu.vn');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$configPath = '/home/mtpc/private/gemini-config.php';
if (is_file($configPath)) {
    require_once $configPath;
}

$apiKey = getenv('GEMINI_API_KEY') ?: ($GEMINI_API_KEY ?? '');
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'GEMINI_API_KEY is not configured on the server.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_encode([
    'uses' => 1,
    'expireTime' => gmdate('c', time() + 1800),
    'newSessionExpireTime' => gmdate('c', time() + 60),
], JSON_UNESCAPED_UNICODE);

$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/auth_tokens');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);

$result = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$response = is_string($result) ? json_decode($result, true) : null;
if ($status < 200 || $status >= 300 || !is_array($response) || empty($response['name'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Gemini Live could not issue a session token.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'token' => $response['name'],
    'model' => 'gemini-3.1-flash-live-preview',
], JSON_UNESCAPED_UNICODE);
