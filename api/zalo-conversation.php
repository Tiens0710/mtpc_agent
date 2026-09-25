<?php
/* Short-lived, per-user Zalo conversation context for follow-up questions. */

function mtpc_zalo_agent_history_directory() {
    return '/home/mtpc/private/mtpc-zalo-oa/conversations';
}

function mtpc_zalo_agent_history_path($userId, $directory) {
    $directory = rtrim((string)$directory, '/\\');
    return $directory . DIRECTORY_SEPARATOR . hash('sha256', (string)$userId) . '.json';
}

function mtpc_zalo_agent_history_text($text, $limit) {
    $text = trim((string)$text);
    if (function_exists('mb_substr')) return mb_substr($text, 0, $limit, 'UTF-8');
    if (function_exists('iconv_substr')) {
        $part = @iconv_substr($text, 0, $limit, 'UTF-8');
        if ($part !== false) return $part;
    }
    return substr($text, 0, $limit);
}

function mtpc_zalo_agent_history_read_unlocked($path, $now) {
    if (!is_file($path)) return array();
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data) || empty($data['updated_at']) || !isset($data['messages']) || !is_array($data['messages'])) return array();
    if ((int)$now - (int)$data['updated_at'] > 86400) return array();

    $messages = array();
    foreach ($data['messages'] as $message) {
        if (!is_array($message) || !isset($message['role'], $message['text'])) continue;
        $role = $message['role'] === 'model' ? 'model' : ($message['role'] === 'user' ? 'user' : '');
        $text = mtpc_zalo_agent_history_text($message['text'], 1600);
        if ($role === '' || $text === '') continue;
        $messages[] = array(
            'role' => $role,
            'text' => $text,
            'at' => isset($message['at']) ? (int)$message['at'] : 0
        );
    }
    return array_slice($messages, -40);
}

function mtpc_zalo_agent_history_prune($directory, $now) {
    if (!is_dir($directory)) return;
    $stamp = $directory . DIRECTORY_SEPARATOR . '.last-pruned';
    if (is_file($stamp) && (int)$now - (int)@filemtime($stamp) < 3600) return;

    $cleanupLock = @fopen($directory . DIRECTORY_SEPARATOR . '.prune.lock', 'c');
    if (!$cleanupLock) return;
    if (!@flock($cleanupLock, LOCK_EX | LOCK_NB)) { fclose($cleanupLock); return; }
    if (is_file($stamp) && (int)$now - (int)@filemtime($stamp) < 3600) {
        @flock($cleanupLock, LOCK_UN);
        fclose($cleanupLock);
        return;
    }

    $files = glob($directory . DIRECTORY_SEPARATOR . '*.json');
    if (is_array($files)) foreach ($files as $path) {
        if (!is_file($path) || (int)@filemtime($path) >= (int)$now - 86400) continue;
        $userLock = @fopen($path . '.lock', 'c');
        if (!$userLock) continue;
        if (@flock($userLock, LOCK_EX | LOCK_NB)) {
            if (is_file($path) && (int)@filemtime($path) < (int)$now - 86400) @unlink($path);
            @flock($userLock, LOCK_UN);
        }
        fclose($userLock);
    }
    @touch($stamp, (int)$now);
    @flock($cleanupLock, LOCK_UN);
    fclose($cleanupLock);
}

function mtpc_zalo_agent_history_append($userId, $role, $text, $directory, $now) {
    $userId = trim((string)$userId);
    $role = $role === 'model' ? 'model' : ($role === 'user' ? 'user' : '');
    $text = mtpc_zalo_agent_history_text($text, 1600);
    if ($userId === '' || $role === '' || $text === '') return false;

    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) return false;
    @chmod($directory, 0750);
    mtpc_zalo_agent_history_prune($directory, $now);

    $path = mtpc_zalo_agent_history_path($userId, $directory);
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) return false;
    if (!@flock($lock, LOCK_EX)) { fclose($lock); return false; }

    $messages = mtpc_zalo_agent_history_read_unlocked($path, $now);
    $lastIndex = count($messages) - 1;
    if ($lastIndex >= 0 && $messages[$lastIndex]['role'] === $role) {
        $messages[$lastIndex]['text'] = mtpc_zalo_agent_history_text($messages[$lastIndex]['text'] . "\n" . $text, 2400);
        $messages[$lastIndex]['at'] = (int)$now;
    } else {
        $messages[] = array('role' => $role, 'text' => $text, 'at' => (int)$now);
    }
    $messages = array_slice($messages, -40);
    $payload = json_encode(array('version' => 1, 'updated_at' => (int)$now, 'messages' => $messages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $saved = $payload !== false && @file_put_contents($path, $payload . "\n", LOCK_EX) !== false;
    if ($saved) @chmod($path, 0640);

    @flock($lock, LOCK_UN);
    fclose($lock);
    return $saved;
}

function mtpc_zalo_agent_history_read($userId, $directory, $now) {
    $userId = trim((string)$userId);
    if ($userId === '' || !is_dir($directory)) return array();
    $path = mtpc_zalo_agent_history_path($userId, $directory);
    if (!is_file($path)) return array();
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) return array();
    if (!@flock($lock, LOCK_SH)) { fclose($lock); return array(); }
    $messages = mtpc_zalo_agent_history_read_unlocked($path, $now);
    @flock($lock, LOCK_UN);
    fclose($lock);
    return $messages;
}

