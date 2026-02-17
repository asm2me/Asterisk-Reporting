# Troubleshooting Guide

## 📊 CDR Reporting Issues

### 1) "Chart is not defined"

**Cause:** Chart.js not loaded.

**Fix:**
- Ensure `chart.umd.min.js` exists next to `index.php`
- Ensure the script tag points to it: `<script src="chart.umd.min.js"></script>`
- Verify your webserver can serve .js files (no blocked MIME types)
- Check browser console for 404 errors

**Download Chart.js:**
```bash
cd /var/www/html/supervisor2
curl -L -o chart.umd.min.js https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js
```

---

### 2) No data shown / missing calls

**Common reasons:**
- Filtering by `src/dst` only (but your CDR `src/dst` may not match actual endpoint)
- This app matches visibility and filtering using `channel/dstchannel` for SIP/PJSIP endpoints
- ACL rules limiting visibility to assigned extensions only

**If you still miss calls:**
- Inspect a missing row in MySQL:
  ```sql
  SELECT channel, dstchannel, src, dst FROM cdr WHERE uniqueid = 'xxx';
  ```
- Check if channels start with `SIP/<ext>-` or `PJSIP/<ext>-`
- Some trunks may show channels like `SIP/provider-...` instead of extension channels
- In those cases, ACL rules must be extended to also allow those patterns

**Check user extensions:**
- Verify user has correct extensions assigned in `config_users.json`
- Admin users can see all calls regardless of ACL

---

### 3) Recording not found

**Reasons:**
- `recordingfile` field empty in CDR
- File not present in `REC_BASEDIR`
- Naming or directory structure differs
- Permissions issue

**Fix:**
```bash
# Check recording directory
ls -la /var/spool/asterisk/monitor

# Check recording file from CDR
mysql -u root -p asteriskcdrdb -e "SELECT recordingfile FROM cdr WHERE recordingfile != '' LIMIT 5;"

# Set correct permissions
chown -R asterisk:apache /var/spool/asterisk/monitor
chmod -R 750 /var/spool/asterisk/monitor
```

**Update config.json:**
```json
{
  "asterisk": {
    "recordings": {
      "baseDir": "/var/spool/asterisk/monitor"
    }
  }
}
```

---

### 4) Permission denied on config_users.json

**Fix permissions:**
```bash
chmod 640 /var/www/html/supervisor2/config_users.json
chown apache:apache /var/www/html/supervisor2/config_users.json
```

Replace `apache` with your web server user (could be `www-data`, `nginx`, `httpd`).

---

### 5) DB connection fails

**Check credentials:**
```bash
# Verify credential files exist
ls -la /etc/amportal.conf
ls -la /etc/freepbx.conf

# Test database connection
mysql -h localhost -u asteriskuser -p asteriskcdrdb
```

**Verify config.json:**
```json
{
  "database": {
    "cdrDb": "asteriskcdrdb",
    "cdrTable": "cdr"
  }
}
```

**Check PHP can read credential files:**
```bash
sudo -u apache cat /etc/amportal.conf
```

---

## 📡 WebSocket Realtime Issues

### 6) WebSocket connection failed

**Symptoms:**
- Red connection status bar
- "Disconnected - Reconnecting..." message
- No live updates

**Check service status:**
```bash
systemctl status asterisk-realtime-websocket
```

**Check if service is running:**
```bash
ps aux | grep asterisk-realtime-websocket
```

**Check if port is listening:**
```bash
netstat -tulpn | grep 8765
# or
ss -tulpn | grep 8765
```

**Check firewall:**
```bash
firewall-cmd --list-ports
firewall-cmd --permanent --add-port=8765/tcp
firewall-cmd --reload
```

**View service logs:**
```bash
journalctl -u asterisk-realtime-websocket -f
```

**Test WebSocket manually:**
```bash
# Install websocat
wget https://github.com/vi/websocat/releases/download/v1.11.0/websocat_amd64-linux
chmod +x websocat_amd64-linux

# Test connection
./websocat_amd64-linux ws://localhost:8765
```

---

### 7) Python service crashes on startup

**Check Python version:**
```bash
python3.6 --version
# Should be 3.6 or higher
```

**Check dependencies:**
```bash
pip3.6 list | grep -E 'websockets|pymysql|asterisk'
```

**Install missing dependencies:**
```bash
pip3.6 install websockets pymysql asterisk-ami
```

**Check config.json syntax:**
```bash
python3.6 -c "import json; print(json.load(open('config.json')))"
```

**Run service manually for debugging:**
```bash
cd /var/www/html/supervisor2
python3.6 asterisk-realtime-websocket.py
# Watch for error messages
```

**Common errors:**

**"AttributeError: module 'asyncio' has no attribute 'run'"**
- Fixed in current version, uses `get_event_loop()` for Python 3.6 compatibility

**"Object of type 'Decimal' is not JSON serializable"**
- Fixed in current version, converts all DB decimals to int

**"unsupported format character"**
- Fixed in current version, SQL patterns properly escaped

---

### 8) AMI connection issues

**Symptoms:**
- No extension status updates
- No queue data
- Service logs show "AMI connection failed"

**Check AMI is enabled:**
```bash
asterisk -rx "manager show settings"
```

**Test AMI connection:**
```bash
telnet localhost 5038
```

You should see:
```
Asterisk Call Manager/x.x.x
```

**Check AMI credentials in config.json:**
```json
{
  "asterisk": {
    "ami": {
      "host": "localhost",
      "port": 5038,
      "username": "admin",
      "secret": "your-ami-password"
    }
  }
}
```

