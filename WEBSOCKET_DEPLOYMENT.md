# WebSocket Realtime Service Deployment Guide

This guide explains how to deploy the new WebSocket-based realtime service to replace the old polling-based service.

## Prerequisites

1. Python 3.6 or higher
2. Required Python packages:
   ```bash
   pip3 install websockets pymysql
   ```

## Installation Steps

### 1. Copy files to server

Copy the following files to `/var/www/html/supervisor2/`:
- `asterisk-realtime-websocket.py`
- `asterisk-realtime-websocket.service`
- `config.json` (already exists, updated with WebSocket settings)
- `ui/realtime.php` (already exists, updated to use WebSocket)

### 2. Set file permissions

```bash
# Set ownership
sudo chown asterisk:asterisk /var/www/html/supervisor2/asterisk-realtime-websocket.py
sudo chmod +x /var/www/html/supervisor2/asterisk-realtime-websocket.py

# Set permissions for service file
sudo cp /var/www/html/supervisor2/asterisk-realtime-websocket.service /etc/systemd/system/
sudo chmod 644 /etc/systemd/system/asterisk-realtime-websocket.service
```

### 3. Configure firewall (if needed)

If you're running a firewall, open port 8765 for WebSocket connections:

```bash
# For firewalld
sudo firewall-cmd --permanent --add-port=8765/tcp
sudo firewall-cmd --reload

# For iptables
sudo iptables -A INPUT -p tcp --dport 8765 -j ACCEPT
sudo service iptables save
```

### 4. Stop the old polling service

```bash
# Stop and disable the old service
sudo systemctl stop asterisk-realtime-report.service
sudo systemctl disable asterisk-realtime-report.service
```

### 5. Start the new WebSocket service

```bash
# Reload systemd to recognize the new service
sudo systemctl daemon-reload

# Enable the service to start on boot
sudo systemctl enable asterisk-realtime-websocket.service

# Start the service
sudo systemctl start asterisk-realtime-websocket.service

# Check status
sudo systemctl status asterisk-realtime-websocket.service
```

### 6. Verify the service is running

```bash
# Check service status
sudo systemctl status asterisk-realtime-websocket.service

# View logs
sudo journalctl -u asterisk-realtime-websocket.service -f

# Test WebSocket connection
# You should see messages like:
# ✓ Loaded configuration from /var/www/html/supervisor2/config.json
# ✓ Gateways: ['we']
# 🌐 Starting WebSocket server on ws://0.0.0.0:8765
# ✓ Connected to AMI at 127.0.0.1:5038
# ✓ AMI login successful
```

### 7. Test from browser

1. Navigate to your realtime report page
2. Open browser console (F12)
3. Look for messages like:
   - "Starting WebSocket realtime connection..."
   - "Connecting to WebSocket: ws://[your-server]:8765"
   - "✓ WebSocket connected"
4. The green connection indicator should appear at the top of the page
5. Data should update in real-time as calls occur

## Troubleshooting

### WebSocket connection refused
- Verify the service is running: `sudo systemctl status asterisk-realtime-websocket.service`
- Check if port 8765 is open: `sudo netstat -tlnp | grep 8765`
- Check firewall rules
- Review service logs: `sudo journalctl -u asterisk-realtime-websocket.service -n 50`

### No data appearing
- Check AMI connection in service logs
- Verify AMI credentials in config.json
- Ensure database connection is working
- Check that asterisk user has permissions

### Browser errors
- Check browser console for errors
- Verify WebSocket URL is correct (should use your server's hostname/IP)
- If using HTTPS, ensure WebSocket uses WSS protocol

### Service crashes or restarts
- View crash logs: `sudo journalctl -u asterisk-realtime-websocket.service -n 100`
- Check Python dependencies are installed
- Verify file permissions

## Configuration

All settings are in `config.json`:

```json
{
  "realtime": {
    "websocketHost": "0.0.0.0",
    "websocketPort": 8765,
    "dbConfigFile": "/etc/amportal.conf"
  },
  "asterisk": {
    "ami": {
      "host": "127.0.0.1",
      "port": 5038,
      "username": "reporting",
      "secret": "your-secret"
    },
    "gateways": ["PJSIP/we"]
  }
}
```

## Benefits of WebSocket vs Polling

1. **Real-time updates**: Data pushed immediately when changes occur
2. **Lower latency**: No polling delay
3. **Reduced server load**: Single persistent connection vs repeated HTTP requests
4. **Better scalability**: WebSocket connections are more efficient
5. **Cleaner architecture**: Event-driven design with async/await

## Rollback (if needed)

If you need to revert to the old polling service:

```bash
# Stop WebSocket service
sudo systemctl stop asterisk-realtime-websocket.service
sudo systemctl disable asterisk-realtime-websocket.service

# Start old polling service
sudo systemctl enable asterisk-realtime-report.service
sudo systemctl start asterisk-realtime-report.service

# Restore old ui/realtime.php from backup (if you made one)
```
