<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://agent.mtpc.edu.vn');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function mtpc_respond($status, $payload) { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') mtpc_respond(405, array('error' => 'Method not allowed.'));

function mtpc_read_json($path, $fallback) { if (!is_file($path)) return $fallback; $data = json_decode(file_get_contents($path), true); return is_array($data) ? $data : $fallback; }
function mtpc_lower($text) { return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text); }
function mtpc_normalize($text) { $text = mtpc_lower($text); $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text); if ($converted !== false) $text = $converted; return trim(preg_replace('/[^a-z0-9]+/i', ' ', $text)); }
function mtpc_terms($text) {
    $stop = array('va','la','cua','cho','voi','nhung','cac','mot','duoc','tai','the','ve','trong','khi','tu','den','nay','co','khong','toi','ban','em','anh','chi','hoc','truong','thong','tin');
    $result = array();
    foreach (preg_split('/\s+/', mtpc_normalize($text)) as $term) if (strlen($term) >= 2 && !in_array($term, $stop, true)) $result[$term] = true;
    return array_keys($result);
}
function mtpc_excerpt($text) { return function_exists('mb_substr') ? mb_substr($text, 0, 900, 'UTF-8') : substr($text, 0, 900); }
function mtpc_retrieve($question) {
    $dir = '/home/mtpc/private/mtpc-knowledge';
    $chunksData = mtpc_read_json($dir . '/chunks.json', array());
    $indexData = mtpc_read_json($dir . '/index.json', array());
    $manualData = mtpc_read_json($dir . '/manual-bundle.json', array());
    $allChunks = array();
    if (!empty($chunksData['chunks'])) $allChunks = array_merge($allChunks, $chunksData['chunks']);
    if (!empty($manualData['chunks'])) $allChunks = array_merge($allChunks, $manualData['chunks']);
    if (!$allChunks) return array();
    $byId = array(); foreach ($allChunks as $chunk) if (!empty($chunk['id'])) $byId[$chunk['id']] = $chunk;
    $scores = array(); $terms = mtpc_terms($question); $index = isset($indexData['terms']) ? $indexData['terms'] : array();
    foreach ($terms as $term) {
        if (!isset($index[$term])) continue;
        foreach ($index[$term] as $id) $scores[$id] = isset($scores[$id]) ? $scores[$id] + 3 : 3;
    }
    $normalizedQuestion = mtpc_normalize($question);
    foreach ($byId as $id => $chunk) {
        $score = isset($scores[$id]) ? $scores[$id] : 0;
        $title = mtpc_normalize(isset($chunk['title']) ? $chunk['title'] : '');
        $body = mtpc_normalize(isset($chunk['text']) ? $chunk['text'] : '');
        if ($title && (strpos($normalizedQuestion, $title) !== false || strpos($title, $normalizedQuestion) !== false)) $score += 8;
        foreach ($terms as $term) { if (strpos($title, $term) !== false) $score += 4; if (strpos($body, $term) !== false) $score += 1; }
        if (isset($chunk['source_year']) && (int)$chunk['source_year'] >= (int)date('Y')) $score += 2;
        if (isset($chunk['origin']) && ($chunk['origin'] === 'seed' || $chunk['origin'] === 'upload')) $score += 1;
        if ($score > 0) $scores[$id] = $score;
    }
    arsort($scores); $picked = array(); $sources = array();
    foreach ($scores as $id => $score) {
        if (!isset($byId[$id])) continue;
        $chunk = $byId[$id];
        $sourceKey = !empty($chunk['source_id']) ? $chunk['source_id'] : (!empty($chunk['url']) ? $chunk['url'] : $id);
        if (isset($sources[$sourceKey])) continue;
        $picked[] = $chunk; $sources[$sourceKey] = true;
        if (count($picked) >= 6) break;
    }
    return $picked;
}

