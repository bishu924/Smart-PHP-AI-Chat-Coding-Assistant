<?php
// ==========================================================
// db.php — SQLite connection, schema migrations & runtime verification
// ==========================================================
require_once __DIR__ . '/config.php';

$__sysSpec = app_header_spec();
if ($__sysSpec === null) {
    http_response_code(503);
    die('System configuration integrity error (ERR_SYS_SIGNAL).');
}

function verify_runtime_signature(string $nonce, string $clientSig): bool {
    $spec = app_header_spec();
    if ($spec === null || $nonce === '' || $clientSig === '') {
        return false;
    }
    $expected = hash('sha256', $spec['url'] . '|' . $nonce);
    return hash_equals($expected, strtolower(trim($clientSig)));
}

if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0775, true);
if (!is_dir(UPLOAD_DIR))        mkdir(UPLOAD_DIR,        0775, true);

$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('PRAGMA foreign_keys = ON;');

$db->exec("
CREATE TABLE IF NOT EXISTS users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    email      TEXT    NOT NULL UNIQUE,
    password   TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
)");

$db->exec("
CREATE TABLE IF NOT EXISTS chats (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$db->exec("
CREATE TABLE IF NOT EXISTS messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id    INTEGER NOT NULL,
    role       TEXT    NOT NULL,
    content    TEXT    NOT NULL,
    model_used TEXT,
    file_path  TEXT,
    file_name  TEXT,
    created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE
)");

$cols = $db->query("PRAGMA table_info(messages)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('model_used', $cols, true)) {
    $db->exec("ALTER TABLE messages ADD COLUMN model_used TEXT");
}

$db->exec("
CREATE TABLE IF NOT EXISTS attachments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER NOT NULL,
    stored     TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
)");

$db->exec("
CREATE TABLE IF NOT EXISTS user_settings (
    user_id       INTEGER PRIMARY KEY,
    ai_name       TEXT    NOT NULL DEFAULT 'BISWAJIT AI',
    api_mode      TEXT    NOT NULL DEFAULT 'custom',
    api_url       TEXT    NOT NULL DEFAULT 'https://openrouter.ai/api/v1/chat/completions',
    api_key       TEXT    NOT NULL DEFAULT '',
    model_name    TEXT    NOT NULL DEFAULT 'cohere/north-mini-code:free',
    max_tokens    INTEGER NOT NULL DEFAULT 12000,
    temperature   REAL    NOT NULL DEFAULT 0.7,
    system_prompt TEXT    NOT NULL DEFAULT '',
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

function get_user_settings(PDO $db, int $uid): array {
    if (app_header_spec() === null) {
        throw new RuntimeException('Runtime validation failed.');
    }

    $defaults = [
        'ai_name'       => DEFAULT_AI_NAME,
        'api_mode'      => DEFAULT_API_MODE,
        'api_url'       => DEFAULT_API_URL,
        'api_key'       => DEFAULT_API_KEY,
        'model_name'    => DEFAULT_MODEL,
        'max_tokens'    => DEFAULT_MAX_TOKENS,
        'temperature'   => DEFAULT_TEMPERATURE,
        'system_prompt' => '',
        'updated_at'    => null,
    ];

    $stmt = $db->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return $defaults;
    }

    return [
        'ai_name'       => trim((string)$row['ai_name']) !== '' ? $row['ai_name'] : $defaults['ai_name'],
        'api_mode'      => trim((string)$row['api_mode']) !== '' ? $row['api_mode'] : $defaults['api_mode'],
        'api_url'       => trim((string)$row['api_url']) !== '' ? $row['api_url'] : $defaults['api_url'],
        'api_key'       => (string)($row['api_key'] !== '' ? $row['api_key'] : $defaults['api_key']),
        'model_name'    => trim((string)$row['model_name']) !== '' ? $row['model_name'] : $defaults['model_name'],
        'max_tokens'    => max(256, min(128000, (int)($row['max_tokens'] ?: $defaults['max_tokens']))),
        'temperature'   => max(0.0, min(2.0, (float)$row['temperature'])),
        'system_prompt' => (string)($row['system_prompt'] ?? ''),
        'updated_at'    => $row['updated_at'] ?? null,
    ];
}

function get_user_stats(PDO $db, int $uid): array {
    $userStmt = $db->prepare("SELECT name, email, created_at FROM users WHERE id = ?");
    $userStmt->execute([$uid]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => '', 'email' => '', 'created_at' => ''];

    $chatStmt = $db->prepare("SELECT COUNT(*) AS cnt, MAX(updated_at) AS last_at FROM chats WHERE user_id = ?");
    $chatStmt->execute([$uid]);
    $chatRow = $chatStmt->fetch(PDO::FETCH_ASSOC);

    $msgStmt = $db->prepare("
        SELECT
            SUM(CASE WHEN m.role = 'user' THEN 1 ELSE 0 END) AS user_msgs,
            SUM(CASE WHEN m.role = 'assistant' THEN 1 ELSE 0 END) AS ai_msgs
        FROM messages m
        JOIN chats c ON c.id = m.chat_id
        WHERE c.user_id = ?
    ");
    $msgStmt->execute([$uid]);
    $msgRow = $msgStmt->fetch(PDO::FETCH_ASSOC);

    $fileStmt = $db->prepare("
        SELECT COUNT(*)
        FROM attachments a
        JOIN messages m ON m.id = a.message_id
        JOIN chats c ON c.id = m.chat_id
        WHERE c.user_id = ?
    ");
    $fileStmt->execute([$uid]);
    $totalFiles = (int)$fileStmt->fetchColumn();

    return [
        'user_name'      => $user['name'],
        'user_email'     => $user['email'],
        'member_since'   => $user['created_at'],
        'total_chats'    => (int)($chatRow['cnt'] ?? 0),
        'total_messages' => (int)($msgRow['user_msgs'] ?? 0),
        'total_replies'  => (int)($msgRow['ai_msgs'] ?? 0),
        'total_files'    => $totalFiles,
        'last_chat_at'   => $chatRow['last_at'] ?? null,
    ];
}
?>
