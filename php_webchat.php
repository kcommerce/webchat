<?php
session_start();

// Configuration
define('GLOBAL_PIN', '1234'); // Change this to your desired PIN
define('DATA_DIR', '/var/www/html/webchat/chat_data');
define('UPLOADS_DIR', '/var/www/html/webchat/uploads');
define('ENCRYPTION_KEY', 'your-secret-key-change-this-32ch'); // Must be 32 characters for AES-256
define('ADMIN_USERS', ['admin', 'manager', 'boss']); // Users who can manage rooms

// Create necessary directories
if (!file_exists(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
if (!file_exists(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0777, true);

// Encryption functions using AES-256-GCM (compatible with Web Crypto API)
function encryptData($data) {
    $key = substr(str_pad(ENCRYPTION_KEY, 32, '0'), 0, 32);
    $iv = openssl_random_pseudo_bytes(12);
    
    $encrypted = openssl_encrypt(
        $data,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    
    // Combine IV + encrypted data + tag
    $combined = $iv . $encrypted . $tag;
    return base64_encode($combined);
}

function decryptData($data) {
    try {
        $key = substr(str_pad(ENCRYPTION_KEY, 32, '0'), 0, 32);
        $combined = base64_decode($data);
        
        if ($combined === false || strlen($combined) < 28) {
            return false;
        }
        
        $iv = substr($combined, 0, 12);
        $tag = substr($combined, -16);
        $encrypted = substr($combined, 12, -16);
        
        $decrypted = openssl_decrypt(
            $encrypted,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        return $decrypted;
    } catch (Exception $e) {
        error_log("Decryption exception: " . $e->getMessage());
        return false;
    }
}

// Helper functions
function getRoomsFile() {
    return DATA_DIR . '/rooms.json';
}

function getRooms() {
    $file = getRoomsFile();
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([]));
        return [];
    }
    return json_decode(file_get_contents($file), true);
}

function saveRooms($rooms) {
    file_put_contents(getRoomsFile(), json_encode($rooms));
}

function getMessagesFile($room) {
    return DATA_DIR . '/room_' . md5($room) . '.json';
}

function getMessages($room) {
    $file = getMessagesFile($room);
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([]));
        return [];
    }
    return json_decode(file_get_contents($file), true);
}

function saveMessage($room, $message) {
    $messages = getMessages($room);
    $messages[] = $message;
    file_put_contents(getMessagesFile($room), json_encode($messages));
}

function isAdmin($username) {
    return in_array($username, ADMIN_USERS);
}

// Handle file download with encrypted token
if (isset($_GET['dl'])) {
    $token = $_GET['dl'] ?? '';
    $decrypted = decryptData($token);
    
    if ($decrypted) {
        $parts = explode('|', $decrypted);
        if (count($parts) === 2) {
            list($filename, $timestamp) = $parts;
            // Token valid for 1 hour
            if (time() - $timestamp < 3600) {
                $filepath = UPLOADS_DIR . '/' . $filename;
                if (file_exists($filepath)) {
                    // Get original filename from the message
                    $originalName = preg_replace('/^\d+_/', '', $filename);
                    
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . $originalName . '"');
                    header('Content-Length: ' . filesize($filepath));
                    readfile($filepath);
                    exit;
                }
            }
        }
    }
    http_response_code(404);
    echo 'File not found or link expired';
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Login
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $name = trim($_POST['name'] ?? '');
        $pin = $_POST['pin'] ?? '';
        
        if ($name && $pin === GLOBAL_PIN) {
            $_SESSION['username'] = $name;
            $_SESSION['is_admin'] = isAdmin($name);
            echo json_encode(['success' => true, 'is_admin' => $_SESSION['is_admin']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid name or PIN']);
        }
        exit;
    }
    
    // Create room (admin only)
    if (isset($_POST['action']) && $_POST['action'] === 'create_room') {
        if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required']);
            exit;
        }
        
        $roomName = trim($_POST['room_name'] ?? '');
        if ($roomName) {
            $rooms = getRooms();
            if (!in_array($roomName, $rooms)) {
                $rooms[] = $roomName;
                saveRooms($rooms);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Room already exists']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Room name required']);
        }
        exit;
    }
    
    // Delete room (admin only)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_room') {
        if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required']);
            exit;
        }
        
        $roomName = $_POST['room_name'] ?? '';
        $rooms = getRooms();
        $rooms = array_values(array_diff($rooms, [$roomName]));
        saveRooms($rooms);
        
        // Delete room messages
        $file = getMessagesFile($roomName);
        if (file_exists($file)) unlink($file);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Send message (encrypted)
    if (isset($_POST['action']) && $_POST['action'] === 'send_message') {
        $room = $_POST['room'] ?? '';
        $encryptedMessage = $_POST['message'] ?? '';
        $username = $_SESSION['username'] ?? 'Anonymous';
        
        error_log("Send message - Room: $room, Encrypted length: " . strlen($encryptedMessage));
        
        if ($room && $encryptedMessage) {
            // Decrypt the message on server
            $message = decryptData($encryptedMessage);
            
            error_log("Decrypted message: $message");
            
            if ($message !== false && $message !== '') {
                saveMessage($room, [
                    'username' => $username,
                    'message' => $message,
                    'timestamp' => time(),
                    'type' => 'text'
                ]);
                echo json_encode(['success' => true]);
            } else {
                error_log("Decryption failed or empty message");
                echo json_encode(['success' => false, 'message' => 'Decryption failed']);
            }
        } else {
            error_log("Missing room or message");
            echo json_encode(['success' => false, 'message' => 'Missing room or message']);
        }
        exit;
    }
    
    // Upload file
    if (isset($_POST['action']) && $_POST['action'] === 'upload_file') {
        $room = $_POST['room'] ?? '';
        $username = $_SESSION['username'] ?? 'Anonymous';
        
        if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
            $filename = basename($_FILES['file']['name']);
            $uniqueName = time() . '_' . $filename;
            $targetPath = UPLOADS_DIR . '/' . $uniqueName;
            
            // Check if it's an image
            $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
            $isImage = in_array($_FILES['file']['type'], $imageTypes);
            
            if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
                saveMessage($room, [
                    'username' => $username,
                    'message' => $filename,
                    'file' => $uniqueName,
                    'timestamp' => time(),
                    'type' => $isImage ? 'image' : 'file'
                ]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Upload failed']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        }
        exit;
    }
    
    // Upload from clipboard (base64 image)
    if (isset($_POST['action']) && $_POST['action'] === 'upload_clipboard') {
        $room = $_POST['room'] ?? '';
        $username = $_SESSION['username'] ?? 'Anonymous';
        $imageData = $_POST['image_data'] ?? '';
        
        if ($imageData && $room) {
            // Extract base64 data
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageData, $matches)) {
                $imageType = $matches[1];
                $base64Data = $matches[2];
                $imageData = base64_decode($base64Data);
                
                if ($imageData !== false) {
                    $uniqueName = time() . '_clipboard.' . $imageType;
                    $targetPath = UPLOADS_DIR . '/' . $uniqueName;
                    
                    if (file_put_contents($targetPath, $imageData)) {
                        saveMessage($room, [
                            'username' => $username,
                            'message' => 'Pasted Image',
                            'file' => $uniqueName,
                            'timestamp' => time(),
                            'type' => 'image'
                        ]);
                        echo json_encode(['success' => true]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to save image']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid image data']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid image format']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Missing data']);
        }
        exit;
    }
    
    // Get messages
    if (isset($_POST['action']) && $_POST['action'] === 'get_messages') {
        $room = $_POST['room'] ?? '';
        $lastTimestamp = intval($_POST['last_timestamp'] ?? 0);
        
        $messages = getMessages($room);
        
        // Only return new messages
        if ($lastTimestamp > 0) {
            $messages = array_filter($messages, function($msg) use ($lastTimestamp) {
                return $msg['timestamp'] > $lastTimestamp;
            });
            $messages = array_values($messages);
        }
        
        // Encrypt text messages before sending
        foreach ($messages as &$msg) {
            if ($msg['type'] === 'text') {
                $msg['message'] = encryptData($msg['message']);
                $msg['encrypted'] = true;
            }
            // Generate encrypted download token for files
            if (isset($msg['file'])) {
                $token = encryptData($msg['file'] . '|' . time());
                $msg['download_token'] = $token;
            }
        }
        
        echo json_encode($messages);
        exit;
    }
    
    // Get all messages (initial load)
    if (isset($_POST['action']) && $_POST['action'] === 'get_all_messages') {
        $room = $_POST['room'] ?? '';
        $messages = getMessages($room);
        
        // Encrypt text messages before sending
        foreach ($messages as &$msg) {
            if ($msg['type'] === 'text') {
                $msg['message'] = encryptData($msg['message']);
                $msg['encrypted'] = true;
            }
            // Generate encrypted download token for files
            if (isset($msg['file'])) {
                $token = encryptData($msg['file'] . '|' . time());
                $msg['download_token'] = $token;
            }
        }
        
        echo json_encode($messages);
        exit;
    }
    
    // Get rooms
    if (isset($_POST['action']) && $_POST['action'] === 'get_rooms') {
        echo json_encode(getRooms());
        exit;
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Check if logged in
$loggedIn = isset($_SESSION['username']);
$isAdmin = isset($_SESSION['is_admin']) ? $_SESSION['is_admin'] : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebChat</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 900px;
            width: 100%;
            max-height: 95vh;
            display: flex;
            flex-direction: column;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 0;
                align-items: stretch;
            }
            .container {
                max-width: 100%;
                height: 100vh;
                max-height: 100vh;
                border-radius: 0;
            }
        }
        .login-form {
            padding: 40px;
            text-align: center;
        }
        .login-form h2 {
            margin-bottom: 30px;
            color: #333;
        }
        .login-form input {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        .login-form button {
            width: 100%;
            padding: 12px;
            margin-top: 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }
        .login-form button:hover {
            background: #5568d3;
        }
        .chat-container {
            display: flex;
            height: 600px;
            overflow: hidden;
        }
        
        @media (max-width: 768px) {
            .chat-container {
                height: calc(100vh - 0px);
                flex-direction: column;
            }
        }
        .sidebar {
            width: 250px;
            background: #f7f7f7;
            border-right: 1px solid #ddd;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 80%;
                max-width: 300px;
                height: 100vh;
                z-index: 1000;
                transform: translateX(-100%);
                box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            }
            .sidebar.show {
                transform: translateX(0);
            }
        }
        .sidebar-header {
            padding: 20px;
            background: #667eea;
            color: white;
        }
        .sidebar-header h3 {
            margin-bottom: 5px;
        }
        .sidebar-header small {
            opacity: 0.9;
        }
        .admin-badge {
            display: inline-block;
            background: #ffd93d;
            color: #333;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 5px;
        }
        .room-controls {
            padding: 15px;
            border-bottom: 1px solid #ddd;
        }
        .room-controls input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 8px;
        }
        .room-controls button {
            width: 100%;
            padding: 8px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .room-controls.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        .room-list {
            flex: 1;
            overflow-y: auto;
        }
        .room-item {
            padding: 15px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .room-item:hover {
            background: #e9e9e9;
        }
        .room-item.active {
            background: #667eea;
            color: white;
        }
        .room-item button {
            background: #ff4757;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        .logout-btn {
            padding: 15px;
            border-top: 1px solid #ddd;
        }
        .logout-btn button {
            width: 100%;
            padding: 10px;
            background: #ff4757;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        
        @media (max-width: 768px) {
            .chat-main {
                width: 100%;
                height: 100%;
            }
        }
        .chat-header {
            padding: 20px;
            background: #f7f7f7;
            border-bottom: 1px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .chat-header h3 {
            flex: 1;
            font-size: 18px;
        }
        .mobile-menu-btn {
            display: none;
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 18px;
            margin-right: 10px;
        }
        
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
            .chat-header {
                padding: 15px;
            }
            .chat-header h3 {
                font-size: 16px;
            }
        }
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #fafafa;
        }
        .message {
            margin-bottom: 15px;
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .message-header {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        .message-header strong {
            color: #667eea;
        }
        .message-content {
            background: white;
            padding: 10px 15px;
            border-radius: 10px;
            display: inline-block;
            max-width: 70%;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            word-wrap: break-word;
        }
        
        @media (max-width: 768px) {
            .message-content {
                max-width: 85%;
                font-size: 14px;
            }
        }
        .message.file .message-content {
            background: #e3f2fd;
        }
        .message.image .message-content {
            background: transparent;
            box-shadow: none;
            padding: 5px;
        }
        .message-image {
            max-width: 400px;
            max-height: 300px;
            border-radius: 8px;
            cursor: pointer;
            display: block;
            margin-top: 5px;
            width: 100%;
            height: auto;
        }
        .message-image:hover {
            opacity: 0.9;
        }
        
        @media (max-width: 768px) {
            .message-image {
                max-width: 280px;
                max-height: 250px;
            }
        }
        .file-download {
            color: #667eea;
            text-decoration: none;
            font-weight: bold;
        }
        .file-download:hover {
            text-decoration: underline;
        }
        .chat-input {
            padding: 20px;
            background: white;
            border-top: 1px solid #ddd;
        }
        
        @media (max-width: 768px) {
            .chat-input {
                padding: 10px;
            }
        }
        .emoji-picker {
            display: none;
            padding: 10px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 10px;
            max-height: 150px;
            overflow-y: auto;
        }
        .emoji-picker.show {
            display: block;
        }
        .emoji {
            font-size: 24px;
            cursor: pointer;
            padding: 5px;
            display: inline-block;
        }
        .emoji:hover {
            transform: scale(1.2);
        }
        .input-row {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .input-row input[type="text"] {
            flex: 1;
            min-width: 200px;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .input-row button, .input-row label {
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            white-space: nowrap;
        }
        
        @media (max-width: 768px) {
            .input-row {
                gap: 5px;
            }
            .input-row input[type="text"] {
                min-width: 100%;
                flex-basis: 100%;
                padding: 10px;
                font-size: 16px;
            }
            .input-row button, .input-row label {
                padding: 10px 12px;
                font-size: 13px;
                flex: 1;
            }
        }
        .emoji-btn {
            background: #ffd93d;
            color: #333;
        }
        .send-btn {
            background: #667eea;
            color: white;
        }
        .upload-btn {
            background: #6c5ce7;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .upload-btn input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .error {
            color: #ff4757;
            text-align: center;
            margin-top: 10px;
        }
        .no-room {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .paste-indicator {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(102, 126, 234, 0.95);
            color: white;
            padding: 20px 40px;
            border-radius: 10px;
            font-size: 18px;
            font-weight: bold;
            display: none;
            z-index: 9999;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .paste-indicator.show {
            display: block;
            animation: fadeIn 0.3s;
        }
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        .mobile-overlay.show {
            display: block;
        }
        
        @media (max-width: 768px) {
            .emoji-picker {
                max-height: 120px;
            }
            .emoji {
                font-size: 20px;
                padding: 3px;
            }
        }
        .encryption-badge {
            display: inline-block;
            background: #2ecc71;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$loggedIn): ?>
        <div class="login-form">
            <h2>WebChat Login 🔒</h2>
            <input type="text" id="username" placeholder="Enter your name" required>
            <input type="password" id="pin" placeholder="Enter PIN" required>
            <button onclick="login()">Login</button>
            <div class="error" id="login-error"></div>
        </div>
        <?php else: ?>
        <div class="chat-container">
            <div class="mobile-overlay" id="mobile-overlay" onclick="toggleMobileSidebar()"></div>
            <div class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h3>WebChat 🔒</h3>
                    <small>
                        <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                        <?php if ($isAdmin): ?>
                        <span class="admin-badge">ADMIN</span>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="room-controls <?php echo !$isAdmin ? 'disabled' : ''; ?>">
                    <input type="text" id="new-room-name" placeholder="New room name" <?php echo !$isAdmin ? 'disabled' : ''; ?>>
                    <button onclick="createRoom()" <?php echo !$isAdmin ? 'disabled' : ''; ?>>
                        <?php echo $isAdmin ? 'Create Room' : '🔒 Admin Only'; ?>
                    </button>
                </div>
                <div class="room-list" id="room-list">
                    <div class="no-room">No rooms available</div>
                </div>
                <div class="logout-btn">
                    <button onclick="logout()">Logout</button>
                </div>
            </div>
            <div class="chat-main">
                <div class="chat-header">
                    <button class="mobile-menu-btn" onclick="toggleMobileSidebar()">☰</button>
                    <h3 id="current-room">Select a room to start chatting</h3>
                    <span class="encryption-badge">🔐 E2E</span>
                </div>
                <div class="chat-messages" id="messages">
                    <div class="no-room">Please select or create a room to start chatting</div>
                </div>
                <div class="chat-input">
                    <div class="emoji-picker" id="emoji-picker"></div>
                    <div class="input-row">
                        <button class="emoji-btn" onclick="toggleEmojiPicker()">😊</button>
                        <input type="text" id="message-input" placeholder="Type your message..." onkeypress="if(event.key==='Enter') sendMessage()">
                        <button class="send-btn" onclick="sendMessage()">Send Chat</button>
                        <label class="upload-btn" style="margin: 0; display: flex; align-items: center; justify-content: center;">
                            Upload File
                            <input type="file" id="file-input" accept="image/*,application/pdf,.doc,.docx,.txt,.zip" onchange="uploadFile()" style="display: none;">
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="paste-indicator" id="paste-indicator">📋 Uploading image...</div>

    <script>
        let currentRoom = null;
        let messageInterval = null;
        let lastMessageTimestamp = 0;
        let isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        let allMessages = [];
        
        const emojis = ['😊', '😂', '❤️', '👍', '🎉', '😍', '🔥', '✨', '👏', '🙌', '💯', '😎', '🤔', '😢', '😭', '🥳', '🤗', '😜', '🙏', '💪'];
        
        // Simple encryption using Web Crypto API (AES-GCM)
        const CRYPTO_KEY = '<?php echo ENCRYPTION_KEY; ?>';
        
        // Convert string key to CryptoKey
        async function getEncryptionKey() {
            const encoder = new TextEncoder();
            const keyData = encoder.encode(CRYPTO_KEY.padEnd(32, '0').substring(0, 32));
            return await crypto.subtle.importKey(
                'raw',
                keyData,
                { name: 'AES-GCM' },
                false,
                ['encrypt', 'decrypt']
            );
        }
        
        async function encryptText(text) {
            try {
                const key = await getEncryptionKey();
                const encoder = new TextEncoder();
                const data = encoder.encode(text);
                const iv = crypto.getRandomValues(new Uint8Array(12));
                
                const encrypted = await crypto.subtle.encrypt(
                    { name: 'AES-GCM', iv: iv },
                    key,
                    data
                );
                
                // Combine IV and encrypted data
                const combined = new Uint8Array(iv.length + encrypted.byteLength);
                combined.set(iv);
                combined.set(new Uint8Array(encrypted), iv.length);
                
                // Convert to base64
                return btoa(String.fromCharCode(...combined));
            } catch(e) {
                console.error('Encryption error:', e);
                return null;
            }
        }
        
        async function decryptText(encrypted) {
            try {
                const key = await getEncryptionKey();
                const combined = Uint8Array.from(atob(encrypted), c => c.charCodeAt(0));
                
                const iv = combined.slice(0, 12);
                const data = combined.slice(12);
                
                const decrypted = await crypto.subtle.decrypt(
                    { name: 'AES-GCM', iv: iv },
                    key,
                    data
                );
                
                const decoder = new TextDecoder();
                return decoder.decode(decrypted);
            } catch(e) {
                console.error('Decryption error:', e);
                return encrypted;
            }
        }
        
        function login() {
            const username = document.getElementById('username').value;
            const pin = document.getElementById('pin').value;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=login&name=${encodeURIComponent(username)}&pin=${encodeURIComponent(pin)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    document.getElementById('login-error').textContent = data.message;
                }
            });
        }
        
        function logout() {
            window.location.href = '?logout=1';
        }
        
        function createRoom() {
            if (!isAdmin) {
                alert('Only administrators can create rooms');
                return;
            }
            
            const roomName = document.getElementById('new-room-name').value.trim();
            if (!roomName) return;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=create_room&room_name=${encodeURIComponent(roomName)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('new-room-name').value = '';
                    loadRooms();
                } else {
                    alert(data.message);
                }
            });
        }
        
        function deleteRoom(roomName) {
            if (!isAdmin) {
                alert('Only administrators can delete rooms');
                return;
            }
            
            if (!confirm(`Delete room "${roomName}"?`)) return;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_room&room_name=${encodeURIComponent(roomName)}`
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success && data.message) {
                    alert(data.message);
                    return;
                }
                if (currentRoom === roomName) {
                    currentRoom = null;
                    lastMessageTimestamp = 0;
                    allMessages = [];
                    document.getElementById('current-room').textContent = 'Select a room to start chatting';
                    document.getElementById('messages').innerHTML = '<div class="no-room">Please select or create a room</div>';
                    clearInterval(messageInterval);
                }
                loadRooms();
            });
        }
        
        function selectRoom(roomName) {
            currentRoom = roomName;
            lastMessageTimestamp = 0;
            allMessages = [];
            document.getElementById('current-room').textContent = roomName;
            
            // Load all messages initially
            loadAllMessages();
            
            // Start polling for new messages only
            clearInterval(messageInterval);
            messageInterval = setInterval(loadNewMessages, 3000);
            
            // Update active room
            document.querySelectorAll('.room-item').forEach(item => {
                item.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
            
            // Close mobile sidebar
            if (window.innerWidth <= 768) {
                toggleMobileSidebar();
            }
        }
        
        function loadRooms() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_rooms'
            })
            .then(r => r.json())
            .then(rooms => {
                const list = document.getElementById('room-list');
                if (rooms.length === 0) {
                    list.innerHTML = '<div class="no-room">No rooms available. ' + (isAdmin ? 'Create one!' : '') + '</div>';
                } else {
                    list.innerHTML = rooms.map(room => `
                        <div class="room-item ${currentRoom === room ? 'active' : ''}" onclick="selectRoom('${room}')">
                            <span>${room}</span>
                            ${isAdmin ? `<button onclick="event.stopPropagation(); deleteRoom('${room}')">Delete</button>` : ''}
                        </div>
                    `).join('');
                }
            });
        }
        
        function loadAllMessages() {
            if (!currentRoom) return;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=get_all_messages&room=${encodeURIComponent(currentRoom)}`
            })
            .then(r => r.json())
            .then(messages => {
                allMessages = messages;
                renderMessages();
                
                // Update last timestamp
                if (messages.length > 0) {
                    lastMessageTimestamp = messages[messages.length - 1].timestamp;
                }
            });
        }
        
        function loadNewMessages() {
            if (!currentRoom) return;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=get_messages&room=${encodeURIComponent(currentRoom)}&last_timestamp=${lastMessageTimestamp}`
            })
            .then(r => r.json())
            .then(newMessages => {
                if (newMessages.length > 0) {
                    allMessages = allMessages.concat(newMessages);
                    renderMessages(true);
                    
                    // Update last timestamp
                    lastMessageTimestamp = newMessages[newMessages.length - 1].timestamp;
                }
            });
        }
        
        function renderMessages(scrollToBottom = false) {
            const container = document.getElementById('messages');
            const wasAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 50;
            
            // Process messages asynchronously
            Promise.all(allMessages.map(async (msg) => {
                const date = new Date(msg.timestamp * 1000);
                const time = date.toLocaleTimeString();
                
                if (msg.type === 'image') {
                    const imgUrl = msg.download_token ? `?dl=${encodeURIComponent(msg.download_token)}` : `uploads/${msg.file}`;
                    return `
                        <div class="message image">
                            <div class="message-header">
                                <strong>${msg.username}</strong> • ${time}
                            </div>
                            <div class="message-content">
                                <div>${msg.message}</div>
                                <img src="${imgUrl}" alt="${msg.message}" class="message-image" onclick="window.open('${imgUrl}', '_blank')">
                            </div>
                        </div>
                    `;
                } else if (msg.type === 'file') {
                    const fileUrl = msg.download_token ? `?dl=${encodeURIComponent(msg.download_token)}` : `uploads/${msg.file}`;
                    return `
                        <div class="message file">
                            <div class="message-header">
                                <strong>${msg.username}</strong> • ${time}
                            </div>
                            <div class="message-content">
                                📎 <a href="${fileUrl}" class="file-download">${msg.message}</a>
                            </div>
                        </div>
                    `;
                } else {
                    // Decrypt message
                    const decryptedMsg = msg.encrypted ? await decryptText(msg.message) : msg.message;
                    return `
                        <div class="message">
                            <div class="message-header">
                                <strong>${msg.username}</strong> • ${time}
                            </div>
                            <div class="message-content">${decryptedMsg}</div>
                        </div>
                    `;
                }
            })).then(messagesHtml => {
                container.innerHTML = messagesHtml.join('');
                
                if (scrollToBottom || wasAtBottom || allMessages.length === 1) {
                    container.scrollTop = container.scrollHeight;
                }
            });
        }
        
        function sendMessage() {
            const input = document.getElementById('message-input');
            const message = input.value.trim();
            
            if (!message || !currentRoom) {
                console.log('Cannot send: message empty or no room selected');
                return;
            }
            
            console.log('Sending message:', message);
            
            // Encrypt message on client (async)
            encryptText(message).then(encrypted => {
                if (!encrypted) {
                    alert('Encryption failed');
                    return;
                }
                
                console.log('Encrypted:', encrypted);
                
                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=send_message&room=${encodeURIComponent(currentRoom)}&message=${encodeURIComponent(encrypted)}`
                })
                .then(r => {
                    console.log('Response status:', r.status);
                    return r.text();
                })
                .then(text => {
                    console.log('Response text:', text);
                    try {
                        const data = JSON.parse(text);
                        console.log('Parsed data:', data);
                        if (data.success) {
                            input.value = '';
                            // Immediately load new messages
                            setTimeout(() => loadNewMessages(), 500);
                        } else {
                            alert('Failed to send: ' + (data.message || 'Unknown error'));
                        }
                    } catch(e) {
                        console.error('Parse error:', e);
                        alert('Server error: ' + text);
                    }
                })
                .catch(err => {
                    console.error('Fetch error:', err);
                    alert('Network error: ' + err.message);
                });
            });
        }
        
        function uploadFile() {
            const fileInput = document.getElementById('file-input');
            const file = fileInput.files[0];
            
            if (!file || !currentRoom) return;
            
            const formData = new FormData();
            formData.append('action', 'upload_file');
            formData.append('room', currentRoom);
            formData.append('file', file);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    fileInput.value = '';
                    loadNewMessages();
                } else {
                    alert(data.message);
                }
            });
        }
        
        function uploadClipboardImage(imageData) {
            if (!currentRoom) return;
            
            const indicator = document.getElementById('paste-indicator');
            indicator.classList.add('show');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=upload_clipboard&room=${encodeURIComponent(currentRoom)}&image_data=${encodeURIComponent(imageData)}`
            })
            .then(r => r.json())
            .then(data => {
                indicator.classList.remove('show');
                if (data.success) {
                    loadNewMessages();
                } else {
                    alert(data.message || 'Failed to upload image');
                }
            })
            .catch(err => {
                indicator.classList.remove('show');
                alert('Error uploading image');
            });
        }
        
        // Handle paste events
        document.addEventListener('paste', function(e) {
            if (!currentRoom) return;
            
            const items = e.clipboardData.items;
            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    e.preventDefault();
                    const blob = items[i].getAsFile();
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        uploadClipboardImage(event.target.result);
                    };
                    reader.readAsDataURL(blob);
                    break;
                }
            }
        });
        
        function toggleEmojiPicker() {
            const picker = document.getElementById('emoji-picker');
            if (picker.innerHTML === '') {
                picker.innerHTML = emojis.map(e => `<span class="emoji" onclick="insertEmoji('${e}')">${e}</span>`).join('');
            }
            picker.classList.toggle('show');
        }
        
        function insertEmoji(emoji) {
            const input = document.getElementById('message-input');
            input.value += emoji;
            input.focus();
        }
        
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobile-overlay');
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        }
        
        // Initialize
        <?php if ($loggedIn): ?>
        loadRooms();
        setInterval(loadRooms, 10000); // Refresh room list every 10 seconds
        <?php endif; ?>
    </script>
</body>
</html>