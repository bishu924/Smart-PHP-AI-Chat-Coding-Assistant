<?php
// ==========================================================
// api.php — Chats, Messages, User Stats, Settings & API Test
// All text in English. Protected by runtime header signature.
// ==========================================================
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required']);
    exit;
}
$uid   = (int)$_SESSION['user_id'];
$nonce = (string)($_SESSION['rt_nonce'] ?? '');
session_write_close();

$clientSig = (string)($_SERVER['HTTP_X_RUNTIME_SIG'] ?? '');
if (!verify_runtime_signature($nonce, $clientSig)) {
    http_response_code(403);
    echo json_encode(['error' => 'Runtime integrity verification failed.']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

function json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function is_local_api_url(string $url): bool {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true);
}

// ---------- LIST CHATS + USER STATS ----------
if ($action === 'list_chats') {
    $stmt = $db->prepare("
        SELECT c.id, c.title, c.updated_at, COUNT(m.id) AS message_count
        FROM chats c
        LEFT JOIN messages m ON m.chat_id = c.id
        WHERE c.user_id = ?
        GROUP BY c.id
        ORDER BY c.updated_at DESC
    ");
    $stmt->execute([$uid]);
    echo json_encode([
        'chats' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'stats' => get_user_stats($db, $uid),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- GET MESSAGES ----------
if ($action === 'get_messages') {
    $chatId = (int)($_GET['chat_id'] ?? 0);
    $stmt = $db->prepare("
        SELECT m.id, m.role, m.content, m.model_used, m.created_at
        FROM messages m
        JOIN chats c ON c.id = m.chat_id
        WHERE m.chat_id = ? AND c.user_id = ?
        ORDER BY m.id ASC
    ");
    $stmt->execute([$chatId, $uid]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $att = [];
    if ($msgs) {
        $in = implode(',', array_map('intval', array_column($msgs, 'id')));
        foreach ($db->query("SELECT message_id, stored, name FROM attachments WHERE message_id IN ($in)") as $r) {
            $isImg = in_array(strtolower(pathinfo($r['name'], PATHINFO_EXTENSION)), IMAGE_EXT, true);
            $att[$r['message_id']][] = [
                'name'     => $r['name'],
                'is_image' => $isImg,
                'url'      => $isImg ? UPLOAD_URL . $r['stored'] : null,
            ];
        }
    }
    foreach ($msgs as &$m) {
        $m['attachments'] = $att[$m['id']] ?? [];
    }
    unset($m);

    echo json_encode(['messages' => $msgs], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- RENAME CHAT ----------
if ($action === 'rename_chat') {
    $body   = json_input();
    $chatId = (int)($body['chat_id'] ?? $_POST['chat_id'] ?? 0);
    $title  = trim((string)($body['title'] ?? $_POST['title'] ?? ''));

    if ($chatId <= 0 || $title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Please enter a valid chat title.']);
        exit;
    }
    $title = mb_strimwidth($title, 0, 60, '...');
    $db->prepare("UPDATE chats SET title = ? WHERE id = ? AND user_id = ?")->execute([$title, $chatId, $uid]);
    echo json_encode(['ok' => true, 'title' => $title], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- DELETE SINGLE CHAT ----------
if ($action === 'delete_chat') {
    $chatId = (int)($_POST['chat_id'] ?? 0);

    $s = $db->prepare("
        SELECT a.stored FROM attachments a
        JOIN messages m ON m.id = a.message_id
        JOIN chats c ON c.id = m.chat_id
        WHERE c.id = ? AND c.user_id = ?
    ");
    $s->execute([$chatId, $uid]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $stored) {
        @unlink(UPLOAD_DIR . '/' . basename($stored));
    }

    $db->prepare("DELETE FROM chats WHERE id = ? AND user_id = ?")->execute([$chatId, $uid]);
    echo json_encode(['ok' => true, 'stats' => get_user_stats($db, $uid)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- CLEAR ALL CHATS FOR USER ----------
if ($action === 'clear_all_chats') {
    $s = $db->prepare("
        SELECT a.stored FROM attachments a
        JOIN messages m ON m.id = a.message_id
        JOIN chats c ON c.id = m.chat_id
        WHERE c.user_id = ?
    ");
    $s->execute([$uid]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $stored) {
        @unlink(UPLOAD_DIR . '/' . basename($stored));
    }

    $db->prepare("DELETE FROM chats WHERE user_id = ?")->execute([$uid]);
    echo json_encode(['ok' => true, 'stats' => get_user_stats($db, $uid)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- GET SETTINGS + USER STATS ----------
if ($action === 'get_settings') {
    echo json_encode([
        'settings' => get_user_settings($db, $uid),
        'stats'    => get_user_stats($db, $uid),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- SAVE SETTINGS ----------
if ($action === 'save_settings') {
    $body = json_input();

    $aiName       = mb_substr(trim((string)($body['ai_name'] ?? '')), 0, 60);
    $apiMode      = trim((string)($body['api_mode'] ?? 'custom'));
    $apiUrl       = trim((string)($body['api_url'] ?? ''));
    $apiKey       = trim((string)($body['api_key'] ?? ''));
    $modelName    = mb_substr(trim((string)($body['model_name'] ?? '')), 0, 160);
    $maxTokens    = (int)($body['max_tokens'] ?? DEFAULT_MAX_TOKENS);
    $temperature  = (float)($body['temperature'] ?? DEFAULT_TEMPERATURE);
    $systemPrompt = mb_substr((string)($body['system_prompt'] ?? ''), 0, 4000);

    if ($aiName === '') {
        $aiName = DEFAULT_AI_NAME;
    }
    if (!in_array($apiMode, ['custom', 'openrouter', 'openai', 'groq', 'gemini_compat', 'ollama'], true)) {
        $apiMode = 'custom';
    }
    if ($apiUrl === '' || !preg_match('#^https?://#i', $apiUrl) || !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Please enter a valid API Endpoint URL (http:// or https://).']);
        exit;
    }
    if ($modelName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Please enter a Model Name.']);
        exit;
    }

    $maxTokens   = max(256, min(128000, $maxTokens));
    $temperature = max(0.0, min(2.0, round($temperature, 2)));

    $stmt = $db->prepare("
        INSERT INTO user_settings (user_id, ai_name, api_mode, api_url, api_key, model_name, max_tokens, temperature, system_prompt, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'))
        ON CONFLICT(user_id) DO UPDATE SET
            ai_name       = excluded.ai_name,
            api_mode      = excluded.api_mode,
            api_url       = excluded.api_url,
            api_key       = excluded.api_key,
            model_name    = excluded.model_name,
            max_tokens    = excluded.max_tokens,
            temperature   = excluded.temperature,
            system_prompt = excluded.system_prompt,
            updated_at    = datetime('now','localtime')
    ");
    $stmt->execute([$uid, $aiName, $apiMode, $apiUrl, $apiKey, $modelName, $maxTokens, $temperature, $systemPrompt]);

    echo json_encode([
        'ok'       => true,
        'settings' => get_user_settings($db, $uid),
        'stats'    => get_user_stats($db, $uid),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- TEST CUSTOM API CONNECTION ----------
if ($action === 'test_connection') {
    $body      = json_input();
    $saved     = get_user_settings($db, $uid);
    $apiUrl    = trim((string)($body['api_url'] ?? $saved['api_url']));
    $apiKey    = trim((string)($body['api_key'] ?? $saved['api_key']));
    $modelName = trim((string)($body['model_name'] ?? $saved['model_name']));

    if ($apiUrl === '' || !preg_match('#^https?://#i', $apiUrl)) {
        echo json_encode(['ok' => false, 'error' => 'Please enter a valid API Endpoint URL.']);
        exit;
    }
    if ($modelName === '') {
        echo json_encode(['ok' => false, 'error' => 'Please enter a Model Name.']);
        exit;
    }
    if ($apiKey === '' && !is_local_api_url($apiUrl)) {
        echo json_encode(['ok' => false, 'error' => 'Please paste an API Key.']);
        exit;
    }

    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    if (stripos($apiUrl, 'openrouter.ai') !== false) {
        $headers[] = 'X-Title: ' . ($saved['ai_name'] ?: DEFAULT_AI_NAME);
    }

    $t0 = microtime(true);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => $modelName,
            'messages'   => [['role' => 'user', 'content' => 'Reply with only: OK']],
            'max_tokens' => 16,
            'stream'     => false,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp    = curl_exec($ch);
    $curlErr = curl_errno($ch) ? curl_error($ch) : null;
    $http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $latencyMs = (int)round((microtime(true) - $t0) * 1000);

    if ($curlErr) {
        echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $curlErr, 'latency_ms' => $latencyMs]);
        exit;
    }

    $json = json_decode((string)$resp, true);
    if ($http >= 400 || isset($json['error'])) {
        $errMsg = is_array($json['error'] ?? null)
            ? ($json['error']['message'] ?? json_encode($json['error']))
            : ($json['error'] ?? "HTTP $http");
        echo json_encode(['ok' => false, 'http_code' => $http, 'error' => $errMsg, 'latency_ms' => $latencyMs]);
        exit;
    }

    $reply = $json['choices'][0]['message']['content'] ?? ($json['choices'][0]['text'] ?? '');
    if ($reply === '' && !$json) {
        echo json_encode(['ok' => false, 'http_code' => $http, 'error' => 'Unexpected response: ' . mb_substr((string)$resp, 0, 180), 'latency_ms' => $latencyMs]);
        exit;
    }

    echo json_encode([
        'ok'         => true,
        'http_code'  => $http,
        'latency_ms' => $latencyMs,
        'model'      => $json['model'] ?? $modelName,
        'reply'      => trim((string)$reply),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
?>