function mtpc_zalo_agent_history_contents($history, $currentQuestion) {
    $normalized = array();
    if (is_array($history)) foreach ($history as $message) {
        if (!is_array($message) || !isset($message['role'], $message['text'])) continue;
        $role = $message['role'] === 'model' ? 'model' : ($message['role'] === 'user' ? 'user' : '');
        $text = mtpc_zalo_agent_history_text($message['text'], 1600);
        if ($role === '' || $text === '') continue;
        $last = count($normalized) - 1;
        if ($last >= 0 && $normalized[$last]['role'] === $role) {
            $normalized[$last]['text'] = mtpc_zalo_agent_history_text($normalized[$last]['text'] . "\n" . $text, 2400);
        } else {
            $normalized[] = array('role' => $role, 'text' => $text);
        }
    }

    $currentQuestion = mtpc_zalo_agent_history_text($currentQuestion, 1600);
    $last = count($normalized) - 1;
    if ($currentQuestion !== '' && ($last < 0 || $normalized[$last]['role'] !== 'user' || $normalized[$last]['text'] !== $currentQuestion)) {
        if ($last >= 0 && $normalized[$last]['role'] === 'user') {
            $normalized[$last]['text'] = mtpc_zalo_agent_history_text($normalized[$last]['text'] . "\n" . $currentQuestion, 2400);
        } else {
            $normalized[] = array('role' => 'user', 'text' => $currentQuestion);
        }
    }

    $selected = array();
    $totalChars = 0;
    for ($i = count($normalized) - 1; $i >= 0 && count($selected) < 40; $i--) {
        $text = $normalized[$i]['text'];
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($selected && $totalChars + $length > 12000) break;
        array_unshift($selected, $normalized[$i]);
        $totalChars += $length;
    }
    while ($selected && $selected[0]['role'] !== 'user') array_shift($selected);

    $contents = array();
    foreach ($selected as $message) $contents[] = array(
        'role' => $message['role'],
        'parts' => array(array('text' => $message['text']))
    );
    return $contents;
}

function mtpc_zalo_agent_history_contextual_query($question, $history) {
    $question = trim((string)$question);
    $normalized = function_exists('mtpc_zalo_agent_normalize') ? mtpc_zalo_agent_normalize($question) : strtolower($question);
    $needsContext = preg_match('/(^| )(vay|do|cai do|cai nay|thoi gian|bao lau|may thang|may nam|thoi luong|hoc phi|bao nhieu|dieu kien|ho so|lo trinh)( |$)/', $normalized);
    if (!$needsContext || !is_array($history)) return $question;

    $priorUserMessages = array();
    foreach ($history as $message) {
        if (!is_array($message) || !isset($message['role'], $message['text']) || $message['role'] !== 'user') continue;
        $text = trim((string)$message['text']);
        if ($text === '' || $text === $question) continue;
        $priorUserMessages[] = $text;
    }
    $priorUserMessages = array_slice($priorUserMessages, -4);
    return $priorUserMessages ? implode("\n", $priorUserMessages) . "\n" . $question : $question;
}

function mtpc_zalo_agent_history_is_conversion_detail_followup($question, $history) {
    $normalize = function_exists('mtpc_zalo_agent_normalize') ? 'mtpc_zalo_agent_normalize' : 'strtolower';
    $current = $normalize($question);
    if (!preg_match('/(^| )(thoi gian|bao lau|may thang|may nam|thoi luong|hoc phi|dieu kien|ho so)( |$)/', $current)) return false;

    $context = $current;
    $recentUserMessages = array();
    if (is_array($history)) foreach (array_slice($history, -6) as $message) {
        if (!is_array($message) || !isset($message['text'])) continue;
        $normalizedMessage = $normalize($message['text']);
        $context .= ' ' . $normalizedMessage;
        if (isset($message['role']) && $message['role'] === 'user' && trim((string)$message['text']) !== trim((string)$question)) {
            $recentUserMessages[] = $normalizedMessage;
        }
    }
    $otherPrograms = '/(^| )(rang ham mat|tro thu nha khoa|dieu duong|y hoc co truyen|mos|tin hoc van phong|xay dung|thuong mai dien tu|pha che|han cong nghe cao|son o to)( |$)/';
    if (preg_match($otherPrograms, $current)) return false;
    if ($recentUserMessages && preg_match($otherPrograms, end($recentUserMessages))) return false;
    $hasConversion = strpos($context, 'chuyen doi') !== false || strpos($context, 'van bang 2') !== false;
    return $hasConversion && strpos($context, 'y si') !== false && strpos($context, 'duoc si') !== false;
}

function mtpc_zalo_agent_history_chunk_has_current_conversion_detail($chunk, $currentYear) {
    if (!is_array($chunk) || !isset($chunk['source_year']) || (int)$chunk['source_year'] < (int)$currentYear) return false;
    $raw = (isset($chunk['title']) ? $chunk['title'] : '') . ' ' . (isset($chunk['text']) ? $chunk['text'] : '');
    $normalize = function_exists('mtpc_zalo_agent_normalize') ? 'mtpc_zalo_agent_normalize' : 'strtolower';
    $text = $normalize($raw);
    if (strpos($text, 'chuyen doi') === false && strpos($text, 'van bang 2') === false) return false;
    if (strpos($text, 'y si') === false || strpos($text, 'duoc si') === false) return false;
    if (strpos($text, 'khong duoc xem la thong tin hien hanh') !== false) return false;
    preg_match_all('/(^| )\d+( \d+)? (thang|nam|hoc ky)( |$)/', $text, $durations);
    return count($durations[0]) === 1;
}
