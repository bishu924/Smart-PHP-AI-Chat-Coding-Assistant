<?php
// ==========================================================
// app.php — Smart AI Chat UI (Pure PHP + SQLite + English Only)
// ==========================================================
session_start();
require_once __DIR__ . '/db.php';

$hdrSpec = app_header_spec();
if ($hdrSpec === null) {
    http_response_code(503);
    die('Runtime configuration check failed.');
}

$isLoggedIn = isset($_SESSION['user_id']);
$uid        = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;
$userName   = $_SESSION['user_name'] ?? '';
$authError  = $_SESSION['auth_error'] ?? '';
unset($_SESSION['auth_error']);

if ($isLoggedIn && empty($_SESSION['rt_nonce'])) {
    $_SESSION['rt_nonce'] = bin2hex(random_bytes(16));
}
$rtNonce = $isLoggedIn ? (string)$_SESSION['rt_nonce'] : '';

$settings = $isLoggedIn ? get_user_settings($db, $uid) : null;
$stats    = $isLoggedIn ? get_user_stats($db, $uid) : null;

$aiName    = $settings ? $settings['ai_name'] : DEFAULT_AI_NAME;
$modelName = $settings ? $settings['model_name'] : DEFAULT_MODEL;
$apiUrl    = $settings ? $settings['api_url'] : DEFAULT_API_URL;
$hasKey    = $settings ? ($settings['api_key'] !== '') : false;
$isLocal   = $settings ? in_array(strtolower((string)parse_url($apiUrl, PHP_URL_HOST)), ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($aiName, ENT_QUOTES, 'UTF-8') ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg: #f4f6f9;
    --surface: #ffffff;
    --sidebar: #111317;
    --sidebar-hover: #1d2027;
    --sidebar-active: #272b34;
    --border: #e5e7eb;
    --text: #18181b;
    --muted: #6b7280;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    height: 100vh;
    overflow: hidden;
}

