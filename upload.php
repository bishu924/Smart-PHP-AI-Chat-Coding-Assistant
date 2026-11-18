<?php
// ==========================================================
// upload.php — Concurrent file uploader (English only)
// ==========================================================
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required']); exit;
}
$uid   = (int)$_SESSION['user_id'];
$nonce = (string)($_SESSION['rt_nonce'] ?? '');
session_write_close();

$clientSig = (string)($_SERVER['HTTP_X_RUNTIME_SIG'] ?? '');
if (!verify_runtime_signature($nonce, $clientSig)) {
    http_response_code(403);
    echo json_encode(['error' => 'Runtime header verification failed.']); exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'File upload failed.']); exit;
}
if ($_FILES['file']['size'] > MAX_UPLOAD) {
    echo json_encode(['error' => 'File exceeds 10MB limit.']); exit;
}

$name = trim((string)($_POST['relpath'] ?? '')) ?: $_FILES['file']['name'];
$name = ltrim(preg_replace('#\.{2,}#', '', str_replace('\\', '/', $name)), '/');
$name = mb_substr($name, 0, 200);

$ext     = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$allowed = array_merge(IMAGE_EXT, TEXT_EXT, ['pdf', 'doc', 'docx']);
if (!in_array($ext, $allowed, true)) {
    echo json_encode(['error' => "File type '.$ext' is not supported."]); exit;
}

$isImage = in_array($ext, IMAGE_EXT, true);
if ($isImage && !@getimagesize($_FILES['file']['tmp_name'])) {
    echo json_encode(['error' => 'Invalid image file.']); exit;
}

$stored = $uid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . ($isImage ? $ext : 'dat');
if (!move_uploaded_file($_FILES['file']['tmp_name'], UPLOAD_DIR . '/' . $stored)) {
    echo json_encode(['error' => 'Could not save file on server.']); exit;
}

echo json_encode([
    'ok'       => true,
    'stored'   => $stored,
    'path'     => $isImage ? UPLOAD_URL . $stored : null,
    'name'     => $name,
    'is_image' => $isImage,
]);
?>
