<?php
/* Public Zalo OA webhook owned by mtpc-agent. PHP 5.6 compatible. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/zalo-conversation.php';
define('MTPC_ZALO_AGENT_BUILD', 'agent-zalo-v13');

function mtpc_zalo_agent_out($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') mtpc_zalo_agent_out(204, array());
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    mtpc_zalo_agent_out(405, array('ok' => false, 'error' => 'Method not allowed.'));
}

$config = array(
    'access_token' => '',
    'webhook_token' => '',
    'send_url' => 'https://openapi.zalo.me/v3.0/oa/message/cs',
    'auto_reply' => false,
    'app_id' => '',
    'secret_key' => '',
    'refresh_token' => '',
    'token_url' => 'https://oauth.zaloapp.com/v4/oa/access_token',
    'access_token_expires_at' => 0
);
$configPath = '/home/mtpc/private/zalo-oa-config.php';
if (is_file($configPath)) {
    require $configPath;
    if (isset($MTPC_ZALO_OA_ACCESS_TOKEN)) $config['access_token'] = trim((string)$MTPC_ZALO_OA_ACCESS_TOKEN);
    if (isset($MTPC_ZALO_OA_WEBHOOK_TOKEN)) $config['webhook_token'] = trim((string)$MTPC_ZALO_OA_WEBHOOK_TOKEN);
    if (isset($MTPC_ZALO_OA_SEND_URL) && trim((string)$MTPC_ZALO_OA_SEND_URL) !== '') $config['send_url'] = trim((string)$MTPC_ZALO_OA_SEND_URL);
    if (isset($MTPC_ZALO_OA_AUTO_REPLY)) $config['auto_reply'] = (bool)$MTPC_ZALO_OA_AUTO_REPLY;
    if (isset($MTPC_ZALO_OA_APP_ID)) $config['app_id'] = trim((string)$MTPC_ZALO_OA_APP_ID);
    if (isset($MTPC_ZALO_OA_SECRET_KEY)) $config['secret_key'] = trim((string)$MTPC_ZALO_OA_SECRET_KEY);
    if (isset($MTPC_ZALO_OA_REFRESH_TOKEN)) $config['refresh_token'] = trim((string)$MTPC_ZALO_OA_REFRESH_TOKEN);
    if (isset($MTPC_ZALO_OA_TOKEN_URL) && trim((string)$MTPC_ZALO_OA_TOKEN_URL) !== '') $config['token_url'] = trim((string)$MTPC_ZALO_OA_TOKEN_URL);
    if (isset($MTPC_ZALO_OA_ACCESS_TOKEN_EXPIRES_AT)) $config['access_token_expires_at'] = (int)$MTPC_ZALO_OA_ACCESS_TOKEN_EXPIRES_AT;
}
foreach (array(
    'access_token' => array('MTPC_ZALO_OA_ACCESS_TOKEN', 'ZALO_OA_ACCESS_TOKEN', 'ZALO_ACCESS_TOKEN'),
    'webhook_token' => array('MTPC_ZALO_OA_WEBHOOK_TOKEN', 'ZALO_OA_WEBHOOK_TOKEN', 'ZALO_WEBHOOK_TOKEN'),
    'app_id' => array('MTPC_ZALO_OA_APP_ID', 'ZALO_OA_APP_ID', 'ZALO_APP_ID'),
    'secret_key' => array('MTPC_ZALO_OA_SECRET_KEY', 'ZALO_OA_SECRET_KEY', 'ZALO_SECRET_KEY'),
    'refresh_token' => array('MTPC_ZALO_OA_REFRESH_TOKEN', 'ZALO_OA_REFRESH_TOKEN', 'ZALO_REFRESH_TOKEN')
) as $field => $names) {
    foreach ($names as $name) {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            $config[$field] = trim($value);
            break;
        }
    }
}
foreach (array('MTPC_ZALO_OA_AUTO_REPLY', 'ZALO_OA_AUTO_REPLY') as $name) {
    $value = getenv($name);
    if (is_string($value) && trim($value) !== '') {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) $config['auto_reply'] = $parsed;
        break;
    }
}

function mtpc_zalo_agent_apply_token_state($config) {
    $path = '/home/mtpc/private/mtpc-zalo-oa/token-state.json';
    if (!is_file($path)) return $config;
    $state = json_decode(@file_get_contents($path), true);
    if (!is_array($state)) return $config;
    if (!empty($state['access_token'])) $config['access_token'] = trim((string)$state['access_token']);
    if (!empty($state['refresh_token'])) $config['refresh_token'] = trim((string)$state['refresh_token']);
    if (!empty($state['expires_at'])) $config['access_token_expires_at'] = (int)$state['expires_at'];
    return $config;
}
function mtpc_zalo_agent_save_token_state($state) {
    $dir = '/home/mtpc/private/mtpc-zalo-oa';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new Exception('Không tạo được vùng lưu token Zalo.');
    $path = $dir . '/token-state.json';
    if (@file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX) === false) throw new Exception('Không lưu được token Zalo đã làm mới.');
}
function mtpc_zalo_agent_refresh_token($config) {
    foreach (array('app_id', 'secret_key', 'refresh_token') as $field) if (empty($config[$field])) throw new Exception('Thiếu cấu hình ' . strtoupper($field) . ' để làm mới token Zalo.');
    $curl = curl_init($config['token_url']);
    curl_setopt_array($curl, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded', 'secret_key: ' . $config['secret_key']),
        CURLOPT_POSTFIELDS => http_build_query(array('grant_type' => 'refresh_token', 'refresh_token' => $config['refresh_token'], 'app_id' => $config['app_id']), '', '&')
    ));
    $raw = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
    $response = json_decode((string)$raw, true);
    if ($raw === false || $status < 200 || $status >= 300 || !is_array($response) || empty($response['access_token'])) throw new Exception('Không thể làm mới token Zalo.' . ($error !== '' ? ' ' . $error : ''));
    $state = array(
        'access_token' => trim((string)$response['access_token']),
        'refresh_token' => !empty($response['refresh_token']) ? trim((string)$response['refresh_token']) : $config['refresh_token'],
        'expires_at' => time() + max(60, (int)(isset($response['expires_in']) ? $response['expires_in'] : 3600)) - 60,
        'updated_at' => gmdate('c')
    );
    mtpc_zalo_agent_save_token_state($state);
    $config['access_token'] = $state['access_token'];
    $config['refresh_token'] = $state['refresh_token'];
    $config['access_token_expires_at'] = $state['expires_at'];
    return $config;
}
function mtpc_zalo_agent_log($row) {
    $dir = '/home/mtpc/private/mtpc-zalo-oa';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $row['received_at'] = isset($row['received_at']) ? $row['received_at'] : gmdate('c');
    @file_put_contents($dir . '/messages.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

$config = mtpc_zalo_agent_apply_token_state($config);

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    mtpc_zalo_agent_out(200, array(
        'ok' => true,
        'service' => 'mtpc-agent',
        'build' => MTPC_ZALO_AGENT_BUILD,
        'configured' => $config['access_token'] !== '' && $config['webhook_token'] !== '',
        'auto_reply' => (bool)$config['auto_reply'],
        'token_state_loaded' => is_file('/home/mtpc/private/mtpc-zalo-oa/token-state.json'),
        'token_refresh_configured' => $config['app_id'] !== '' && $config['secret_key'] !== '' && $config['refresh_token'] !== '',
        'webhook_url' => 'https://agent.mtpc.edu.vn/api/zalo-oa.php?action=webhook'
    ));
}
if ($action !== 'webhook') mtpc_zalo_agent_out(404, array('ok' => false, 'error' => 'Invalid Zalo OA action.'));

$providedToken = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if ($providedToken === '' && isset($_SERVER['HTTP_X_MTPC_ZALO_WEBHOOK_TOKEN'])) $providedToken = trim((string)$_SERVER['HTTP_X_MTPC_ZALO_WEBHOOK_TOKEN']);
if ($config['webhook_token'] === '' || $providedToken === '' || !function_exists('hash_equals') || !hash_equals($config['webhook_token'], $providedToken)) {
    mtpc_zalo_agent_out(403, array('ok' => false, 'error' => 'Webhook token không hợp lệ.'));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') mtpc_zalo_agent_out(405, array('ok' => false, 'error' => 'Webhook requires POST.'));

function mtpc_zalo_agent_path($value, $path, $fallback) {
    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return $fallback;
        $value = $value[$key];
    }
    return is_scalar($value) ? (string)$value : $fallback;
}
function mtpc_zalo_agent_first($event, $paths) {
    foreach ($paths as $path) {
        $value = trim(mtpc_zalo_agent_path($event, $path, ''));
        if ($value !== '') return $value;
    }
    return '';
}
function mtpc_zalo_agent_normalize($text) {
    $text = function_exists('mb_strtolower') ? mb_strtolower((string)$text, 'UTF-8') : strtolower((string)$text);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) $text = $converted;
    return trim(preg_replace('/[^a-z0-9]+/i', ' ', $text));
}
function mtpc_zalo_agent_read_json($path) {
    if (!is_file($path)) return array();
    $data = json_decode(@file_get_contents($path), true);
    return is_array($data) ? $data : array();
}
function mtpc_zalo_agent_verified_answer($raw, $maxEvidence) {
    $clean = trim((string)$raw);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
    $clean = preg_replace('/\s*```$/', '', $clean);
    $review = json_decode($clean, true);
    if (!is_array($review) || empty($review['supported']) || empty($review['answer']) || empty($review['evidence']) || !is_array($review['evidence'])) return '';
    $hasValidEvidence = false;
    foreach ($review['evidence'] as $number) {
        $number = (int)$number;
        if ($number >= 1 && $number <= $maxEvidence) { $hasValidEvidence = true; break; }
    }
    if (!$hasValidEvidence) return '';
    return trim(preg_replace('/\s+/u', ' ', strip_tags((string)$review['answer'])));
}
function mtpc_zalo_agent_knowledge($question, $history = array()) {
    $dir = '/home/mtpc/private/mtpc-knowledge';
    $website = mtpc_zalo_agent_read_json($dir . '/chunks.json');
    $manual = mtpc_zalo_agent_read_json($dir . '/manual-bundle.json');
    $index = mtpc_zalo_agent_read_json($dir . '/index.json');
    $all = array();
    if (isset($website['chunks']) && is_array($website['chunks'])) $all = array_merge($all, $website['chunks']);
    if (isset($manual['chunks']) && is_array($manual['chunks'])) $all = array_merge($all, $manual['chunks']);
    if (!$all) return '';
    $contextualQuestion = mtpc_zalo_agent_history_contextual_query($question, $history);
    $conversionDetail = mtpc_zalo_agent_history_is_conversion_detail_followup($question, $history);
    $terms = array_filter(explode(' ', mtpc_zalo_agent_normalize($contextualQuestion)), function($term) { return strlen($term) >= 2; });
    $scores = array();
    $indexedTerms = isset($index['terms']) && is_array($index['terms']) ? $index['terms'] : array();
    foreach ($terms as $term) if (isset($indexedTerms[$term]) && is_array($indexedTerms[$term])) foreach ($indexedTerms[$term] as $id) $scores[$id] = isset($scores[$id]) ? $scores[$id] + 3 : 3;
    $byId = array();
    foreach ($all as $chunk) if (is_array($chunk) && !empty($chunk['id'])) $byId[(string)$chunk['id']] = $chunk;
    foreach ($byId as $id => $chunk) {
        if ($conversionDetail && !mtpc_zalo_agent_history_chunk_has_current_conversion_detail($chunk, (int)date('Y'))) {
            unset($scores[$id]);
            continue;
        }
        $haystack = mtpc_zalo_agent_normalize((isset($chunk['title']) ? $chunk['title'] : '') . ' ' . (isset($chunk['text']) ? $chunk['text'] : ''));
        $score = isset($scores[$id]) ? $scores[$id] : 0;
        foreach ($terms as $term) if (strpos($haystack, $term) !== false) $score++;
        if ($score > 0 && isset($chunk['source_year']) && (int)$chunk['source_year'] >= (int)date('Y')) $score += 2;
        if ($score >= 3) $scores[$id] = $score;
    }
    arsort($scores);
    $context = ''; $count = 0; $seen = array();
    foreach ($scores as $id => $score) {
        if (!isset($byId[$id])) continue;
        $chunk = $byId[$id];
        $source = !empty($chunk['source_id']) ? $chunk['source_id'] : $id;
        if (isset($seen[$source])) continue;
        $seen[$source] = true;
        $title = isset($chunk['title']) ? (string)$chunk['title'] : 'Nguồn MTPC';
        $text = isset($chunk['text']) ? (string)$chunk['text'] : '';
        $text = function_exists('mb_substr') ? mb_substr($text, 0, 1000, 'UTF-8') : substr($text, 0, 1000);
        $context .= "\n[" . ($count + 1) . "] " . $title . "\n" . $text . "\n";
        $count++;
        if ($count >= 4) break;
    }
    return $context;
}
function mtpc_zalo_agent_generate_reply($question, $history = array()) {
    $apiKey = getenv('GEMINI_API_KEY');
    $privateConfig = '/home/mtpc/private/gemini-config.php';
    if (!$apiKey && is_file($privateConfig)) {
        require $privateConfig;
        $apiKey = isset($GEMINI_API_KEY) ? $GEMINI_API_KEY : '';
    }
    if (!$apiKey) throw new Exception('Chưa cấu hình GEMINI_API_KEY cho Agent Zalo.');
    $knowledge = mtpc_zalo_agent_knowledge($question, $history);
    if ($knowledge === '') return mtpc_zalo_agent_unverified_reply();
    $prompt = 'Bạn là Nhi, tư vấn viên tuyển sinh của Trường Trung cấp Miền Tây tại Cần Thơ. Hãy trả lời câu hỏi mới nhất; đọc lịch sử hội thoại chỉ để hiểu chủ đề, bằng cấp người hỏi và các từ như “vậy”, “còn thời gian”, không cần hỏi lại điều đã có trong lịch sử. Lịch sử chat không phải bằng chứng về thông tin của trường; mọi dữ kiện thực tế phải được chứng minh trực tiếp bởi DỮ LIỆU MTPC bên dưới. Không dùng kiến thức riêng, không suy đoán, không lấy câu trả lời trước của bạn làm nguồn và tuyệt đối không đổi sang một khóa/ngành khác chỉ vì kết quả tìm kiếm có nhắc đến khóa đó. Đặc biệt, khi được hỏi thời lượng/học phí/điều kiện của một lộ trình chuyển đổi, chỉ trả lời nếu nguồn hiện hành nói rõ đúng lộ trình và dữ kiện được hỏi; nếu không, supported phải là false. Nguồn có xác nhận trực tiếp của quản trị viên ngày 24/09/2026 về trạng thái đang tuyển chỉ xác nhận trạng thái của đúng chương trình được liệt kê; xác nhận đó không làm cho thời hạn, học phí, thời lượng, điều kiện hoặc lịch cũ trong slide trở thành hiện hành. Nếu nguồn không trực tiếp trả lời, đã cũ, mâu thuẫn hoặc không rõ hiệu lực thì supported phải là false. Nếu đủ bằng chứng, trả lời như một tư vấn viên thật, gần gũi và rõ ràng trong 1 đến 3 câu; xưng “em”, gọi người dùng là “anh/chị”, dùng “dạ” hoặc “ạ” tối đa một lần trong mỗi phản hồi, không chào lại, không tự giới thiệu lại và không tự chèn số điện thoại hoặc website. Chỉ xuất một JSON hợp lệ theo mẫu {"supported":true,"answer":"Nội dung trả lời","evidence":[1,2]}. evidence là số thứ tự nguồn thực sự chứng minh câu trả lời. Khi không đủ dữ liệu, xuất {"supported":false,"answer":"","evidence":[]}. Không viết Markdown hoặc nội dung ngoài JSON. DỮ LIỆU MTPC:' . $knowledge;
    $contents = mtpc_zalo_agent_history_contents($history, $question);
    if (!$contents) return mtpc_zalo_agent_unverified_reply();
    $payload = json_encode(array(
        'systemInstruction' => array('parts' => array(array('text' => $prompt))),
        'contents' => $contents,
        'generationConfig' => array('maxOutputTokens' => 260, 'temperature' => 0.2, 'responseMimeType' => 'application/json')
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $curl = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent');
    curl_setopt_array($curl, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'x-goog-api-key: ' . $apiKey), CURLOPT_POSTFIELDS => $payload));
    $raw = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
    if ($raw === false || $status < 200 || $status >= 300) throw new Exception('Gemini không trả lời được.' . ($error !== '' ? ' ' . $error : ''));
    $response = json_decode($raw, true); $rawAnswer = '';
    if (is_array($response) && isset($response['candidates'][0]['content']['parts'])) foreach ($response['candidates'][0]['content']['parts'] as $part) if (isset($part['text'])) $rawAnswer .= $part['text'];
    if (trim($rawAnswer) === '') throw new Exception('Gemini trả về nội dung rỗng.');
    $answer = mtpc_zalo_agent_verified_answer($rawAnswer, 4);
    if ($answer === '') return mtpc_zalo_agent_unverified_reply();
    return function_exists('mb_substr') && mb_strlen($answer, 'UTF-8') > 420 ? rtrim(mb_substr($answer, 0, 417, 'UTF-8')) . '…' : $answer;
}
function mtpc_zalo_agent_unverified_reply() {
    return '';
}
function mtpc_zalo_agent_log_unanswered($question) {
    $path = '/home/mtpc/private/mtpc-knowledge/unanswered.jsonl';
    $text = function_exists('mb_substr') ? mb_substr(trim((string)$question), 0, 1000, 'UTF-8') : substr(trim((string)$question), 0, 1000);
    if ($text === '') return;
    $entry = json_encode(array('created_at' => gmdate('c'), 'channel' => 'zalo', 'question' => $text, 'reason' => 'no_verified_answer'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($entry !== false) @file_put_contents($path, $entry . "\n", FILE_APPEND | LOCK_EX);
}
function mtpc_zalo_agent_direct_reply($question) {
    $normalized = mtpc_zalo_agent_normalize($question);
    if ($normalized === '' || preg_match('/^(xin chao|chao|hello|hi|alo)$/', $normalized)) return 'Dạ, em chào anh/chị! Em là Nhi 😊 Anh/chị đang muốn tìm hiểu ngành học, học phí hay hồ sơ xét tuyển?';
    $asksProgramAvailability = strpos($normalized, 'co tuyen') !== false || strpos($normalized, 'tuyen sinh') !== false || strpos($normalized, 'co dao tao') !== false || strpos($normalized, 'truong minh co') !== false;
    $asksMedicalAssistant = strpos($normalized, 'y si') !== false && strpos($normalized, 'duoc si') === false;
    if ($asksProgramAvailability && $asksMedicalAssistant) return 'Dạ có, trường có tuyển ngành Trung cấp Y sĩ (Y sĩ đa khoa). Theo thông tin hiện có, điều kiện xét tuyển là tốt nghiệp THPT trở lên; anh/chị muốn em gửi luôn hồ sơ cần chuẩn bị không?';
    $asksProgramList = strpos($normalized, 'nganh nao') !== false || strpos($normalized, 'nhung nganh') !== false || strpos($normalized, 'cac nganh') !== false || strpos($normalized, 'dao tao nganh gi') !== false || strpos($normalized, 'hoc gi o truong') !== false;
    if ($asksProgramList) return 'Dạ, trường đang tuyển các ngành chính Y sĩ đa khoa, Dược sĩ trung học, Điều dưỡng, Hộ sinh và Công nghệ thông tin – Ứng dụng AI. Theo xác nhận của nhà trường, tài liệu tuyển sinh cover2 cũng đang tuyển Trung cấp Xây dựng dân dụng – công nghiệp, Thương mại điện tử; các khóa nghề/chứng chỉ Hàn công nghệ cao, Sơn ô tô, Sửa chữa máy tính, MOS, Pha chế (Bartender/Barista), nội dung xoa bóp bấm huyệt – phục hồi chức năng, cùng các chương trình chuyển đổi sang Y sĩ/Dược sĩ và liên thông. Anh/chị quan tâm chương trình nào để em gửi thông tin phù hợp ạ?';
    return '';
}
function mtpc_zalo_agent_fallback_reply($question) {
    $direct = mtpc_zalo_agent_direct_reply($question);
    if ($direct !== '') return $direct;
    return mtpc_zalo_agent_unverified_reply();
}
function mtpc_zalo_agent_send($config, $userId, $message) {
    $config = mtpc_zalo_agent_apply_token_state($config);
    if (!empty($config['access_token_expires_at']) && (int)$config['access_token_expires_at'] <= time()) $config = mtpc_zalo_agent_refresh_token($config);
    if ($config['access_token'] === '') {
        if ($config['refresh_token'] !== '') $config = mtpc_zalo_agent_refresh_token($config);
        else throw new Exception('Chưa cấu hình Zalo OA access token.');
    }
    $attempt = mtpc_zalo_agent_send_once($config, $userId, $message);
    if ($attempt['token_error'] && $config['refresh_token'] !== '') {
        $config = mtpc_zalo_agent_refresh_token($config);
        $attempt = mtpc_zalo_agent_send_once($config, $userId, $message);
    }
    if (!$attempt['ok']) throw new Exception($attempt['error']);
}
function mtpc_zalo_agent_send_once($config, $userId, $message) {
    $payload = json_encode(array('recipient' => array('user_id' => $userId), 'message' => array('text' => $message)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $curl = curl_init($config['send_url']);
    curl_setopt_array($curl, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'access_token: ' . $config['access_token']), CURLOPT_POSTFIELDS => $payload));
    $raw = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
    $response = json_decode($raw, true);
    $code = is_array($response) && isset($response['error']) ? (int)$response['error'] : 0;
    $messageText = is_array($response) && isset($response['message']) ? strtolower((string)$response['message']) : '';
    $tokenError = $status === 401 || strpos($messageText, 'access token') !== false || strpos($messageText, 'expired') !== false || strpos($messageText, 'invalid token') !== false;
    $ok = $raw !== false && $status >= 200 && $status < 300 && $code === 0;
    return array('ok' => $ok, 'token_error' => $tokenError, 'error' => 'Zalo OA từ chối gửi tin (HTTP ' . $status . ').' . ($error !== '' ? ' ' . $error : ''));
}

$event = json_decode(file_get_contents('php://input'), true);
if (!is_array($event)) mtpc_zalo_agent_out(400, array('ok' => false, 'error' => 'Invalid webhook payload.'));
$eventName = strtolower(mtpc_zalo_agent_first($event, array(array('event_name'), array('event'), array('eventName'))));
$userId = mtpc_zalo_agent_first($event, array(array('sender', 'id'), array('sender', 'user_id'), array('user_id'), array('from', 'id'), array('from', 'user_id')));
$text = mtpc_zalo_agent_first($event, array(array('message', 'text'), array('message'), array('text')));
$isUserText = $userId !== '' && $text !== '' && ($eventName === '' || $eventName === 'unknown' || $eventName === 'user_send_text' || strpos($eventName, 'user_send_') === 0);
mtpc_zalo_agent_log(array('direction' => 'inbound', 'event_name' => $eventName, 'user_id' => $userId, 'text' => $text, 'payload' => $event));
if (!$config['auto_reply'] || !$isUserText) mtpc_zalo_agent_out(200, array('ok' => true, 'received' => true, 'auto_reply' => array('enabled' => (bool)$config['auto_reply'], 'sent' => false)));

try {
    $historyDirectory = mtpc_zalo_agent_history_directory();
    mtpc_zalo_agent_history_append($userId, 'user', $text, $historyDirectory, time());
    $conversationHistory = mtpc_zalo_agent_history_read($userId, $historyDirectory, time());
    $reply = mtpc_zalo_agent_direct_reply($text);
    if ($reply === '') {
        try {
            $reply = mtpc_zalo_agent_generate_reply($text, $conversationHistory);
        } catch (Exception $aiError) {
            error_log('[MTPC_AGENT_ZALO_AI] ' . $aiError->getMessage());
            mtpc_zalo_agent_log(array('direction' => 'system', 'event_name' => 'ai_reply_fallback', 'user_id' => $userId, 'text' => $aiError->getMessage()));
            $reply = mtpc_zalo_agent_fallback_reply($text);
        }
    }
    if ($reply === '') {
        mtpc_zalo_agent_log_unanswered($text);
        mtpc_zalo_agent_log(array('direction' => 'system', 'event_name' => 'unverified_reply_suppressed', 'user_id' => $userId, 'text' => $text));
        mtpc_zalo_agent_out(200, array('ok' => true, 'received' => true, 'auto_reply' => array('enabled' => true, 'sent' => false, 'reason' => 'no_verified_answer')));
    }
    mtpc_zalo_agent_send($config, $userId, $reply);
    mtpc_zalo_agent_history_append($userId, 'model', $reply, $historyDirectory, time());
    mtpc_zalo_agent_log(array('direction' => 'outbound', 'event_name' => 'auto_reply_text', 'user_id' => $userId, 'text' => $reply));
    mtpc_zalo_agent_out(200, array('ok' => true, 'received' => true, 'auto_reply' => array('enabled' => true, 'sent' => true)));
} catch (Exception $error) {
    error_log('[MTPC_AGENT_ZALO_AUTO_REPLY] ' . $error->getMessage());
    mtpc_zalo_agent_log(array('direction' => 'system', 'event_name' => 'auto_reply_error', 'user_id' => $userId, 'text' => $error->getMessage()));
    mtpc_zalo_agent_out(200, array('ok' => true, 'received' => true, 'auto_reply' => array('enabled' => true, 'sent' => false, 'error' => $error->getMessage())));
}
