<?php
// Professional PHP Bot Hosting Panel - Complete Fixed Version
// File: host.php

// Configuration
define('BOT_TOKEN', '8773262722:AAHwMYviIe2c4ghzy9aOjSjqL5dLYy5y_RE');
define('HOST_URL', 'https://ashupanel.online/host');
define('OWNER_ID', 6068408280);
define('OWNER_USERNAME', '@Nobitaaa001');
define('CHANNEL_LINK', 'https://t.me/+E2JvqlvS3URkZjFl');
define('MAX_BOTS_PER_USER', 10);
define('MAX_ZIP_SIZE', 50 * 1024 * 1024); // 50MB
define('VERSION', '2.0.1');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

// Start Session
if (session_status() == PHP_SESSION_NONE) session_start();

// Create Directories
$dirs = ['bots', 'temp', 'logs', 'backups', 'users_data'];
foreach ($dirs as $dir) {
    if (!file_exists($dir)) mkdir($dir, 0777, true);
}

// Database Class
class Database {
    private $db;
    private $dbFile = 'bot_hosting.db';
    
    public function __construct() {
        try {
            $this->db = new SQLite3($this->dbFile);
            $this->db->enableExceptions(true);
            $this->initTables();
        } catch (Exception $e) {
            $this->log("Database Error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function log($message, $type = 'INFO') {
        $logFile = 'logs/' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] [$type] $message\n", FILE_APPEND);
    }
    
    private function initTables() {
        // Users Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            username TEXT,
            first_name TEXT,
            last_name TEXT,
            is_banned INTEGER DEFAULT 0,
            is_admin INTEGER DEFAULT 0,
            total_bots INTEGER DEFAULT 0,
            total_uploads INTEGER DEFAULT 0,
            joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_active DATETIME
        )");
        
        // Bots Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS bots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            bot_token TEXT,
            bot_name TEXT,
            bot_username TEXT,
            file_path TEXT,
            webhook_url TEXT,
            status TEXT DEFAULT 'stopped',
            is_active INTEGER DEFAULT 0,
            start_count INTEGER DEFAULT 0,
            last_started DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id)
        )");
        
        // Temp Data Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS temp_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            data TEXT,
            type TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Statistics Table
        $this->db->exec("CREATE TABLE IF NOT EXISTS stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date DATE UNIQUE,
            total_users INTEGER DEFAULT 0,
            total_bots INTEGER DEFAULT 0,
            active_bots INTEGER DEFAULT 0,
            total_uploads INTEGER DEFAULT 0,
            total_downloads INTEGER DEFAULT 0
        )");
        
        // System Settings
        $this->db->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Activity Logs
        $this->db->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            details TEXT,
            ip TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Insert default settings
        $this->db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES 
            ('system_locked', '0'),
            ('maintenance_mode', '0'),
            ('total_uploads', '0'),
            ('total_bots', '0'),
            ('total_users', '0'),
            ('welcome_message', 'Welcome to Bot Hosting'),
            ('theme_color', '#6a11cb')");
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            return $stmt->execute();
        } catch (Exception $e) {
            $this->log("Query Error: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    public function getRow($sql, $params = []) {
        $result = $this->query($sql, $params);
        return $result ? $result->fetchArray(SQLITE3_ASSOC) : null;
    }
    
    public function getAll($sql, $params = []) {
        $result = $this->query($sql, $params);
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }
    
    public function logActivity($user_id, $action, $details = '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $this->query("INSERT INTO activity_logs (user_id, action, details, ip) VALUES (:uid, :action, :details, :ip)",
                    [':uid' => $user_id, ':action' => $action, ':details' => $details, ':ip' => $ip]);
    }
}

// Telegram Bot Class
class TelegramBot {
    private $token;
    private $api_url;
    private $db;
    
    public function __construct($token) {
        $this->token = $token;
        $this->api_url = "https://api.telegram.org/bot{$token}/";
        $this->db = new Database();
    }
    
    public function handleWebhook() {
        $content = file_get_contents("php://input");
        file_put_contents('logs/webhook.log', date('Y-m-d H:i:s') . " - " . $content . "\n", FILE_APPEND);
        
        $update = json_decode($content, true);
        
        if (!$update) return;
        
        if (isset($update["message"])) {
            $this->handleMessage($update["message"]);
        } elseif (isset($update["callback_query"])) {
            $this->handleCallback($update["callback_query"]);
        }
    }
    
    private function handleMessage($message) {
        $chat_id = $message["chat"]["id"];
        $user_id = $message["from"]["id"];
        $username = $message["from"]["username"] ?? "";
        $first_name = $message["from"]["first_name"] ?? "";
        $last_name = $message["from"]["last_name"] ?? "";
        
        file_put_contents('logs/messages.log', date('Y-m-d H:i:s') . " - From: $user_id - " . json_encode($message) . "\n", FILE_APPEND);
        
        // Check system lock
        $settings = $this->db->getRow("SELECT value FROM settings WHERE key = 'system_locked'");
        if ($settings && $settings['value'] == '1' && $user_id != OWNER_ID) {
            if (isset($message["text"])) {
                $this->sendMessage($chat_id, "🔒 *System Under Maintenance*\n\nOnly owner can use the bot right now.", "Markdown");
            }
            return;
        }
        
        // Save/Update User
        $this->db->query("INSERT OR REPLACE INTO users (user_id, username, first_name, last_name, last_active) 
                         VALUES (:uid, :un, :fn, :ln, datetime('now'))",
                         [':uid' => $user_id, ':un' => $username, ':fn' => $first_name, ':ln' => $last_name]);
        
        // Check ban
        $user = $this->db->getRow("SELECT is_banned FROM users WHERE user_id = :uid", [':uid' => $user_id]);
        if ($user && $user['is_banned'] == 1) {
            $this->sendMessage($chat_id, "🚫 *You are banned from using this bot!*", "Markdown");
            return;
        }
        
        if (isset($message["text"])) {
            $text = $message["text"];
            
            // Handle commands
            if ($text == "/start") {
                $this->sendWelcomeMessage($chat_id, $first_name, $username);
            }
            elseif ($text == "/newbot") {
                $this->startNewBot($chat_id, $user_id);
            }
            elseif ($text == "/mybots") {
                $this->showMyBots($chat_id, $user_id);
            }
            elseif ($text == "/help") {
                $this->sendHelpMessage($chat_id);
            }
            elseif (strpos($text, "/token") === 0) {
                $this->handleToken($chat_id, $user_id, $text);
            }
            elseif ($text == "/channel") {
                $this->sendChannelInfo($chat_id);
            }
            elseif ($text == "/profile") {
                $this->showUserProfile($chat_id, $user_id);
            }
            elseif ($text == "/stats") {
                $this->showUserStats($chat_id, $user_id);
            }
            elseif ($text == "/admin" && $user_id == OWNER_ID) {
                $this->showAdminPanel($chat_id);
            }
            elseif ($text == "/users" && $user_id == OWNER_ID) {
                $this->showAllUsers($chat_id);
            }
            elseif ($text == "/lock" && $user_id == OWNER_ID) {
                $this->toggleSystemLock($chat_id);
            }
            elseif (strpos($text, "/ban") === 0 && $user_id == OWNER_ID) {
                $this->banUser($chat_id, $text);
            }
            elseif (strpos($text, "/unban") === 0 && $user_id == OWNER_ID) {
                $this->unbanUser($chat_id, $text);
            }
            // ✅ FIX: Handle normal text messages (like token without /token command)
            elseif (preg_match('/^\d+:[A-Za-z0-9_-]+$/', $text)) {
                $this->handleToken($chat_id, $user_id, "/token " . $text);
            }
            else {
                $this->sendMessage($chat_id, "❌ Unknown command!\nUse /help to see available commands.", "Markdown");
            }
        } 
        elseif (isset($message["document"])) {
            $this->handleFileUpload($message, $user_id, $chat_id);
        }
    }
    
