# Supervisor CDR Report (ACL + Recordings + Admin User Management)

A lightweight PHP app for browsing Asterisk/FreePBX/Issabel CDRs with:
- Session-based login + Logout
- Per-user ACL (can only see calls that match allowed extensions via SIP/PJSIP channels)
- Secure recordings playback/download (ACL enforced)
- Admin-only user management (add/edit/delete users, change password)
- CSV export
- Responsive UI
- Totals bar chart (Chart.js, self-hosted)

---

## Screenshots

> See: [docs/SCREENSHOTS.md](docs/SCREENSHOTS.md)

Quick preview:

![Login](docs/screenshots/login.png)
![Dashboard](docs/screenshots/dashboard-top.png)
![User Management](docs/screenshots/user-management.png)

---

## Requirements

- PHP 7.x or PHP 8.x
- MySQL/MariaDB access to CDR database
- Access to FreePBX/Issabel DB credential files:
  - `/etc/amportal.conf` OR `/etc/freepbx.conf`
- Asterisk recordings directory (default):
  - `/var/spool/asterisk/monitor`
- Chart.js UMD build (self-hosted): `chart.umd.min.js`

---

## Installation

1) Copy files into a web directory:
- `index.php`
- `config_users.json`
- `chart.umd.min.js`

2) Set correct ownership and permissions (example):
```bash
chown -R apache:apache /var/www/html/supervisor
chmod 640 /var/www/html/supervisor/config_users.json
Place Chart.js locally:

cd /var/www/html/supervisor
curl -L -o chart.umd.min.js https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js
Optional environment variables:

CDR_DB (default: asteriskcdrdb)

CDR_TABLE (default: cdr)

REC_BASEDIR (default: /var/spool/asterisk/monitor)

Example (Apache):

SetEnv CDR_DB asteriskcdrdb
SetEnv CDR_TABLE cdr
SetEnv REC_BASEDIR /var/spool/asterisk/monitor
Browse:

http(s)://<server>/supervisor/

config_users.json format
Example:

{
  "users": {
    "admin": {
      "password_hash": "$2y$10$...",
      "is_admin": true,
      "extensions": ["1000", "1001"]
    },
    "agent1": {
      "password_hash": "$2y$10$...",
      "is_admin": false,
      "extensions": ["2000"]
    }
  }
}
Passwords are stored using password_hash().

ACL rules (important)
For non-admin users, calls are visible only if their allowed extension matches channel or dstchannel:

SIP/<ext>-... or PJSIP/<ext>-...

This avoids missing records when src/dst values differ from actual endpoint/channel in CDR.

Filters behavior
Src field matches:

src = <digits> OR channel LIKE SIP/<digits>-% OR channel LIKE PJSIP/<digits>-%

Dst field matches:

dst = <digits> OR dstchannel LIKE SIP/<digits>-% OR dstchannel LIKE PJSIP/<digits>-%

Search matches src/dst/clid/uniqueid/channel/dstchannel via LIKE

Disposition supports Answered / No Answer / Busy / Failed / Congestion

Admin user management
Admin can access:

?page=users

Functions:

list users

add user (password required)

edit user extensions/admin flag

change password

delete user (admin protected)

Security notes
See: docs/SECURITY.md

Troubleshooting
See: docs/TROUBLESHOOTING.md

License
Internal / private use (update as needed).



