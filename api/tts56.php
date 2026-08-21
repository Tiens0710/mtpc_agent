<?php
/* Gemini TTS gateway for the MTPC voice-first assistant. PHP 5.6 compatible. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://agent.mtpc.edu.vn');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function mtpc_tts_respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') mtpc_tts_respond(405, array('error' => 'Method not allowed.'));

function mtpc_tts_allow_request() {
    $dir = '/home/mtpc/private/mtpc-tts-rate';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (!is_dir($dir) || !is_writable($dir)) return true;
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $file = $dir . '/' . sha1($ip) . '.json';
    $now = time();
    $times = is_file($file) ? json_decode(@file_get_contents($file), true) : array();
    if (!is_array($times)) $times = array();
    $recent = array();
    foreach ($times as $time) if ((int)$time > $now - 300) $recent[] = (int)$time;
    if (count($recent) >= 12) return false;
    $recent[] = $now;
    @file_put_contents($file, json_encode($recent), LOCK_EX);
    return true;
}

if (!mtpc_tts_allow_request()) mtpc_tts_respond(429, array('error' => 'Bạn đã dùng quá nhiều lượt đọc. Vui lòng thử lại sau ít phút.'));

$configPath = '/home/mtpc/private/gemini-config.php';
$apiKey = getenv('GEMINI_API_KEY');
$voice = 'Zephyr';
if (!$apiKey && is_file($configPath)) {
    require $configPath;
    $apiKey = isset($GEMINI_API_KEY) ? $GEMINI_API_KEY : '';
    if (isset($MTPC_TTS_VOICE) && $MTPC_TTS_VOICE) $voice = $MTPC_TTS_VOICE;
}
if (!$apiKey) mtpc_tts_respond(500, array('error' => 'GEMINI_API_KEY is not configured on the server.'));

$body = json_decode(file_get_contents('php://input'), true);
$text = is_array($body) && isset($body['text']) ? trim((string)$body['text']) : '';
if ($text === '') mtpc_tts_respond(400, array('error' => 'Text is required.'));
if (function_exists('mb_substr')) $text = mb_substr($text, 0, 1400, 'UTF-8'); else $text = substr($text, 0, 4200);

$prompt = "Read only the Vietnamese message below. Use a warm, friendly, youthful female admissions-assistant delivery. Speak clearly at a natural pace.\n\n" . $text;
$model = 'gemini-3.1-flash-tts-preview';
$payload = json_encode(array(
    'contents' => array(array('parts' => array(array('text' => $prompt)))),
    'generationConfig' => array(
        'responseModalities' => array('AUDIO'),
        'speechConfig' => array('voiceConfig' => array('prebuiltVoiceConfig' => array('voiceName' => $voice)))
    )
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent');
curl_setopt_array($curl, array(
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'x-goog-api-key: ' . $apiKey),
    CURLOPT_POSTFIELDS => $payload
));
$raw = curl_exec($curl);
$error = curl_error($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if ($raw === false || $error || $status < 200 || $status >= 300) mtpc_tts_respond(502, array('error' => 'Gemini TTS is unavailable right now.'));

$response = json_decode($raw, true);
$audio = '';
$mimeType = 'audio/L16;rate=24000';
if (is_array($response) && isset($response['candidates'][0]['content']['parts'])) {
    foreach ($response['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['inlineData']['data'])) {
            $audio = $part['inlineData']['data'];
            if (isset($part['inlineData']['mimeType'])) $mimeType = $part['inlineData']['mimeType'];
            break;
        }
    }
}
if ($audio === '') mtpc_tts_respond(502, array('error' => 'Gemini TTS returned no audio.'));
mtpc_tts_respond(200, array('audio' => $audio, 'mimeType' => $mimeType, 'sampleRate' => 24000, 'voice' => $voice, 'model' => $model));
