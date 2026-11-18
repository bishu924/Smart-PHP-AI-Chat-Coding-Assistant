# Smart PHP AI Chat & Coding Assistant

A fast, lightweight, **100% Pure PHP + SQLite** AI chat and coding assistant web application. Built for simplicity and speed—**no Node.js, no TypeScript, no npm, no Composer, no CLI tools, and no complex build steps required.**

Upload the files to any PHP server (or run locally in 30 seconds), open your browser, create an account, and configure your own **Custom API Endpoint, API Key, Model Name, and AI Brand Name** directly from the built-in **Settings** menu—without touching a single line of code.

---

## Why Developers & Beginners Love This Project

- **Zero Build Tools Required:** Pure PHP, Vanilla JavaScript, CSS, and SQLite. Works out of the box on XAMPP, Laragon, WAMP, cPanel shared hosting, VPS, or PHP's built-in server.
- **No Code Editing Needed:** Configure everything from the bottom-left **Settings** panel in your browser.
- **Custom API & Multi-Provider Support:** Switch away from OpenRouter anytime or use any OpenAI-compatible endpoint:
  - **Custom API Endpoint URL** (paste any `/v1/chat/completions` compatible link)
  - **OpenRouter**
  - **OpenAI**
  - **Groq**
  - **Google Gemini (OpenAI-Compatible Endpoint)**
  - **Ollama / LM Studio (Local AI)**
- **Live Connection Tester:** Test your API Endpoint Link, API Key, and Model Name with real latency measurement before saving.
- **Custom AI Name & System Prompt:** Rename the assistant (default `BISWAJIT AI`) to your own brand or personal name directly from Settings.
- **Real-Time SSE Streaming & Stop Button:** Smooth token-by-token streaming with an instant **Stop** button and automatic non-streaming JSON fallback for custom endpoints.
- **Built-In Coding Agent Mode:** Upload individual files, drag-and-drop, paste images, or upload an **entire project folder** (up to 30 files at once). AI-generated files stream into interactive code cards with **Copy**, **Single Download**, and **Download All as ZIP**.
- **Multi-User Accounts & Usage Statistics:** Each user has private chat history, search, rename/delete controls, and live usage metrics (Total Chats, Messages Sent, AI Replies, and Uploaded Files).

---

## Requirements

- **PHP 7.4 or PHP 8.x** (PHP 8.0+ recommended)
- **PHP Extensions** (enabled by default on almost all hosting/XAMPP setups):
  - `pdo_sqlite`
  - `curl`
  - `json`
- **No external database server required** (SQLite database `data/chat.db` is created automatically).

---

## Project Structure

```text
├── app.php        # Main UI (Login/Register, Chat Workspace, File Cards & Settings Modal)
├── api.php        # JSON API (Chat history, Settings CRUD, User Stats & Connection Tester)
├── chat.php       # Real-time SSE Streaming Engine (Custom API / OpenRouter + Vision + Files)
├── auth.php       # User Authentication (Register, Login, Logout)
├── upload.php     # Secure multi-file and folder upload handler
├── db.php         # SQLite auto-setup, schema migrations & per-user settings helpers
├── config.php     # Default constants, upload limits & dynamic system prompt builder
└── README.md      # Documentation
```

> `data/` and `uploads/` folders are created automatically on first run.

---

## Step-by-Step Installation Guide (Beginner Friendly)

Choose whichever method fits your setup:

### Option 1: Run on Your PC in 30 Seconds (Using PHP Built-In Server)

1. **Download this repository:**
   - Click the green **`<> Code`** button at the top of this GitHub page and select **Download ZIP**, then extract the folder.
   - Or clone with Git:
     ```bash
     git clone https://github.com/bishu924/Smart-PHP-AI-Chat-Coding-Assistant.git
     cd Smart-PHP-AI-Chat-Coding-Assistant
     ```
2. **Start the PHP server:**
   Open Terminal / Command Prompt inside the extracted folder and run:
   ```bash
   php -S localhost:8000
   ```
3. **Open in your browser:**
   Visit:
   ```text
   http://localhost:8000/app.php
   ```
4. **Register your account** and start chatting!

---

### Option 2: Run Locally with XAMPP / Laragon / WAMP (Windows & macOS)