**Check /etc/asterisk/manager.conf:**
```ini
[general]
enabled = yes
port = 5038
bindaddr = 0.0.0.0

[admin]
secret = your-ami-password
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.0
read = system,call,log,verbose,command,agent,user,config
write = system,call,log,verbose,command,agent,user,config
```

**Reload AMI:**
```bash
asterisk -rx "manager reload"
```

---

### 9) No realtime data / stale data

**Check data directory:**
```bash
ls -la /var/www/html/supervisor2/data/
```

**Check if service is writing data:**
```bash
watch -n 1 ls -lh /var/www/html/supervisor2/data/asterisk-realtime-data.json
```

File timestamp should update every second.

**Check permissions:**
```bash
chown asterisk:asterisk /var/www/html/supervisor2/data
chmod 755 /var/www/html/supervisor2/data
```

**Check service logs:**
```bash
journalctl -u asterisk-realtime-websocket -n 100
```

---

### 10) Extension status shows all offline

**Possible causes:**
- SIP/PJSIP peers not registered
- AMI permissions insufficient
- Service can't query peer status

**Check SIP peers:**
```bash
asterisk -rx "sip show peers"
# or for PJSIP
asterisk -rx "pjsip show endpoints"
```

**Verify AMI has read permissions:**
```bash
# In manager.conf
read = system,call,log,verbose,command,agent,user,config
```

**Check service logs for AMI errors:**
```bash
journalctl -u asterisk-realtime-websocket -f | grep -i "sip\|pjsip\|peer"
```

---

### 11) Queue realtime shows no queues

**Check queues are configured:**
```bash
asterisk -rx "queue show"
```

**Check AMI queue permissions:**
```bash
# In manager.conf
read = system,call,log,verbose,command,agent,user,config,queue
```

**Reload queues:**
```bash
asterisk -rx "module reload app_queue.so"
```

**Check service logs:**
```bash
journalctl -u asterisk-realtime-websocket -f | grep -i queue
```

---

## 🔐 Authentication & Session Issues

### 12) Session/login issues

**Check PHP session directory:**
```bash
ls -la /var/lib/php/session
# or
ls -la /var/lib/php/sessions
```

**Check permissions:**
```bash
chown -R root:apache /var/lib/php/session
chmod 770 /var/lib/php/session
```

**Check session configuration:**
```bash
php -i | grep session
```

**Clear old sessions:**
```bash
find /var/lib/php/session -type f -mtime +7 -delete
```

---

### 13) "Too many redirects" error

**Cause:** Session not being maintained between pages.

**Check bootstrap.php is loaded:**
```bash
grep -n "require.*bootstrap" realtime-queues.php
```

**Verify session is started:**
```bash
grep -n "startSecureSession" bootstrap.php
```

**Check browser cookies:**
- Clear browser cookies for the site
- Try incognito/private browsing mode
- Check browser console for errors

---

### 14) "Call to undefined function"

**Cause:** Missing includes or function definitions.

**Check file includes:**
```bash
grep -r "function functionName" lib/
```

**Verify bootstrap.php includes all required files:**
```bash
grep "require_once" bootstrap.php
```

**Check for typos in function names.**

---

## 🔧 Performance Issues

### 15) Slow CDR queries

**Add database indexes:**
```sql
USE asteriskcdrdb;

ALTER TABLE cdr ADD INDEX idx_calldate (calldate);
ALTER TABLE cdr ADD INDEX idx_src (src);
ALTER TABLE cdr ADD INDEX idx_dst (dst);
ALTER TABLE cdr ADD INDEX idx_channel (channel(50));
ALTER TABLE cdr ADD INDEX idx_dstchannel (dstchannel(50));
ALTER TABLE cdr ADD INDEX idx_disposition (disposition);
```

**Check query performance:**
```sql
EXPLAIN SELECT * FROM cdr WHERE calldate >= '2026-02-17 00:00:00';
```

---

### 16) High CPU usage from WebSocket service

**Check update interval in Python service:**
- Default is 1 second updates
- Can be increased if too frequent

**Check number of connected clients:**
```bash
netstat -an | grep 8765 | grep ESTABLISHED | wc -l
```

**Monitor resource usage:**
```bash
top -p $(pgrep -f asterisk-realtime-websocket)
```

---

## 🐛 Debugging Tips

### Enable Python debug logging

Edit `asterisk-realtime-websocket.py` and add debug prints.

### Enable PHP error logging

```php
// In bootstrap.php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
```

### Check Apache/Nginx error logs

```bash
tail -f /var/log/httpd/error_log
# or
tail -f /var/log/nginx/error.log
```

### Use browser developer tools

- Open browser DevTools (F12)
- Check Console tab for JavaScript errors
- Check Network tab for failed requests
- Check WebSocket frames in Network tab

### Test WebSocket in browser console

```javascript
const ws = new WebSocket('ws://your-server:8765');
ws.onopen = () => console.log('Connected!');
ws.onmessage = (e) => console.log('Message:', e.data);
ws.onerror = (e) => console.error('Error:', e);
```

---

## 📞 Getting Help

If you're still experiencing issues:

1. Check service logs:
   ```bash
   journalctl -u asterisk-realtime-websocket -n 100 --no-pager
   ```

2. Check web server logs:
   ```bash
   tail -n 100 /var/log/httpd/error_log
   ```

3. Check Asterisk logs:
   ```bash
   tail -n 100 /var/log/asterisk/full
   ```

4. Collect diagnostic information:
   ```bash
   systemctl status asterisk-realtime-websocket
   netstat -tulpn | grep 8765
   asterisk -rx "sip show peers"
   asterisk -rx "queue show"
   ```

5. Include relevant logs and error messages when seeking support.
