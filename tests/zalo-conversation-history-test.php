<?php
require_once __DIR__ . '/../api/zalo-conversation.php';

function mtpc_zalo_agent_normalize($text) {
    $text = function_exists('mb_strtolower') ? mb_strtolower((string)$text, 'UTF-8') : strtolower((string)$text);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) $text = $converted;
    return trim(preg_replace('/[^a-z0-9]+/i', ' ', $text));
}

function check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mtpc-zalo-history-' . uniqid('', true);
$now = 1790370000;
$userA = 'zalo-user-opaque-a';
$userB = 'zalo-user-opaque-b';

check(mtpc_zalo_agent_history_append($userA, 'user', 'Truong co tuyen Trung cap Y si khong?', $directory, $now), 'persist inbound user message');
check(mtpc_zalo_agent_history_append($userA, 'model', 'Truong dang tuyen chuong trinh Y si va chuyen doi van bang 2.', $directory, $now + 1), 'persist assistant response');
$history = mtpc_zalo_agent_history_read($userA, $directory, $now + 2);
check(count($history) === 2, 'read both turns in chronological order');
check($history[0]['role'] === 'user' && $history[1]['role'] === 'model', 'keep Gemini user/model role order');
check(mtpc_zalo_agent_history_read($userB, $directory, $now + 2) === array(), 'conversation state is isolated by Zalo user');
check(strpos(mtpc_zalo_agent_history_path($userA, $directory), $userA) === false, 'conversation filenames do not expose raw Zalo user IDs');

$history[] = array('role' => 'user', 'text' => 'Em co bang Trung cap Duoc si, thoi gian chuyen doi sang Y si bao lau?');
$contextualQuery = mtpc_zalo_agent_history_contextual_query('Thoi gian hoc bao lau?', $history);
check(strpos($contextualQuery, 'Trung cap Duoc') !== false, 'contextual retrieval includes the learner qualification');
check(mtpc_zalo_agent_history_is_conversion_detail_followup('Thoi gian hoc bao lau?', $history), 'recognize a follow-up about the Y si/Duoc si conversion route');
check(!mtpc_zalo_agent_history_is_conversion_detail_followup('Chung chi Rang Ham Mat hoc bao lau?', $history), 'a new, explicitly named course overrides an older conversion topic');

$contents = mtpc_zalo_agent_history_contents(array(
    array('role' => 'user', 'text' => 'Trường có tuyển Trung cấp Y sĩ không?'),
    array('role' => 'model', 'text' => 'Trường đang tuyển chương trình Y sĩ.')
), 'Thời gian học bao lâu ạ?');
check(count($contents) === 3, 'Gemini receives conversation plus the current follow-up');
check($contents[0]['role'] === 'user' && $contents[1]['role'] === 'model' && $contents[2]['role'] === 'user', 'chronological transcript maps assistant to Gemini model role');
check(strpos($contents[2]['parts'][0]['text'], 'Thời gian học bao lâu') !== false, 'latest user question is included');
$longHistory = array();
for ($i = 0; $i < 20; $i++) $longHistory[] = array('role' => $i % 2 ? 'model' : 'user', 'text' => str_repeat('Context ', 180));
$boundedContents = mtpc_zalo_agent_history_contents($longHistory, 'Latest question');
$boundedChars = 0;
foreach ($boundedContents as $message) $boundedChars += strlen($message['parts'][0]['text']);
check($boundedChars <= 12000 && strpos(end($boundedContents)['parts'][0]['text'], 'Latest question') !== false, 'transcript is bounded while retaining the newest question');

$validCurrent = array(
    'source_year' => 2026,
    'title' => 'Tuyen sinh chuyen doi van bang 2 Y si, Duoc si',
    'text' => 'Lo trinh chuyen doi tu Trung cap Duoc sang Y si dao tao 10 thang.'
);
$statusOnly = array(
    'source_year' => 2026,
    'title' => 'Xac nhan dang tuyen chuong trinh chuyen doi Y si Duoc si',
    'text' => 'Cac chuong trinh chuyen doi van bang 2 sang Y si, Duoc si hien dang tuyen.'
);
$oldDuration = array(
    'source_year' => 2022,
    'title' => 'Thong bao chuyen doi van bang 2 Y si Duoc si',
    'text' => 'Thoi gian dao tao chuong trinh chuyen doi la 10 thang.'
);
$unrelatedDuration = array(
    'source_year' => 2026,
    'title' => 'Chung chi Dieu duong',
    'text' => 'Thoi gian dao tao 6 thang.'
);
$ambiguousDurations = array(
    'source_year' => 2026,
    'title' => 'Chuyen doi van bang 2 Y si, Duoc si',
    'text' => 'Chuyen doi Y si, Duoc si hoc 10 thang. Chung chi nha khoa hoc 9 thang.'
);
check(mtpc_zalo_agent_history_chunk_has_current_conversion_detail($validCurrent, 2026), 'allow current explicit evidence for the exact conversion route and duration');
check(!mtpc_zalo_agent_history_chunk_has_current_conversion_detail($statusOnly, 2026), 'status-only confirmation must not authorize an old duration');
check(!mtpc_zalo_agent_history_chunk_has_current_conversion_detail($oldDuration, 2026), 'reject historical conversion duration');
check(!mtpc_zalo_agent_history_chunk_has_current_conversion_detail($unrelatedDuration, 2026), 'reject duration from a different program');
check(!mtpc_zalo_agent_history_chunk_has_current_conversion_detail($ambiguousDurations, 2026), 'reject mixed chunks with multiple possible durations');
$bundle = json_decode((string)file_get_contents(__DIR__ . '/../database/knowledge/mtpc-manual-knowledge.json'), true);
$confirmedStatusChunk = null;
if (is_array($bundle) && isset($bundle['chunks']) && is_array($bundle['chunks'])) foreach ($bundle['chunks'] as $chunk) {
    if (isset($chunk['id']) && $chunk['id'] === 'manual-cover2-active-confirmed-20260924-chunk-1') $confirmedStatusChunk = $chunk;
}
check(is_array($confirmedStatusChunk), 'reviewed knowledge bundle contains the current cover2 confirmation');
check(!mtpc_zalo_agent_history_chunk_has_current_conversion_detail($confirmedStatusChunk, 2026), 'real current cover2 chunk confirms status only and cannot answer conversion duration');

for ($i = 0; $i < 42; $i++) {
    $role = $i % 2 === 0 ? 'user' : 'model';
    check(mtpc_zalo_agent_history_append($userA, $role, 'bounded message ' . $i, $directory, $now + 2 + $i), 'append bounded test message ' . $i);
}
$boundedHistory = mtpc_zalo_agent_history_read($userA, $directory, $now + 50);
check(count($boundedHistory) === 40, 'persist at most the most recent 40 chat messages');
check(strpos($boundedHistory[count($boundedHistory) - 1]['text'], 'bounded message 41') !== false, 'retain the newest message when trimming history');
check(count(mtpc_zalo_agent_history_read($userA, $directory, $now + 86450)) === 0, 'expire conversation after 24 hours');

foreach (glob($directory . DIRECTORY_SEPARATOR . '*') as $path) if (is_file($path)) @unlink($path);
@unlink($directory . DIRECTORY_SEPARATOR . '.last-pruned');
@unlink($directory . DIRECTORY_SEPARATOR . '.prune.lock');
if (is_dir($directory)) @rmdir($directory);

echo "zalo-conversation-history: history, isolation, expiry, and evidence guards passed\n";
