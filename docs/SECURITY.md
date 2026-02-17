# Security Documentation

## 🔐 Overview

This document outlines the security measures implemented in the Asterisk Supervisor CDR & Realtime Monitoring System and provides best practices for secure deployment.

---

## Authentication & Sessions

### Session Management

**Implementation:**
- Session-based authentication using PHP sessions
- `startSecureSession()` called in `bootstrap.php`
- `session_regenerate_id(true)` on successful login to prevent session fixation
- Session destroyed and cookie cleared on logout

**Configuration:**
```php
// In lib/auth.php
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '0'); // Set to '1' if using HTTPS
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}
```

**Best Practices:**
- Always use HTTPS in production
- Set `session.cookie_secure = 1` when using HTTPS
- Configure appropriate `session.gc_maxlifetime` in php.ini
- Use secure session storage directory with proper permissions:
  ```bash
  chmod 770 /var/lib/php/session
  chown root:apache /var/lib/php/session
  ```

---

### Password Storage

**Implementation:**
- Passwords hashed using `password_hash()` with `PASSWORD_BCRYPT`
- Password verification using `password_verify()`
- No plaintext passwords stored anywhere

**Example:**
```php
$hash = password_hash($password, PASSWORD_BCRYPT);
// Stored in config_users.json
```

**Best Practices:**
- Never commit `config_users.json` with real passwords to version control
- Use strong passwords (minimum 12 characters, mix of letters/numbers/symbols)
- Rotate passwords periodically
- Set appropriate file permissions:
  ```bash
  chmod 640 config_users.json
  chown apache:apache config_users.json
  ```

---

## Authorization & Access Control (ACL)

### User Roles

**Admin Users:**
- `is_admin: true` in config_users.json
- Can see ALL calls regardless of extension
- Can access user management interface
- Can add/edit/delete users
- Can modify user extensions and permissions

**Regular Users:**
- `is_admin: false` in config_users.json
- Can ONLY see calls matching their assigned extensions
- Cannot access user management
- Cannot see other users' calls

### ACL Enforcement

**Call Visibility Rules:**

Non-admin users can only view CDR records where their allowed extension matches:
- `channel` begins with `SIP/<ext>-` or `PJSIP/<ext>-`
- OR `dstchannel` begins with `SIP/<ext>-` or `PJSIP/<ext>-`

**Implementation:**
```php
// ACL enforced at database query level in lib/cdr.php
if (!$isAdmin) {
    $extList = implode(',', array_map([$pdo, 'quote'], $allowedExtensions));
    $whereParts[] = "(
        channel LIKE CONCAT('SIP/', $extList, '-%') OR
        dstchannel LIKE CONCAT('PJSIP/', $extList, '-%')
    )";
}
```

**Why Channel-based ACL:**
- CDR `src`/`dst` fields may not reflect actual endpoint
- Channel matching ensures accurate call attribution
- Prevents missing calls in reports

---

### Recording Access Control

**Security Measures:**
- Valid session required
- ACL match (same rule as CDR visibility)
- Path sanitization to prevent directory traversal
- `realpath()` checks to restrict access to recording base directory
- File existence validation before serving

**Implementation:**
```php
// In lib/recordings.php
function getRecordingPath($filename, $allowedExtensions) {
    $base = realpath($CONFIG['recBaseDir']);
    $file = realpath($base . '/' . $filename);

    // Prevent directory traversal
    if (strpos($file, $base) !== 0) {
        return null;
    }

    // ACL check
    if (!userCanAccessRecording($filename, $allowedExtensions)) {
        return null;
    }

    return $file;
}
```

**Best Practices:**
- Set restrictive permissions on recording directory:
  ```bash
  chown -R asterisk:apache /var/spool/asterisk/monitor
  chmod -R 750 /var/spool/asterisk/monitor
  ```
- Keep recordings in a directory outside web root
- Serve through PHP script, not direct web access

---

## CSRF Protection

### Admin Actions

**Protected Operations:**
- Add user
- Edit user
- Delete user
- Change password

**Implementation:**
```php
// CSRF token generated per session
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Verified on form submission
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('CSRF token validation failed');
}
```

**Token included in forms:**
```html
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
```

---

## Input Validation & Sanitization

### XSS Prevention

**HTML Output Escaping:**
```php
// Using h() helper function
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// Usage in views
<td><?= h($row['src']) ?></td>
```

**JavaScript Escaping:**
```javascript
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
```

---

### SQL Injection Prevention

**Prepared Statements:**
```php
// Always use PDO prepared statements
$stmt = $pdo->prepare('SELECT * FROM cdr WHERE src = :src');
$stmt->execute(['src' => $userInput]);
```