1. Download and extract the project files into your web root folder:
   - **XAMPP:** `C:\xampp\htdocs\ai-chat\`
   - **Laragon:** `C:\laragon\www\ai-chat\`
   - **WAMP:** `C:\wamp64\www\ai-chat\`
2. Start **Apache** from your XAMPP / Laragon / WAMP control panel.
3. Make sure `curl` and `pdo_sqlite` are enabled in `php.ini` (they are enabled by default in most installations).
4. Open your browser and go to:
   ```text
   http://localhost/ai-chat/app.php
   ```
5. Click **Register**, create your account, and open **Settings**.

---

### Option 3: Deploy on cPanel Shared Hosting

1. Log in to your **cPanel** dashboard and open **File Manager**.
2. Go to `public_html/` (or create a subfolder like `public_html/ai/`).
3. Upload the project ZIP file and click **Extract**.
4. Ensure PHP version is set to **PHP 8.0+** in **Select PHP Version / MultiPHP Manager** with `pdo_sqlite` and `curl` enabled.
5. Visit `https://yourdomain.com/app.php` (or `https://yourdomain.com/ai/app.php`).
6. Register your account and configure your API inside the UI!

---

### Option 4: Deploy on a Linux VPS (Ubuntu / Debian + Nginx or Apache)

1. Install PHP and required extensions:
   ```bash
   sudo apt update
   sudo apt install -y php php-sqlite3 php-curl php-cli
   ```
2. Place the files in `/var/www/html/ai-chat` and ensure write permissions for SQLite and file uploads:
   ```bash
   sudo mkdir -p /var/www/html/ai-chat/data /var/www/html/ai-chat/uploads
   sudo chown -R www-data:www-data /var/www/html/ai-chat/data /var/www/html/ai-chat/uploads
   sudo chmod -R 775 /var/www/html/ai-chat/data /var/www/html/ai-chat/uploads
   ```
3. For **Nginx**, ensure PHP FastCGI buffering is disabled so real-time streaming works smoothly (`chat.php` also sends `X-Accel-Buffering: no`).

---

## How to Configure Your API & AI Name (No Code Required)

Once logged in:

1. Click **`Settings`** at the bottom-left of the sidebar (or top-right header).
2. Under the **Custom API & Model** tab:
   - Pick a quick preset (**Custom API**, **OpenRouter**, **OpenAI**, **Groq**, **Gemini (Compat)**, or **Ollama Local**).
   - Paste your **API Endpoint Link** (e.g., `https://api.openai.com/v1/chat/completions` or any custom OpenAI-compatible URL).
   - Paste your **API Key** (leave blank if running local Ollama).
   - Enter your **Model Name** (e.g., `gpt-4o-mini`, `llama-3.3-70b-versatile`, `deepseek/deepseek-chat`, `qwen2.5-coder:7b`).
   - Adjust **Max Tokens** and **Temperature** if desired.
   - Click **`Test Connection`** to verify everything works, then click **`Save Settings`**.
3. Under the **AI Name & Prompt** tab:
   - Change the **AI Assistant Display Name** to anything you want (updates the sidebar, header, page title, and identity replies).
   - Add optional **Custom System Instructions**.
4. Under the **Usage & Chat Stats** tab:
   - View your account's total chats, messages sent, AI replies, uploaded files, and clear chat history anytime.

---

## Using Coding Mode & Folder Uploads

- Click the **paperclip icon** to attach multiple files, or the **folder icon** to upload an entire project folder (automatically skips `node_modules`, `vendor`, `.git`, `dist`, and `build`).
- You can also **drag and drop** files directly onto the chat window or **paste** screenshots from your clipboard.
- When asking the AI to fix or generate code, files render in live-updating code cards with:
  - **Copy** button per file
  - **Download** button per file
  - **Download all files (ZIP)** button when multiple files are generated

---

## Troubleshooting

- **Permission error creating SQLite DB or uploading files:**
  Make sure the web server user (`www-data`, `apache`, or `nobody`) has write permissions on the `data/` and `uploads/` directories (`chmod 775`).
- **API Test fails with cURL error:**
  Verify that the `php-curl` extension is enabled in `php.ini` and your hosting provider allows outbound HTTPS requests.
- **Streaming appears all at once instead of word-by-word:**
  Disable output gzip compression/proxy buffering for `chat.php` if using Cloudflare or a reverse proxy.

---

## Author & Support

Created and maintained by **Biswajit Chakraborty**

- **Email:** [biswajitofficial@zohomail.in](mailto:biswajitofficial@zohomail.in)
- **Buy Me a Coffee:** [Support My Work](https://sites.google.com/view/buymeacoffy/home)

If this project helped you save time or build something cool:
1. Give this repository a **Star** on GitHub so others can discover it!
2. Feel free to **Fork** it or open an **Issue** for suggestions.
3. If you're happy with the project, you can gift me a cup of coffee here:
   **https://sites.google.com/view/buymeacoffy/home**

---

## License

This project is open-source and free to use for personal and educational projects. Attribution and header credit are appreciated.