    private function handleCallback($callback) {
        $data = $callback["data"];
        $message = $callback["message"];
        $chat_id = $message["chat"]["id"];
        $user_id = $callback["from"]["id"];
        $message_id = $message["message_id"];
        
        file_put_contents('logs/callbacks.log', date('Y-m-d H:i:s') . " - Callback: $data from $user_id\n", FILE_APPEND);
        
        $this->answerCallback($callback["id"]);
        
        // Check if user is banned
        $user = $this->db->getRow("SELECT is_banned FROM users WHERE user_id = :uid", [':uid' => $user_id]);
        if ($user && $user['is_banned'] == 1 && $data != 'help') {
            $this->sendMessage($chat_id, "🚫 *You are banned from using this bot!*", "Markdown");
            return;
        }
        
        // Bot Management
        if (strpos($data, "start_") === 0) {
            $bot_id = str_replace("start_", "", $data);
            $this->startBot($chat_id, $user_id, $bot_id, $message_id);
        }
        elseif (strpos($data, "stop_") === 0) {
            $bot_id = str_replace("stop_", "", $data);
            $this->stopBot($chat_id, $user_id, $bot_id, $message_id);
        }
        elseif (strpos($data, "restart_") === 0) {
            $bot_id = str_replace("restart_", "", $data);
            $this->restartBot($chat_id, $user_id, $bot_id, $message_id);
        }
        elseif (strpos($data, "delete_") === 0) {
            $bot_id = str_replace("delete_", "", $data);
            $this->confirmDeleteBot($chat_id, $user_id, $bot_id, $message_id);
        }
        elseif (strpos($data, "confirm_delete_") === 0) {
            $bot_id = str_replace("confirm_delete_", "", $data);
            $this->deleteBot($chat_id, $user_id, $bot_id, $message_id);
        }
        elseif (strpos($data, "info_") === 0) {
            $bot_id = str_replace("info_", "", $data);
            $this->showBotInfo($chat_id, $user_id, $bot_id, $message_id);
        }
        elseif (strpos($data, "logs_") === 0) {
            $bot_id = str_replace("logs_", "", $data);
            $this->showBotLogs($chat_id, $user_id, $bot_id, $message_id);
        }
        elseif ($data == "mybots") {
            $this->showMyBots($chat_id, $user_id, $message_id);
        }
        elseif ($data == "newbot") {
            $this->startNewBot($chat_id, $user_id, $message_id);
        }
        elseif ($data == "help") {
            $this->sendHelpMessage($chat_id, $message_id);
        }
        elseif ($data == "myprofile") {
            $this->showUserProfile($chat_id, $user_id, $message_id);
        }
        elseif ($data == "mystats") {
            $this->showUserStats($chat_id, $user_id, $message_id);
        }
        elseif ($data == "cancel") {
            $this->db->query("DELETE FROM temp_data WHERE user_id = :uid", [':uid' => $user_id]);
            $this->editMessage($chat_id, $message_id, "✅ Action cancelled!", $this->getMainKeyboard($user_id));
        }
        elseif ($data == "main_menu") {
            $this->sendWelcomeMessage($chat_id, $user_id, $message_id);
        }
        
        // Admin Callbacks
        elseif ($data == "admin_panel" && $user_id == OWNER_ID) {
            $this->showAdminPanel($chat_id, $message_id);
        }
        elseif ($data == "admin_stats" && $user_id == OWNER_ID) {
            $this->showDetailedStats($chat_id, $message_id);
        }
        elseif ($data == "admin_users" && $user_id == OWNER_ID) {
            $this->showAllUsers($chat_id, $message_id);
        }
        elseif ($data == "admin_toggle_lock" && $user_id == OWNER_ID) {
            $this->toggleSystemLock($chat_id, $message_id);
        }
        elseif ($data == "admin_logs" && $user_id == OWNER_ID) {
            $this->showSystemLogs($chat_id, $message_id);
        }
        elseif ($data == "admin_backup" && $user_id == OWNER_ID) {
            $this->createBackup($chat_id, $message_id);
        }
        elseif (strpos($data, "ban_user_") === 0 && $user_id == OWNER_ID) {
            $target_id = str_replace("ban_user_", "", $data);
            $this->banUserById($chat_id, $target_id, $message_id);
        }
        elseif (strpos($data, "unban_user_") === 0 && $user_id == OWNER_ID) {
            $target_id = str_replace("unban_user_", "", $data);
            $this->unbanUserById($chat_id, $target_id, $message_id);
        }
        elseif (strpos($data, "view_user_") === 0 && $user_id == OWNER_ID) {
            $target_id = str_replace("view_user_", "", $data);
            $this->viewUserDetails($chat_id, $target_id, $message_id);
        }
        elseif ($data == "refresh_users" && $user_id == OWNER_ID) {
            $this->showAllUsers($chat_id, $message_id);
        }
    }
    
    private function getMainKeyboard($user_id = null) {
        $keyboard = [
            "inline_keyboard" => [
                [
                    ["text" => "🚀 NEW BOT", "callback_data" => "newbot"],
                    ["text" => "📋 MY BOTS", "callback_data" => "mybots"]
                ],
                [
                    ["text" => "📊 STATS", "callback_data" => "mystats"],
                    ["text" => "👤 PROFILE", "callback_data" => "myprofile"]
                ],
                [
                    ["text" => "📢 CHANNEL", "url" => CHANNEL_LINK],
                    ["text" => "❓ HELP", "callback_data" => "help"]
                ]
            ]
        ];
        
        // Add admin button for owner
        if ($user_id == OWNER_ID) {
            $keyboard["inline_keyboard"][] = [
                ["text" => "👑 ADMIN PANEL", "callback_data" => "admin_panel"]
            ];
        }
        
        return $keyboard;
    }
    
    private function sendWelcomeMessage($chat_id, $first_name, $username, $message_id = null) {
        $user_id = $chat_id;
        $total_users = $this->db->getRow("SELECT COUNT(*) as count FROM users")['count'];
        $total_bots = $this->db->getRow("SELECT COUNT(*) as count FROM bots")['count'];
        $active_bots = $this->db->getRow("SELECT COUNT(*) as count FROM bots WHERE status = 'running'")['count'];
        $your_bots = $this->db->getRow("SELECT COUNT(*) as count FROM bots WHERE user_id = :uid", [':uid' => $user_id])['count'];
        
        $text = "╔══════════════════════════════╗\n";
        $text .= "║    🤖 PHP BOT HOSTING PRO    ║\n";
        $text .= "║    Professional Bot Panel    ║\n";
        $text .= "╚══════════════════════════════╝\n\n";
        $text .= "🌟 *Welcome, {$first_name}!*\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "📊 *System Statistics*\n";
        $text .= "├ Total Users: `{$total_users}`\n";
        $text .= "├ Total Bots: `{$total_bots}`\n";
        $text .= "├ Active Bots: `{$active_bots}`\n";
        $text .= "└ Version: `" . VERSION . "`\n\n";
        $text .= "👤 *Your Statistics*\n";
        $text .= "├ Your Bots: `{$your_bots}`\n";
        $text .= "└ Remaining: `" . (MAX_BOTS_PER_USER - $your_bots) . "`\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "✨ *Powered by @ASHUSHARMA_JIBOT*";
        
        if ($message_id) {
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
        } else {
            $this->sendMessage($chat_id, $text, "Markdown", $this->getMainKeyboard($user_id));
        }
        
        $this->db->logActivity($user_id, 'start_bot', 'User started the bot');
    }
    