**Never use string concatenation:**
```php
// ❌ DANGEROUS
$sql = "SELECT * FROM cdr WHERE src = '$userInput'";

// ✅ SAFE
$stmt = $pdo->prepare('SELECT * FROM cdr WHERE src = ?');
$stmt->execute([$userInput]);
```

---

### Path Traversal Prevention

**Recording File Access:**
```php
// Sanitize filename
$filename = basename($userInput);

// Use realpath to resolve symbolic links and relative paths
$fullPath = realpath($baseDir . '/' . $filename);

// Verify path is within allowed directory
if (strpos($fullPath, realpath($baseDir)) !== 0) {
    die('Access denied');
}
```

---

## WebSocket Security

### Connection Security

**Network Security:**
- WebSocket service listens on localhost by default
- Use reverse proxy (nginx/Apache) for external access
- Enable WSS (WebSocket Secure) for production:
  ```nginx
  location /ws {
      proxy_pass http://localhost:8765;
      proxy_http_version 1.1;
      proxy_set_header Upgrade $http_upgrade;
      proxy_set_header Connection "upgrade";
      proxy_set_header Host $host;
  }
  ```

**Authentication:**
- WebSocket connections currently require user to be logged in via PHP session
- Browser only connects to WebSocket after successful login
- Consider implementing WebSocket-level authentication tokens for additional security

**Firewall Configuration:**
```bash
# Allow only from web server if reverse proxied
firewall-cmd --permanent --add-rich-rule='rule family="ipv4" source address="127.0.0.1" port port="8765" protocol="tcp" accept'
firewall-cmd --reload
```

---

### AMI (Asterisk Manager Interface) Security

**Credentials Protection:**
- AMI credentials stored in `config.json`
- File permissions:
  ```bash
  chmod 640 config.json
  chown asterisk:asterisk config.json
  ```
- Never commit config.json with real credentials

**AMI User Permissions:**
```ini
# /etc/asterisk/manager.conf
[realtime-service]
secret = strong-random-password
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.0
read = system,call,log,verbose,command,agent,user,config,queue
write = system,call,log,verbose,command,agent,user,config,queue
```

**Best Practices:**
- Use dedicated AMI user for this application
- Grant minimum required permissions
- Restrict access to localhost only
- Use strong, random passwords
- Rotate passwords periodically

---

## File Permissions

### Web Application Files

```bash
# Application directory
chown -R apache:apache /var/www/html/supervisor2
chmod -R 755 /var/www/html/supervisor2

# Configuration files (sensitive)
chmod 640 /var/www/html/supervisor2/config.json
chmod 640 /var/www/html/supervisor2/config_users.json

# Runtime data directory
mkdir -p /var/www/html/supervisor2/data
chown apache:asterisk /var/www/html/supervisor2/data
chmod 770 /var/www/html/supervisor2/data
```

### Python Service Files

```bash
# Service script
chown asterisk:asterisk /var/www/html/supervisor2/asterisk-realtime-websocket.py
chmod 750 /var/www/html/supervisor2/asterisk-realtime-websocket.py

# Systemd service file
chown root:root /etc/systemd/system/asterisk-realtime-websocket.service
chmod 644 /etc/systemd/system/asterisk-realtime-websocket.service
```

---

## Database Security

### Database User Permissions

**Principle of Least Privilege:**
```sql
-- Create dedicated database user for application
CREATE USER 'supervisor_app'@'localhost' IDENTIFIED BY 'strong-password';

-- Grant only SELECT on CDR table
GRANT SELECT ON asteriskcdrdb.cdr TO 'supervisor_app'@'localhost';

-- No INSERT, UPDATE, DELETE needed for read-only reporting
FLUSH PRIVILEGES;
```

**Connection Security:**
- Use localhost connection when possible
- Avoid remote database connections unless necessary
- Use SSL for remote connections
- Store credentials in protected files

---

## Network Security

### Firewall Rules

**Minimal Exposure:**
```bash
# Only allow HTTPS from external networks
firewall-cmd --permanent --add-service=https
firewall-cmd --reload

# WebSocket port should NOT be exposed directly
# Use reverse proxy instead
```

### Reverse Proxy Configuration

**Nginx Example:**
```nginx
server {
    listen 443 ssl http2;
    server_name supervisor.example.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    # PHP application
    location / {
        root /var/www/html/supervisor2;
        index index.php;
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm/www.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # WebSocket proxy
    location /ws {
        proxy_pass http://127.0.0.1:8765;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 86400;
    }
}
```

