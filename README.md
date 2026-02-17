# Asterisk Supervisor CDR & Realtime Monitoring System

A comprehensive PHP/Python application for Asterisk/FreePBX/Issabel with:

## 🎯 Core Features

### CDR Reporting & Analytics
- **Session-based authentication** with secure login/logout
- **Per-user ACL** - Users can only see calls matching their allowed extensions
- **Secure recordings playback/download** with ACL enforcement
- **Admin user management** - Add/edit/delete users, change passwords
- **CSV export** with filtered data
- **Responsive UI** with dark theme
- **Interactive charts** using Chart.js (self-hosted)
- **Advanced filters** - Date range, source, destination, disposition, search

### 📡 Realtime Monitoring (WebSocket-based)
- **Extension Realtime Monitor** - Live view of all active calls
  - Real-time call status tracking
  - Caller ID, destination, duration tracking
  - Channel status visibility
  - WebSocket push updates (no polling)

- **Extension Status Dashboard**
  - Live extension states: Online, Offline, In-Call, Busy, On-Hold, Paused
  - SIP/PJSIP peer registration monitoring
  - Queue membership status

- **Extension KPIs**
  - Total Handle Time (THT)
  - Average Handle Time (AHT)
  - First Call Start timestamp
  - Last Call End timestamp
  - Today's call statistics per extension
  - Answered vs total calls

### 📞 Queue Realtime Monitor
- **Live queue status** with WebSocket updates
- **Calls waiting in queue** with position and wait time
- **Queue member tracking**
  - Available, Busy, Paused, On-Call status
  - Calls taken count
  - Last call timestamp
- **Queue health indicators**
  - Healthy / Busy / Critical status
  - Available vs busy agent ratio
  - Longest wait time alerts

---

## 📸 Screenshots

> See: [screenshots/](screenshots/)

Quick preview:

![Login](screenshots/login.png)
![Dashboard](screenshots/dashboard-top.png)
![CDR Table](screenshots/table.png)
![User Management](screenshots/user-management.png)

---

## 📋 Requirements

### PHP Application
- **PHP 7.x or PHP 8.x**
- **MySQL/MariaDB** access to CDR database
- Access to FreePBX/Issabel DB credential files:
  - `/etc/amportal.conf` OR `/etc/freepbx.conf`
- **Asterisk recordings directory** (default: `/var/spool/asterisk/monitor`)
- **Chart.js UMD build** (self-hosted): `chart.umd.min.js`
- **Web server**: Apache or Nginx with PHP-FPM

### Python WebSocket Service (for realtime features)
- **Python 3.6+** (tested on Python 3.6)
- **Python packages**:
  - `websockets` - WebSocket server
  - `pymysql` - MySQL database connector
  - `asterisk.manager` - Asterisk Manager Interface (AMI) client
- **Asterisk Manager Interface (AMI)** access
- **Network access**: Port 8765 for WebSocket connections

---

## 🚀 Installation

### 1. PHP Application Setup

**Copy files to web directory:**
```bash
cd /var/www/html
git clone <this-repo> supervisor2
cd supervisor2
```

**Install Chart.js locally:**
```bash
curl -L -o chart.umd.min.js https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js
```

**Set correct ownership and permissions:**
```bash
chown -R apache:apache /var/www/html/supervisor2
chmod 640 /var/www/html/supervisor2/config_users.json
chmod 640 /var/www/html/supervisor2/config.json
```

**Create required directories:**
```bash
mkdir -p /var/www/html/supervisor2/data
chown apache:apache /var/www/html/supervisor2/data
chmod 755 /var/www/html/supervisor2/data
```

### 2. Configuration

**Edit `config.json`:**
```json
{
  "database": {
    "cdrDb": "asteriskcdrdb",
    "cdrTable": "cdr"
  },
  "asterisk": {
    "gateways": ["PJSIP/we"],
    "recordings": {
      "baseDir": "/var/spool/asterisk/monitor"
    },
    "ami": {
      "host": "localhost",
      "port": 5038,
      "username": "admin",
      "secret": "your-ami-password"
    }
  },
  "realtime": {
    "websocketHost": "0.0.0.0",
    "websocketPort": 8765
  },
  "ui": {
    "assetsUrl": "assets"
  }
}
```

