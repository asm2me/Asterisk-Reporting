# Security Notes

## Authentication
- Session-based login
- `session_regenerate_id(true)` on successful login
- Logout destroys session and clears cookie

## Authorization / ACL
Non-admin users can only view records where their allowed extension matches:
- `channel` begins with `SIP/<ext>-` or `PJSIP/<ext>-`
- OR `dstchannel` begins with `SIP/<ext>-` or `PJSIP/<ext>-`

This approach prevents missing calls where `src/dst` do not reflect the actual endpoint.

## Recordings
Playback/download requires:
- Valid session
- ACL match (same rule as report visibility)
- Path sanitization and realpath checks to restrict to recording base dir

## Admin-only actions
- User management restricted to `is_admin`
- CSRF token required for add/edit/delete/change-password
- Protects deletion of `admin` account
