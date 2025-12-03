# 🔐 WebChat - Secure PHP Chat Application

A modern, secure, and feature-rich web-based chat application built with PHP and vanilla JavaScript. Features end-to-end encryption, role-based access control, file sharing, and mobile-responsive design.

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Encryption](https://img.shields.io/badge/encryption-AES--256--GCM-red)

## ✨ Features

### 🔒 Security Features
- **End-to-End Encryption**: AES-256-GCM encryption for all text messages
- **Encrypted File Links**: Download links use encrypted tokens to bypass DLP systems
- **Role-Based Access Control**: Admin-only room management
- **Secure Authentication**: PIN-based login system
- **Token Expiration**: Download tokens valid for 1 hour

### 💬 Chat Features
- **Real-time Messaging**: Background polling for instant updates (no page refresh)
- **Multiple Chat Rooms**: Create and manage separate conversation spaces
- **File Sharing**: Upload and share files with other users
- **Image Support**: Upload images and paste from clipboard
- **Emoji Picker**: Built-in emoji selector
- **Message Timestamps**: All messages include sender name and time
- **Auto-scroll**: Smart scrolling to latest messages

### 📱 User Experience
- **Mobile Responsive**: Fully optimized for mobile devices
- **Slide-out Sidebar**: Touch-friendly navigation on mobile
- **No Page Refresh**: Seamless background updates
- **Intuitive Interface**: Clean, modern design
- **Fast Performance**: Efficient polling system
- **Copy/Paste Images**: Direct clipboard image support

## 🚀 Quick Start

### Prerequisites
- PHP 7.4 or higher
- Web server (Apache/Nginx)
- OpenSSL PHP extension
- Write permissions for data directories

### Installation

1. **Clone the repository**
```bash
git clone https://github.com/yourusername/webchat.git
cd webchat
```

2. **Create required directories**
```bash
sudo mkdir -p /var/www/html/webchat/chat_data
sudo mkdir -p /var/www/html/webchat/uploads
sudo chown -R www-data:www-data /var/www/html/webchat/
sudo chmod -R 755 /var/www/html/webchat/
```

3. **Configure the application**

Edit the configuration section at the top of `index.php`:

```php
// Configuration
define('GLOBAL_PIN', '1234'); // Change this to your desired PIN
define('DATA_DIR', '/var/www/html/webchat/chat_data');
define('UPLOADS_DIR', '/var/www/html/webchat/uploads');
define('ENCRYPTION_KEY', 'your-secret-key-change-this-32ch'); // Must be 32 characters
define('ADMIN_USERS', ['admin', 'manager', 'boss']); // Users who can manage rooms
```

4. **Set up web server**

For Apache, create a virtual host or place `index.php` in your web root.

For Nginx:
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/html/webchat;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location /uploads {
        internal;
    }
}
```

5. **Access the application**

Navigate to `http://your-domain.com` in your web browser.

## 🔧 Configuration

### Security Settings

| Setting | Description | Default |
|---------|-------------|---------|
| `GLOBAL_PIN` | PIN code for user authentication | `1234` |
| `ENCRYPTION_KEY` | 32-character key for AES-256-GCM encryption | Must be changed! |
| `ADMIN_USERS` | Array of usernames with admin privileges | `['admin', 'manager', 'boss']` |

### Directory Settings

| Setting | Description | Default |
|---------|-------------|---------|
| `DATA_DIR` | Path to store chat data and room info | `/var/www/html/webchat/chat_data` |
| `UPLOADS_DIR` | Path to store uploaded files | `/var/www/html/webchat/uploads` |

### Important Security Notes

⚠️ **Before deploying to production:**
1. Change `GLOBAL_PIN` to a secure PIN
2. Change `ENCRYPTION_KEY` to a random 32-character string
3. Update `ADMIN_USERS` with your admin usernames
4. Enable HTTPS/SSL for your web server
5. Set restrictive file permissions
6. Consider using a database instead of JSON files for production

## 👥 User Roles

### Regular Users
- Login with name and PIN
- Join existing chat rooms
- Send/receive encrypted messages
- Upload and download files
- Paste images from clipboard
- Use emoji picker

### Admin Users
- All regular user permissions
- Create new chat rooms
- Delete existing chat rooms
- Manage room access
- Identified with "ADMIN" badge

To make a user an admin, add their username to the `ADMIN_USERS` array in the configuration.

## 📖 Usage Guide

### Login
1. Enter your name (any name you choose)
2. Enter the global PIN configured in settings
3. Click "Login"

### Creating Rooms (Admin Only)
1. Enter a room name in the sidebar
2. Click "Create Room"
3. The room appears in the room list

### Joining a Room
1. Click on any room name in the sidebar
2. Start chatting immediately