**Configure users in `config_users.json`:**
```json
{
  "users": {
    "admin": {
      "password_hash": "$2y$10$...",
      "is_admin": true,
      "extensions": ["1000", "1001", "1002"]
    },
    "agent1": {
      "password_hash": "$2y$10$...",
      "is_admin": false,
      "extensions": ["2000"]
    }
  }
}
```

**Note:** Generate password hashes in PHP:
```php
<?php echo password_hash('yourpassword', PASSWORD_BCRYPT); ?>
```

### 3. Python WebSocket Service Setup

**Install Python dependencies:**
```bash
pip3 install websockets pymysql asterisk-ami
```

**Test the service:**
```bash
python3.6 asterisk-realtime-websocket.py
```

**Install as systemd service:**
```bash
# Copy service file
sudo cp asterisk-realtime-websocket.service /etc/systemd/system/

# Edit service file to match your paths
sudo nano /etc/systemd/system/asterisk-realtime-websocket.service

# Enable and start service
sudo systemctl daemon-reload
sudo systemctl enable asterisk-realtime-websocket
sudo systemctl start asterisk-realtime-websocket

# Check status
sudo systemctl status asterisk-realtime-websocket
```

**View logs:**
```bash
sudo journalctl -u asterisk-realtime-websocket -f
```

For detailed deployment instructions, see: [WEBSOCKET_DEPLOYMENT.md](WEBSOCKET_DEPLOYMENT.md)

---

## 🔧 Configuration Details

### Environment Variables (Optional)

You can override config.json settings with environment variables:

```apache
# Apache example (.htaccess or VirtualHost)
SetEnv CDR_DB asteriskcdrdb
SetEnv CDR_TABLE cdr
SetEnv REC_BASEDIR /var/spool/asterisk/monitor
```

### AMI Configuration

Add AMI user in `/etc/asterisk/manager.conf`:
```ini
[admin]
secret = your-ami-password
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.0
read = system,call,log,verbose,command,agent,user,config
write = system,call,log,verbose,command,agent,user,config
```

Then reload AMI:
```bash
asterisk -rx "manager reload"
```

### Firewall Configuration

If accessing from remote browsers, allow WebSocket port:
```bash
firewall-cmd --permanent --add-port=8765/tcp
firewall-cmd --reload
```

---

## 📚 Usage

### Accessing the Application

**Main Dashboard (CDR Reports):**
```
http(s)://<server>/supervisor2/
```

**Available Pages:**
- `/` or `index.php` - CDR Report with filters and charts
- `realtime.php` - Extension realtime monitor with live call tracking
- `realtime-queues.php` - Queue realtime monitor with waiting calls
- `kpi.php` - Extension KPI dashboard
- `?page=users` - User management (admin only)

### User Roles

**Admin Users:**
- Can see all calls
- Can access user management
- Can add/edit/delete users
- Can manage extensions for all users

**Regular Users:**
- Can only see calls matching their allowed extensions
- Cannot access user management
- Cannot see other users' calls

---

## 🔐 ACL Rules

For non-admin users, calls are visible only if their allowed extension matches the channel or destination channel:

**Matching patterns:**
- `SIP/<ext>-...`
- `PJSIP/<ext>-...`

This ensures users only see calls involving their assigned extensions, regardless of the src/dst values in the CDR.

### Filter Behavior

**Source field matches:**
```
src = <digits> OR channel LIKE 'SIP/<digits>-%' OR channel LIKE 'PJSIP/<digits>-%'
```

**Destination field matches:**
```
dst = <digits> OR dstchannel LIKE 'SIP/<digits>-%' OR dstchannel LIKE 'PJSIP/<digits>-%'
```

**Search matches:**
- `src`, `dst`, `clid`, `uniqueid`, `channel`, `dstchannel` via LIKE

