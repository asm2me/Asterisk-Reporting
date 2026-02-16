# Asterisk Realtime Report Service Installation

This guide explains how to install and configure the Asterisk Realtime Report service.

## Prerequisites

- **Python 3.6** (specifically required)
- Asterisk PBX with AMI (Asterisk Manager Interface) enabled
- systemd (for service management)
- Proper AMI credentials

### Verify Python 3.6 Installation

Check if Python 3.6 is installed:
```bash
python3.6 --version
```

If not installed, install it (CentOS/RHEL):
```bash
sudo yum install python36
```

Or (Ubuntu/Debian):
```bash
sudo apt-get install python3.6
```

## Installation Steps

### 1. Configure Asterisk Manager Interface (AMI)

Edit `/etc/asterisk/manager.conf`:

```ini
[general]
enabled = yes
port = 5038
bindaddr = 127.0.0.1

[admin]
secret = amp111
deny=0.0.0.0/0.0.0.0
permit=127.0.0.1/255.255.255.0
read = system,call,log,verbose,command,agent,user,config,dtmf,reporting,cdr,dialplan
write = system,call,log,verbose,command,agent,user,config,dtmf,reporting,cdr,dialplan
```

**Note:** Change the secret password to something secure!

Update the Python script (`asterisk-realtime-report.py`) with your AMI credentials:
```python
AMI_HOST = '127.0.0.1'
AMI_PORT = 5038
AMI_USER = 'admin'
AMI_SECRET = 'your_secure_password_here'
```

Reload Asterisk configuration:
```bash
asterisk -rx "manager reload"
```

### 2. Set File Permissions

Make the Python script executable:
```bash
chmod +x /var/www/html/supervisor2/asterisk-realtime-report.py
```

Ensure the asterisk user can write to /tmp:
```bash
chown asterisk:asterisk /var/www/html/supervisor2/asterisk-realtime-report.py
```

### 3. Install the Systemd Service

Copy the service file to systemd directory:
```bash
sudo cp /var/www/html/supervisor2/asterisk-realtime-report.service /etc/systemd/system/
```

Reload systemd daemon:
```bash
sudo systemctl daemon-reload
```

### 4. Enable and Start the Service

Enable the service to start on boot:
```bash
sudo systemctl enable asterisk-realtime-report.service
```

Start the service:
```bash
sudo systemctl start asterisk-realtime-report.service
```

Check service status:
```bash
sudo systemctl status asterisk-realtime-report.service
```

### 5. View Service Logs

View real-time logs:
```bash
sudo journalctl -u asterisk-realtime-report.service -f
```

View recent logs:
```bash
sudo journalctl -u asterisk-realtime-report.service -n 50
```

## Service Management Commands

```bash
# Start the service
sudo systemctl start asterisk-realtime-report.service

# Stop the service
sudo systemctl stop asterisk-realtime-report.service

# Restart the service
sudo systemctl restart asterisk-realtime-report.service

# Check service status
sudo systemctl status asterisk-realtime-report.service

# Disable service (prevent auto-start on boot)
sudo systemctl disable asterisk-realtime-report.service

# Enable service (auto-start on boot)
sudo systemctl enable asterisk-realtime-report.service
```

## Troubleshooting

### Service won't start

1. Check AMI credentials in the Python script
2. Verify Asterisk AMI is enabled and listening
3. Check file permissions
4. View service logs: `journalctl -u asterisk-realtime-report.service -n 50`

### Connection issues

Test AMI connection manually:
```bash
telnet 127.0.0.1 5038
```

You should see:
```
Asterisk Call Manager/X.X.X
```

Then try to login:
```
Action: Login
Username: admin
Secret: your_password

```

### Data not updating

1. Check if service is running: `systemctl status asterisk-realtime-report.service`
2. Verify data file is being updated: `ls -la /tmp/asterisk-realtime-data.json`
3. Check file contents: `cat /tmp/asterisk-realtime-data.json`
4. View service logs for errors

### Permission errors

Ensure the asterisk user has write access to /tmp:
```bash
sudo chown asterisk:asterisk /tmp/asterisk-realtime-data.json
sudo chmod 644 /tmp/asterisk-realtime-data.json
```

## Configuration

The service updates every 2 seconds by default. To change this, edit `asterisk-realtime-report.py`:

```python
UPDATE_INTERVAL = 2  # Change to desired seconds
```

After making changes, restart the service:
```bash
sudo systemctl restart asterisk-realtime-report.service
```

## Accessing the Realtime Report

Once the service is running, access the realtime report through the web interface:

1. Login to Supervisor CDR
2. Click "📡 Realtime Report" button
3. View live call data updating every 2 seconds

## Security Notes

- **Change the default AMI password** in `/etc/asterisk/manager.conf`
- **Update the password** in `asterisk-realtime-report.py`
- Keep AMI bound to localhost (127.0.0.1) for security
- Review AMI permissions - only grant necessary access

## Data File Location

The service writes real-time data to:
```
/var/www/html/supervisor2/data/asterisk-realtime-data.json
```

This file is automatically created and updated by the service.

**Note:** The data directory must exist and be writable by the asterisk user:
```bash
sudo mkdir -p /var/www/html/supervisor2/data
sudo chown asterisk:asterisk /var/www/html/supervisor2/data
sudo chmod 755 /var/www/html/supervisor2/data
```

## Uninstallation

To remove the service:

```bash
# Stop and disable the service
sudo systemctl stop asterisk-realtime-report.service
sudo systemctl disable asterisk-realtime-report.service

# Remove the service file
sudo rm /etc/systemd/system/asterisk-realtime-report.service

# Reload systemd
sudo systemctl daemon-reload

# Remove the data file
sudo rm /var/www/html/supervisor2/data/asterisk-realtime-data.json
```
