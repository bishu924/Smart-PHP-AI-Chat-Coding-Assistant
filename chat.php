<?php
// ==========================================================
// chat.php — Dynamic Custom API / OpenRouter real-time SSE streaming
// English only. Protected by runtime header signature.
// ==========================================================
session_start();
require_once __DIR__ . '/db.php';

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_implicit_flush(true);
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');

function sse(string $event, array $data): void {
    echo "event: $event\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush();
    flush();
}

echo ':' . str_repeat(' ', 4096) . "\n\n";
@ob_flush(); flush();

if (!isset($_SESSION['user_id'])) {
    sse('error', ['error' => 'Login required — please refresh the page.']);
    exit;
}
$uid   = (int)$_SESSION['user_id'];
$nonce = (string)($_SESSION['rt_nonce'] ?? '');
session_write_close();

$clientSig = (string)($_SERVER['HTTP_X_RUNTIME_SIG'] ?? '');
if (!verify_runtime_signature($nonce, $clientSig)) {
    sse('error', ['error' => 'Runtime integrity verification failed.']);
    exit;
}

// ---------- Load User's Custom Settings ----------
$settings     = get_user_settings($db, $uid);
$aiName       = $settings['ai_name'];
$apiUrl       = $settings['api_url'];
$apiKey       = $settings['api_key'];
$modelName    = $settings['model_name'];
$maxTokens    = (int)$settings['max_tokens'];
$temperature  = (float)$settings['temperature'];
$systemPrompt = build_system_prompt($aiName, $settings['system_prompt']);

$host    = strtolower((string)parse_url($apiUrl, PHP_URL_HOST));
$isLocal = in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true);

if ($apiUrl === '' || !preg_match('#^https?://#i', $apiUrl)) {
    sse('error', ['error' => 'API Endpoint URL is not configured. Open Settings at the bottom-left to add your API link.']);
    exit;
}
if ($modelName === '') {
    sse('error', ['error' => 'Model Name is not configured. Open Settings to enter your model name.']);
    exit;
}
if ($apiKey === '' && !$isLocal) {
    sse('error', ['error' => 'API Key is missing. Open Settings at the bottom-left and paste your API key.']);
    exit;
}

// ---------- Read Input ----------
$body     = json_decode(file_get_contents('php://input'), true) ?: [];
$question = trim($body['question'] ?? '');
$chatId   = (int)($body['chat_id'] ?? 0);

$files = [];
foreach (array_slice((array)($body['files'] ?? []), 0, 30) as $f) {
    $stored = basename((string)($f['stored'] ?? ''));
    if (!preg_match('/^' . $uid . '_\d+_[a-f0-9]{8}\.[a-z0-9]+$/', $stored)) continue;
    if (!is_file(UPLOAD_DIR . '/' . $stored)) continue;
    $name = trim(str_replace('\\', '/', (string)($f['name'] ?? $stored)));
    $files[] = ['stored' => $stored, 'name' => mb_substr($name !== '' ? $name : $stored, 0, 200)];
}

if ($question === '' && !$files) {
    sse('error', ['error' => 'No prompt or files provided.']);
    exit;
}

// ---------- Create or Validate Chat ----------
if ($chatId <= 0) {
    $t     = $question !== '' ? $question : ($files ? $files[0]['name'] : 'New Chat');
    $title = mb_strimwidth($t, 0, 44, '...');
    $db->prepare("INSERT INTO chats (user_id, title) VALUES (?, ?)")->execute([$uid, $title]);
    $chatId = (int)$db->lastInsertId();
    sse('meta', ['chat_id' => $chatId, 'title' => $title, 'ai_name' => $aiName, 'model_name' => $modelName]);
} else {
    $stmt = $db->prepare("SELECT id FROM chats WHERE id = ? AND user_id = ?");
    $stmt->execute([$chatId, $uid]);
    if (!$stmt->fetch()) {
        sse('error', ['error' => 'Chat not found.']);
        exit;
    }
    sse('meta', ['chat_id' => $chatId, 'ai_name' => $aiName, 'model_name' => $modelName]);
}

// ---------- Save User Message & Attachments ----------
$db->prepare("INSERT INTO messages (chat_id, role, content) VALUES (?, 'user', ?)")->execute([$chatId, $question]);
$msgId = (int)$db->lastInsertId();
$ins   = $db->prepare("INSERT INTO attachments (message_id, stored, name) VALUES (?, ?, ?)");
foreach ($files as $f) {
    $ins->execute([$msgId, $f['stored'], $f['name']]);
}

function build_content(string $text, array $atts) {
    if (trim($text) === '' && $atts) {
        $text = 'Inspect these attached files carefully, fix all bugs/errors, and provide the complete updated files.';
    }
    $images = [];
    $extra  = '';
    $budget = 300000;
    foreach ($atts as $a) {
        $abs = UPLOAD_DIR . '/' . $a['stored'];
        if (!is_file($abs)) continue;
        $ext = strtolower(pathinfo($a['name'], PATHINFO_EXTENSION));
        if (in_array($ext, IMAGE_EXT, true)) {
            $mime     = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
            $images[] = ['type' => 'image_url', 'image_url' => ['url' => "data:$mime;base64," . base64_encode(file_get_contents($abs))]];
            $extra   .= "\n\n[Image attached: {$a['name']}]";
        } elseif (in_array($ext, TEXT_EXT, true)) {
            if ($budget <= 0) {
                $extra .= "\n\n[Skipped (size budget exceeded): {$a['name']}]";
                continue;
            }
            $c = file_get_contents($abs);
            if (strlen($c) > $budget) $c = substr($c, 0, $budget) . "\n...[truncated]";
            $budget -= strlen($c);
            $extra  .= "\n\n=== FILE: {$a['name']} ===\n$c\n=== END OF {$a['name']} ===";
        } else {
            $extra .= "\n\n[File attached (binary/unsupported preview): {$a['name']}]";
        }
    }
    $text .= $extra;
    if (!$images) return $text;
    return array_merge([['type' => 'text', 'text' => $text]], $images);
}

