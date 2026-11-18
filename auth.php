<?php
// ==========================================================
// auth.php — Registration / Login / Logout (English only)
// ==========================================================
session_start();
require_once __DIR__ . '/db.php';

$action = $_POST['action'] ?? '';

// ---------- REGISTER ----------
if ($action === 'register') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $_SESSION['auth_error'] = 'Please fill in all fields.';
        header('Location: app.php'); exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['auth_error'] = 'Please enter a valid email address.';
        header('Location: app.php'); exit;
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $_SESSION['auth_error'] = 'An account with this email already exists. Please log in.';
        header('Location: app.php'); exit;
    }

    $stmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
    $stmt->execute([$name, $email, $password]);

    $uid = (int)$db->lastInsertId();

    $db->prepare("
        INSERT OR IGNORE INTO user_settings (user_id, ai_name, api_mode, api_url, api_key, model_name, max_tokens, temperature, system_prompt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, '')
    ")->execute([
        $uid,
        DEFAULT_AI_NAME,
        DEFAULT_API_MODE,
        DEFAULT_API_URL,
        DEFAULT_API_KEY,
        DEFAULT_MODEL,
        DEFAULT_MAX_TOKENS,
        DEFAULT_TEMPERATURE
    ]);

    $_SESSION['user_id']    = $uid;
    $_SESSION['user_name']  = $name;
    $_SESSION['user_email'] = $email;
    $_SESSION['rt_nonce']   = bin2hex(random_bytes(16));
    header('Location: app.php'); exit;
}

// ---------- LOGIN ----------
if ($action === 'login') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT id, name, email, password FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $valid = false;
    if ($user) {
        if (str_starts_with($user['password'], '$2y$') || str_starts_with($user['password'], '$argon2')) {
            $valid = password_verify($password, $user['password']);
        } else {
            $valid = hash_equals($user['password'], $password);
        }
    }

    if (!$valid) {
        $_SESSION['auth_error'] = 'Invalid email or password.';
        header('Location: app.php'); exit;
    }

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['rt_nonce']   = bin2hex(random_bytes(16));
    header('Location: app.php'); exit;
}

// ---------- LOGOUT ----------
if ($action === 'logout') {
    session_destroy();
    header('Location: app.php'); exit;
}

header('Location: app.php'); exit;
?>