**Disposition options:**
- Answered
- No Answer
- Busy
- Failed
- Congestion

---

## 🛡️ Security

See: [docs/SECURITY.md](docs/SECURITY.md)

**Key security features:**
- Secure session management with `session_regenerate_id()`
- Password hashing with bcrypt (`PASSWORD_BCRYPT`)
- ACL enforcement at database query level
- Recording access controlled by user extensions
- CSRF protection on forms
- XSS prevention with HTML escaping
- SQL injection prevention with prepared statements

---

## 🔍 Troubleshooting

See: [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)

### Common Issues

**WebSocket connection failed:**
- Check if service is running: `systemctl status asterisk-realtime-websocket`
- Check firewall: `firewall-cmd --list-ports`
- Check logs: `journalctl -u asterisk-realtime-websocket -f`

**No realtime data:**
- Verify AMI credentials in config.json
- Check AMI connectivity: `telnet localhost 5038`
- Verify data directory permissions: `ls -la /var/www/html/supervisor2/data`

**Session/login issues:**
- Check PHP session directory: `ls -la /var/lib/php/session`
- Verify session permissions
- Check bootstrap.php is being loaded

---

## 📁 File Structure

```
supervisor2/
├── index.php                           # Main CDR report entry point
├── realtime.php                        # Extension realtime monitor entry
├── realtime-queues.php                 # Queue realtime monitor entry
├── kpi.php                             # Extension KPI entry
├── bootstrap.php                       # App initialization
├── config.json                         # Main configuration
├── config_users.json                   # User accounts
├── asterisk-realtime-websocket.py      # Python WebSocket service
├── asterisk-realtime-websocket.service # Systemd service file
├── lib/
│   ├── auth.php                        # Authentication functions
│   ├── acl.php                         # ACL enforcement
│   ├── cdr.php                         # CDR query functions
│   ├── db_creds.php                    # Database credentials
│   ├── helpers.php                     # Helper functions
│   ├── recordings.php                  # Recording functions
│   └── users_store.php                 # User management
├── ui/
│   ├── login.php                       # Login page UI
│   ├── report.php                      # CDR report UI
│   ├── users.php                       # User management UI
│   ├── realtime.php                    # Extension realtime UI
│   ├── realtime-queues.php             # Queue realtime UI
│   └── kpi.php                         # Extension KPI UI
├── data/                               # Runtime data directory
├── docs/
│   ├── SECURITY.md                     # Security documentation
│   └── TROUBLESHOOTING.md              # Troubleshooting guide
├── screenshots/                        # UI screenshots
├── WEBSOCKET_DEPLOYMENT.md             # WebSocket service deployment guide
└── README.md                           # This file
```

---

## 🔄 WebSocket Architecture

The realtime monitoring features use WebSocket for efficient push-based updates:

1. **Python service** (`asterisk-realtime-websocket.py`) connects to:
   - Asterisk AMI for live channel/queue/peer status
   - MySQL CDR database for call statistics

2. **WebSocket server** (port 8765) broadcasts JSON updates every second:
   - Active calls with status
   - Extension states (online/offline/in-call/etc.)
   - Queue status with waiting calls and member availability
   - Extension KPIs

3. **Web UI** connects via WebSocket and receives live updates
   - No polling required
   - Automatic reconnection on disconnect
   - Connection status indicator

---

## 📊 Data Flow

```
Asterisk PBX
    ↓ AMI
    ↓ CDR → MySQL
    ↓
asterisk-realtime-websocket.py
    ↓ WebSocket (port 8765)
    ↓
Browser (realtime.php, realtime-queues.php)
```

---

## 🤝 Contributing

This is an internal project. For issues or feature requests, contact the development team.

---

## 📄 License

Internal / private use. Update as needed for your organization.

---

## 📞 Support

For detailed documentation:
- [WebSocket Deployment Guide](WEBSOCKET_DEPLOYMENT.md)
- [Security Best Practices](docs/SECURITY.md)
- [Troubleshooting Guide](docs/TROUBLESHOOTING.md)