$messages = [['role' => 'system', 'content' => $systemPrompt]];

$stmt = $db->prepare("SELECT id, role, content FROM messages WHERE chat_id = ? ORDER BY id DESC LIMIT 20");
$stmt->execute([$chatId]);
$old = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
array_pop($old);

$attStmt = $db->prepare("SELECT stored, name FROM attachments WHERE message_id = ?");
foreach ($old as $m) {
    if ($m['role'] === 'user') {
        $attStmt->execute([$m['id']]);
        $messages[] = ['role' => 'user', 'content' => build_content($m['content'], $attStmt->fetchAll(PDO::FETCH_ASSOC))];
    } elseif ($m['role'] === 'assistant') {
        $messages[] = ['role' => 'assistant', 'content' => $m['content']];
    }
}

if ($files) sse('status', ['text' => 'Reading ' . count($files) . ' attached file(s)...']);
$messages[] = ['role' => 'user', 'content' => build_content($question, $files)];
sse('status', ['text' => $aiName . ' is thinking...']);

// ---------- Custom / OpenRouter API Stream Request ----------
$fullAnswer = '';
$rawErr     = '';
$finish     = '';
$thinkSent  = false;
$buffer     = '';

$headers = [
    'Content-Type: application/json',
    'Accept: text/event-stream, application/json',
];
if ($apiKey !== '') {
    $headers[] = 'Authorization: Bearer ' . $apiKey;
}
if (stripos($apiUrl, 'openrouter.ai') !== false) {
    $headers[] = 'X-Title: ' . $aiName;
}

$payloadData = [
    'model'       => $modelName,
    'messages'    => $messages,
    'stream'      => true,
    'max_tokens'  => $maxTokens,
    'temperature' => $temperature,
];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => json_encode($payloadData),
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$fullAnswer, &$rawErr, &$finish, &$thinkSent, &$buffer) {
        $buffer .= $data;
        $lines  = explode("\n", $buffer);
        $buffer = array_pop($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ':') continue;
            if (strpos($line, 'data:') !== 0) {
                $rawErr .= $line;
                continue;
            }

            $payload = trim(substr($line, 5));
            if ($payload === '[DONE]') continue;
            $json = json_decode($payload, true);
            if (!$json) continue;

            if (isset($json['error'])) {
                sse('error', ['error' => is_array($json['error']) ? ($json['error']['message'] ?? json_encode($json['error'])) : (string)$json['error']]);
                continue;
            }

            $choice = $json['choices'][0] ?? [];
            $delta  = $choice['delta'] ?? [];

            if (!$thinkSent && (!empty($delta['reasoning']) || !empty($delta['reasoning_content']))) {
                $thinkSent = true;
                sse('status', ['text' => 'Reasoning deeply...']);
            }
            if (!empty($choice['finish_reason'])) $finish = $choice['finish_reason'];

            $text = $delta['content'] ?? ($choice['message']['content'] ?? '');
            if ($text !== '') {
                $fullAnswer .= $text;
                sse('delta', ['text' => $text]);
            }
        }
        return strlen($data);
    },
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_CONNECTTIMEOUT => 20,
]);

curl_exec($ch);
$curlErr = curl_errno($ch) ? curl_error($ch) : null;
$http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$rem = trim($buffer);
if ($rem !== '' && strpos($rem, 'data:') !== 0) {
    $rawErr .= $rem;
}
if ($fullAnswer === '' && $rawErr !== '') {
    $fallbackJson = json_decode($rawErr, true);
    if (is_array($fallbackJson) && isset($fallbackJson['choices'][0]['message']['content'])) {
        $fbText = (string)$fallbackJson['choices'][0]['message']['content'];
        if ($fbText !== '') {
            $fullAnswer = $fbText;
            $finish     = $fallbackJson['choices'][0]['finish_reason'] ?? 'stop';
            sse('delta', ['text' => $fbText]);
            $rawErr = '';
        }
    }
}

if ($fullAnswer !== '') {
    $db->prepare("INSERT INTO messages (chat_id, role, content, model_used) VALUES (?, 'assistant', ?, ?)")
       ->execute([$chatId, $fullAnswer, $modelName]);
    $db->prepare("UPDATE chats SET updated_at = datetime('now','localtime') WHERE id = ?")->execute([$chatId]);

    if ($finish === 'length') {
        sse('warn', ['text' => 'Response hit Max Tokens limit. Increase Max Tokens in Settings or type "continue".']);
    }
}

if ($curlErr) {
    sse('error', ['error' => 'cURL error: ' . $curlErr]);
} elseif ($fullAnswer === '') {
    $j   = json_decode($rawErr, true);
    $msg = is_array($j['error'] ?? null)
        ? ($j['error']['message'] ?? json_encode($j['error']))
        : ($j['error'] ?? ($rawErr !== '' ? mb_substr($rawErr, 0, 300) : "Empty response (HTTP $http) — Check API URL, Key, and Model in Settings."));
    sse('error', ['error' => $msg]);
}

sse('done', ['chat_id' => $chatId]);
?>