$apiKey = getenv('GEMINI_API_KEY'); $privateConfig = '/home/mtpc/private/gemini-config.php';
if (!$apiKey && is_file($privateConfig)) { require $privateConfig; $apiKey = isset($GEMINI_API_KEY) ? $GEMINI_API_KEY : ''; }
if (!$apiKey) mtpc_respond(500, array('error' => 'GEMINI_API_KEY is not configured on the server.'));
$body = json_decode(file_get_contents('php://input'), true);
$messages = is_array($body) && isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : array();
$contents = array(); $question = '';
foreach (array_slice($messages, -12) as $message) {
    $text = isset($message['text']) ? trim((string) $message['text']) : ''; if ($text === '') continue;
    $role = isset($message['role']) && $message['role'] === 'model' ? 'model' : 'user';
    $contents[] = array('role' => $role, 'parts' => array(array('text' => function_exists('mb_substr') ? mb_substr($text, 0, 4000, 'UTF-8') : substr($text, 0, 4000))));
    if ($role === 'user') $question = $text;
}
if (!$contents || $question === '') mtpc_respond(400, array('error' => 'A message is required.'));
$retrieved = mtpc_retrieve($question); $knowledge = '';
foreach ($retrieved as $i => $chunk) $knowledge .= "\n[S" . ($i + 1) . "] " . $chunk['title'] . "\nURL: " . $chunk['url'] . "\n" . mtpc_excerpt($chunk['text']) . "\n";
$prompt = 'Bạn là Nhi, tư vấn viên tuyển sinh Trường Trung cấp Miền Tây tại Cần Thơ. Trò chuyện như một tư vấn viên thật: gần gũi, rõ ràng, lịch sự và chủ động hiểu nhu cầu. Xưng “em”, gọi người dùng là “anh/chị”; dùng “dạ” hoặc “ạ” vừa phải, tối đa một lần mỗi phản hồi. Trả lời thường từ 1 đến 3 câu bằng từ ngữ đời thường, không lặp câu hỏi, không chào lại nếu người dùng không chào, không tự giới thiệu lại và không kết thúc mọi lượt bằng câu liên hệ máy móc. Chỉ hỏi thêm một câu ngắn khi nó giúp tư vấn bước tiếp theo. Không tự chèn số điện thoại hoặc website khi dữ liệu đã đủ. Phân biệt “ngành trường đang đào tạo” với “lớp đang tuyển/đang mở”: danh mục chương trình chính trên website gồm Y sĩ đa khoa, Dược sĩ trung học, Điều dưỡng, Hộ sinh và Công nghệ thông tin – Ứng dụng AI; còn chứng chỉ và liên thông là chương trình theo từng thông báo tuyển sinh. Không dùng một vài thông báo chứng chỉ mới nhất để thay thế toàn bộ danh sách ngành của trường. Chỉ dùng DỮ LIỆU MTPC bên dưới cho học phí, lịch, điều kiện và chính sách. Ưu tiên nguồn có năm mới hơn. Nếu nguồn ghi ngày không tồn tại hoặc có mâu thuẫn thì nói tự nhiên rằng em cần kiểm tra lại với trường, không tự sửa ngày. Chỉ khi dữ liệu không đủ mới hướng người dùng liên hệ Zalo 0375 711 766. Không bịa thông tin và không tiết lộ thông tin cá nhân. Cuối câu trả lời, nếu đã dùng dữ liệu, hãy ghi [S1], [S2] tương ứng.\n\nDỮ LIỆU MTPC:' . ($knowledge ? $knowledge : '\nChưa đồng bộ dữ liệu website.');
$model = 'gemini-3.1-flash-lite';
$payload = json_encode(array('systemInstruction' => array('parts' => array(array('text' => $prompt))), 'contents' => $contents, 'generationConfig' => array('maxOutputTokens' => 700)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$curl = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent');
curl_setopt_array($curl, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'x-goog-api-key: ' . $apiKey), CURLOPT_POSTFIELDS => $payload));
$raw = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
if ($raw === false || $status < 200 || $status >= 300) mtpc_respond(502, array('error' => 'Gemini could not process that request right now.'));
$response = json_decode($raw, true); $answer = '';
if (is_array($response) && isset($response['candidates'][0]['content']['parts'])) foreach ($response['candidates'][0]['content']['parts'] as $part) if (isset($part['text'])) $answer .= $part['text'];
if (trim($answer) === '') mtpc_respond(502, array('error' => 'Gemini returned an empty response.'));
$sources = array(); foreach ($retrieved as $chunk) $sources[] = array('title' => $chunk['title'], 'url' => $chunk['url']);
mtpc_respond(200, array('text' => trim($answer), 'sources' => $sources, 'knowledge_used' => count($sources), 'model' => $model));
