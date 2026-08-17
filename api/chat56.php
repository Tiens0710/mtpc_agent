<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://agent.mtpc.edu.vn');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function mtpc_respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { mtpc_respond(405, array('error' => 'Method not allowed.')); }

$apiKey = getenv('GEMINI_API_KEY');
$privateConfig = '/home/mtpc/private/gemini-config.php';
if (!$apiKey && is_file($privateConfig)) { require $privateConfig; $apiKey = isset($GEMINI_API_KEY) ? $GEMINI_API_KEY : ''; }
if (!$apiKey) { mtpc_respond(500, array('error' => 'GEMINI_API_KEY is not configured on the server.')); }

$body = json_decode(file_get_contents('php://input'), true);
$messages = is_array($body) && isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : array();
$contents = array();
foreach (array_slice($messages, -12) as $message) {
    $text = isset($message['text']) ? trim((string)$message['text']) : '';
    if ($text === '') { continue; }
    $contents[] = array('role' => (isset($message['role']) && $message['role'] === 'model') ? 'model' : 'user', 'parts' => array(array('text' => function_exists('mb_substr') ? mb_substr($text, 0, 4000, 'UTF-8') : substr($text, 0, 4000))));
}
if (!$contents) { mtpc_respond(400, array('error' => 'A message is required.')); }

$prompt = 'Bạn là Nhi, trợ lý tuyển sinh Trường Trung cấp Miền Tây tại Cần Thơ. Trả lời tiếng Việt ngắn gọn, thân thiện. Các ngành: Y sĩ đa khoa, Dược sĩ, Điều dưỡng, Hộ sinh, Công nghệ thông tin - Ứng dụng AI, Sửa chữa máy tính. Địa chỉ 192-194 Ngô Quyền, Cần Thơ; website https://mtpc.edu.vn; Zalo 0375 711 766. Không bịa học phí, lịch tuyển sinh hoặc chính sách. Khi không chắc, hướng người dùng đến website hoặc Zalo.';
$model = 'gemini-3.1-flash-lite';
$payload = json_encode(array('systemInstruction' => array('parts' => array(array('text' => $prompt))), 'contents' => $contents, 'generationConfig' => array('maxOutputTokens' => 700)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent');
curl_setopt_array($curl, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'x-goog-api-key: ' . $apiKey), CURLOPT_POSTFIELDS => $payload));
$raw = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
if ($raw === false || $status < 200 || $status >= 300) { mtpc_respond(502, array('error' => 'Gemini could not process that request right now.')); }
$response = json_decode($raw, true); $answer = '';
if (is_array($response) && isset($response['candidates'][0]['content']['parts'])) { foreach ($response['candidates'][0]['content']['parts'] as $part) { if (isset($part['text'])) { $answer .= $part['text']; } } }
if (trim($answer) === '') { mtpc_respond(502, array('error' => 'Gemini returned an empty response.')); }
mtpc_respond(200, array('text' => trim($answer), 'model' => $model));