---

## Logging & Monitoring

### Access Logging

**Monitor for suspicious activity:**
```bash
# Check failed login attempts
grep "Login failed" /var/log/httpd/error_log

# Monitor WebSocket connections
journalctl -u asterisk-realtime-websocket | grep "connection"

# Check AMI connection attempts
grep "Manager" /var/log/asterisk/full
```

### Security Auditing

**Regular checks:**
- Review user accounts and permissions monthly
- Check for unauthorized access in web server logs
- Monitor unusual query patterns in MySQL slow query log
- Review systemd service logs for anomalies

**Automated monitoring:**
```bash
# Alert on failed login attempts
grep "Login failed" /var/log/httpd/error_log | mail -s "Failed login attempts" admin@example.com

# Monitor service crashes
systemctl status asterisk-realtime-websocket | grep "failed" && notify-admin
```

---

## Security Hardening

### PHP Configuration

**Recommended php.ini settings:**
```ini
; Hide PHP version
expose_php = Off

; Disable dangerous functions
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source

; Enable display_errors only in development
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log

; Session security
session.cookie_httponly = 1
session.cookie_secure = 1  ; For HTTPS only
session.use_strict_mode = 1
session.cookie_samesite = Strict
session.gc_maxlifetime = 3600
```

### Web Server Hardening

**Apache:**
```apache
# Disable directory listing
Options -Indexes

# Hide server version
ServerTokens Prod
ServerSignature Off

# Prevent access to sensitive files
<FilesMatch "^(config_users\.json|config\.json|\.git)">
    Require all denied
</FilesMatch>
```

**Nginx:**
```nginx
# Hide nginx version
server_tokens off;

# Prevent access to sensitive files
location ~ /\. {
    deny all;
}

location ~ config.*\.json$ {
    deny all;
}
```

---

## Systemd Service Hardening

**Security settings in systemd service file:**
```ini
[Service]
# Run as non-root user
User=asterisk
Group=asterisk

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
PrivateDevices=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/www/html/supervisor2/data

# Resource limits
LimitNOFILE=1024
LimitNPROC=128
```

---

## Backup & Recovery

### Configuration Backup

**Backup sensitive files:**
```bash
# Create encrypted backup
tar czf - config.json config_users.json | \
    openssl enc -aes-256-cbc -salt -out backup-$(date +%Y%m%d).tar.gz.enc

# Restore
openssl enc -aes-256-cbc -d -in backup.tar.gz.enc | tar xz
```

**Exclude from version control:**
```gitignore
config.json
config_users.json
data/
*.log
```

---

## Security Checklist

Before deploying to production:

- [ ] Enable HTTPS with valid SSL certificate
- [ ] Set `session.cookie_secure = 1` in php.ini
- [ ] Set strong AMI password in config.json
- [ ] Set restrictive file permissions (640 for configs, 750 for scripts)
- [ ] Configure firewall to block direct WebSocket access
- [ ] Set up reverse proxy for WebSocket with SSL
- [ ] Create dedicated database user with minimal permissions
- [ ] Disable PHP `display_errors` in production
- [ ] Enable web server access logging
- [ ] Configure log rotation for all logs
- [ ] Set up automated backups of configuration files
- [ ] Review and test ACL rules for each user
- [ ] Document admin password recovery procedure
- [ ] Configure fail2ban or similar intrusion detection
- [ ] Set up monitoring/alerting for service failures
- [ ] Conduct security audit of all user accounts

---

## Incident Response

### Compromised Admin Account

1. **Immediate actions:**
   ```bash
   # Stop web server
   systemctl stop httpd

   # Change AMI password in /etc/asterisk/manager.conf
   asterisk -rx "manager reload"

   # Review access logs
   tail -n 1000 /var/log/httpd/access_log
   ```

2. **Recovery:**
   - Reset admin password in config_users.json
   - Review and reset all user accounts
   - Check for unauthorized changes
   - Restore from backup if necessary

### Suspicious Activity

1. **Investigation:**
   ```bash
   # Check recent logins
   grep "Login" /var/log/httpd/error_log | tail -50

   # Check WebSocket connections
   journalctl -u asterisk-realtime-websocket -n 100

   # Check active sessions
   ls -la /var/lib/php/session/
   ```

2. **Mitigation:**
   - Clear all sessions
   - Force password reset for affected users
   - Review ACL rules and permissions
   - Update firewall rules if needed

---

## Contact & Support

For security issues or concerns:
- Contact your system administrator
- Review logs for suspicious activity
- Keep system and dependencies updated
- Follow security best practices outlined in this document