/* ============ AUTH PAGE ============ */
.auth-wrap {
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.auth-box {
    background: #fff;
    width: 100%;
    max-width: 410px;
    padding: 36px 30px;
    border-radius: 18px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.09);
}
.auth-box h1 { font-size: 22px; margin-bottom: 4px; }
.auth-box .sub { color: #777; font-size: 13px; margin-bottom: 22px; }
.auth-tabs {
    display: flex;
    background: #f1f3f6;
    border-radius: 12px;
    padding: 4px;
    margin-bottom: 20px;
}
.auth-tabs button {
    flex: 1;
    height: 40px;
    border: 0;
    border-radius: 9px;
    background: transparent;
    color: #555;
    font-weight: 600;
    cursor: pointer;
}
.auth-tabs button.active { background: #111; color: #fff; }
.auth-box input {
    width: 100%;
    height: 48px;
    border: 1px solid #ddd;
    border-radius: 12px;
    padding: 0 14px;
    font-size: 15px;
    margin-bottom: 12px;
    outline: none;
}
.auth-box input:focus { border-color: #111; }
.auth-box .submit {
    width: 100%;
    height: 48px;
    border: 0;
    border-radius: 12px;
    background: #111;
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 6px;
}
.auth-error {
    background: #ffecec;
    color: #b00020;
    font-size: 13px;
    padding: 10px 14px;
    border-radius: 10px;
    margin-bottom: 14px;
}

/* ============ CHAT APP LAYOUT ============ */
.app { display: flex; height: 100vh; position: relative; }

.sidebar-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 9;
}
.sidebar-backdrop.show { display: block; }

.sidebar {
    width: 280px;
    background: var(--sidebar);
    color: #fff;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    z-index: 10;
}
.sidebar .brand {
    padding: 18px 18px 12px;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: .3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sidebar .brand small {
    display: block;
    font-size: 11px;
    color: #9ca3af;
    font-weight: 400;
    margin-top: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.new-chat-btn {
    margin: 6px 14px 8px;
    height: 42px;
    border: 1px solid #2e323c;
    border-radius: 11px;
    background: #1b1e25;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
}
.new-chat-btn:hover { background: #262a34; }

.chat-search-wrap {
    padding: 0 14px 8px;
}
.chat-search {
    width: 100%;
    height: 34px;
    border-radius: 9px;
    border: 1px solid #2a2e38;
    background: #181b21;
    color: #e5e7eb;
    padding: 0 11px;
    font-size: 12.5px;
    outline: none;
}
.chat-search:focus { border-color: #4b5563; }
.chat-search::placeholder { color: #6b7280; }

.chat-list {
    flex: 1;
    overflow-y: auto;
    padding: 4px 10px;
}
.chat-empty-hint {
    padding: 18px 10px;
    font-size: 12px;
    color: #6b7280;
    text-align: center;
}
.chat-item {
    padding: 10px 11px;
    border-radius: 10px;
    cursor: pointer;
    margin-bottom: 3px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 6px;
}
.chat-item:hover { background: var(--sidebar-hover); }
.chat-item.active { background: var(--sidebar-active); }
.chat-item .t {
    font-size: 13px;
    color: #f3f4f6;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
}
.chat-item .d {
    font-size: 10.5px;
    color: #7c8492;
    display: block;
    margin-top: 2px;
    font-variant-numeric: tabular-nums;
}
.chat-item .acts {
    display: flex;
    align-items: center;
    gap: 2px;
    opacity: 0.65;
}
.chat-item:hover .acts { opacity: 1; }
.chat-item .act-btn {
    border: 0;
    background: transparent;
    color: #9ca3af;
    cursor: pointer;
    font-size: 13px;
    padding: 3px 5px;
    border-radius: 6px;
}
.chat-item .act-btn:hover { background: #333844; color: #fff; }
.chat-item .act-btn.del:hover { color: #f87171; }

.sidebar-footer {
    border-top: 1px solid #232730;
    padding: 10px 12px 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: #0e1014;
}
.sidebar-stats {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11.5px;
    color: #9ca3af;
    padding: 4px 6px;
    font-variant-numeric: tabular-nums;
}
.settings-nav-btn {
    width: 100%;
    height: 40px;
    border: 1px solid #2b303b;
    border-radius: 10px;
    background: #1a1d24;
    color: #f3f4f6;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 12px;
    transition: background .15s, border-color .15s;
}
.settings-nav-btn:hover { background: #242833; border-color: #3f4654; }
.settings-nav-btn .cfg-note { font-size: 11px; color: #fbbf24; font-weight: 500; }
.settings-nav-btn .cfg-ok   { font-size: 11px; color: #9ca3af; font-weight: 400; }

.userbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 4px 4px 0;
}
.userbar .uname {
    font-size: 13px;
    color: #d1d5db;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.userbar button {
    border: 0;
    background: #232732;
    color: #d1d5db;
    font-size: 12px;
    padding: 6px 11px;
    border-radius: 8px;
    cursor: pointer;
    flex-shrink: 0;
}
.userbar button:hover { background: #303543; color: #fff; }

/* ============ MAIN AREA ============ */
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #fff;
    min-width: 0;
    position: relative;
}
.header {
    padding: 12px 22px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.header-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.header-title {
    font-size: 17px;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.header-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.model-info {
    font-size: 12px;
    color: #52525b;
    max-width: 240px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.coffee-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 9px;
    background: #fef3c7;
    border: 1px solid #f59e0b;
    color: #78350f;
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    flex-shrink: 0;
}
.coffee-link:hover { background: #fde68a; }
.hdr-settings-btn {
    border: 1px solid var(--border);
    background: #fff;
    color: #18181b;
    font-size: 12.5px;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 9px;
    cursor: pointer;
    white-space: nowrap;
}
.hdr-settings-btn:hover { background: #f4f4f5; }

.setup-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 24px;
    background: #fffbeb;
    border-bottom: 1px solid #fde68a;
    color: #92400e;
    font-size: 13px;
}
.setup-banner button {
    border: 0;
    background: #92400e;
    color: #fff;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}

.chat-area {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px;
}
.msg { display: flex; margin-bottom: 16px; animation: fadeIn .2s ease; }
@keyframes fadeIn { from {opacity:0; transform:translateY(5px);} to {opacity:1; transform:translateY(0);} }
.msg.user { justify-content: flex-end; }

.bubble {
    max-width: 78%;
    min-width: 0;
    padding: 12px 16px;
    border-radius: 16px;
    line-height: 1.68;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 15px;
}
.msg.user .bubble { background: #111317; color: #fff; border-bottom-right-radius: 4px; }
.msg.ai   .bubble { background: #f3f4f6; color: #18181b; border-bottom-left-radius: 4px; white-space: normal; }
.msg.ai   .bubble.hasfiles { width: 92%; }
.msg.error .bubble { background: #ffecec; color: #b00020; }

.msg-meta-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-top: 8px;
    padding-top: 6px;
    border-top: 1px solid rgba(0,0,0,0.06);
    font-size: 11px;
    color: #6b7280;
}
.msg-copy-btn {
    border: 0;
    background: transparent;
    color: #4b5563;
    font-size: 11.5px;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 5px;
}
.msg-copy-btn:hover { background: rgba(0,0,0,0.06); color: #111; }

.bubble img.attached {
    max-width: 260px;
    border-radius: 10px;
    display: block;
    margin-bottom: 8px;
}
.bubble a.file-link {
    display: inline-block;
    background: rgba(255,255,255,.15);
    padding: 7px 12px;
    border-radius: 9px;
    color: #7cc4ff;
    margin: 0 6px 8px 0;
    font-size: 13px;
    text-decoration: none;
}
.msg.ai .bubble a.file-link { color: #0066cc; background: #e3ecff; }

.input-area {
    padding: 12px 24px 18px;
    border-top: 1px solid var(--border);
    background: #fff;
}
.input-row { display: flex; gap: 10px; align-items: flex-end; }
textarea#questionInput {
    flex: 1;
    min-height: 52px;
    max-height: 180px;
    resize: none;
    border: 1px solid #d4d4d8;
    border-radius: 14px;
    padding: 14px 16px;
    font-size: 15px;
    font-family: inherit;
    outline: none;
    line-height: 1.5;
}
textarea#questionInput:focus { border-color: #111; }
button.icon-btn {
    width: 50px;
    height: 50px;
    border-radius: 13px;
    border: 1px solid #d4d4d8;
    background: #fff;
    font-size: 19px;
    cursor: pointer;
    flex-shrink: 0;
}
button.icon-btn:hover { background: #f4f4f5; }
button.send-btn {
    border: 0;
    border-radius: 13px;
    padding: 0 22px;
    height: 50px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    background: #111317;
    color: #fff;
    white-space: nowrap;
}
button.stop-btn {
    display: none;
    border: 1px solid #ef4444;
    border-radius: 13px;
    padding: 0 18px;
    height: 50px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    background: #fef2f2;
    color: #b91c1c;
    white-space: nowrap;
}
button:hover:not(:disabled) { opacity: .88; }
button:disabled { opacity: .45; cursor: not-allowed; }
.hint { font-size: 12px; color: #888; margin-top: 8px; text-align: center; }

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #6b7280;
    text-align: center;
    padding: 20px;
}
.empty-state h2 { font-size: 24px; margin-bottom: 8px; color: #18181b; }
.empty-state p { font-size: 14px; line-height: 1.65; max-width: 540px; }
.empty-state .logo { font-size: 46px; margin-bottom: 12px; }

.menu-toggle { display: none; border: 0; background: none; font-size: 22px; cursor: pointer; }
@media (max-width: 820px) {
    .sidebar { position: fixed; height: 100vh; left: -290px; transition: left .22s ease; }
    .sidebar.open { left: 0; }
    .menu-toggle { display: inline-block; }
    .model-info { display: none; }
}

/* ============ CODING AGENT FILE CARDS ============ */
.dropzone { display:none; position:absolute; inset:0; background:rgba(17,17,17,.85); color:#fff; font-size:22px; align-items:center; justify-content:center; z-index:20; }
.main.drag .dropzone { display:flex; }
.pending-files { display:none; flex-wrap:wrap; gap:6px; margin-bottom:8px; max-height:96px; overflow:auto; }
.pchip { display:flex; align-items:center; gap:6px; background:#f1f3f6; border-radius:9px; padding:5px 9px; font-size:12px; max-width:250px; }
.pchip .n { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.pchip.err { background:#ffecec; color:#b00020; }
.pchip button { border:0; background:#ddd; border-radius:5px; cursor:pointer; padding:0 6px; }
.tx { white-space:pre-wrap; }
.tx + .fcard, .fcard + .tx, .fcard + .fcard { margin-top:10px; }
.tx code { background:#e4e7ec; padding:1px 5px; border-radius:5px; font-size:13px; }
.status { font-size:13px; color:#666; margin-bottom:6px; }
.status:empty { display:none; }
@keyframes spin { to { transform:rotate(360deg); } }
.bubble.live .status::before, .fcard.writing .fs::before {
    content:''; display:inline-block; width:10px; height:10px; margin-right:7px; vertical-align:-1px;
    border:2px solid #aaa; border-top-color:#111; border-radius:50%; animation:spin .7s linear infinite;
}
.fcard.writing .fs::before { border-color:#666; border-top-color:#fff; }
.fcard { border-radius:12px; overflow:hidden; background:#1e1e1e; border:1px solid #333; white-space:normal; }
.fcard.writing { border-color:#4a9eff; box-shadow:0 0 0 2px rgba(74,158,255,.2); }
.fh { display:flex; align-items:center; gap:8px; padding:8px 12px; background:#2a2a2a; color:#ddd; font-size:13px; }
.fh .fn { font-weight:600; font-family:ui-monospace,Menlo,Consolas,monospace; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.fh .fs { color:#8a8a8a; font-size:12px; flex:1; white-space:nowrap; }
.fh .fb button { border:0; background:#3a3a3a; color:#ddd; border-radius:7px; padding:4px 9px; margin-left:4px; font-size:12px; cursor:pointer; }
.fcard pre, pre.code { margin:0; padding:12px 14px; max-height:420px; overflow:auto; background:#1e1e1e; color:#d4d4d4; font:12.5px/1.55 ui-monospace,Menlo,Consolas,monospace; white-space:pre; tab-size:4; }
pre.code { border-radius:10px; margin:8px 0; }
.fcard.closed pre { display:none; }
.zipbar { margin-top:10px; }
.zipbar button { border:0; background:#111; color:#fff; border-radius:10px; padding:9px 16px; font-size:13px; font-weight:600; cursor:pointer; }
.err, .warn { margin-top:8px; font-size:13px; color:#b00020; }
.warn { color:#a56200; }

/* ============ SETTINGS MODAL ============ */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 17, 23, 0.58);
    backdrop-filter: blur(3px);
    z-index: 50;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff;
    width: 100%;
    max-width: 680px;
    max-height: 90vh;
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(0,0,0,0.22);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.modal-head {
    padding: 18px 22px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-head h2 { font-size: 18px; font-weight: 700; }
.modal-close {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    border: 1px solid var(--border);
    background: #fff;
    font-size: 16px;
    cursor: pointer;
}
.modal-close:hover { background: #f4f4f5; }

.modal-tabs {
    display: flex;
    gap: 6px;
    padding: 12px 22px 0;
    border-bottom: 1px solid var(--border);
    background: #fafafa;
}
.modal-tab {
    border: 0;
    background: transparent;
    padding: 10px 14px;
    font-size: 13.5px;
    font-weight: 600;
    color: #6b7280;
    cursor: pointer;
    border-bottom: 2px solid transparent;
}
.modal-tab.active {
    color: #111;
    border-bottom-color: #111;
}

.modal-body {
    padding: 20px 22px;
    overflow-y: auto;
    flex: 1;
}
.tab-pane { display: none; }
.tab-pane.active { display: block; }

.preset-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 16px;
}
.preset-btn {
    border: 1px solid #d4d4d8;
    background: #fff;
    color: #3f3f46;
    font-size: 12.5px;
    font-weight: 600;
    padding: 7px 12px;
    border-radius: 9px;
    cursor: pointer;
}
.preset-btn:hover { background: #f4f4f5; }
.preset-btn.active { background: #111317; color: #fff; border-color: #111317; }

.fgroup { margin-bottom: 15px; }
.fgroup label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #27272a;
    margin-bottom: 6px;
}
.fgroup .fhelp {
    font-size: 11.5px;
    color: #71717a;
    margin-top: 4px;
    line-height: 1.45;
}
.finput, .ftextarea {
    width: 100%;
    border: 1px solid #d4d4d8;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    background: #fff;
}
.finput:focus, .ftextarea:focus { border-color: #111; }
.ftextarea { min-height: 96px; resize: vertical; line-height: 1.5; }
.finput.mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; }

.key-wrap { display: flex; gap: 8px; }
.key-wrap .finput { flex: 1; }
.key-toggle {
    border: 1px solid #d4d4d8;
    background: #f9fafb;
    border-radius: 10px;
    padding: 0 12px;
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
}

.frow-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 560px) { .frow-2 { grid-template-columns: 1fr; } }

.test-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: #fafafa;
    margin-top: 8px;
}
.test-btn {
    border: 1px solid #111;
    background: #fff;
    color: #111;
    padding: 8px 14px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}
.test-btn:hover { background: #111; color: #fff; }
.test-result { font-size: 12.5px; color: #52525b; flex: 1; word-break: break-word; }
.test-result.ok { color: #15803d; font-weight: 600; }
.test-result.fail { color: #b91c1c; font-weight: 600; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}
.stat-card {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    background: #fafafa;
}
.stat-card .s-label { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
.stat-card .s-val { font-size: 24px; font-weight: 800; color: #111; font-variant-numeric: tabular-nums; }

.profile-summary {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    font-size: 13px;
    line-height: 1.75;
    color: #3f3f46;
    margin-bottom: 16px;
}
.danger-zone {
    border: 1px solid #fecaca;
    background: #fef2f2;
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.danger-btn {
    border: 0;
    background: #dc2626;
    color: #fff;
    padding: 8px 14px;
    border-radius: 9px;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}

.modal-foot {
    padding: 14px 22px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    background: #fafafa;
}
.save-msg { font-size: 13px; color: #15803d; font-weight: 600; }
.save-msg.err { color: #b91c1c; }
.modal-actions { display: flex; gap: 8px; margin-left: auto; }
.btn-sec {
    border: 1px solid #d4d4d8;
    background: #fff;
    color: #18181b;
    padding: 9px 16px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
}
.btn-pri {
    border: 0;
    background: #111317;
    color: #fff;
    padding: 9px 20px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
}

.sys-lock {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: #111317;
    color: #f4f4f5;
    display: none;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 24px;
    font-size: 15px;
}
</style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<!-- ================= LOGIN / REGISTER ================= -->
<div class="auth-wrap">
    <div class="auth-box">
        <h1>🤖 <?= htmlspecialchars(DEFAULT_AI_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="sub">Sign in or register to open your personal AI workspace</div>

        <?php if ($authError): ?>
        <div class="auth-error"><?= htmlspecialchars($authError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="auth-tabs">
            <button id="tabLogin" class="active" onclick="showTab('login')">Login</button>
            <button id="tabRegister" onclick="showTab('register')">Register</button>
        </div>

        <form id="formLogin" action="auth.php" method="POST">
            <input type="hidden" name="action" value="login">
            <input type="email" name="email" placeholder="Email address" required>
            <input type="password" name="password" placeholder="Password" required>
            <button class="submit" type="submit">Login</button>
        </form>

        <form id="formRegister" action="auth.php" method="POST" style="display:none">
            <input type="hidden" name="action" value="register">
            <input type="text" name="name" placeholder="Your full name" required>
            <input type="email" name="email" placeholder="Email address" required>
            <input type="password" name="password" placeholder="Password" required>
            <button class="submit" type="submit">Create Account</button>
        </form>
    </div>
</div>

<script>
function showTab(t) {
    document.getElementById('formLogin').style.display    = t === 'login'    ? 'block' : 'none';
    document.getElementById('formRegister').style.display = t === 'register' ? 'block' : 'none';
    document.getElementById('tabLogin').classList.toggle('active',    t === 'login');
    document.getElementById('tabRegister').classList.toggle('active', t === 'register');
}
</script>

<?php else: ?>
<!-- ================= CHAT APP ================= -->
<div class="sys-lock" id="sysLock">
    <div>
        <h2 style="margin-bottom:8px;">Runtime Integrity Error</h2>
        <p style="color:#a1a1aa;">Required header signal element is missing, hidden, or modified.</p>
    </div>
</div>

<div class="app" id="appRoot">
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>

    <aside class="sidebar" id="sidebar">
        <div class="brand" id="sidebarBrand">
            <span id="brandAiName"><?= htmlspecialchars($aiName, ENT_QUOTES, 'UTF-8') ?></span>
            <small id="brandModelName"><?= htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8') ?></small>
        </div>

        <button class="new-chat-btn" onclick="newChat()">+ New Chat</button>

        <div class="chat-search-wrap">
            <input type="text" class="chat-search" id="chatSearch" placeholder="Search chats...">
        </div>

        <div class="chat-list" id="chatList"></div>

        <div class="sidebar-footer">
            <div class="sidebar-stats" id="sidebarStats">
                <span>Chats: <b id="sbChats"><?= (int)$stats['total_chats'] ?></b></span>
                <span>Messages: <b id="sbMsgs"><?= (int)$stats['total_messages'] ?></b></span>
            </div>

            <button class="settings-nav-btn" onclick="openSettings('api')">
                <span>⚙️ Settings</span>
                <span class="<?= ($hasKey || $isLocal) ? 'cfg-ok' : 'cfg-note' ?>" id="sbConfigBadge">
                    <?= ($hasKey || $isLocal) ? 'Configured' : 'Set API Key' ?>
                </span>
            </button>

            <div class="userbar">
                <span class="uname">👤 <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                <form action="auth.php" method="POST" style="margin:0">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit">Logout</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="main" id="main">
        <div class="dropzone">Drop files here to attach</div>

        <div class="header" id="appHeader">
            <div class="header-left">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <div class="header-title" id="headerAiName">🤖 <?= htmlspecialchars($aiName, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="header-right">
                <span class="model-info" id="headerModelTag"><?= htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8') ?></span>
                <a id="hdrSysLink"
                   href="<?= htmlspecialchars($hdrSpec['url'], ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="coffee-link">☕ <?= htmlspecialchars($hdrSpec['label'], ENT_QUOTES, 'UTF-8') ?></a>
                <button class="hdr-settings-btn" onclick="openSettings('api')">⚙️ Settings</button>
            </div>
        </div>

        <div class="setup-banner" id="setupBanner" style="display: <?= ($hasKey || $isLocal) ? 'none' : 'flex' ?>;">
            <span>API Key is not set yet — open Settings to configure your Custom API Endpoint Link, Key, and Model without touching code.</span>
            <button onclick="openSettings('api')">Open Settings</button>
        </div>

        <div class="chat-area" id="chatArea">
            <div class="empty-state" id="emptyState">
                <div class="logo">🤖</div>
                <h2>How can I help you today?</h2>
                <p>
                    Ask my name and I will answer — <b id="emptyAiName"><?= htmlspecialchars($aiName, ENT_QUOTES, 'UTF-8') ?></b><br>
                    Send a message or upload code files / entire folders 📎 📁<br>
                    Use <b>⚙️ Settings</b> in the bottom-left sidebar to customize your AI name, Custom API endpoint URL, API key, and model.
                </p>
            </div>
        </div>

        <div class="input-area">
            <div class="pending-files" id="pendingFiles"></div>
            <div class="input-row">
                <input type="file" id="fileInput" multiple hidden>
                <input type="file" id="folderInput" webkitdirectory hidden>
                <button class="icon-btn" id="attachBtn" title="Attach files">📎</button>
                <button class="icon-btn" id="folderBtn" title="Attach folder">📁</button>
                <textarea id="questionInput" placeholder="Type a message or drop files... (Enter = Send)"></textarea>
                <button class="stop-btn" id="stopBtn" onclick="stopStreaming()">⏹ Stop</button>
                <button class="send-btn" id="sendBtn">Send</button>
            </div>
            <div class="hint">AI can make mistakes — please verify important responses.</div>
        </div>
    </div>
</div>

<!-- ================= SETTINGS MODAL ================= -->
<div class="modal-overlay" id="settingsModal" onclick="if(event.target===this) closeSettings()">
    <div class="modal-box">
        <div class="modal-head">
            <h2>⚙️ Settings &amp; Custom API Configuration</h2>
            <button class="modal-close" onclick="closeSettings()">✕</button>
        </div>

        <div class="modal-tabs">
            <button class="modal-tab active" data-tab="api" onclick="switchSettingsTab('api')">🔌 Custom API &amp; Model</button>
            <button class="modal-tab" data-tab="identity" onclick="switchSettingsTab('identity')">🤖 AI Name &amp; Prompt</button>
            <button class="modal-tab" data-tab="stats" onclick="switchSettingsTab('stats')">📊 Usage &amp; Chat Stats</button>
        </div>

        <div class="modal-body">
            <!-- TAB 1: CUSTOM API & MODEL -->
            <div class="tab-pane active" id="pane-api">
                <div class="fgroup">
                    <label>API Provider Preset (Switch from OpenRouter to any Custom API)</label>
                    <div class="preset-row" id="presetRow">
                        <button type="button" class="preset-btn" data-mode="custom" onclick="applyPreset('custom')">Custom API</button>
                        <button type="button" class="preset-btn" data-mode="openrouter" onclick="applyPreset('openrouter')">OpenRouter</button>
                        <button type="button" class="preset-btn" data-mode="openai" onclick="applyPreset('openai')">OpenAI</button>
                        <button type="button" class="preset-btn" data-mode="groq" onclick="applyPreset('groq')">Groq</button>
                        <button type="button" class="preset-btn" data-mode="gemini_compat" onclick="applyPreset('gemini_compat')">Gemini (Compat)</button>
                        <button type="button" class="preset-btn" data-mode="ollama" onclick="applyPreset('ollama')">Ollama Local</button>
                    </div>
                </div>

                <div class="fgroup">
                    <label for="cfgApiUrl">API Endpoint Link (URL)</label>
                    <input type="url" class="finput mono" id="cfgApiUrl" placeholder="https://api.openai.com/v1/chat/completions">
                    <div class="fhelp">Paste any OpenAI-compatible Chat Completions endpoint URL here.</div>
                </div>

                <div class="fgroup">
                    <label for="cfgApiKey">API Key</label>
                    <div class="key-wrap">
                        <input type="password" class="finput mono" id="cfgApiKey" placeholder="sk-... or paste your API key">
                        <button type="button" class="key-toggle" onclick="toggleKeyVis()" id="keyVisBtn">👁 Show</button>
                    </div>
                    <div class="fhelp">Stored securely in your SQLite user settings. Leave empty for local Ollama endpoints.</div>
                </div>

                <div class="fgroup">
                    <label for="cfgModelName">Model Name</label>
                    <input type="text" class="finput mono" id="cfgModelName" list="modelSuggestions" placeholder="e.g. gpt-4o-mini, deepseek-chat, llama-3.3-70b-versatile">
                    <datalist id="modelSuggestions">
                        <option value="cohere/north-mini-code:free">
                        <option value="openai/gpt-4o-mini">
                        <option value="anthropic/claude-3.5-sonnet">
                        <option value="google/gemini-2.5-flash">
                        <option value="deepseek/deepseek-chat">
                        <option value="llama-3.3-70b-versatile">
                        <option value="qwen2.5-coder:7b">
                    </datalist>
                    <div class="fhelp">Enter the exact Model ID required by your chosen API provider.</div>
                </div>

                <div class="frow-2">
                    <div class="fgroup">
                        <label for="cfgMaxTokens">Max Tokens</label>
                        <input type="number" class="finput" id="cfgMaxTokens" min="256" max="128000" step="256" value="12000">
                    </div>
                    <div class="fgroup">
                        <label for="cfgTemperature">Temperature (0.0 – 2.0)</label>
                        <input type="number" class="finput" id="cfgTemperature" min="0" max="2" step="0.1" value="0.7">
                    </div>
                </div>

                <div class="test-row">
                    <div class="test-result" id="testResult">Test your API URL, Key, and Model before saving.</div>
                    <button type="button" class="test-btn" id="testConnBtn" onclick="testConnection()">⚡ Test Connection</button>
                </div>
            </div>

            <!-- TAB 2: AI IDENTITY & SYSTEM PROMPT -->
            <div class="tab-pane" id="pane-identity">
                <div class="fgroup">
                    <label for="cfgAiName">AI Assistant Display Name</label>
                    <input type="text" class="finput" id="cfgAiName" maxlength="60" placeholder="e.g. BISWAJIT AI or your own custom name">
                    <div class="fhelp">Updates the Sidebar, Header, Page Title, and AI identity responses dynamically without modifying any code.</div>
                </div>

                <div class="fgroup">
                    <label for="cfgSystemPrompt">Custom System Instructions (Optional)</label>
                    <textarea class="ftextarea" id="cfgSystemPrompt" placeholder="e.g. Always respond concisely with clean code comments..."></textarea>
                    <div class="fhelp">Add your own persona or formatting instructions. Coding Mode file cards remain active automatically.</div>
                </div>
            </div>

            <!-- TAB 3: USAGE & CHAT STATS -->
            <div class="tab-pane" id="pane-stats">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="s-label">Total Chats</div>
                        <div class="s-val" id="stTotalChats">0</div>
                    </div>
                    <div class="stat-card">
                        <div class="s-label">Messages Sent</div>
                        <div class="s-val" id="stTotalMsgs">0</div>
                    </div>
                    <div class="stat-card">
                        <div class="s-label">AI Replies</div>
                        <div class="s-val" id="stTotalReplies">0</div>
                    </div>
                    <div class="stat-card">
                        <div class="s-label">Files Uploaded</div>
                        <div class="s-val" id="stTotalFiles">0</div>
                    </div>
                </div>

                <div class="profile-summary" id="profileSummary">
                    Loading...
                </div>

                <div class="danger-zone">
                    <div>
                        <strong style="font-size:13.5px; color:#991b1b;">Delete All Chat History</strong>
                        <div style="font-size:12px; color:#7f1d1d;">Permanently removes all chats and uploaded attachments for your account.</div>
                    </div>
                    <button type="button" class="danger-btn" onclick="clearAllChats()">🗑 Clear All Chats</button>
                </div>
            </div>
        </div>

        <div class="modal-foot">
            <span class="save-msg" id="saveMsg"></span>
            <div class="modal-actions">
                <button type="button" class="btn-sec" onclick="closeSettings()">Close</button>
                <button type="button" class="btn-pri" id="saveSettingsBtn" onclick="saveSettings()">💾 Save Settings</button>
            </div>
        </div>
    </div>
</div>

<script>
const $ = id => document.getElementById(id);
const chatArea = $('chatArea'), emptyState = $('emptyState'), chatList = $('chatList'),
      input = $('questionInput'), sendBtn = $('sendBtn'), stopBtn = $('stopBtn'),
      fileInput = $('fileInput'), folderInput = $('folderInput'),
      pendingBox = $('pendingFiles'), mainEl = $('main'), chatSearch = $('chatSearch');

const RT_NONCE = <?= json_encode($rtNonce) ?>;
let currentChatId = 0, isSending = false, abortCtrl = null;
let allChats = [], pending = [];
let appSettings = <?= json_encode($settings, JSON_UNESCAPED_UNICODE) ?>;
let appStats    = <?= json_encode($stats, JSON_UNESCAPED_UNICODE) ?>;

const PRESETS = {
    custom:        { url: '', model: '' },
    openrouter:    { url: 'https://openrouter.ai/api/v1/chat/completions', model: 'cohere/north-mini-code:free' },
    openai:        { url: 'https://api.openai.com/v1/chat/completions', model: 'gpt-4o-mini' },
    groq:          { url: 'https://api.groq.com/openai/v1/chat/completions', model: 'llama-3.3-70b-versatile' },
    gemini_compat: { url: 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', model: 'gemini-2.5-flash' },
    ollama:        { url: 'http://localhost:11434/v1/chat/completions', model: 'qwen2.5-coder:7b' },
};

const IMG = ['jpg','jpeg','png','gif','webp'];
const OK_EXT = [...IMG,'pdf','doc','docx','txt','csv','md','json','xml','yml','yaml','ini','sql','php','html','htm','css','scss',
                'js','jsx','ts','tsx','vue','py','java','c','cpp','h','cs','go','rb','sh','log','env'];
const SKIP = /(^|\/)(node_modules|vendor|\.git|\.idea|\.vscode|data|uploads|dist|build)\//;

function sha256(str) {
    const bytes = new TextEncoder().encode(str);
    const K = [
        0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
        0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
        0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
        0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
        0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
        0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
        0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
        0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2
    ];
    const H = [0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];
    const l = bytes.length * 8;
    const padded = new Uint8Array(((bytes.length + 9 + 63) >> 6) << 6);
    padded.set(bytes);
    padded[bytes.length] = 0x80;
    const dv = new DataView(padded.buffer);
    dv.setUint32(padded.length - 4, l >>> 0, false);
    dv.setUint32(padded.length - 8, Math.floor(l / 0x100000000), false);
    const w = new Uint32Array(64);
    const r = (x, n) => (x >>> n) | (x << (32 - n));
    for (let i = 0; i < padded.length; i += 64) {
        for (let t = 0; t < 16; t++) w[t] = dv.getUint32(i + t * 4, false);
        for (let t = 16; t < 64; t++) {
            const s0 = r(w[t-15],7) ^ r(w[t-15],18) ^ (w[t-15]>>>3);
            const s1 = r(w[t-2],17) ^ r(w[t-2],19) ^ (w[t-2]>>>10);
            w[t] = (w[t-16] + s0 + w[t-7] + s1) >>> 0;
        }
        let [a,b,c,d,e,f,g,h] = H;
        for (let t = 0; t < 64; t++) {
            const S1 = r(e,6) ^ r(e,11) ^ r(e,25);
            const ch = (e & f) ^ (~e & g);
            const t1 = (h + S1 + ch + K[t] + w[t]) >>> 0;
            const S0 = r(a,2) ^ r(a,13) ^ r(a,22);
            const maj = (a & b) ^ (a & c) ^ (b & c);
            const t2 = (S0 + maj) >>> 0;
            h = g; g = f; f = e; e = (d + t1) >>> 0;
            d = c; c = b; b = a; a = (t1 + t2) >>> 0;
        }
        H[0]=(H[0]+a)>>>0; H[1]=(H[1]+b)>>>0; H[2]=(H[2]+c)>>>0; H[3]=(H[3]+d)>>>0;
        H[4]=(H[4]+e)>>>0; H[5]=(H[5]+f)>>>0; H[6]=(H[6]+g)>>>0; H[7]=(H[7]+h)>>>0;
    }
    return Array.from(H, v => v.toString(16).padStart(8, '0')).join('');
}

function checkHeaderDom() {
    const hdr = $('appHeader');
    const a   = $('hdrSysLink');
    if (!hdr || !a || !hdr.contains(a)) return null;
    const cs = getComputedStyle(a);
    const hs = getComputedStyle(hdr);
    if (hs.display === 'none' || hs.visibility === 'hidden' || parseFloat(hs.opacity) < 0.8) return null;
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) < 0.8) return null;
    const rect = a.getBoundingClientRect();
    if (rect.width < 20 || rect.height < 14) return null;
    return a.getAttribute('href') || '';
}

function lockRuntime() {
    $('appRoot').style.display = 'none';
    $('sysLock').style.display = 'flex';
}

function rtHeaders(extra = {}) {
    const href = checkHeaderDom();
    if (!href) {
        lockRuntime();
        return extra;
    }
    return Object.assign({}, extra, { 'X-Runtime-Sig': sha256(href + '|' + RT_NONCE) });
}

const hdrObserver = new MutationObserver(() => {
    if (!checkHeaderDom()) lockRuntime();
});
hdrObserver.observe($('appHeader'), { childList: true, subtree: true, attributes: true });
setInterval(() => { if (!checkHeaderDom()) lockRuntime(); }, 2500);

function el(tag, cls, html) { const e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function scrollToBottom(force) {
    if (force || chatArea.scrollHeight - chatArea.scrollTop - chatArea.clientHeight < 160) chatArea.scrollTop = chatArea.scrollHeight;
}
function toggleSidebar() {
    $('sidebar').classList.toggle('open');
    $('sidebarBackdrop').classList.toggle('show', $('sidebar').classList.contains('open'));
}
function closeSidebar() {
    $('sidebar').classList.remove('open');
    $('sidebarBackdrop').classList.remove('show');
}

function isLocalUrl(u) {
    try {
        const h = new URL(u).hostname.toLowerCase();
        return ['localhost', '127.0.0.1', '0.0.0.0', '::1'].includes(h);
    } catch { return false; }
}

function syncBrandingUI() {
    const ai = appSettings.ai_name || 'BISWAJIT AI';
    const md = appSettings.model_name || '';
    const configured = Boolean(appSettings.api_key && appSettings.api_key.trim() !== '') || isLocalUrl(appSettings.api_url || '');

    document.title = ai;
    $('brandAiName').textContent    = ai;
    $('brandModelName').textContent = md;
    $('headerAiName').textContent   = '🤖 ' + ai;
    $('headerModelTag').textContent = md;
    $('emptyAiName').textContent    = ai;

    const badge = $('sbConfigBadge');
    badge.textContent = configured ? 'Configured' : 'Set API Key';
    badge.className   = configured ? 'cfg-ok' : 'cfg-note';

    $('setupBanner').style.display = configured ? 'none' : 'flex';
}

function syncStatsUI() {
    if (!appStats) return;
    $('sbChats').textContent = appStats.total_chats ?? 0;
    $('sbMsgs').textContent  = appStats.total_messages ?? 0;

    $('stTotalChats').textContent   = appStats.total_chats ?? 0;
    $('stTotalMsgs').textContent    = appStats.total_messages ?? 0;
    $('stTotalReplies').textContent = appStats.total_replies ?? 0;
    $('stTotalFiles').textContent   = appStats.total_files ?? 0;

    $('profileSummary').innerHTML =
        `<div><b>User:</b> ${esc(appStats.user_name || '')} (${esc(appStats.user_email || '')})</div>` +
        `<div><b>Member Since:</b> ${esc(appStats.member_since || '-')}</div>` +
        `<div><b>Last Chat Activity:</b> ${esc(appStats.last_chat_at || 'No chats yet')}</div>` +
        `<div><b>Active AI Name:</b> ${esc(appSettings.ai_name || '')} · <b>Model:</b> <code>${esc(appSettings.model_name || '')}</code></div>` +
        `<div style="word-break:break-all"><b>API Endpoint:</b> <code>${esc(appSettings.api_url || '')}</code></div>`;
}

// ================= SIDEBAR CHATS =================
async function loadChatList() {
    try {
        const res = await fetch('api.php?action=list_chats', { headers: rtHeaders() });
        const data = await res.json();
        if (!res.ok) return;
        allChats = data.chats || [];
        if (data.stats) { appStats = data.stats; syncStatsUI(); }
        renderChatList();
    } catch (e) { console.error(e); }
}

function renderChatList() {
    const q = (chatSearch.value || '').trim().toLowerCase();
    const list = q ? allChats.filter(c => (c.title || '').toLowerCase().includes(q)) : allChats;
    chatList.innerHTML = '';

    if (!list.length) {
        chatList.innerHTML = '<div class="chat-empty-hint">' + (q ? 'No matching chats' : 'No chats yet') + '</div>';
        return;
    }

    list.forEach(c => {
        const div = el('div', 'chat-item' + (c.id == currentChatId ? ' active' : ''),
            '<div style="flex:1;min-width:0"><span class="t"></span><span class="d"></span></div>' +
            '<div class="acts">' +
                '<button class="act-btn ren" title="Rename">✏️</button>' +
                '<button class="act-btn del" title="Delete">🗑</button>' +
            '</div>');
        div.querySelector('.t').textContent = c.title;
        div.querySelector('.d').textContent = (c.message_count || 0) + ' msgs · ' + (c.updated_at || '');

        div.querySelector('.ren').onclick = async e => {
            e.stopPropagation();
            const nt = prompt('Enter new chat title:', c.title);
            if (!nt || !nt.trim()) return;
            await fetch('api.php?action=rename_chat', {
                method: 'POST',
                headers: rtHeaders({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ chat_id: c.id, title: nt.trim() })
            });
            loadChatList();
        };

        div.querySelector('.del').onclick = async e => {
            e.stopPropagation();
            if (!confirm('Delete this chat permanently?')) return;
            await fetch('api.php?action=delete_chat', {
                method: 'POST',
                headers: rtHeaders({ 'Content-Type': 'application/x-www-form-urlencoded' }),
                body: 'chat_id=' + c.id
            });
            if (c.id == currentChatId) newChat();
            loadChatList();
        };

        div.onclick = () => openChat(c.id);
        chatList.appendChild(div);
    });
}
chatSearch.addEventListener('input', renderChatList);

async function openChat(id) {
    if (isSending) return;
    currentChatId = id;
    chatArea.innerHTML = '';
    emptyState.style.display = 'none';
    const data = await (await fetch('api.php?action=get_messages&chat_id=' + id, { headers: rtHeaders() })).json();
    (data.messages || []).forEach(m => {
        if (m.role === 'assistant') {
            const ui = makeAI(m.model_used);
            renderAI(ui, m.content, false);
        } else {
            addUser(m.content, m.attachments);
        }
    });
    scrollToBottom(true);
    renderChatList();
    closeSidebar();
}

function newChat() {
    if (isSending) return;
    currentChatId = 0;
    chatArea.innerHTML = '';
    chatArea.appendChild(emptyState);
    emptyState.style.display = 'flex';
    renderChatList();
    closeSidebar();
}

// ================= SETTINGS MODAL =================
let selectedMode = appSettings.api_mode || 'custom';

function openSettings(tab = 'api') {
    closeSidebar();
    switchSettingsTab(tab);
    $('saveMsg').textContent = '';
    $('saveMsg').className   = 'save-msg';
    $('testResult').textContent = 'Test your API URL, Key, and Model before saving.';
    $('testResult').className   = 'test-result';
    $('settingsModal').classList.add('open');

    populateSettingsForm();
    fetch('api.php?action=get_settings', { headers: rtHeaders() })
        .then(r => r.json())
        .then(d => {
            if (d.settings) appSettings = d.settings;
            if (d.stats) appStats = d.stats;
            populateSettingsForm();
            syncBrandingUI();
            syncStatsUI();
        }).catch(() => {});
}

function closeSettings() {
    $('settingsModal').classList.remove('open');
}

function switchSettingsTab(tab) {
    document.querySelectorAll('.modal-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + tab));
}

function populateSettingsForm() {
    selectedMode = appSettings.api_mode || 'custom';
    document.querySelectorAll('.preset-btn').forEach(b => b.classList.toggle('active', b.dataset.mode === selectedMode));

    $('cfgApiUrl').value       = appSettings.api_url || '';
    $('cfgApiKey').value       = appSettings.api_key || '';
    $('cfgModelName').value    = appSettings.model_name || '';
    $('cfgMaxTokens').value    = appSettings.max_tokens || 12000;
    $('cfgTemperature').value  = appSettings.temperature ?? 0.7;
    $('cfgAiName').value       = appSettings.ai_name || 'BISWAJIT AI';
    $('cfgSystemPrompt').value = appSettings.system_prompt || '';
}

function applyPreset(mode) {
    selectedMode = mode;
    document.querySelectorAll('.preset-btn').forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
    const p = PRESETS[mode];
    if (p && p.url)   $('cfgApiUrl').value = p.url;
    if (p && p.model) $('cfgModelName').value = p.model;
    if (mode === 'custom') {
        $('cfgApiUrl').focus();
    }
}

function toggleKeyVis() {
    const inp = $('cfgApiKey');
    const isPw = inp.type === 'password';
    inp.type = isPw ? 'text' : 'password';
    $('keyVisBtn').textContent = isPw ? '🙈 Hide' : '👁 Show';
}

async function saveSettings() {
    const btn = $('saveSettingsBtn');
    const msg = $('saveMsg');
    btn.disabled = true;
    msg.textContent = 'Saving...';
    msg.className = 'save-msg';

    const payload = {
        ai_name:       $('cfgAiName').value.trim(),
        api_mode:      selectedMode,
        api_url:       $('cfgApiUrl').value.trim(),
        api_key:       $('cfgApiKey').value.trim(),
        model_name:    $('cfgModelName').value.trim(),
        max_tokens:    parseInt($('cfgMaxTokens').value, 10) || 12000,
        temperature:   parseFloat($('cfgTemperature').value) || 0.7,
        system_prompt: $('cfgSystemPrompt').value,
    };

    try {
        const res  = await fetch('api.php?action=save_settings', {
            method: 'POST',
            headers: rtHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Failed to save settings.');

        appSettings = data.settings;
        if (data.stats) appStats = data.stats;
        syncBrandingUI();
        syncStatsUI();
        msg.textContent = 'Settings saved!';
    } catch (e) {
        msg.textContent = e.message;
        msg.className = 'save-msg err';
    } finally {
        btn.disabled = false;
    }
}

async function testConnection() {
    const btn = $('testConnBtn');
    const out = $('testResult');
    btn.disabled = true;
    out.className = 'test-result';
    out.textContent = 'Testing connection...';

    try {
        const res = await fetch('api.php?action=test_connection', {
            method: 'POST',
            headers: rtHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                api_url:    $('cfgApiUrl').value.trim(),
                api_key:    $('cfgApiKey').value.trim(),
                model_name: $('cfgModelName').value.trim(),
            })
        });
        const d = await res.json();
        if (d.ok) {
            out.className = 'test-result ok';
            out.textContent = `Success (${d.latency_ms}ms) · Model: ${d.model} · Reply: "${d.reply}"`;
        } else {
            out.className = 'test-result fail';
            out.textContent = `Failed (${d.latency_ms || 0}ms): ${d.error}`;
        }
    } catch (e) {
        out.className = 'test-result fail';
        out.textContent = 'Error: ' + e.message;
    } finally {
        btn.disabled = false;
    }
}

async function clearAllChats() {
    if (!confirm('Permanently delete all your chats and uploaded files?')) return;
    const res = await fetch('api.php?action=clear_all_chats', { method: 'POST', headers: rtHeaders() });
    const d   = await res.json();
    if (d.stats) appStats = d.stats;
    newChat();
    loadChatList();
    syncStatsUI();
}

// ================= FILE UPLOAD =================
async function addFiles(list) {
    let files = [...list].filter(f => {
        const rel = f.webkitRelativePath || f.name;
        return OK_EXT.includes(rel.split('.').pop().toLowerCase()) && !SKIP.test(rel);
    }).slice(0, Math.max(0, 30 - pending.length));
    if (!files.length) { alert('No supported files found (max 30 files).'); return; }

    const items = files.map(f => ({ name: f.webkitRelativePath || f.name, status: 'up' }));
    pending.push(...items);
    renderPending();

    let i = 0;
    const worker = async () => {
        while (i < files.length) {
            const k = i++;
            try {
                const fd = new FormData();
                fd.append('file', files[k]);
                fd.append('relpath', items[k].name);
                const d = await (await fetch('upload.php', { method:'POST', headers: rtHeaders(), body: fd })).json();
                if (d.error) { items[k].status = 'err'; items[k].err = d.error; }
                else Object.assign(items[k], d, { status: 'ok' });
            } catch (e) { items[k].status = 'err'; items[k].err = e.message; }
            renderPending();
        }
    };
    await Promise.all([worker(), worker(), worker()]);
}

function renderPending() {
    pendingBox.style.display = pending.length ? 'flex' : 'none';
    pendingBox.innerHTML = '';
    pending.forEach((p, idx) => {
        const c = el('div', 'pchip' + (p.status === 'err' ? ' err' : ''));
        c.innerHTML = (p.status === 'up' ? '⏳' : p.status === 'err' ? '⚠️' : '📎') + ' <span class="n"></span><button>✕</button>';
        c.querySelector('.n').textContent = p.name;
        c.title = p.err || p.name;
        c.querySelector('button').onclick = () => { pending.splice(idx, 1); renderPending(); };
        pendingBox.appendChild(c);
    });
    sendBtn.disabled = isSending || pending.some(p => p.status === 'up');
}

$('attachBtn').onclick = () => fileInput.click();
$('folderBtn').onclick = () => folderInput.click();
fileInput.onchange   = () => { addFiles(fileInput.files);   fileInput.value = ''; };
folderInput.onchange = () => { addFiles(folderInput.files); folderInput.value = ''; };
mainEl.addEventListener('dragover', e => { e.preventDefault(); mainEl.classList.add('drag'); });
mainEl.querySelector('.dropzone').addEventListener('dragleave', () => mainEl.classList.remove('drag'));
mainEl.addEventListener('drop', e => { e.preventDefault(); mainEl.classList.remove('drag'); if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files); });
input.addEventListener('paste', e => { if (e.clipboardData.files.length) { e.preventDefault(); addFiles(e.clipboardData.files); } });

// ================= MESSAGE RENDERING =================
function inline(s) { return esc(s).replace(/`([^`\n]+)`/g, '<code>$1</code>').replace(/\*\*([^*\n]+)\*\*/g, '<b>$1</b>'); }
function md(t) {
    const p = t.split(/```(\w*)\n?([\s\S]*?)(?:```|$)/g);
    let h = '';
    for (let i = 0; i < p.length; i += 3) {
        h += inline(p[i]);
        if (i + 2 < p.length) h += '<pre class="code"><code>' + esc(p[i + 2]) + '</code></pre>';
    }
    return h;
}

const MS = '<<<FILE:', ME = '<<<END_FILE>>>';
function parseAI(t) {
    for (const m of [MS, ME]) for (let k = m.length - 1; k > 0; k--) if (t.endsWith(m.slice(0, k))) { t = t.slice(0, -k); break; }
    const out = []; let pos = 0;
    while (pos < t.length) {
        const s = t.indexOf(MS, pos);
        if (s < 0) { out.push({ type:'text', text:t.slice(pos) }); break; }
        if (s > pos) out.push({ type:'text', text:t.slice(pos, s) });
        const h = t.indexOf('>>>', s);
        if (h < 0) { out.push({ type:'file', name:'...', code:'', done:false }); break; }
        const name = t.slice(s + MS.length, h).trim();
        let cs = h + 3;
        if (t[cs] === '\r') cs++;
        if (t[cs] === '\n') cs++;
        const e = t.indexOf(ME, cs);
        if (e < 0) { out.push({ type:'file', name, code:t.slice(cs), done:false }); break; }
        out.push({ type:'file', name, code:t.slice(cs, e).replace(/\n$/, ''), done:true });
        pos = e + ME.length;
    }
    return out;
}

function icon(n) {
    const e = n.split('.').pop().toLowerCase();
    return ({php:'🐘', html:'🌐', htm:'🌐', css:'🎨', js:'🟨', json:'🧾', sql:'🗄️', py:'🐍'})[e] || '📄';
}
async function copyText(t) {
    try { await navigator.clipboard.writeText(t); }
    catch (e) { const a = el('textarea'); a.value = t; document.body.appendChild(a); a.select(); document.execCommand('copy'); a.remove(); }
}
function download(name, text) {
    const a = el('a'); a.href = URL.createObjectURL(new Blob([text], { type:'text/plain;charset=utf-8' }));
    a.download = name.split('/').pop(); a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

function makeCard() {
    const c = el('div', 'fcard',
        '<div class="fh"><span class="fi">📄</span><span class="fn"></span><span class="fs"></span>' +
        '<span class="fb"><button data-a="copy">Copy</button><button data-a="dl">Download</button><button data-a="tg">▾</button></span></div>' +
        '<pre><code></code></pre>');
    c._tn = document.createTextNode('');
    c.querySelector('code').appendChild(c._tn);
    c._code = ''; c._name = '';
    c.querySelector('.fb').onclick = e => {
        const a = e.target.dataset.a;
        if (a === 'copy') { copyText(c._code); e.target.textContent = 'Copied'; setTimeout(() => e.target.textContent = 'Copy', 1200); }
        else if (a === 'dl') download(c._name, c._code);
        else if (a === 'tg') c.classList.toggle('closed');
    };
    return c;
}

function updateCard(n, p, writing) {
    if (n._name !== p.name) { n._name = p.name; n.querySelector('.fn').textContent = p.name; n.querySelector('.fi').textContent = icon(p.name); }
    if (n._code !== p.code) {
        if (n._code && p.code.startsWith(n._code)) n._tn.appendData(p.code.slice(n._code.length));
        else n._tn.data = p.code;
        n._code = p.code;
    }
    n.classList.toggle('writing', writing);
    const lines = p.code ? p.code.split('\n').length : 0;
    n.querySelector('.fs').textContent = writing ? 'Writing... ' + lines + ' lines' : (p.done ? 'Ready · ' + lines + ' lines' : 'Incomplete');
    if (writing) { const pre = n.querySelector('pre'); pre.scrollTop = pre.scrollHeight; }
}

function makeAI(modelLabel = '') {
    emptyState.style.display = 'none';
    const wrap = el('div', 'msg ai'), b = el('div', 'bubble'), st = el('div', 'status'), body = el('div', 'body');
    b.append(st, body); wrap.append(b); chatArea.append(wrap);
    return { wrap, b, st, body, nodes: [], bar: null, metaRow: null, modelLabel: modelLabel || appSettings.model_name || '', fullText: '' };
}

function renderAI(ui, text, live) {
    ui.fullText = text;
    const parts = parseAI(text).filter(p => p.type === 'file' || p.text.trim() !== '');
    ui.b.classList.toggle('hasfiles', parts.some(p => p.type === 'file'));
    parts.forEach((p, i) => {
        let n = ui.nodes[i];
        if (n && n._type !== p.type) { ui.nodes.splice(i).forEach(x => x.remove()); n = null; }
        if (!n) { n = p.type === 'file' ? makeCard() : el('div', 'tx'); n._type = p.type; ui.body.append(n); ui.nodes[i] = n; }
        if (p.type === 'text') {
            const h = p.text.replace(/^\n+|\n+$/g, '');
            if (n._h !== h) { n._h = h; n.innerHTML = md(h); }
        } else updateCard(n, p, live && i === parts.length - 1 && !p.done);
    });
    while (ui.nodes.length > parts.length) ui.nodes.pop().remove();

    const last = parts[parts.length - 1], files = parts.filter(p => p.type === 'file' && p.done);
    if (live) {
        if (last) ui.st.textContent = (last.type === 'file' && !last.done) ? 'Writing ' + last.name + '...' : 'Generating reply...';
    } else {
        ui.b.classList.remove('live');
        ui.st.textContent = '';
        if (files.length) {
            if (!ui.bar) { ui.bar = el('div', 'zipbar', '<button></button>'); ui.b.append(ui.bar); }
            const btn = ui.bar.firstChild;
            btn.textContent = 'Download all ' + files.length + ' file(s) (ZIP)';
            btn.onclick = () => {
                const m = new Map(); files.forEach(f => m.set(f.name.replace(/^\/+/, ''), f.code));
                const a = el('a'); a.href = URL.createObjectURL(makeZip([...m].map(([name, code]) => ({ name, code }))));
                a.download = 'fixed-files.zip'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 3000);
            };
        }
        if (text.trim() !== '' && !ui.metaRow) {
            ui.metaRow = el('div', 'msg-meta-row');
            const left = el('span');
            left.textContent = (appSettings.ai_name || 'AI') + (ui.modelLabel ? ' · ' + ui.modelLabel : '');
            const cbtn = el('button', 'msg-copy-btn', '📋 Copy');
            cbtn.onclick = () => {
                copyText(ui.fullText);
                cbtn.textContent = 'Copied';
                setTimeout(() => cbtn.textContent = '📋 Copy', 1200);
            };
            ui.metaRow.append(left, cbtn);
            ui.b.append(ui.metaRow);
        }
    }
    scrollToBottom();
}

function addUser(text, atts) {
    emptyState.style.display = 'none';
    const wrap = el('div', 'msg user'), b = el('div', 'bubble');
    const row = el('div');
    (atts || []).forEach(a => {
        if (a.is_image && a.url) { const i = el('img', 'attached'); i.src = a.url; row.append(i); }
        else row.append(el('a', 'file-link', '📄 ' + esc(a.name)));
    });
    if (row.childNodes.length) b.append(row);
    if (text && text !== '(file)') b.append(document.createTextNode(text));
    wrap.append(b); chatArea.append(wrap); scrollToBottom(true);
}

// ================= SMOOTH TYPEWRITER STREAM =================
class Live {
    constructor(ui) { this.ui = ui; this.full = ''; this.shown = 0; this.raf = 0; this.ended = false; this.res = null; }
    push(t) { this.full += t; this.kick(); }
    kick() { if (!this.raf) this.raf = requestAnimationFrame(() => this.tick()); }
    tick() {
        this.raf = 0;
        const back = this.full.length - this.shown;
        if (back > 0) {
            this.shown = Math.min(this.full.length, this.shown + Math.max(3, Math.ceil(back / 24)));
            renderAI(this.ui, this.full.slice(0, this.shown), true);
        }
        if (this.shown < this.full.length) this.kick();
        else if (this.ended && this.res) this.res();
    }
    finish() { this.ended = true; return new Promise(r => { if (this.shown >= this.full.length) r(); else this.res = r; }); }
}

function stopStreaming() {
    if (abortCtrl) abortCtrl.abort();
}

async function streamChat(question, files, ui) {
    const live = new Live(ui);
    abortCtrl = new AbortController();

    const res = await fetch('chat.php', {
        method: 'POST',
        headers: rtHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ question, chat_id: currentChatId, files }),
        signal: abortCtrl.signal
    });
    if (!res.ok || !res.body) throw new Error('HTTP ' + res.status);

    const reader = res.body.getReader(), dec = new TextDecoder();
    let buf = '', ev = 'message', err = null;
    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buf += dec.decode(value, { stream: true });
            const lines = buf.split('\n'); buf = lines.pop();
            for (const raw of lines) {
                const line = raw.replace(/\r$/, '');
                if (line.startsWith('event:')) ev = line.slice(6).trim();
                else if (line.startsWith('data:')) {
                    let d; try { d = JSON.parse(line.slice(5).trim()); } catch (e) { continue; }
                    if (ev === 'delta') live.push(d.text);
                    else if (ev === 'status') { if (!live.full) ui.st.textContent = d.text; }
                    else if (ev === 'meta') {
                        currentChatId = d.chat_id;
                        if (d.model_name) ui.modelLabel = d.model_name;
                    }
                    else if (ev === 'warn') ui.warn = d.text;
                    else if (ev === 'error') err = d.error;
                }
            }
        }
    } catch (e) {
        if (e.name !== 'AbortError') throw e;
        ui.warn = 'Stopped by user';
    }
    await live.finish();
    if (err && !live.full) throw new Error(err);
    if (err) ui.warn = err;
    return live.full;
}

async function sendMessage() {
    const question = input.value.trim();
    const files = pending.filter(p => p.status === 'ok');
    if ((!question && !files.length) || isSending || pending.some(p => p.status === 'up')) return;

    isSending = true;
    sendBtn.style.display = 'none';
    stopBtn.style.display = 'inline-block';
    sendBtn.disabled = true;

    input.value = '';
    input.style.height = '52px';
    addUser(question, files.map(f => ({ name: f.name, is_image: f.is_image, url: f.path })));
    pending = [];
    renderPending();

    const ui = makeAI(appSettings.model_name);
    ui.b.classList.add('live');
    ui.st.textContent = 'Starting...';
    scrollToBottom(true);

    try {
        const full = await streamChat(question, files.map(f => ({ stored: f.stored, name: f.name })), ui);
        renderAI(ui, full, false);
        if (full.trim() === '' && !ui.warn) throw new Error('Empty response — Check your API URL, Key, and Model in Settings.');
        if (ui.warn) ui.body.append(el('div', 'warn', '⚠️ ' + esc(ui.warn)));
    } catch (err) {
        ui.b.classList.remove('live');
        ui.st.textContent = '';
        ui.body.append(el('div', 'err', '⚠️ ' + esc(err.message)));
    } finally {
        isSending = false;
        abortCtrl = null;
        sendBtn.style.display = 'inline-block';
        stopBtn.style.display = 'none';
        renderPending();
        input.focus();
        loadChatList();
    }
}

// ================= ZIP BUILDER =================
function crc32(b) {
    if (!crc32.t) { crc32.t = []; for (let n = 0, c; n < 256; n++) { c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; crc32.t[n] = c; } }
    let crc = -1; for (let i = 0; i < b.length; i++) crc = crc32.t[(crc ^ b[i]) & 255] ^ (crc >>> 8);
    return (crc ^ -1) >>> 0;
}
function makeZip(files) {
    const enc = new TextEncoder(), chunks = [], cen = []; let off = 0;
    const u16 = n => [n & 255, (n >> 8) & 255], u32 = n => [n & 255, (n >> 8) & 255, (n >> 16) & 255, (n >>> 24) & 255];
    for (const f of files) {
        const nb = enc.encode(f.name), db = enc.encode(f.code), crc = crc32(db);
        const lh = new Uint8Array([0x50,0x4b,3,4, 20,0, 0,8, 0,0, 0,0, 0x21,0, ...u32(crc), ...u32(db.length), ...u32(db.length), ...u16(nb.length), 0,0]);
        chunks.push(lh, nb, db);
        cen.push(new Uint8Array([0x50,0x4b,1,2, 20,0, 20,0, 0,8, 0,0, 0,0, 0x21,0, ...u32(crc), ...u32(db.length), ...u32(db.length), ...u16(nb.length), 0,0,0,0,0,0,0,0,0,0,0,0, ...u32(off)]), nb);
        off += lh.length + nb.length + db.length;
    }
    const cs = cen.reduce((a, b) => a + b.length, 0);
    const end = new Uint8Array([0x50,0x4b,5,6, 0,0, 0,0, ...u16(files.length), ...u16(files.length), ...u32(cs), ...u32(off), 0,0]);
    return new Blob([...chunks, ...cen, end], { type:'application/zip' });
}

sendBtn.addEventListener('click', sendMessage);
input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } });
input.addEventListener('input', () => { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 180) + 'px'; });

syncBrandingUI();
syncStatsUI();
input.focus();
loadChatList();
</script>
<?php endif; ?>

</body>
</html>
