# Troubleshooting

## 1) "Chart is not defined"
Cause: Chart.js not loaded.

Fix:
- Ensure `chart.umd.min.js` exists next to `index.php`
- Ensure the script tag points to it:
  `<script src="chart.umd.min.js"></script>`
- Verify your webserver can serve .js files (no blocked MIME types)

## 2) No data shown / missing calls
Common reasons:
- Filtering by `src/dst` only (but your CDR `src/dst` may not match actual endpoint)
- This app matches visibility and filtering using `channel/dstchannel` for SIP/PJSIP endpoints.

If you still miss calls:
- Inspect a missing row in MySQL:
  - what values are in `channel` and `dstchannel`?
  - do they start with SIP/<ext>- or PJSIP/<ext>- ?
- Some trunks may show channels like `SIP/provider-...` instead of extension channels.
  In those cases, ACL rules must be extended to also allow those patterns.

## 3) Recording not found
Reasons:
- `recordingfile` empty in CDR
- file not present in `REC_BASEDIR`
- naming or directory structure differs

Fix:
- confirm actual recording location
- set `REC_BASEDIR` correctly

## 4) Permission denied on config_users.json
Fix permissions so web server can read and (admin actions) write:
- `chmod 640 config_users.json`
- `chown apache:apache config_users.json` (adjust for your web user)

## 5) DB connection fails
Make sure server can read:
- `/etc/amportal.conf` OR `/etc/freepbx.conf`

Also verify DB host/user/password in those files.
