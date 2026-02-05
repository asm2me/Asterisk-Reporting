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


---

# docs/SCREENSHOTS.md (copy/paste)

```md
# Screenshots Guide

Place screenshots in:

`docs/screenshots/`

Use PNG preferred. Recommended width: **1200px** (or higher) so text stays readable.

---

## 1) Login Screen

Filename:
- `docs/screenshots/login.png`

![Login](screenshots/login.png)

What to capture:
- Empty login form
- Optional: show an invalid login error message once

---

## 2) Dashboard Header (Top bar)

Filename:
- `docs/screenshots/dashboard-top.png`

![Dashboard Top](screenshots/dashboard-top.png)

What to capture:
- Username badge
- Date range pills
- Buttons: User Management (admin), Export CSV, Logout

---

## 3) Totals Cards

Filename:
- `docs/screenshots/totals-cards.png`

![Totals Cards](screenshots/totals-cards.png)

What to capture:
- Total Calls
- Answered
- No Answer
- Busy
- Failed
- Congested

---

## 4) Totals Bar Chart

Filename:
- `docs/screenshots/totals-chart.png`

![Totals Chart](screenshots/totals-chart.png)

What to capture:
- Chart with colored bars + legend
- Ensure there is data (use a day with calls)

---

## 5) Filters Section

Filename:
- `docs/screenshots/filters.png`

![Filters](screenshots/filters.png)

What to capture:
- From / To / Search
- Src and Dst fields
- Disposition dropdown
- Min billsec
- Per page / Sort / Direction
- Apply / Reset buttons

---

## 6) CDR Table

Filename:
- `docs/screenshots/table.png`

![Table](screenshots/table.png)

What to capture:
- A few rows visible
- Columns including Channel and DstChannel

---

## 7) Recording Playback (Inline audio)

Filename:
- `docs/screenshots/recording-inline.png`

![Recording Inline](screenshots/recording-inline.png)

What to capture:
- A row with audio player visible
- Listen / Download links

---

## 8) Export CSV

Filename:
- `docs/screenshots/export-csv.png`

![Export CSV](screenshots/export-csv.png)

What to capture:
- Browser download prompt OR downloaded file in browser downloads list
- Optional: open CSV in spreadsheet view (redact sensitive numbers if needed)

---

# Admin Screens

> Only admin can access these screens.

---

## 9) User Management (List)

Filename:
- `docs/screenshots/user-management.png`

![User Management](screenshots/user-management.png)

What to capture:
- Existing users table
- Back to report button
- Logout button

---

## 10) Add User

Filename:
- `docs/screenshots/user-add.png`

![Add User](screenshots/user-add.png)

What to capture:
- Add user form filled (do not show real passwords)
- Allowed extensions example: `1000,1001`

---

## 11) Edit User

Filename:
- `docs/screenshots/user-edit.png`

![Edit User](screenshots/user-edit.png)

What to capture:
- Edit mode for a user
- Extensions + Admin checkbox

---

## 12) Change Password

Filename:
- `docs/screenshots/user-change-password.png`

![Change Password](screenshots/user-change-password.png)

What to capture:
- Change password form (empty fields)

---

## 13) Logout

Filename:
- `docs/screenshots/logout.png`

![Logout](screenshots/logout.png)

What to capture:
- After clicking Logout, the Login screen appears again