    private function startNewBot($chat_id, $user_id, $message_id = null) {
        // Check bot limit
        $bot_count = $this->db->getRow("SELECT COUNT(*) as count FROM bots WHERE user_id = :uid", [':uid' => $user_id])['count'];
        
        if ($bot_count >= MAX_BOTS_PER_USER && $user_id != OWNER_ID) {
            $text = "╔══════════════════════════╗\n";
            $text .= "║   ❌ LIMIT REACHED       ║\n";
            $text .= "╚══════════════════════════╝\n\n";
            $text .= "You can only have *" . MAX_BOTS_PER_USER . "* bots maximum.\n";
            $text .= "Current bots: *{$bot_count}*\n\n";
            $text .= "Delete old bots to add new ones.";
            
            $keyboard = [
                "inline_keyboard" => [
                    [["text" => "📋 MANAGE BOTS", "callback_data" => "mybots"]]
                ]
            ];
            
            if ($message_id) {
                $this->editMessage($chat_id, $message_id, $text, $keyboard);
            } else {
                $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
            }
            return;
        }
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   🤖 DEPLOY NEW BOT     ║\n";
        $text .= "║   Step 1/3 - Token      ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "Please send me your bot token:\n\n";
        $text .= "📌 *Format:*\n";
        $text .= "`1234567890:ABCdefGHIjklMNOpqrsTUVwxyz`\n\n";
        $text .= "📌 *How to get token:*\n";
        $text .= "1. Open @BotFather\n";
        $text .= "2. Create new bot or use existing\n";
        $text .= "3. Copy the token\n\n";
        $text .= "⏳ *Note:* Token valid for 1 hour only";
        
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "❌ CANCEL", "callback_data" => "cancel"]],
                [["text" => "◀️ BACK", "callback_data" => "main_menu"]]
            ]
        ];
        
        if ($message_id) {
            $this->editMessage($chat_id, $message_id, $text, $keyboard);
        } else {
            $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
        }
    }
    
    // ✅ FIXED: handleToken function properly
    private function handleToken($chat_id, $user_id, $text) {
        // Log for debugging
        file_put_contents('logs/token.log', date('Y-m-d H:i:s') . " - Token received: $text from $user_id\n", FILE_APPEND);
        
        $parts = explode(" ", $text);
        $token = '';
        
        if (count($parts) == 2) {
            $token = trim($parts[1]);
        } elseif (count($parts) == 1) {
            // Direct token without /token command
            $token = trim($parts[0]);
        } else {
            $this->sendMessage($chat_id, "❌ *Invalid Format!*\nSend token like:\n`1234567890:ABCdefGHIjklMNOpqrsTUVwxyz`", "Markdown");
            return;
        }
        
        // Validate token
        if (!preg_match('/^\d+:[\w-]+$/', $token)) {
            $this->sendMessage($chat_id, "❌ *Invalid Token Format!*\n\nToken format: `1234567890:ABCdefGHIjklMNOpqrsTUVwxyz`", "Markdown");
            return;
        }
        
        // Send processing message
        $this->sendMessage($chat_id, "🔄 *Verifying token...*", "Markdown");
        
        // Verify token
        $bot_info = $this->getBotInfo($token);
        if (!$bot_info || !isset($bot_info['ok']) || !$bot_info['ok']) {
            $this->sendMessage($chat_id, "❌ *Invalid Token!*\n\nToken verify nahi ho saka.\nCheck karo ki token sahi hai ya nahi.", "Markdown");
            return;
        }
        
        // Check if token already used
        $existing = $this->db->getRow("SELECT * FROM bots WHERE bot_token = :token", [':token' => $token]);
        if ($existing) {
            $this->sendMessage($chat_id, "❌ *Token Already Used!*\n\nYeh token already kisi aur bot ke liye use ho raha hai.", "Markdown");
            return;
        }
        
        // Save to temp - ✅ FIXED: Remove UNIQUE constraint issue
        // First delete any existing temp data for this user
        $this->db->query("DELETE FROM temp_data WHERE user_id = :uid", [':uid' => $user_id]);
        
        // Then insert new
        $this->db->query("INSERT INTO temp_data (user_id, data, type) VALUES (:uid, :token, 'bot_token')",
                        [':uid' => $user_id, ':token' => $token]);
        
        $bot_name = $bot_info['result']['first_name'] ?? 'Unknown';
        $bot_username = $bot_info['result']['username'] ?? 'unknown';
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   ✅ TOKEN VERIFIED     ║\n";
        $text .= "║   Step 2/3 - Upload     ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "📊 *Bot Details:*\n";
        $text .= "├ Name: `{$bot_name}`\n";
        $text .= "├ Username: @{$bot_username}\n";
        $text .= "└ Token: `" . substr($token, 0, 10) . "...`\n\n";
        $text .= "📎 *Upload your bot file:*\n";
        $text .= "• Send `.php` file\n";
        $text .= "• Max size: 10MB\n";
        $text .= "• ZIP files supported (auto-extract)\n\n";
        $text .= "⏳ *You have 1 hour to upload*";
        
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "❌ CANCEL", "callback_data" => "cancel"]],
                [["text" => "◀️ BACK", "callback_data" => "newbot"]]
            ]
        ];
        
        $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
        $this->db->logActivity($user_id, 'token_verified', "Token for @$bot_username");
    }
    
    private function handleFileUpload($message, $user_id, $chat_id) {
        // ✅ FIX: Check for temp data first
        $temp = $this->db->getRow("SELECT data FROM temp_data WHERE user_id = :uid AND type = 'bot_token'", [':uid' => $user_id]);
        
        if (!$temp) {
            $this->sendMessage($chat_id, "❌ *No active session!*\n\nPehle /newbot karo aur token bhejo.", "Markdown");
            return;
        }
        
        $document = $message["document"];
        $file_name = $document["file_name"];
        $file_size = $document["file_size"];
        $file_id = $document["file_id"];
        $mime_type = $document["mime_type"] ?? '';
        
        $token = $temp['data'];
        
        // Check file type
        $is_zip = ($mime_type == 'application/zip' || $mime_type == 'application/x-zip-compressed' || pathinfo($file_name, PATHINFO_EXTENSION) == 'zip');
        $is_php = (pathinfo($file_name, PATHINFO_EXTENSION) == 'php');
        
        if (!$is_php && !$is_zip) {
            $this->sendMessage($chat_id, "❌ *Invalid file type!*\n\nSirf PHP ya ZIP files allowed hain.", "Markdown");
            return;
        }
        
        // Check size
        $max_size = $is_zip ? MAX_ZIP_SIZE : 10 * 1024 * 1024;
        if ($file_size > $max_size) {
            $size_mb = $max_size / 1024 / 1024;
            $this->sendMessage($chat_id, "❌ *File too large!*\n\nMax size: {$size_mb}MB", "Markdown");
            return;
        }
        
        // Send processing message
        $this->sendMessage($chat_id, "🔄 *Processing your file...*\n\n⏳ Please wait...", "Markdown");
        
        // Download file
        $file_path = $this->getFilePath($file_id);
        if (!$file_path) {
            $this->sendMessage($chat_id, "❌ *Download failed!*\n\nPlease try again.", "Markdown");
            return;
        }
        
        // Create bot folder
        $bot_folder = "bots/" . uniqid("bot_") . "/";
        mkdir($bot_folder, 0777, true);
        
        // Download file
        $file_url = "https://api.telegram.org/file/bot{$this->token}/{$file_path}";
        $file_content = file_get_contents($file_url);
        
        if ($file_content === false) {
            $this->deleteDirectory($bot_folder);
            $this->sendMessage($chat_id, "❌ *Download failed!*\n\nPlease try again.", "Markdown");
            return;
        }
        
        // Handle ZIP
        if ($is_zip) {
            $zip_file = $bot_folder . "archive.zip";
            file_put_contents($zip_file, $file_content);
            
            $zip = new ZipArchive();
            if ($zip->open($zip_file) === true) {
                $zip->extractTo($bot_folder);
                $zip->close();
                unlink($zip_file);
                
                // Find main PHP file
                $files = glob($bot_folder . "*.php");
                if (empty($files)) {
                    $this->deleteDirectory($bot_folder);
                    $this->sendMessage($chat_id, "❌ *No PHP file found in ZIP!*", "Markdown");
                    return;
                }
                
                // Use first PHP file as main
                $local_file = $files[0];
                $display_name = basename($local_file);
            } else {
                $this->deleteDirectory($bot_folder);
                $this->sendMessage($chat_id, "❌ *Invalid ZIP file!*", "Markdown");
                return;
            }
        } else {
            $local_file = $bot_folder . "bot.php";
            file_put_contents($local_file, $file_content);
            $display_name = $file_name;
        }
        
        // Set webhook
        $webhook_url = HOST_URL . str_replace('./', '', $local_file);
        $webhook_result = $this->setWebhook($token, $webhook_url);
        
        if ($webhook_result && isset($webhook_result['ok']) && $webhook_result['ok']) {
            $bot_info = $this->getBotInfo($token);
            $bot_display_name = $bot_info['result']['first_name'] ?? 'Unknown';
            $bot_username = $bot_info['result']['username'] ?? 'unknown';
            
            // Save to database
            $this->db->query("INSERT INTO bots (user_id, bot_token, bot_name, bot_username, file_path, webhook_url, status, is_active) 
                             VALUES (:uid, :token, :name, :username, :path, :webhook, 'running', 1)",
                             [':uid' => $user_id, ':token' => $token, ':name' => $bot_display_name,
                              ':username' => $bot_username, ':path' => $local_file, ':webhook' => $webhook_url]);
            
            // Clear temp
            $this->db->query("DELETE FROM temp_data WHERE user_id = :uid", [':uid' => $user_id]);
            
            // Update stats
            $this->db->query("UPDATE settings SET value = value + 1 WHERE key = 'total_uploads'");
            $this->db->query("UPDATE users SET total_bots = total_bots + 1, total_uploads = total_uploads + 1 WHERE user_id = :uid", [':uid' => $user_id]);
            
            $text = "╔══════════════════════════╗\n";
            $text .= "║   ✅ BOT DEPLOYED       ║\n";
            $text .= "║   Successfully!         ║\n";
            $text .= "╚══════════════════════════╝\n\n";
            $text .= "📊 *Bot Details:*\n";
            $text .= "├ Name: `{$bot_display_name}`\n";
            $text .= "├ Username: @{$bot_username}\n";
            $text .= "├ Status: 🟢 Running\n";
            $text .= "├ File: `{$display_name}`\n";
            $text .= "└ Webhook: `{$webhook_url}`\n\n";
            $text .= "Use /mybots to manage your bot.";
            
            $keyboard = [
                "inline_keyboard" => [
                    [
                        ["text" => "📋 MANAGE", "callback_data" => "mybots"],
                        ["text" => "🤖 OPEN BOT", "url" => "https://t.me/{$bot_username}"]
                    ],
                    [["text" => "◀️ MAIN MENU", "callback_data" => "main_menu"]]
                ]
            ];
            
            $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
            $this->db->logActivity($user_id, 'bot_deployed', "Bot @$bot_username deployed");
            
        } else {
            $this->deleteDirectory($bot_folder);
            $error = $webhook_result['description'] ?? 'Unknown error';
            $this->sendMessage($chat_id, "❌ *Webhook setup failed!*\n\nError: `$error`", "Markdown");
        }
    }
    
    private function showMyBots($chat_id, $user_id, $message_id = null) {
        $bots = $this->db->getAll("SELECT * FROM bots WHERE user_id = :uid ORDER BY created_at DESC", [':uid' => $user_id]);
        
        if (empty($bots)) {
            $text = "╔══════════════════════════╗\n";
            $text .= "║   📭 NO BOTS FOUND      ║\n";
            $text .= "╚══════════════════════════╝\n\n";
            $text .= "You haven't deployed any bots yet.\n\n";
            $text .= "Use /newbot to deploy your first bot!";
            
            $keyboard = [
                "inline_keyboard" => [
                    [["text" => "🚀 DEPLOY NOW", "callback_data" => "newbot"]],
                    [["text" => "◀️ MAIN MENU", "callback_data" => "main_menu"]]
                ]
            ];
            
            if ($message_id) {
                $this->editMessage($chat_id, $message_id, $text, $keyboard);
            } else {
                $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
            }
            return;
        }
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   📋 YOUR BOTS          ║\n";
        $text .= "║   Total: " . count($bots) . "           ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        
        $keyboard = ["inline_keyboard" => []];
        
        foreach ($bots as $index => $bot) {
            $status_emoji = $bot['status'] == 'running' ? '🟢' : '🔴';
            $text .= "{$status_emoji} *Bot " . ($index + 1) . ":* {$bot['bot_name']}\n";
            $text .= "├ Username: @{$bot['bot_username']}\n";
            $text .= "├ Status: *{$bot['status']}*\n";
            $text .= "└ Created: " . date("d/m/Y", strtotime($bot['created_at'])) . "\n\n";
            
            // Add to keyboard
            $keyboard['inline_keyboard'][] = [
                ["text" => "🟢 START", "callback_data" => "start_{$bot['id']}"],
                ["text" => "🔴 STOP", "callback_data" => "stop_{$bot['id']}"],
                ["text" => "🔄 RESTART", "callback_data" => "restart_{$bot['id']}"]
            ];
            $keyboard['inline_keyboard'][] = [
                ["text" => "ℹ️ INFO", "callback_data" => "info_{$bot['id']}"],
                ["text" => "📋 LOGS", "callback_data" => "logs_{$bot['id']}"],
                ["text" => "🗑️ DELETE", "callback_data" => "delete_{$bot['id']}"]
            ];
        }
        
        $keyboard['inline_keyboard'][] = [
            ["text" => "🚀 DEPLOY NEW", "callback_data" => "newbot"],
            ["text" => "◀️ MAIN MENU", "callback_data" => "main_menu"]
        ];
        
        if ($message_id) {
            $this->editMessage($chat_id, $message_id, $text, $keyboard);
        } else {
            $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
        }
    }
    
    private function showBotInfo($chat_id, $user_id, $bot_id, $message_id = null) {
        $bot = $this->db->getRow("SELECT * FROM bots WHERE id = :id AND user_id = :uid", [':id' => $bot_id, ':uid' => $user_id]);
        
        if (!$bot) {
            $this->editMessage($chat_id, $message_id, "❌ Bot not found!", $this->getMainKeyboard($user_id));
            return;
        }
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   🤖 BOT INFORMATION    ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "📊 *Basic Info*\n";
        $text .= "├ ID: `{$bot['id']}`\n";
        $text .= "├ Name: `{$bot['bot_name']}`\n";
        $text .= "├ Username: @{$bot['bot_username']}\n";
        $text .= "├ Status: " . ($bot['status'] == 'running' ? '🟢 Running' : '🔴 Stopped') . "\n";
        $text .= "├ Token: `" . substr($bot['bot_token'], 0, 10) . "...`\n";
        $text .= "└ Created: " . date("d/m/Y H:i", strtotime($bot['created_at'])) . "\n\n";
        
        $text .= "📁 *Files*\n";
        $text .= "├ Path: `{$bot['file_path']}`\n";
        $text .= "└ Webhook: `{$bot['webhook_url']}`\n\n";
        
        $text .= "📊 *Statistics*\n";
        $text .= "├ Start Count: `{$bot['start_count']}`\n";
        $text .= "└ Last Started: " . ($bot['last_started'] ?? 'Never') . "\n";
        
        $keyboard = [
            "inline_keyboard" => [
                [
                    ["text" => "🟢 START", "callback_data" => "start_{$bot['id']}"],
                    ["text" => "🔴 STOP", "callback_data" => "stop_{$bot['id']}"],
                    ["text" => "🔄 RESTART", "callback_data" => "restart_{$bot['id']}"]
                ],
                [
                    ["text" => "📋 LOGS", "callback_data" => "logs_{$bot['id']}"],
                    ["text" => "🗑️ DELETE", "callback_data" => "delete_{$bot['id']}"],
                    ["text" => "◀️ BACK", "callback_data" => "mybots"]
                ]
            ]
        ];
        
        $this->editMessage($chat_id, $message_id, $text, $keyboard);
    }
    
    private function startBot($chat_id, $user_id, $bot_id, $message_id = null) {
        $bot = $this->db->getRow("SELECT * FROM bots WHERE id = :id AND user_id = :uid", [':id' => $bot_id, ':uid' => $user_id]);
        
        if (!$bot) {
            $this->editMessage($chat_id, $message_id, "❌ Bot not found!", $this->getMainKeyboard($user_id));
            return;
        }
        
        // Set webhook
        $result = $this->setWebhook($bot['bot_token'], $bot['webhook_url']);
        
        if ($result && isset($result['ok']) && $result['ok']) {
            $this->db->query("UPDATE bots SET status = 'running', is_active = 1, start_count = start_count + 1, last_started = datetime('now') WHERE id = :id", [':id' => $bot_id]);
            
            $text = "✅ *Bot started successfully!*";
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
            $this->db->logActivity($user_id, 'bot_started', "Bot @{$bot['bot_username']} started");
        } else {
            $text = "❌ *Failed to start bot!*";
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
        }
    }
    
    private function stopBot($chat_id, $user_id, $bot_id, $message_id = null) {
        $bot = $this->db->getRow("SELECT * FROM bots WHERE id = :id AND user_id = :uid", [':id' => $bot_id, ':uid' => $user_id]);
        
        if (!$bot) {
            $this->editMessage($chat_id, $message_id, "❌ Bot not found!", $this->getMainKeyboard($user_id));
            return;
        }
        
        // Remove webhook
        $result = $this->setWebhook($bot['bot_token'], '');
        
        if ($result && isset($result['ok']) && $result['ok']) {
            $this->db->query("UPDATE bots SET status = 'stopped', is_active = 0 WHERE id = :id", [':id' => $bot_id]);
            
            $text = "✅ *Bot stopped successfully!*";
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
            $this->db->logActivity($user_id, 'bot_stopped', "Bot @{$bot['bot_username']} stopped");
        } else {
            $text = "❌ *Failed to stop bot!*";
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
        }
    }
    
    private function restartBot($chat_id, $user_id, $bot_id, $message_id = null) {
        $bot = $this->db->getRow("SELECT * FROM bots WHERE id = :id AND user_id = :uid", [':id' => $bot_id, ':uid' => $user_id]);
        
        if (!$bot) {
            $this->editMessage($chat_id, $message_id, "❌ Bot not found!", $this->getMainKeyboard($user_id));
            return;
        }
        
        // First remove webhook
        $this->setWebhook($bot['bot_token'], '');
        sleep(1);
        
        // Then set again
        $result = $this->setWebhook($bot['bot_token'], $bot['webhook_url']);
        
        if ($result && isset($result['ok']) && $result['ok']) {
            $this->db->query("UPDATE bots SET status = 'running', is_active = 1, start_count = start_count + 1, last_started = datetime('now') WHERE id = :id", [':id' => $bot_id]);
            
            $text = "✅ *Bot restarted successfully!*";
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
            $this->db->logActivity($user_id, 'bot_restarted', "Bot @{$bot['bot_username']} restarted");
        } else {
            $text = "❌ *Failed to restart bot!*";
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
        }
    }
    
    private function confirmDeleteBot($chat_id, $user_id, $bot_id, $message_id) {
        $bot = $this->db->getRow("SELECT * FROM bots WHERE id = :id AND user_id = :uid", [':id' => $bot_id, ':uid' => $user_id]);
        
        if (!$bot) {
            $this->editMessage($chat_id, $message_id, "❌ Bot not found!", $this->getMainKeyboard($user_id));
            return;
        }
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   ⚠️ CONFIRM DELETE     ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "Are you sure you want to delete *{$bot['bot_name']}*?\n\n";
        $text .= "⚠️ *This action cannot be undone!*\n";
        $text .= "All bot files will be permanently removed.";
        
        $keyboard = [
            "inline_keyboard" => [
                [
                    ["text" => "✅ YES, DELETE", "callback_data" => "confirm_delete_{$bot_id}"],
                    ["text" => "❌ NO, CANCEL", "callback_data" => "info_{$bot_id}"]
                ]
            ]
        ];
        
        $this->editMessage($chat_id, $message_id, $text, $keyboard);
    }
    
    private function deleteBot($chat_id, $user_id, $bot_id, $message_id) {
        $bot = $this->db->getRow("SELECT * FROM bots WHERE id = :id AND user_id = :uid", [':id' => $bot_id, ':uid' => $user_id]);
        
        if (!$bot) {
            $this->editMessage($chat_id, $message_id, "❌ Bot not found!", $this->getMainKeyboard($user_id));
            return;
        }
        
        // Remove webhook
        $this->setWebhook($bot['bot_token'], '');
        
        // Delete files
        if (file_exists($bot['file_path'])) {
            $bot_dir = dirname($bot['file_path']);
            $this->deleteDirectory($bot_dir);
        }
        
        // Delete from database
        $this->db->query("DELETE FROM bots WHERE id = :id", [':id' => $bot_id]);
        
        $text = "✅ *Bot deleted successfully!*";
        $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
        $this->db->logActivity($user_id, 'bot_deleted', "Bot @{$bot['bot_username']} deleted");
    }
    
    private function showBotLogs($chat_id, $user_id, $bot_id, $message_id = null) {
        $bot = $this->db->getRow("SELECT * FROM bots WHERE id = :id AND user_id = :uid", [':id' => $bot_id, ':uid' => $user_id]);
        
        if (!$bot) {
            $this->editMessage($chat_id, $message_id, "❌ Bot not found!", $this->getMainKeyboard($user_id));
            return;
        }
        
        $log_file = 'logs/bot_' . $bot_id . '.log';
        
        if (!file_exists($log_file)) {
            $text = "📋 *No logs found for this bot.*\n\n";
            $text .= "Bot logs will appear here when the bot receives updates.";
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
            return;
        }
        
        $logs = file_get_contents($log_file);
        $logs = substr($logs, -2000); // Last 2000 chars
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   📋 BOT LOGS           ║\n";
        $text .= "║   {$bot['bot_name']}    \n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "```\n" . $logs . "\n```";
        
        if (strlen($logs) >= 2000) {
            $text .= "\n\n*Showing last 2000 characters*";
        }
        
        $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($user_id));
    }
    
    private function showUserProfile($chat_id, $user_id, $message_id = null) {
        $user = $this->db->getRow("SELECT * FROM users WHERE user_id = :uid", [':uid' => $user_id]);
        $bots = $this->db->getAll("SELECT * FROM bots WHERE user_id = :uid", [':uid' => $user_id]);
        $active_bots = count(array_filter($bots, function($b) { return $b['status'] == 'running'; }));
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   👤 USER PROFILE       ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "📊 *User Information*\n";
        $text .= "├ ID: `{$user_id}`\n";
        $text .= "├ Username: @" . ($user['username'] ?? 'Not set') . "\n";
        $text .= "├ Name: {$user['first_name']} {$user['last_name']}\n";
        $text .= "├ Joined: " . date("d/m/Y", strtotime($user['joined_at'])) . "\n";
        $text .= "└ Last Active: " . date("d/m/Y H:i", strtotime($user['last_active'])) . "\n\n";
        
        $text .= "📊 *Bot Statistics*\n";
        $text .= "├ Total Bots: `" . count($bots) . "`\n";
        $text .= "├ Active Bots: `{$active_bots}`\n";
        $text .= "├ Total Uploads: `" . ($user['total_uploads'] ?? 0) . "`\n";
        $text .= "└ Remaining Slots: `" . (MAX_BOTS_PER_USER - count($bots)) . "`\n";
        
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "◀️ BACK", "callback_data" => "main_menu"]]
            ]
        ];
        
        if ($message_id) {
            $this->editMessage($chat_id, $message_id, $text, $keyboard);
        } else {
            $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
        }
    }
    
    private function showUserStats($chat_id, $user_id, $message_id = null) {
        $total_users = $this->db->getRow("SELECT COUNT(*) as count FROM users")['count'];
        $total_bots = $this->db->getRow("SELECT COUNT(*) as count FROM bots")['count'];
        $active_bots = $this->db->getRow("SELECT COUNT(*) as count FROM bots WHERE status = 'running'")['count'];
        $today_uploads = $this->db->getRow("SELECT COUNT(*) as count FROM bots WHERE date(created_at) = date('now')")['count'];
        
        $user_bots = $this->db->getAll("SELECT * FROM bots WHERE user_id = :uid", [':uid' => $user_id]);
        $user_active = count(array_filter($user_bots, function($b) { return $b['status'] == 'running'; }));
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   📊 STATISTICS         ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "🌐 *Global Statistics*\n";
        $text .= "├ Total Users: `{$total_users}`\n";
        $text .= "├ Total Bots: `{$total_bots}`\n";
        $text .= "├ Active Bots: `{$active_bots}`\n";
        $text .= "├ Today Uploads: `{$today_uploads}`\n";
        $text .= "└ Max Bots/User: `" . MAX_BOTS_PER_USER . "`\n\n";
        
        $text .= "👤 *Your Statistics*\n";
        $text .= "├ Your Bots: `" . count($user_bots) . "`\n";
        $text .= "├ Your Active: `{$user_active}`\n";
        $text .= "├ Remaining Slots: `" . (MAX_BOTS_PER_USER - count($user_bots)) . "`\n";
        $text .= "└ Total Uploads: `" . ($this->db->getRow("SELECT total_uploads FROM users WHERE user_id = :uid", [':uid' => $user_id])['total_uploads'] ?? 0) . "`\n";
        
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "◀️ BACK", "callback_data" => "main_menu"]]
            ]
        ];
        
        if ($message_id) {
            $this->editMessage($chat_id, $message_id, $text, $keyboard);
        } else {
            $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
        }
    }
    
    private function showAdminPanel($chat_id, $message_id = null) {
        if ($chat_id != OWNER_ID) return;
        
        $total_users = $this->db->getRow("SELECT COUNT(*) as count FROM users")['count'];
        $total_bots = $this->db->getRow("SELECT COUNT(*) as count FROM bots")['count'];
        $active_bots = $this->db->getRow("SELECT COUNT(*) as count FROM bots WHERE status = 'running'")['count'];
        $banned_users = $this->db->getRow("SELECT COUNT(*) as count FROM users WHERE is_banned = 1")['count'];
        $today_activity = $this->db->getRow("SELECT COUNT(*) as count FROM activity_logs WHERE date(created_at) = date('now')")['count'];
        
        $settings = $this->db->getRow("SELECT value FROM settings WHERE key = 'system_locked'");
        $lock_status = $settings['value'] == '1' ? '🔒 LOCKED' : '🔓 UNLOCKED';
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   👑 ADMIN PANEL        ║\n";
        $text .= "║   Owner Access Only     ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "📊 *System Overview*\n";
        $text .= "├ Total Users: `{$total_users}`\n";
        $text .= "├ Total Bots: `{$total_bots}`\n";
        $text .= "├ Active Bots: `{$active_bots}`\n";
        $text .= "├ Banned Users: `{$banned_users}`\n";
        $text .= "├ Today Activity: `{$today_activity}`\n";
        $text .= "└ System Status: {$lock_status}\n\n";
        
        $keyboard = [
            "inline_keyboard" => [
                [
                    ["text" => "📊 DETAILED STATS", "callback_data" => "admin_stats"],
                    ["text" => "👥 MANAGE USERS", "callback_data" => "admin_users"]
                ],
                [
                    ["text" => $settings['value'] == '1' ? "🔓 UNLOCK SYSTEM" : "🔒 LOCK SYSTEM", "callback_data" => "admin_toggle_lock"],
                    ["text" => "📋 SYSTEM LOGS", "callback_data" => "admin_logs"]
                ],
                [
                    ["text" => "💾 CREATE BACKUP", "callback_data" => "admin_backup"],
                    ["text" => "◀️ MAIN MENU", "callback_data" => "main_menu"]
                ]
            ]
        ];
        
        if ($message_id) {
            $this->editMessage($chat_id, $message_id, $text, $keyboard);
        } else {
            $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
        }
    }
    
    private function showDetailedStats($chat_id, $message_id = null) {
        if ($chat_id != OWNER_ID) return;
        
        // Get daily stats for last 7 days
        $daily_stats = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $stats = $this->db->getRow("SELECT * FROM stats WHERE date = :date", [':date' => $date]);
            if ($stats) {
                $daily_stats[] = [
                    'date' => date('d/m', strtotime($date)),
                    'uploads' => $stats['total_uploads'] ?? 0
                ];
            } else {
                $daily_stats[] = [
                    'date' => date('d/m', strtotime($date)),
                    'uploads' => 0
                ];
            }
        }
        
        $total_uploads = $this->db->getRow("SELECT SUM(total_uploads) as sum FROM users")['sum'] ?? 0;
        $disk_usage = $this->getDirectorySize('bots');
        $disk_free = disk_free_space('/') / 1024 / 1024 / 1024;
        $disk_total = disk_total_space('/') / 1024 / 1024 / 1024;
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   📊 DETAILED STATS     ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "📈 *Overall Statistics*\n";
        $text .= "├ Total Uploads: `{$total_uploads}`\n\n";
        
        $text .= "💾 *System Resources*\n";
        $text .= "├ Disk Usage: `" . round($disk_usage / 1024 / 1024, 2) . "MB`\n";
        $text .= "├ Disk Free: `" . round($disk_free, 2) . "GB`\n";
        $text .= "└ Disk Total: `" . round($disk_total, 2) . "GB`\n\n";
        
        $text .= "📅 *Last 7 Days Uploads*\n";
        foreach ($daily_stats as $stat) {
            $bar = str_repeat('█', min(20, $stat['uploads']));
            $text .= "├ {$stat['date']}: {$bar} ({$stat['uploads']})\n";
        }
        
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "◀️ BACK TO ADMIN", "callback_data" => "admin_panel"]]
            ]
        ];
        
        $this->editMessage($chat_id, $message_id, $text, $keyboard);
    }
    
    private function showAllUsers($chat_id, $message_id = null) {
        if ($chat_id != OWNER_ID) return;
        
        $users = $this->db->getAll("SELECT * FROM users ORDER BY joined_at DESC");
        
        if (empty($users)) {
            $text = "📭 *No users found in database*";
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($chat_id));
            return;
        }
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   👥 USER MANAGEMENT    ║\n";
        $text .= "║   Total: " . count($users) . " users   ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        
        $keyboard = ["inline_keyboard" => []];
        
        foreach (array_slice($users, 0, 10) as $user) {
            $bot_count = $this->db->getRow("SELECT COUNT(*) as count FROM bots WHERE user_id = :uid", [':uid' => $user['user_id']])['count'];
            $ban_status = $user['is_banned'] == 1 ? '🚫 BANNED' : '✅ ACTIVE';
            $status_emoji = $user['is_banned'] == 1 ? '🔴' : '🟢';
            
            $text .= "{$status_emoji} *User:* `{$user['user_id']}`\n";
            $text .= "├ Username: @" . ($user['username'] ?? 'Not set') . "\n";
            $text .= "├ Bots: {$bot_count}\n";
            $text .= "└ Status: {$ban_status}\n\n";
            
            // Add action buttons
            if ($user['is_banned'] == 1) {
                $keyboard['inline_keyboard'][] = [
                    ["text" => "✅ UNBAN", "callback_data" => "unban_user_{$user['user_id']}"],
                    ["text" => "👁️ VIEW", "callback_data" => "view_user_{$user['user_id']}"]
                ];
            } else {
                $keyboard['inline_keyboard'][] = [
                    ["text" => "🚫 BAN", "callback_data" => "ban_user_{$user['user_id']}"],
                    ["text" => "👁️ VIEW", "callback_data" => "view_user_{$user['user_id']}"]
                ];
            }
        }
        
        if (count($users) > 10) {
            $text .= "\n*Showing 10 of " . count($users) . " users*";
        }
        
        $keyboard['inline_keyboard'][] = [
            ["text" => "🔄 REFRESH", "callback_data" => "refresh_users"],
            ["text" => "◀️ BACK", "callback_data" => "admin_panel"]
        ];
        
        $this->editMessage($chat_id, $message_id, $text, $keyboard);
    }
    
    private function viewUserDetails($chat_id, $target_id, $message_id) {
        if ($chat_id != OWNER_ID) return;
        
        $user = $this->db->getRow("SELECT * FROM users WHERE user_id = :uid", [':uid' => $target_id]);
        
        if (!$user) {
            $this->editMessage($chat_id, $message_id, "❌ User not found!", $this->getMainKeyboard($chat_id));
            return;
        }
        
        $bots = $this->db->getAll("SELECT * FROM bots WHERE user_id = :uid", [':uid' => $target_id]);
        $active_bots = count(array_filter($bots, function($b) { return $b['status'] == 'running'; }));
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   👤 USER DETAILS       ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "📊 *User Information*\n";
        $text .= "├ ID: `{$target_id}`\n";
        $text .= "├ Username: @" . ($user['username'] ?? 'Not set') . "\n";
        $text .= "├ Name: {$user['first_name']} {$user['last_name']}\n";
        $text .= "├ Status: " . ($user['is_banned'] == 1 ? '🚫 BANNED' : '✅ ACTIVE') . "\n";
        $text .= "├ Joined: " . date("d/m/Y H:i", strtotime($user['joined_at'])) . "\n";
        $text .= "└ Last Active: " . date("d/m/Y H:i", strtotime($user['last_active'])) . "\n\n";
        
        $text .= "📊 *Bot Statistics*\n";
        $text .= "├ Total Bots: `" . count($bots) . "`\n";
        $text .= "├ Active Bots: `{$active_bots}`\n";
        $text .= "└ Total Uploads: `" . ($user['total_uploads'] ?? 0) . "`\n";
        
        $keyboard = [
            "inline_keyboard" => [
                [
                    ["text" => $user['is_banned'] == 1 ? "✅ UNBAN" : "🚫 BAN", 
                     "callback_data" => ($user['is_banned'] == 1 ? "unban_user_{$target_id}" : "ban_user_{$target_id}")]
                ],
                [["text" => "◀️ BACK TO USERS", "callback_data" => "admin_users"]]
            ]
        ];
        
        $this->editMessage($chat_id, $message_id, $text, $keyboard);
    }
    
    private function banUserById($chat_id, $target_id, $message_id) {
        if ($chat_id != OWNER_ID) return;
        
        if ($target_id == OWNER_ID) {
            $this->answerCallbackQuery($chat_id, "Cannot ban owner!");
            return;
        }
        
        $this->db->query("UPDATE users SET is_banned = 1 WHERE user_id = :uid", [':uid' => $target_id]);
        
        $text = "✅ *User `{$target_id}` has been banned successfully!*";
        $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($chat_id));
        $this->db->logActivity($chat_id, 'user_banned', "User $target_id banned");
    }
    
    private function unbanUserById($chat_id, $target_id, $message_id) {
        if ($chat_id != OWNER_ID) return;
        
        $this->db->query("UPDATE users SET is_banned = 0 WHERE user_id = :uid", [':uid' => $target_id]);
        
        $text = "✅ *User `{$target_id}` has been unbanned successfully!*";
        $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($chat_id));
        $this->db->logActivity($chat_id, 'user_unbanned', "User $target_id unbanned");
    }
    
    private function banUser($chat_id, $text) {
        if ($chat_id != OWNER_ID) return;
        
        $parts = explode(" ", $text);
        if (count($parts) != 2) {
            $this->sendMessage($chat_id, "❌ Use: /ban [user_id]", "Markdown");
            return;
        }
        
        $user_id = $parts[1];
        if ($user_id == OWNER_ID) {
            $this->sendMessage($chat_id, "❌ Cannot ban owner!", "Markdown");
            return;
        }
        
        $this->db->query("UPDATE users SET is_banned = 1 WHERE user_id = :uid", [':uid' => $user_id]);
        
        $this->sendMessage($chat_id, "✅ *User {$user_id} banned*", "Markdown");
        $this->db->logActivity($chat_id, 'user_banned', "User $user_id banned");
    }
    
    private function unbanUser($chat_id, $text) {
        if ($chat_id != OWNER_ID) return;
        
        $parts = explode(" ", $text);
        if (count($parts) != 2) {
            $this->sendMessage($chat_id, "❌ Use: /unban [user_id]", "Markdown");
            return;
        }
        
        $user_id = $parts[1];
        $this->db->query("UPDATE users SET is_banned = 0 WHERE user_id = :uid", [':uid' => $user_id]);
        
        $this->sendMessage($chat_id, "✅ *User {$user_id} unbanned*", "Markdown");
        $this->db->logActivity($chat_id, 'user_unbanned', "User $user_id unbanned");
    }
    
    private function toggleSystemLock($chat_id, $message_id = null) {
        if ($chat_id != OWNER_ID) return;
        
        $settings = $this->db->getRow("SELECT value FROM settings WHERE key = 'system_locked'");
        $new_value = $settings['value'] == '1' ? '0' : '1';
        $this->db->query("UPDATE settings SET value = :val WHERE key = 'system_locked'", [':val' => $new_value]);
        
        $status = $new_value == '1' ? 'LOCKED' : 'UNLOCKED';
        $text = "🔒 *System {$status}*";
        
        if ($message_id) {
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($chat_id));
        } else {
            $this->sendMessage($chat_id, $text, "Markdown");
        }
        
        $this->db->logActivity($chat_id, 'system_lock', "System $status");
    }
    
    private function showSystemLogs($chat_id, $message_id = null) {
        if ($chat_id != OWNER_ID) return;
        
        $log_file = 'logs/' . date('Y-m-d') . '.log';
        
        if (!file_exists($log_file)) {
            $text = "📋 *No logs for today*";
            $this->editMessage($chat_id, $message_id, $text, $this->getMainKeyboard($chat_id));
            return;
        }
        
        $logs = file_get_contents($log_file);
        $logs = substr($logs, -3000);
        
        $text = "╔══════════════════════════╗\n";
        $text .= "║   📋 SYSTEM LOGS        ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "```\n" . $logs . "\n```";
        
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "◀️ BACK TO ADMIN", "callback_data" => "admin_panel"]]
            ]
        ];
        
        $this->editMessage($chat_id, $message_id, $text, $keyboard);
    }
    
    private function createBackup($chat_id, $message_id = null) {
        if ($chat_id != OWNER_ID) return;
        
        $backup_file = 'backups/backup_' . date('Y-m-d_H-i-s') . '.zip';
        
        $zip = new ZipArchive();
        if ($zip->open($backup_file, ZipArchive::CREATE) === true) {
            // Add database
            if (file_exists('bot_hosting.db')) {
                $zip->addFile('bot_hosting.db', 'database/bot_hosting.db');
            }
            
            // Add bots folder
            $this->addFolderToZip($zip, 'bots/', 'bots/');
            
            $zip->close();
            
            $text = "✅ *Backup created successfully!*\n\nFile: `{$backup_file}`";
        } else {
            $text = "❌ *Failed to create backup!*";
        }
        
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "◀️ BACK TO ADMIN", "callback_data" => "admin_panel"]]
            ]
        ];
        
        $this->editMessage($chat_id, $message_id, $text, $keyboard);
    }
    
    private function addFolderToZip($zip, $folder, $zipFolder) {
        if (!is_dir($folder)) return;
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($folder));
                $zip->addFile($filePath, $zipFolder . $relativePath);
            }
        }
    }
    
    private function sendHelpMessage($chat_id, $message_id = null) {
        $text = "╔══════════════════════════╗\n";
        $text .= "║   ❓ HELP & SUPPORT     ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "📚 *Bot Commands*\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "🚀 /newbot - Deploy new bot\n";
        $text .= "📋 /mybots - Manage your bots\n";
        $text .= "👤 /profile - View profile\n";
        $text .= "📊 /stats - View statistics\n";
        $text .= "📢 /channel - Join channel\n";
        $text .= "❓ /help - This menu\n\n";
        
        $text .= "📌 *How to Deploy Bot*\n";
        $text .= "1. Send /newbot\n";
        $text .= "2. Send your bot token\n";
        $text .= "3. Upload PHP file or ZIP\n";
        $text .= "4. Bot auto-deploys!\n\n";
        
        $text .= "📌 *Bot Management*\n";
        $text .= "• Start - Activate bot\n";
        $text .= "• Stop - Deactivate bot\n";
        $text .= "• Restart - Reboot bot\n";
        $text .= "• Info - View details\n";
        $text .= "• Logs - View bot logs\n";
        $text .= "• Delete - Remove bot\n\n";
        
        $text .= "⚠️ *Limits*\n";
        $text .= "• Max " . MAX_BOTS_PER_USER . " bots per user\n";
        $text .= "• Max 10MB per PHP file\n";
        $text .= "• Max 50MB per ZIP file\n\n";
        
        $text .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "👨‍💻 *Support*: @ASHUSHARMA_JIBOT\n";
        $text .= "📢 *Channel*: " . CHANNEL_LINK . "\n";
        $text .= "✨ *Version*: " . VERSION;
        
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "◀️ BACK TO MENU", "callback_data" => "main_menu"]]
            ]
        ];
        
        if ($message_id) {
            $this->editMessage($chat_id, $message_id, $text, $keyboard);
        } else {
            $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
        }
    }
    
    private function sendChannelInfo($chat_id) {
        $text = "╔══════════════════════════╗\n";
        $text .= "║   📢 OFFICIAL CHANNEL   ║\n";
        $text .= "╚══════════════════════════╝\n\n";
        $text .= "Join our official channel for:\n";
        $text .= "✅ Latest Updates\n";
        $text .= "✅ New Features\n";
        $text .= "✅ Bot Tutorials\n";
        $text .= "✅ Giveaways\n";
        $text .= "✅ Support\n\n";
        $text .= "👉 [Click here to join](" . CHANNEL_LINK . ")";
        
        $keyboard = [
            "inline_keyboard" => [
                [["text" => "📢 JOIN CHANNEL", "url" => CHANNEL_LINK]],
                [["text" => "◀️ BACK", "callback_data" => "main_menu"]]
            ]
        ];
        
        $this->sendMessage($chat_id, $text, "Markdown", $keyboard);
    }
    
    private function getFilePath($file_id) {
        $url = $this->api_url . "getFile?file_id=" . $file_id;
        $response = file_get_contents($url);
        $data = json_decode($response, true);
        
        if ($data && isset($data['ok']) && $data['ok']) {
            return $data['result']['file_path'];
        }
        return false;
    }
    
    private function setWebhook($token, $url) {
        $api_url = "https://api.telegram.org/bot{$token}/setWebhook";
        $data = ['url' => $url];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response ? json_decode($response, true) : null;
    }
    
    private function getBotInfo($token) {
        $api_url = "https://api.telegram.org/bot{$token}/getMe";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ? json_decode($response, true) : null;
    }
    
    private function sendMessage($chat_id, $text, $parse_mode = "Markdown", $reply_markup = null) {
        $url = $this->api_url . "sendMessage";
        $data = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => $parse_mode
        ];
        
        if ($reply_markup) {
            $data['reply_markup'] = json_encode($reply_markup);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        file_put_contents('logs/send.log', date('Y-m-d H:i:s') . " - To: $chat_id - Response: $response\n", FILE_APPEND);
        
        return $response;
    }
    
    private function editMessage($chat_id, $message_id, $text, $reply_markup = null) {
        $url = $this->api_url . "editMessageText";
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        
        if ($reply_markup) {
            $data['reply_markup'] = json_encode($reply_markup);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response;
    }
    
    private function answerCallback($callback_id) {
        $url = $this->api_url . "answerCallbackQuery";
        $data = ['callback_query_id' => $callback_id];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        curl_exec($ch);
        curl_close($ch);
    }
    
    private function answerCallbackQuery($chat_id, $text) {
        $this->sendMessage($chat_id, $text, "Markdown");
    }
    
    private function deleteDirectory($dir) {
        if (!file_exists($dir)) return true;
        if (!is_dir($dir)) return unlink($dir);
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;
            if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
        }
        
        return rmdir($dir);
    }
    
    private function getDirectorySize($path) {
        $size = 0;
        if (!file_exists($path)) return 0;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
        return $size;
    }
}

