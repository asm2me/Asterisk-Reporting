# Changelog

All notable changes to the Asterisk Supervisor CDR & Realtime Monitoring System.

## [2.0.0] - 2026-02-17

### 🚀 Major Features Added

#### WebSocket Realtime Monitoring
- **WebSocket-based architecture** replacing HTTP polling for efficient push updates
- Real-time updates broadcast every second to all connected clients
- Automatic reconnection handling with connection status indicator
- Python service (`asterisk-realtime-websocket.py`) for AMI integration

#### Extension Realtime Monitor
- Live call tracking with active channel monitoring
- Real-time caller ID, destination, and duration display
- WebSocket push updates (no page refresh required)
- Call status tracking (ringing, talking, on-hold)

#### Extension Status Dashboard
- **Live extension states:**
  - 🟢 Online - Extension registered and available
  - 🔴 Offline - Extension not registered
  - 📞 In-Call - Extension on active call
  - ⏸️ Paused - Extension paused from queue
  - ⏳ On-Hold - Call on hold
  - 🔵 Busy - Extension busy
- SIP/PJSIP peer registration monitoring via AMI
- Queue membership status tracking

#### Extension KPI Dashboard
- **Total Handle Time (THT)** - Total seconds on calls today
- **Average Handle Time (AHT)** - Average call duration
- **First Call Start** - Timestamp of first call today
- **Last Call End** - Timestamp of last call today
- Today's call statistics per extension
- Answered vs total calls tracking
- Real-time updates via WebSocket

#### Queue Realtime Monitor
- **Live queue status monitoring**
  - Total queues, calls waiting, available/busy agents
  - Queue health indicators (Healthy / Busy / Critical)
  - Longest wait time tracking

- **Calls in Queue:**
  - Position in queue
  - Caller ID information
  - Live wait time countdown
  - Color-coded alerts for long waits

- **Queue Members:**
  - Extension number and name
  - Status: Available, On Call, Paused
  - Calls taken count
  - Last call timestamp

### 🔧 Technical Improvements

#### Python WebSocket Service
- Python 3.6+ compatibility with `get_event_loop()` approach
- Asterisk Manager Interface (AMI) integration
- MySQL database queries for KPI calculations
- JSON serialization with Decimal handling
- Proper SQL parameter escaping for LIKE patterns
- WebSocket server on port 8765
- Systemd service integration

#### Backend Updates
- Enhanced CDR query functions for KPI metrics
- Extension state tracking from SIP/PJSIP peers
- Queue status queries via AMI
- Session-based authentication for all pages
- Consistent authentication flow across all entry points

#### Frontend Updates
- Dark theme UI with glassmorphic design
- Responsive tables for mobile devices
- Real-time connection status indicators
- Live updating timers and counters
- Color-coded status badges
- Pulse animations for live elements

### 🐛 Bug Fixes
- Fixed Python 3.6 compatibility (`asyncio.run()` → `get_event_loop()`)
- Fixed MySQL Decimal to JSON serialization
- Fixed SQL LIKE pattern escaping in parameterized queries
- Fixed WebSocket handler signature (added path parameter)
- Fixed authentication redirect loop in realtime-queues.php
- Fixed session initialization across all pages

### 📚 Documentation
- **README.md** - Complete rewrite with all features documented
- **docs/SCREENSHOTS.md** - Detailed UI descriptions for all pages
- **docs/TROUBLESHOOTING.md** - Comprehensive troubleshooting guide
- **docs/SECURITY.md** - Extensive security documentation
- **WEBSOCKET_DEPLOYMENT.md** - WebSocket service deployment guide
- **.gitignore** - Protect sensitive configuration files

### 🔐 Security
- Session-based authentication maintained across WebSocket connections
- ACL enforcement in realtime monitoring
- Secure WebSocket configuration (localhost by default)
- File permission guidelines for all components
- AMI credential protection

### 📁 New Files
- `asterisk-realtime-websocket.py` - Python WebSocket service
- `asterisk-realtime-websocket.service` - Systemd service file
- `realtime.php` - Extension realtime monitor entry point
- `realtime-queues.php` - Queue realtime monitor entry point
- `kpi.php` - Extension KPI dashboard entry point
- `ui/realtime.php` - Extension realtime UI
- `ui/realtime-queues.php` - Queue realtime UI
- `ui/kpi.php` - Extension KPI UI
- `WEBSOCKET_DEPLOYMENT.md` - Deployment guide
- `docs/SCREENSHOTS.md` - Screenshots documentation
- `.gitignore` - Git ignore file
- `CHANGELOG.md` - This file

### 🔄 Modified Files
- `bootstrap.php` - Enhanced session management
- `config.json` - Added WebSocket and AMI configuration
- `lib/cdr.php` - Added KPI query functions
- `index.php` - Updated navigation with new pages
- All UI files - Added navigation to new realtime pages

---

## [1.0.0] - Previous Version

### Initial Features
- Session-based login and logout
- Per-user ACL (channel-based filtering)
- Secure recordings playback/download
- Admin-only user management
- CSV export functionality
- Responsive UI with Chart.js
- Date range and disposition filters
- CDR search functionality

### Core Components
- PHP-based CDR reporting
- MySQL/MariaDB database integration
- FreePBX/Issabel integration
- Chart.js for data visualization
- Bootstrap-based UI

---

## Upgrade Notes

### Upgrading from 1.x to 2.0

**Requirements:**
- Python 3.6 or higher
- Python packages: `websockets`, `pymysql`, `asterisk-ami`
- Asterisk Manager Interface (AMI) access
- Port 8765 available for WebSocket server

**Installation Steps:**

1. **Install Python dependencies:**
   ```bash
   pip3 install websockets pymysql asterisk-ami
   ```

2. **Update config.json:**
   Add AMI and WebSocket configuration:
   ```json
   {
     "asterisk": {
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
     }
   }
   ```

3. **Configure AMI:**
   Edit `/etc/asterisk/manager.conf` and add AMI user, then:
   ```bash
   asterisk -rx "manager reload"
   ```

4. **Install WebSocket service:**
   ```bash
   sudo cp asterisk-realtime-websocket.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable asterisk-realtime-websocket
   sudo systemctl start asterisk-realtime-websocket
   ```

5. **Create data directory:**
   ```bash
   mkdir -p /var/www/html/supervisor2/data
   chown apache:asterisk /var/www/html/supervisor2/data
   chmod 770 /var/www/html/supervisor2/data
   ```

6. **Update firewall (if needed):**
   ```bash
   firewall-cmd --permanent --add-port=8765/tcp
   firewall-cmd --reload
   ```

**Breaking Changes:**
- WebSocket service required for realtime features
- New configuration format in config.json
- AMI credentials now in config.json (previously not required)

**What's Still Compatible:**
- Existing user accounts in config_users.json
- CDR reporting functionality
- ACL rules and permissions
- Session management
- Recording playback

---

## Future Roadmap

### Planned Features
- Historical queue analytics
- Agent performance reports
- Call recording search and playback from UI
- Export queue statistics to CSV
- Email alerts for queue thresholds
- Multi-tenant support
- API endpoints for external integration
- Mobile app for realtime monitoring

### Potential Enhancements
- WebSocket authentication tokens
- Redis caching for performance
- GraphQL API
- Webhook notifications
- Custom dashboard widgets
- Advanced filtering and search
- Call recording transcription
- AI-powered call analytics

---

## Support

For issues, questions, or feature requests:
- Review the documentation in `/docs`
- Check the troubleshooting guide
- Review system logs for errors
- Contact your system administrator