### Sending Messages
1. Type your message in the input field
2. Click "Send Chat" or press Enter
3. Messages are automatically encrypted

### Uploading Files
1. Click "Upload File" button
2. Select a file from your device
3. File appears in chat for others to download

### Pasting Images
1. Copy any image to clipboard
2. Press Ctrl+V (Cmd+V on Mac) in the chat
3. Image uploads and displays automatically

### Using Emojis
1. Click the 😊 emoji button
2. Select an emoji from the picker
3. Emoji is inserted into your message

### Deleting Rooms (Admin Only)
1. Click "Delete" button next to room name
2. Confirm deletion
3. Room and all messages are permanently deleted

## 🏗️ Architecture

### Data Storage
- **Room List**: Stored in `chat_data/rooms.json`
- **Messages**: Stored per room in `chat_data/room_[hash].json`
- **Files**: Stored in `uploads/` directory with timestamp prefix

### Encryption Flow
1. **Client**: Message encrypted with AES-256-GCM using Web Crypto API
2. **Transit**: Encrypted message sent to server via HTTPS
3. **Server**: Message decrypted, re-encrypted, and stored
4. **Retrieval**: Server sends encrypted message to clients
5. **Client**: Message decrypted and displayed

### Background Polling
- Initial load: Fetches all messages
- Polling interval: 3 seconds for new messages
- Room list refresh: 10 seconds
- Only new messages transferred (efficient bandwidth usage)

### File Download Security
- Direct file URLs are replaced with encrypted tokens
- Token format: `?dl=[encrypted_token]`
- Token contains: `filename|timestamp`
- Tokens expire after 1 hour
- DLP systems cannot detect actual filenames

## 🔐 Security Considerations

### What's Protected
✅ Message content (AES-256-GCM encryption)  
✅ File download links (encrypted tokens)  
✅ Room management (admin-only)  
✅ Session-based authentication  

### What's NOT Protected (by default)
❌ Metadata (timestamps, usernames, room names)  
❌ File content (files stored unencrypted)  
❌ Multiple concurrent logins  
❌ Rate limiting  

### Recommendations for Production
1. **Use HTTPS**: Enable SSL/TLS on your web server
2. **Database Backend**: Replace JSON files with MySQL/PostgreSQL
3. **Rate Limiting**: Implement rate limits on API endpoints
4. **File Encryption**: Encrypt uploaded files at rest
5. **Audit Logging**: Log all security-relevant events
6. **Session Security**: Use secure session configuration
7. **Input Validation**: Add comprehensive input sanitization
8. **CSRF Protection**: Implement CSRF tokens
9. **Backup Strategy**: Regular backups of chat data

## 🐛 Troubleshooting

### Messages not sending
- Check browser console for errors
- Verify encryption key is exactly 32 characters
- Check PHP error logs
- Ensure directories have write permissions

### Files not uploading
- Check upload directory permissions
- Verify `upload_max_filesize` in php.ini
- Check available disk space
- Review PHP error logs

### Download links not working
- Verify token hasn't expired (1 hour limit)
- Check file exists in uploads directory
- Review server error logs
- Ensure web server can access upload directory

### Mobile layout issues
- Clear browser cache
- Verify viewport meta tag is present
- Test in different mobile browsers
- Check console for JavaScript errors

## 📝 API Endpoints

All endpoints use POST method and return JSON responses:

| Endpoint | Parameters | Description |
|----------|------------|-------------|
| `action=login` | `name`, `pin` | Authenticate user |
| `action=create_room` | `room_name` | Create new room (admin) |
| `action=delete_room` | `room_name` | Delete room (admin) |
| `action=send_message` | `room`, `message` | Send encrypted message |
| `action=upload_file` | `room`, `file` | Upload file to room |
| `action=upload_clipboard` | `room`, `image_data` | Upload clipboard image |
| `action=get_messages` | `room`, `last_timestamp` | Get new messages |
| `action=get_all_messages` | `room` | Get all messages |
| `action=get_rooms` | - | Get room list |

## 🤝 Contributing

Contributions are welcome! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- Built with PHP and vanilla JavaScript
- Uses Web Crypto API for client-side encryption
- OpenSSL for server-side encryption
- Responsive design inspired by modern chat applications

## 📧 Support

For issues, questions, or suggestions:
- Open an issue on GitHub
- Contact: your-email@example.com

## 🗺️ Roadmap

- [ ] Database backend support (MySQL/PostgreSQL)
- [ ] User registration system
- [ ] Private messaging (1-on-1)
- [ ] Voice/video call integration
- [ ] Message search functionality
- [ ] File encryption at rest
- [ ] Read receipts
- [ ] Typing indicators
- [ ] Message editing/deletion
- [ ] User presence indicators
- [ ] Push notifications
- [ ] Multi-language support

---

**Made with ❤️ for secure communication**