// Main Execution
try {
    $db = new Database();
    $bot = new TelegramBot(BOT_TOKEN);
    
    // Handle webhook
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $bot->handleWebhook();
        exit;
    }
    
} catch (Exception $e) {
    file_put_contents('logs/fatal.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
}

// Web Interface with Modern UI (Same as before)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Bot Hosting Pro - Premium Telegram Bot Hosting Platform</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated Background */
        #particles-js {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }

        .container {
            position: relative;
            z-index: 1;
            max-width: 1300px;
            width: 100%;
        }

        /* Glassmorphism Card */
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 40px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeInUp 1s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }

        .logo-wrapper {
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
            position: relative;
        }

        .logo-glow {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 50%;
            filter: blur(20px);
            opacity: 0.5;
            animation: pulse 2s infinite;
        }

        .logo {
            position: relative;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 20px 40px rgba(59, 130, 246, 0.3);
        }

        .logo i {
            font-size: 4rem;
            color: white;
            filter: drop-shadow(0 5px 10px rgba(0,0,0,0.3));
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.3; }
        }

        h1 {
            font-size: 3.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
            letter-spacing: -0.02em;
        }

        .subtitle {
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.2rem;
            font-weight: 400;
        }

        .version-badge {
            display: inline-block;
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 15px;
            border: 1px solid rgba(59, 130, 246, 0.3);
            backdrop-filter: blur(10px);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 30px;
            padding: 25px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-5px);
            border-color: rgba(59, 130, 246, 0.3);
        }

        .stat-item:hover::before {
            opacity: 1;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .stat-icon i {
            font-size: 1.8rem;
            color: white;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
            line-height: 1.2;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }

        .stat-label {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            z-index: 1;
        }

        /* Info Cards */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-bottom: 40px;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 30px;
            padding: 30px;
            transition: all 0.3s ease;
        }

        .info-card:hover {
            border-color: rgba(59, 130, 246, 0.3);
            transform: translateY(-5px);
        }

        .info-title {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .info-title i {
            font-size: 2rem;
            color: #3b82f6;
        }

        .info-title h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: white;
        }

        .info-content {
            color: rgba(255, 255, 255, 0.8);
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-item i {
            color: #3b82f6;
            width: 20px;
        }

        .info-item strong {
            color: white;
            font-weight: 600;
        }

        .info-item code {
            background: rgba(0, 0, 0, 0.3);
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #a5b4fc;
        }

        /* Setup Steps */
        .setup-section {
            margin-bottom: 40px;
        }

        .section-title {
            text-align: center;
            margin-bottom: 30px;
        }

        .section-title h2 {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 10px;
        }

        .section-title p {
            color: rgba(255, 255, 255, 0.6);
        }

        .steps-container {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
        }

        .step {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 25px;
            padding: 25px;
            text-align: center;
            position: relative;
            transition: all 0.3s ease;
        }

        .step:hover {
            transform: translateY(-5px);
            border-color: #3b82f6;
        }

        .step:not(:last-child)::after {
            content: '→';
            position: absolute;
            right: -15px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.5rem;
            color: #3b82f6;
            font-weight: bold;
            z-index: 2;
        }

        .step-number {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-weight: 700;
            font-size: 1.2rem;
            color: white;
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
        }

        .step-title {
            font-weight: 600;
            color: white;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .step-desc {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
        }

        /* Code Block */
        .code-section {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 30px;
            padding: 30px;
            margin-bottom: 40px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .code-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .code-header i {
            font-size: 1.5rem;
            color: #3b82f6;
        }

        .code-header h3 {
            font-size: 1.2rem;
            color: white;
            font-weight: 600;
        }

        .code-block {
            background: #0f172a;
            border-radius: 20px;
            padding: 25px;
            font-family: 'Fira Code', monospace;
            font-size: 0.9rem;
            line-height: 1.6;
            color: #e2e8f0;
            overflow-x: auto;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .code-block .comment { color: #6a9955; }
        .code-block .keyword { color: #569cd6; }
        .code-block .string { color: #ce9178; }
        .code-block .variable { color: #9cdcfe; }

        /* Features Grid */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }

        .feature {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 25px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .feature:hover {
            border-color: #3b82f6;
            transform: translateY(-5px);
        }

        .feature i {
            font-size: 2rem;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 15px;
        }

        .feature h4 {
            color: white;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .feature p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
        }

        /* Buttons */
        .button-group {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 16px 40px;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(59, 130, 246, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: #3b82f6;
            transform: translateY(-3px);
        }

        /* Footer */
        .footer {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .footer p {
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 10px;
        }

        .footer a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        .footer i.fa-heart {
            color: #ef4444;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 992px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            .steps-container {
                grid-template-columns: repeat(3, 1fr);
            }
            .steps-container .step:nth-child(4)::after,
            .steps-container .step:nth-child(5)::after {
                display: none;
            }
        }

        @media (max-width: 768px) {
            h1 {
                font-size: 2.5rem;
            }
            .glass-card {
                padding: 25px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .features-grid {
                grid-template-columns: 1fr;
            }
            .steps-container {
                grid-template-columns: 1fr;
            }
            .step::after {
                display: none;
            }
            .button-group {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            h1 {
                font-size: 2rem;
            }
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Tooltip */
        [data-tooltip] {
            position: relative;
            cursor: pointer;
        }

        [data-tooltip]:before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 8px 12px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            font-size: 0.8rem;
            border-radius: 8px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        [data-tooltip]:hover:before {
            opacity: 1;
            visibility: visible;
            bottom: 120%;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
</head>
<body>
    <div id="particles-js"></div>
    
    <div class="container">
        <div class="glass-card">
            <div class="header">
                <div class="logo-wrapper">
                    <div class="logo-glow"></div>
                    <div class="logo">
                        <i class="fas fa-robot"></i>
                    </div>
                </div>
                <h1>PHP BOT HOSTING PRO</h1>
                <p class="subtitle">Premium Telegram Bot Hosting Platform</p>
                <span class="version-badge">Version <?= VERSION ?></span>
            </div>
            
            <?php
            try {
                $temp_bot = new TelegramBot(BOT_TOKEN);
                $bot_info = $temp_bot->getBotInfo(BOT_TOKEN);
                $db = new Database();
                
                $total_users = $db->getRow("SELECT COUNT(*) as count FROM users")['count'] ?? 0;
                $total_bots = $db->getRow("SELECT COUNT(*) as count FROM bots")['count'] ?? 0;
                $active_bots = $db->getRow("SELECT COUNT(*) as count FROM bots WHERE status = 'running'")['count'] ?? 0;
                $today_uploads = $db->getRow("SELECT COUNT(*) as count FROM bots WHERE date(created_at) = date('now')")['count'] ?? 0;
                
                // Get disk usage
                $disk_usage = 0;
                if (file_exists('bots')) {
                    $disk_usage = round($temp_bot->getDirectorySize('bots') / 1024 / 1024, 2);
                }
            } catch (Exception $e) {
                $bot_info = null;
                $total_users = $total_bots = $active_bots = $today_uploads = 0;
                $disk_usage = 0;
            }
            ?>
            
            <div class="stats-grid">
                <div class="stat-item" data-tooltip="Total registered users">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-number"><?= number_format($total_users) ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-item" data-tooltip="Total bots deployed">
                    <div class="stat-icon"><i class="fas fa-robot"></i></div>
                    <div class="stat-number"><?= number_format($total_bots) ?></div>
                    <div class="stat-label">Total Bots</div>
                </div>
                <div class="stat-item" data-tooltip="Currently active bots">
                    <div class="stat-icon"><i class="fas fa-play-circle"></i></div>
                    <div class="stat-number"><?= number_format($active_bots) ?></div>
                    <div class="stat-label">Active Bots</div>
                </div>
                <div class="stat-item" data-tooltip="Uploads today">
                    <div class="stat-icon"><i class="fas fa-upload"></i></div>
                    <div class="stat-number"><?= number_format($today_uploads) ?></div>
                    <div class="stat-label">Today Uploads</div>
                </div>
            </div>
            
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-info-circle"></i>
                        <h3>Bot Information</h3>
                    </div>
                    <div class="info-content">
                        <div class="info-item">
                            <i class="fas fa-user"></i>
                            <span><strong>Username:</strong> @<?= ($bot_info && isset($bot_info['result']['username'])) ? $bot_info['result']['username'] : 'Not set' ?></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-check-circle"></i>
                            <span><strong>Status:</strong> <span style="color: #10b981;">✅ Active</span></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-link"></i>
                            <span><strong>Webhook:</strong> <code><?= HOST_URL ?></code></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-database"></i>
                            <span><strong>Disk Usage:</strong> <code><?= $disk_usage ?> MB</code></span>
                        </div>
                    </div>
                </div>
                
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-crown"></i>
                        <h3>Owner Information</h3>
                    </div>
                    <div class="info-content">
                        <div class="info-item">
                            <i class="fas fa-id"></i>
                            <span><strong>ID:</strong> <code><?= OWNER_ID ?></code></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-user-tag"></i>
                            <span><strong>Username:</strong> @<?= OWNER_USERNAME ?></span>
                        </div>
                        <div class="info-item">
                            <i class="fab fa-telegram"></i>
                            <span><strong>Channel:</strong> <a href="<?= CHANNEL_LINK ?>" target="_blank" style="color: #3b82f6;">Join Channel</a></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-shield-alt"></i>
                            <span><strong>Max Bots/User:</strong> <code><?= MAX_BOTS_PER_USER ?></code></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="setup-section">
                <div class="section-title">
                    <h2>🚀 Quick Setup Guide</h2>
                    <p>Deploy your bot in 5 simple steps</p>
                </div>
                
                <div class="steps-container">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-title">Open Bot</div>
                        <div class="step-desc">Start @<?= ($bot_info && isset($bot_info['result']['username'])) ? $bot_info['result']['username'] : 'YourBot' ?></div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-title">Send /start</div>
                        <div class="step-desc">Initialize the bot</div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-title">Send /newbot</div>
                        <div class="step-desc">Start deployment</div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-title">Enter Token</div>
                        <div class="step-desc">Paste your bot token</div>
                    </div>
                    <div class="step">
                        <div class="step-number">5</div>
                        <div class="step-title">Upload File</div>
                        <div class="step-desc">PHP or ZIP file</div>
                    </div>
                </div>
            </div>
            
            <div class="code-section">
                <div class="code-header">
                    <i class="fas fa-code"></i>
                    <h3>Sample Bot Code (bot.php)</h3>
                </div>
                <div class="code-block">
                    <span class="comment">&lt;?php</span><br>
                    <span class="variable">$token</span> = <span class="string">'YOUR_BOT_TOKEN'</span>;<br>
                    <span class="variable">$update</span> = json_decode(file_get_contents(<span class="string">'php://input'</span>), <span class="keyword">true</span>);<br><br>
                    <span class="keyword">if</span> (isset(<span class="variable">$update</span>[<span class="string">'message'</span>])) {<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;<span class="variable">$chat_id</span> = <span class="variable">$update</span>[<span class="string">'message'</span>][<span class="string">'chat'</span>][<span class="string">'id'</span>];<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;<span class="variable">$text</span> = <span class="variable">$update</span>[<span class="string">'message'</span>][<span class="string">'text'</span>];<br><br>
                    &nbsp;&nbsp;&nbsp;&nbsp;<span class="keyword">if</span> (<span class="variable">$text</span> == <span class="string">'/start'</span>) {<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;file_get_contents(<span class="string">"https://api.telegram.org/bot<span class="variable">$token</span>/sendMessage?"</span> . http_build_query([<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span class="string">'chat_id'</span> => <span class="variable">$chat_id</span>,<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span class="string">'text'</span> => <span class="string">'Hello! I am your bot!'</span><br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;]));<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;}<br>
                    }<br>
                    <span class="comment">?&gt;</span>
                </div>
            </div>
            
            <div class="section-title">
                <h2>✨ Premium Features</h2>
                <p>Everything you need to host your Telegram bots</p>
            </div>
            
            <div class="features-grid">
                <div class="feature">
                    <i class="fas fa-upload"></i>
                    <h4>PHP Upload</h4>
                    <p>Upload PHP files up to 10MB</p>
                </div>
                <div class="feature">
                    <i class="fas fa-file-zipper"></i>
                    <h4>ZIP Support</h4>
                    <p>Upload & extract ZIP archives</p>
                </div>
                <div class="feature">
                    <i class="fas fa-play"></i>
                    <h4>Start/Stop</h4>
                    <p>Control your bots anytime</p>
                </div>
                <div class="feature">
                    <i class="fas fa-sync"></i>
                    <h4>Restart</h4>
                    <p>Quick bot restart option</p>
                </div>
                <div class="feature">
                    <i class="fas fa-trash"></i>
                    <h4>Delete</h4>
                    <p>Remove unwanted bots</p>
                </div>
                <div class="feature">
                    <i class="fas fa-history"></i>
                    <h4>Bot Logs</h4>
                    <p>View detailed activity logs</p>
                </div>
                <div class="feature">
                    <i class="fas fa-chart-line"></i>
                    <h4>Statistics</h4>
                    <p>Track bot performance</p>
                </div>
                <div class="feature">
                    <i class="fas fa-shield-alt"></i>
                    <h4>Secure</h4>
                    <p>24/7 secure hosting</p>
                </div>
            </div>
            
            <div class="button-group">
                <a href="https://t.me/<?= ($bot_info && isset($bot_info['result']['username'])) ? $bot_info['result']['username'] : 'ashusharma_jibot' ?>" class="btn btn-primary" target="_blank">
                    <i class="fab fa-telegram"></i> Open Bot
                </a>
                <a href="<?= CHANNEL_LINK ?>" class="btn btn-secondary" target="_blank">
                    <i class="fas fa-bell"></i> Join Channel
                </a>
                <?php if (isset($_GET['admin']) && $_GET['admin'] == 'panel'): ?>
                <a href="?admin_login=true" class="btn btn-primary">
                    <i class="fas fa-crown"></i> Admin Login
                </a>
                <?php endif; ?>
            </div>
            
            <div class="footer">
                <p>© 2024 PHP Bot Hosting Pro | Created with <i class="fas fa-heart"></i> by <a href="https://t.me/<?= OWNER_USERNAME ?>" target="_blank">@<?= OWNER_USERNAME ?></a></p>
                <p style="margin-top: 10px; font-size: 0.9rem;">
                    <i class="fas fa-server"></i> Server Time: <?= date('Y-m-d H:i:s') ?> | 
                    <i class="fas fa-clock"></i> Uptime: 99.9% |
                    <i class="fas fa-tag"></i> Version <?= VERSION ?>
                </p>
            </div>
        </div>
    </div>

    <script>
        // Particles.js Configuration
        particlesJS('particles-js', {
            particles: {
                number: {
                    value: 80,
                    density: {
                        enable: true,
                        value_area: 800
                    }
                },
                color: {
                    value: '#3b82f6'
                },
                shape: {
                    type: 'circle'
                },
                opacity: {
                    value: 0.5,
                    random: false,
                    anim: {
                        enable: false
                    }
                },
                size: {
                    value: 3,
                    random: true,
                    anim: {
                        enable: false
                    }
                },
                line_linked: {
                    enable: true,
                    distance: 150,
                    color: '#3b82f6',
                    opacity: 0.4,
                    width: 1
                },
                move: {
                    enable: true,
                    speed: 2,
                    direction: 'none',
                    random: false,
                    straight: false,
                    out_mode: 'out',
                    bounce: false,
                    attract: {
                        enable: false,
                        rotateX: 600,
                        rotateY: 1200
                    }
                }
            },
            interactivity: {
                detect_on: 'canvas',
                events: {
                    onhover: {
                        enable: true,
                        mode: 'grab'
                    },
                    onclick: {
                        enable: true,
                        mode: 'push'
                    },
                    resize: true
                },
                modes: {
                    grab: {
                        distance: 140,
                        line_linked: {
                            opacity: 1
                        }
                    },
                    push: {
                        particles_nb: 4
                    }
                }
            },
            retina_detect: true
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Add loading animation to buttons
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (this.classList.contains('btn-primary') && !this.classList.contains('no-loading')) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<span class="loading"></span> Loading...';
                    setTimeout(() => {
                        this.innerHTML = originalText;
                    }, 2000);
                }
            });
        });

        // Fade in animation for stat cards
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.stat-item, .info-card, .feature, .step').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'all 0.6s ease';
            observer.observe(el);
        });
    </script>
</body>
</html>
<?php
// Auto-update statistics
if (isset($db)) {
    $total_users = $db->getRow("SELECT COUNT(*) as count FROM users")['count'] ?? 0;
    $total_bots = $db->getRow("SELECT COUNT(*) as count FROM bots")['count'] ?? 0;
    $active_bots = $db->getRow("SELECT COUNT(*) as count FROM bots WHERE status = 'running'")['count'] ?? 0;
    $today_uploads = $db->getRow("SELECT COUNT(*) as count FROM bots WHERE date(created_at) = date('now')")['count'] ?? 0;
    
    $db->query("INSERT OR REPLACE INTO stats (date, total_users, total_bots, active_bots, total_uploads) 
                VALUES (date('now'), :users, :bots, :active, :uploads)",
                [':users' => $total_users, ':bots' => $total_bots, ':active' => $active_bots, ':uploads' => $today_uploads]);
}
?>