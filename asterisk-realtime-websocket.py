#!/usr/bin/env python3.6
"""
Asterisk Realtime WebSocket Service
Real-time call monitoring with WebSocket push updates
"""

import asyncio
import json
import re
import time
import signal
from datetime import datetime, date
from typing import Set, Dict, Any

try:
    import websockets
    from websockets.server import WebSocketServerProtocol
except ImportError:
    print("ERROR: websockets library not found!")
    print("Install with: pip3 install websockets")
    exit(1)

try:
    import pymysql
    MYSQL_AVAILABLE = True
except ImportError:
    print("Warning: pymysql not available. Database stats disabled.")
    MYSQL_AVAILABLE = False

# Load configuration
CONFIG_FILE = '/var/www/html/supervisor2/config.json'
try:
    with open(CONFIG_FILE, 'r') as f:
        CONFIG = json.load(f)
        print(f"✓ Loaded configuration from {CONFIG_FILE}")
except Exception as e:
    print(f"⚠ Could not load config.json: {e}")
    CONFIG = {}

# Configuration
AMI_HOST = CONFIG.get('asterisk', {}).get('ami', {}).get('host', '127.0.0.1')
AMI_PORT = CONFIG.get('asterisk', {}).get('ami', {}).get('port', 5038)
AMI_USER = CONFIG.get('asterisk', {}).get('ami', {}).get('username', 'reporting')
AMI_SECRET = CONFIG.get('asterisk', {}).get('ami', {}).get('secret', 'HfsobKSEPNQiiQWsFzzj')
WS_HOST = CONFIG.get('realtime', {}).get('websocketHost', '0.0.0.0')
WS_PORT = CONFIG.get('realtime', {}).get('websocketPort', 8765)
DB_CONFIG_FILE = CONFIG.get('realtime', {}).get('dbConfigFile', '/etc/amportal.conf')

# Gateway configuration
GATEWAYS = []
for gw in CONFIG.get('asterisk', {}).get('gateways', ['PJSIP/we']):
    if 'PJSIP/' in gw:
        GATEWAYS.append(gw.replace('PJSIP/', ''))
    elif 'SIP/' in gw:
        GATEWAYS.append(gw.replace('SIP/', ''))
    else:
        GATEWAYS.append(gw)

print(f"✓ Gateways: {GATEWAYS}")

# Global state
connected_clients: Set[WebSocketServerProtocol] = set()
extension_stats_db: Dict[str, Dict[str, Any]] = {}
last_db_reload = 0
DB_RELOAD_INTERVAL = 30
presence_states: Dict[str, Dict[str, str]] = {}   # updated by event listener
presence_prev:   Dict[str, Dict[str, str]] = {}   # snapshot for transition detection
break_history:   Dict[str, list]           = {}


class AsteriskAMI:
    """Asterisk Manager Interface client"""

    def __init__(self, host, port, username, secret):
        self.host = host
        self.port = port
        self.username = username
        self.secret = secret
        self.reader = None
        self.writer = None
        self.connected = False

    async def connect(self):
        """Connect to AMI"""
        try:
            self.reader, self.writer = await asyncio.open_connection(self.host, self.port)
            # Read welcome message
            await self.reader.read(1024)
            self.connected = True
            print(f"✓ Connected to AMI at {self.host}:{self.port}")
            return True
        except Exception as e:
            print(f"✗ AMI connection failed: {e}")
            return False

    async def login(self):
        """Login to AMI"""
        try:
            command = f"Action: Login\r\nUsername: {self.username}\r\nSecret: {self.secret}\r\n\r\n"
            self.writer.write(command.encode())
            await self.writer.drain()

            response = await self.reader.read(1024)
            if b'Success' in response:
                print("✓ AMI login successful")
                return True
            print("✗ AMI login failed")
            return False
        except Exception as e:
            print(f"✗ AMI login error: {e}")
            return False

    async def get_channels(self):
        """Get active channels from AMI"""
        try:
            command = "Action: CoreShowChannels\r\n\r\n"
            self.writer.write(command.encode())
            await self.writer.drain()

            channels = []
            buffer = b""
            timeout = time.time() + 3

            while time.time() < timeout:
                try:
                    chunk = await asyncio.wait_for(self.reader.read(4096), timeout=1.0)
                    if not chunk:
                        break
                    buffer += chunk

                    # Check if we received the complete message
                    if b'CoreShowChannelsComplete' in buffer:
                        break
                except asyncio.TimeoutError:
                    break

            # Parse events
            events = buffer.decode('utf-8', errors='ignore').split('\r\n\r\n')
            for event_text in events:
                event = {}
                for line in event_text.split('\r\n'):
                    if ': ' in line:
                        key, value = line.split(': ', 1)
                        event[key.strip()] = value.strip()

                if event.get('Event') == 'CoreShowChannel':
                    channels.append({
                        'channel': event.get('Channel', ''),
                        'callerid': event.get('CallerIDNum', ''),
                        'calleridname': event.get('CallerIDName', ''),
                        'extension': event.get('Exten', ''),
                        'context': event.get('Context', ''),
                        'state': event.get('ChannelStateDesc', ''),
                        'duration': parse_duration(event.get('Duration', '0')),
                    })

            return channels
        except Exception as e:
            print(f"✗ Error getting channels: {e}")
            return []

    async def get_extension_states(self):
        """Get SIP/PJSIP peer registration status"""
        try:
            command = "Action: SIPpeers\r\n\r\n"
            self.writer.write(command.encode())
            await self.writer.drain()

            peer_states = {}
            buffer = b""
            timeout = time.time() + 3

            while time.time() < timeout:
                try:
                    chunk = await asyncio.wait_for(self.reader.read(4096), timeout=1.0)
                    if not chunk:
                        break
                    buffer += chunk
                    if b'PeerlistComplete' in buffer:
                        break
                except asyncio.TimeoutError:
                    break

            # Parse peer events
            events = buffer.decode('utf-8', errors='ignore').split('\r\n\r\n')
            for event_text in events:
                event = {}
                for line in event_text.split('\r\n'):
                    if ': ' in line:
                        key, value = line.split(': ', 1)
                        event[key.strip()] = value.strip()

                if event.get('Event') == 'PeerEntry':
                    peer = event.get('ObjectName', '')
                    status = event.get('Status', '')
                    # Extract extension number
                    if peer.isdigit():
                        peer_states[peer] = 'online' if 'OK' in status or 'Registered' in status else 'offline'

            # Try PJSIP endpoints
            command = "Action: PJSIPShowEndpoints\r\n\r\n"
            self.writer.write(command.encode())
            await self.writer.drain()

            buffer = b""
            timeout = time.time() + 3

            while time.time() < timeout:
                try:
                    chunk = await asyncio.wait_for(self.reader.read(4096), timeout=1.0)
                    if not chunk:
                        break
                    buffer += chunk
                    if b'EndpointListComplete' in buffer:
                        break
                except asyncio.TimeoutError:
                    break

            events = buffer.decode('utf-8', errors='ignore').split('\r\n\r\n')
            for event_text in events:
                event = {}
                for line in event_text.split('\r\n'):
                    if ': ' in line:
                        key, value = line.split(': ', 1)
                        event[key.strip()] = value.strip()

                if event.get('Event') == 'EndpointList':
                    endpoint = event.get('ObjectName', '')
                    device_state = event.get('DeviceState', '')
                    if endpoint.isdigit():
                        if 'Not in use' in device_state or 'Idle' in device_state:
                            peer_states[endpoint] = 'online'
                        elif 'Unavailable' in device_state or 'Invalid' in device_state:
                            peer_states[endpoint] = 'offline'
                        elif 'InUse' in device_state or 'Busy' in device_state:
                            peer_states[endpoint] = 'busy'
                        elif 'Ringing' in device_state:
                            peer_states[endpoint] = 'ringing'

            return peer_states
        except Exception as e:
            print(f"✗ Error getting extension states: {e}")
            return {}

    async def get_queue_paused_members(self):
        """Get queue members that are paused"""
        try:
            command = "Action: QueueStatus\r\n\r\n"
            self.writer.write(command.encode())
            await self.writer.drain()

            paused_extensions = set()
            buffer = b""
            timeout = time.time() + 3

            while time.time() < timeout:
                try:
                    chunk = await asyncio.wait_for(self.reader.read(4096), timeout=1.0)
                    if not chunk:
                        break
                    buffer += chunk
                    if b'QueueStatusComplete' in buffer:
                        break
                except asyncio.TimeoutError:
                    break

            # Parse queue member events
            events = buffer.decode('utf-8', errors='ignore').split('\r\n\r\n')
            for event_text in events:
                event = {}
                for line in event_text.split('\r\n'):
                    if ': ' in line:
                        key, value = line.split(': ', 1)
                        event[key.strip()] = value.strip()

                if event.get('Event') == 'QueueMember':
                    paused = event.get('Paused', '0')
                    member_name = event.get('MemberName', '')
                    location = event.get('Location', '')

                    # Extract extension from member name or location
                    ext = None
                    if member_name.isdigit():
                        ext = member_name
                    else:
                        # Try to extract from location like "PJSIP/1234"
                        match = re.search(r'(?:PJSIP|SIP)/(\d+)', location)
                        if match:
                            ext = match.group(1)

                    if ext and paused == '1':
                        paused_extensions.add(ext)

            return paused_extensions
        except Exception as e:
            print(f"✗ Error getting queue paused members: {e}")
            return set()

    async def get_presence_states(self):
        """Get FOP2/CustomPresence states from AstDB.

        FOP2 stores agent presence under AstDB family 'CustomPresence'.
        Value format: state[:subtype[:note]]
        e.g.  available  |  away:break:  |  xa::At lunch
        """
        try:
            command = "Action: Command\r\nCommand: database show CustomPresence\r\n\r\n"
            self.writer.write(command.encode())
            await self.writer.drain()

            buffer = b""
            timeout = time.time() + 3
            while time.time() < timeout:
                try:
                    chunk = await asyncio.wait_for(self.reader.read(4096), timeout=1.0)
                    if not chunk:
                        break
                    buffer += chunk
                    if b'--END COMMAND--' in buffer:
                        break
                except asyncio.TimeoutError:
                    break

            presence = {}
            text = buffer.decode('utf-8', errors='ignore')
            for line in text.split('\n'):
                # Line format: "/1001                 : away:break:On break"
                m = re.search(r'/(\d+)\s*:\s*(.+)', line)
                if m:
                    ext = m.group(1)
                    raw = m.group(2).strip()
                    parts = raw.split(':')
                    presence[ext] = {
                        'state':   parts[0].strip() if parts else 'available',
                        'subtype': parts[1].strip() if len(parts) > 1 else '',
                        'note':    parts[2].strip() if len(parts) > 2 else '',
                    }
            return presence
        except Exception as e:
            print(f"✗ Error getting presence states: {e}")
            return {}

    async def get_queue_status(self):
        """Get detailed queue status including waiting calls and members"""
        try:
            command = "Action: QueueStatus\r\n\r\n"
            self.writer.write(command.encode())
            await self.writer.drain()

            queues = {}
            current_queue = None
            buffer = b""
            timeout = time.time() + 3

            while time.time() < timeout:
                try:
                    chunk = await asyncio.wait_for(self.reader.read(4096), timeout=1.0)
                    if not chunk:
                        break
                    buffer += chunk
                    if b'QueueStatusComplete' in buffer:
                        break
                except asyncio.TimeoutError:
                    break

            # Parse events
            events = buffer.decode('utf-8', errors='ignore').split('\r\n\r\n')
            for event_text in events:
                event = {}
                for line in event_text.split('\r\n'):
                    if ': ' in line:
                        key, value = line.split(': ', 1)
                        event[key.strip()] = value.strip()

                event_type = event.get('Event', '')

                if event_type == 'QueueParams':
                    queue_name = event.get('Queue', '')
                    if queue_name:
                        current_queue = queue_name
                        queues[queue_name] = {
                            'name': queue_name,
                            'max': int(event.get('Max', 0)),
                            'calls_waiting': int(event.get('Calls', 0)),
                            'hold_time': int(event.get('Holdtime', 0)),
                            'talk_time': int(event.get('TalkTime', 0)),
                            'service_level': int(event.get('ServiceLevel', 0)),
                            'service_level_perf': float(event.get('ServicelevelPerf', 0)),
                            'weight': int(event.get('Weight', 0)),
                            'completed': int(event.get('Completed', 0)),
                            'abandoned': int(event.get('Abandoned', 0)),
                            'members': [],
                            'entries': []
                        }

                elif event_type == 'QueueMember' and current_queue:
                    member_name = event.get('MemberName', event.get('Name', ''))
                    location = event.get('Location', '')

                    # Extract extension
                    ext = None
                    if member_name.isdigit():
                        ext = member_name
                    else:
                        match = re.search(r'(?:PJSIP|SIP)/(\d+)', location)
                        if match:
                            ext = match.group(1)

                    queues[current_queue]['members'].append({
                        'name': member_name,
                        'extension': ext or member_name,
                        'location': location,
                        'status': event.get('Status', ''),
                        'paused': event.get('Paused', '0') == '1',
                        'calls_taken': int(event.get('CallsTaken', 0)),
                        'last_call': int(event.get('LastCall', 0)),
                        'in_call': event.get('InCall', '0') == '1',
                    })

                elif event_type == 'QueueEntry' and current_queue:
                    queues[current_queue]['entries'].append({
                        'position': int(event.get('Position', 0)),
                        'channel': event.get('Channel', ''),
                        'callerid': event.get('CallerIDNum', ''),
                        'calleridname': event.get('CallerIDName', ''),
                        'wait_time': int(event.get('Wait', 0)),
                    })

            return queues
        except Exception as e:
            print(f"✗ Error getting queue status: {e}")
            return {}

    async def close(self):
        """Close AMI connection"""
        if self.writer:
            try:
                self.writer.write(b"Action: Logoff\r\n\r\n")
                await self.writer.drain()
                self.writer.close()
                await self.writer.wait_closed()
            except:
                pass
        self.connected = False


def parse_duration(duration_str):
    """Convert HH:MM:SS or seconds to integer seconds"""
    try:
        if ':' in str(duration_str):
            parts = str(duration_str).split(':')
            if len(parts) == 3:
                h, m, s = parts
                return int(h) * 3600 + int(m) * 60 + int(s)
            elif len(parts) == 2:
                m, s = parts
                return int(m) * 60 + int(s)
        return int(duration_str)
    except:
        return 0


def extract_extension(channel):
    """Extract extension number from channel name"""
    match = re.search(r'(?:PJSIP|SIP)/(\d+)', channel)
    return match.group(1) if match else None


def process_queue_data(queue_status):
    """Process queue data into structured format"""
    queue_list = []

    for queue_name, queue_info in sorted(queue_status.items()):
        # Calculate metrics
        total_members = len(queue_info['members'])
        available_members = sum(1 for m in queue_info['members'] if not m['paused'] and not m['in_call'])
        paused_members = sum(1 for m in queue_info['members'] if m['paused'])
        busy_members = sum(1 for m in queue_info['members'] if m['in_call'])

        # Get longest wait time
        longest_wait = max((e['wait_time'] for e in queue_info['entries']), default=0)

        queue_list.append({
            'name': queue_info['name'],
            'calls_waiting': queue_info['calls_waiting'],
            'longest_wait': longest_wait,
            'total_members': total_members,
            'available_members': available_members,
            'paused_members': paused_members,
            'busy_members': busy_members,
            'completed': queue_info['completed'],
            'abandoned': queue_info['abandoned'],
            'avg_hold_time': queue_info['hold_time'],
            'avg_talk_time': queue_info['talk_time'],
            'service_level_perf': queue_info['service_level_perf'],
            'waiting_calls': queue_info['entries'],
            'members': queue_info['members'],
        })

    return queue_list


def load_db_stats():
    """Load today's extension statistics from database"""
    global extension_stats_db

    if not MYSQL_AVAILABLE:
        return

    try:
        # Parse database config
        db_config = {'host': 'localhost', 'user': 'root', 'password': '', 'database': 'asteriskcdrdb', 'port': 3306}
        try:
            with open(DB_CONFIG_FILE, 'r') as f:
                for line in f:
                    line = line.strip()
                    if '=' in line and not line.startswith('#'):
                        key, value = line.split('=', 1)
                        key, value = key.strip(), value.strip().strip('"').strip("'")
                        if key == 'AMPDBHOST': db_config['host'] = value
                        elif key == 'AMPDBUSER': db_config['user'] = value
                        elif key == 'AMPDBPASS': db_config['password'] = value
                        elif key == 'AMPDBPORT': db_config['port'] = int(value) if value.isdigit() else 3306
        except:
            pass

        # Connect and query
        conn = pymysql.connect(**db_config)
        cursor = conn.cursor(pymysql.cursors.DictCursor)

        today = date.today().strftime('%Y-%m-%d')
        # Double the % for SQL escaping when using parameterized queries
        gateway_like = " OR ".join([f"channel LIKE '%%{gw}%%' OR dstchannel LIKE '%%{gw}%%'" for gw in GATEWAYS])

        query = f"""
        SELECT
            extension,
            SUM(total_calls) as total_calls,
            SUM(answered_calls) as answered_calls,
            SUM(total_duration) as total_duration,
            SUM(missed_calls) as missed_calls,
            SUM(inbound_calls) as inbound_calls,
            SUM(outbound_calls) as outbound_calls,
            SUM(internal_calls) as internal_calls,
            MIN(first_call_start) as first_call_start,
            MAX(last_call_end) as last_call_end
        FROM (
            SELECT
                SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1) AS extension,
                COUNT(*) as total_calls,
                SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered_calls,
                SUM(billsec) as total_duration,
                SUM(CASE WHEN disposition IN ('NO ANSWER', 'NOANSWER') THEN 1 ELSE 0 END) as missed_calls,
                SUM(CASE WHEN ({gateway_like}) AND dstchannel REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as outbound_calls,
                0 as inbound_calls,
                SUM(CASE WHEN NOT ({gateway_like}) OR dstchannel NOT REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as internal_calls,
                MIN(calldate) as first_call_start,
                MAX(DATE_ADD(calldate, INTERVAL billsec SECOND)) as last_call_end
            FROM cdr
            WHERE calldate >= %s AND calldate < DATE_ADD(%s, INTERVAL 1 DAY)
            AND (channel LIKE 'PJSIP/%%%%' OR channel LIKE 'SIP/%%%%')
            AND channel REGEXP '^(PJSIP|SIP)/[0-9]+'
            GROUP BY extension

            UNION ALL

            SELECT
                SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', -1), '-', 1) AS extension,
                COUNT(*) as total_calls,
                SUM(CASE WHEN disposition = 'ANSWERED' AND dstchannel REGEXP '^(PJSIP|SIP)/[0-9]+' THEN 1 ELSE 0 END) as answered_calls,
                SUM(billsec) as total_duration,
                SUM(CASE WHEN disposition IN ('NO ANSWER', 'NOANSWER') THEN 1 ELSE 0 END) as missed_calls,
                0 as outbound_calls,
                SUM(CASE WHEN ({gateway_like}) AND channel REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as inbound_calls,
                SUM(CASE WHEN NOT ({gateway_like}) OR channel NOT REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as internal_calls,
                MIN(calldate) as first_call_start,
                MAX(DATE_ADD(calldate, INTERVAL billsec SECOND)) as last_call_end
            FROM cdr
            WHERE calldate >= %s AND calldate < DATE_ADD(%s, INTERVAL 1 DAY)
            AND (dstchannel LIKE 'PJSIP/%%%%' OR dstchannel LIKE 'SIP/%%%%')
            AND dstchannel REGEXP '^(PJSIP|SIP)/[0-9]+'
            GROUP BY extension
        ) combined
        WHERE extension REGEXP '^[0-9]+$'
        GROUP BY extension
        """

        cursor.execute(query, (today, today, today, today))
        results = cursor.fetchall()

        extension_stats_db = {}
        for row in results:
            ext = row['extension'].replace('PJSIP/', '').replace('SIP/', '')
            if ext.isdigit():
                # Convert datetime objects to strings for JSON serialization
                first_call = row['first_call_start'].strftime('%H:%M:%S') if row.get('first_call_start') else ''
                last_call = row['last_call_end'].strftime('%H:%M:%S') if row.get('last_call_end') else ''

                extension_stats_db[ext] = {
                    'total_calls_today': int(row['total_calls'] or 0),
                    'answered_today': int(row['answered_calls'] or 0),
                    'missed_today': int(row['missed_calls'] or 0),
                    'total_duration_today': int(row['total_duration'] or 0),
                    'inbound_today': int(row['inbound_calls'] or 0),
                    'outbound_today': int(row['outbound_calls'] or 0),
                    'internal_today': int(row['internal_calls'] or 0),
                    'first_call_start': first_call,
                    'last_call_end': last_call,
                }

        cursor.close()
        conn.close()
        print(f"✓ Loaded DB stats for {len(extension_stats_db)} extensions")

    except Exception as e:
        print(f"⚠ Database stats load failed: {e}")


_FOP2_DND_VALUES  = {'dnd', 'do not disturb'}
_FOP2_AWAY_VALUES = {'break', 'lunch', 'meeting', 'training', 'away', 'xa', 'out', 'unavailable'}


def fop2_value_to_state(value: str):
    """Map a FOP2 Value label (e.g. 'Break') to (state, subtype)."""
    v = value.lower().strip()
    if v in _FOP2_DND_VALUES:
        return ('dnd', 'dnd')
    if v in _FOP2_AWAY_VALUES:
        return ('away', v)
    return ('available', '')


def apply_fop2_event(ext: str, value: str) -> None:
    """Apply a FOP2ASTDB UserEvent: update presence_states and break history."""
    global presence_states, presence_prev, break_history

    new_state, subtype = fop2_value_to_state(value)
    old_state = presence_prev.get(ext, {}).get('state', 'available')

    # Update live presence dict
    presence_states[ext] = {'state': new_state, 'subtype': subtype, 'note': ''}

    # Break history transition
    today_str    = datetime.now().strftime('%Y-%m-%d')
    now_ts       = datetime.now().strftime('%H:%M:%S')
    away_states  = {'away', 'xa', 'dnd'}
    avail_states = {'available', 'chat', ''}

    hist = [e for e in break_history.get(ext, []) if e.get('date') == today_str]
    break_history[ext] = hist

    if old_state in avail_states and new_state in away_states:
        break_history[ext].append({
            'date': today_str, 'start': now_ts, 'end': None,
            'subtype': subtype, 'note': '', 'duration': None,
        })
        print(f"[FOP2] {ext}: break started → {value}")
    elif old_state in away_states and new_state in avail_states:
        for entry in reversed(break_history[ext]):
            if entry['end'] is None:
                entry['end'] = now_ts
                try:
                    s = datetime.strptime(f"{today_str} {entry['start']}", '%Y-%m-%d %H:%M:%S')
                    e = datetime.strptime(f"{today_str} {now_ts}",         '%Y-%m-%d %H:%M:%S')
                    entry['duration'] = max(0, int((e - s).total_seconds()))
                except Exception:
                    entry['duration'] = 0
                break
        print(f"[FOP2] {ext}: break ended ← {value}")

    # Advance snapshot
    presence_prev[ext] = dict(presence_states[ext])


def detect_presence_changes(current_presence: Dict[str, Dict[str, str]]) -> None:
    """Track FOP2 presence state transitions to build today's break history per extension."""
    global presence_prev, break_history
    today_str    = datetime.now().strftime('%Y-%m-%d')
    now_ts       = datetime.now().strftime('%H:%M:%S')
    away_states  = {'away', 'xa', 'dnd'}
    avail_states = {'available', 'chat', ''}

    for ext, cur in current_presence.items():
        prev   = presence_prev.get(ext, {})
        prev_s = prev.get('state', 'available')
        cur_s  = cur.get('state', 'available')

        # Keep only today's history
        hist = [e for e in break_history.get(ext, []) if e.get('date') == today_str]
        break_history[ext] = hist

        if prev_s in avail_states and cur_s in away_states:
            # Break started
            break_history[ext].append({
                'date':     today_str,
                'start':    now_ts,
                'end':      None,
                'subtype':  cur.get('subtype', ''),
                'note':     cur.get('note', ''),
                'duration': None,
            })
        elif prev_s in away_states and cur_s in avail_states:
            # Break ended — close the last open entry
            for entry in reversed(break_history[ext]):
                if entry['end'] is None:
                    entry['end'] = now_ts
                    try:
                        start_dt = datetime.strptime(f"{today_str} {entry['start']}", '%Y-%m-%d %H:%M:%S')
                        end_dt   = datetime.strptime(f"{today_str} {now_ts}",         '%Y-%m-%d %H:%M:%S')
                        entry['duration'] = max(0, int((end_dt - start_dt).total_seconds()))
                    except Exception:
                        entry['duration'] = 0
                    break

    # Update snapshot for next cycle
    presence_prev.update({ext: dict(d) for ext, d in current_presence.items()})


def process_channels(channels, extension_states=None, paused_extensions=None, presence_states=None):
    """Process channel data into structured format"""
    if extension_states is None:
        extension_states = {}
    if paused_extensions is None:
        paused_extensions = set()

    # Group channels by duration (merge call legs)
    sip_channels = [ch for ch in channels if 'SIP/' in ch['channel'] or 'PJSIP/' in ch['channel']]

    call_groups = {}
    for ch in sip_channels:
        bucket = (ch['duration'] // 3) * 3
        if bucket not in call_groups:
            call_groups[bucket] = []
        call_groups[bucket].append(ch)

    calls = []
    extension_kpis = {}
    extensions_on_call = set()  # Track which extensions are currently on calls

    # Process each group
    for duration, group in call_groups.items():
        gateway_legs = [ch for ch in group if any(gw in ch['channel'].lower() for gw in GATEWAYS)]
        extension_legs = [ch for ch in group if not any(gw in ch['channel'].lower() for gw in GATEWAYS)]

        if gateway_legs and extension_legs:
            # Bridged call (gateway + extension)
            gw_ch = gateway_legs[0]
            ext_ch = extension_legs[0]
            ext = extract_extension(ext_ch['channel'])

            is_outbound = any(p in ext_ch['context'].lower() for p in ['macro-dialout', 'outbound', 'dialout-trunk'])
            direction = 'outbound' if is_outbound else 'inbound'

            if is_outbound:
                calls.append({
                    'channel': ext_ch['channel'],
                    'dstchannel': gw_ch['channel'],
                    'callerid': f"{ext_ch['calleridname']} <{ext_ch['callerid']}>",
                    'extension': ext_ch['extension'],
                    'destination': gw_ch['extension'],
                    'status': ext_ch['state'],
                    'duration': ext_ch['duration'],
                    'direction': direction,
                })
            else:
                calls.append({
                    'channel': gw_ch['channel'],
                    'dstchannel': ext_ch['channel'],
                    'callerid': f"{gw_ch['calleridname']} <{gw_ch['callerid']}>",
                    'extension': gw_ch['extension'],
                    'destination': ext_ch['extension'],
                    'status': gw_ch['state'],
                    'duration': gw_ch['duration'],
                    'direction': direction,
                })

            # Track extension KPI
            if ext and ext.isdigit():
                if ext not in extension_kpis:
                    extension_kpis[ext] = {'extension': ext, 'caller_id': ext_ch['calleridname'], 'active': 0, 'in': 0, 'out': 0, 'int': 0, 'status': 'online', 'on_hold': False}
                if direction == 'outbound':
                    extension_kpis[ext]['out'] += 1
                else:
                    extension_kpis[ext]['in'] += 1
                if ext_ch['state'] == 'Up':
                    extension_kpis[ext]['active'] += 1
                    extensions_on_call.add(ext)
                    # Check if on hold (muted or no audio)
                    if 'hold' in ext_ch['context'].lower() or ext_ch['state'] == 'Hold':
                        extension_kpis[ext]['on_hold'] = True
                elif ext_ch['state'] in ['Ringing', 'Ring']:
                    extensions_on_call.add(ext)

        elif gateway_legs and not extension_legs:
            # Inbound call not yet bridged (in IVR, queue, ringing, etc.)
            for gw_ch in gateway_legs:
                # Check if it's an outbound context (unlikely for gateway-only, but check anyway)
                is_outbound_context = any(p in gw_ch['context'].lower() for p in ['macro-dialout', 'outbound', 'dialout-trunk'])
                if not is_outbound_context:
                    # It's an inbound call in IVR/announcement/queue
                    calls.append({
                        'channel': gw_ch['channel'],
                        'dstchannel': '',
                        'callerid': f"{gw_ch['calleridname']} <{gw_ch['callerid']}>",
                        'extension': gw_ch['extension'],
                        'destination': gw_ch['context'],  # Show context as destination
                        'status': gw_ch['state'],
                        'duration': gw_ch['duration'],
                        'direction': 'inbound',
                    })

        elif extension_legs and not gateway_legs:
            # Extension-only calls (could be internal calls or ringing extensions)
            for ext_ch in extension_legs:
                ext = extract_extension(ext_ch['channel'])
                calls.append({
                    'channel': ext_ch['channel'],
                    'dstchannel': '',
                    'callerid': f"{ext_ch['calleridname']} <{ext_ch['callerid']}>",
                    'extension': ext_ch['extension'],
                    'destination': ext_ch['context'],
                    'status': ext_ch['state'],
                    'duration': ext_ch['duration'],
                    'direction': 'internal',
                })

                # Track extension KPI for internal calls
                if ext and ext.isdigit():
                    if ext not in extension_kpis:
                        extension_kpis[ext] = {'extension': ext, 'caller_id': ext_ch['calleridname'], 'active': 0, 'in': 0, 'out': 0, 'int': 0, 'status': 'online', 'on_hold': False}
                    extension_kpis[ext]['int'] += 1
                    if ext_ch['state'] == 'Up':
                        extension_kpis[ext]['active'] += 1
                        extensions_on_call.add(ext)
                    elif ext_ch['state'] in ['Ringing', 'Ring']:
                        extensions_on_call.add(ext)

    # Merge with DB stats - add all extensions from database
    for ext in list(extension_stats_db.keys()):
        if ext not in extension_kpis:
            # Get registration status from extension_states
            reg_status = extension_states.get(ext, 'offline')
            extension_kpis[ext] = {'extension': ext, 'caller_id': ext, 'active': 0, 'in': 0, 'out': 0, 'int': 0, 'status': reg_status, 'on_hold': False}

    kpi_list = []
    for ext, stats in sorted(extension_kpis.items()):
        db = extension_stats_db.get(ext, {})
        total_duration = db.get('total_duration_today', 0)
        total_calls = db.get('total_calls_today', 0)
        avg_dur = total_duration // total_calls if total_calls > 0 else 0

        # Determine detailed status
        detailed_status = 'offline'
        if ext in paused_extensions:
            detailed_status = 'paused'
        elif stats.get('on_hold', False):
            detailed_status = 'on-hold'
        elif ext in extensions_on_call:
            if stats['active'] > 0:
                detailed_status = 'in-call'
            else:
                detailed_status = 'ringing'
        elif ext in extension_states:
            # Use registration status from AMI
            peer_status = extension_states[ext]
            if peer_status == 'busy':
                detailed_status = 'busy'
            elif peer_status == 'ringing':
                detailed_status = 'ringing'
            elif peer_status == 'online':
                detailed_status = 'online'
            else:
                detailed_status = peer_status
        elif stats['active'] == 0:
            # No active calls and not in extension_states, assume offline
            detailed_status = 'offline'

        # FOP2 / CustomPresence state
        presence = (presence_states or {}).get(ext, {})
        pstate   = presence.get('state', 'available')   # available, away, xa, dnd, chat
        psubtype = presence.get('subtype', '')           # break, lunch, training, meeting …
        pnote    = presence.get('note', '')

        # Combined availability for the breaks/availability report
        sip_online = (detailed_status != 'offline')
        if not sip_online:
            availability = 'offline'
        elif detailed_status in ('in-call', 'busy', 'on-hold'):
            availability = 'on_call'
        elif detailed_status == 'ringing':
            availability = 'ringing'
        elif pstate == 'dnd':
            availability = 'dnd'
        elif pstate in ('away', 'xa'):
            availability = 'break'
        else:
            availability = 'available'

        kpi_list.append({
            'extension': stats['extension'],
            'caller_id': stats['caller_id'],
            'status': detailed_status,
            'active_calls': stats['active'],
            'total_calls_today': total_calls,
            'inbound_today': db.get('inbound_today', 0) + stats['in'],
            'outbound_today': db.get('outbound_today', 0) + stats['out'],
            'internal_today': db.get('internal_today', 0) + stats['int'],
            'answered_today': db.get('answered_today', 0),
            'missed_today': db.get('missed_today', 0),
            'avg_duration': avg_dur,
            'tht': total_duration,  # Total Handle Time
            'aht': avg_dur,  # Average Handle Time (same as avg_duration)
            'first_call_start': db.get('first_call_start', ''),
            'last_call_end': db.get('last_call_end', ''),
            # Presence / availability fields
            'presence_state':   pstate,
            'presence_subtype': psubtype,
            'presence_note':    pnote,
            'sip_status':       extension_states.get(ext, 'offline'),
            'availability':     availability,
        })

    return {
        'status': 'ok',
        'active_calls': sum(1 for c in calls if c['status'] == 'Up'),
        'total_channels': len(channels),
        'calls': calls,
        'extension_kpis': kpi_list,
        'timestamp': int(time.time())
    }


async def handle_client(websocket: WebSocketServerProtocol, path: str):
    """Handle WebSocket client connection"""
    connected_clients.add(websocket)
    client_addr = websocket.remote_address
    print(f"✓ Client connected: {client_addr} (total: {len(connected_clients)})")

    try:
        async for message in websocket:
            # Handle client messages if needed
            pass
    except websockets.exceptions.ConnectionClosed:
        pass
    finally:
        connected_clients.remove(websocket)
        print(f"✗ Client disconnected: {client_addr} (total: {len(connected_clients)})")


async def broadcast(data):
    """Broadcast data to all connected clients"""
    if not connected_clients:
        return

    message = json.dumps(data)
    dead_clients = set()

    for client in connected_clients:
        try:
            await client.send(message)
        except:
            dead_clients.add(client)

    # Remove dead clients
    for client in dead_clients:
        connected_clients.discard(client)


async def ami_monitor_loop():
    """Main AMI monitoring loop"""
    global last_db_reload, presence_states

    ami = None
    last_channel_count = 0
    ami_just_connected = False

    while True:
        try:
            # Connect to AMI
            if not ami or not ami.connected:
                ami = AsteriskAMI(AMI_HOST, AMI_PORT, AMI_USER, AMI_SECRET)
                if not await ami.connect():
                    await asyncio.sleep(10)
                    continue
                if not await ami.login():
                    await asyncio.sleep(10)
                    continue
                ami_just_connected = True

            # On (re)connect, do a one-shot AstDB sync to seed presence_states
            # so the event listener has a valid baseline even before the first event.
            if ami_just_connected:
                initial = await ami.get_presence_states()
                for ext, pdata in initial.items():
                    if ext not in presence_states:
                        presence_states[ext] = pdata
                        presence_prev[ext]   = dict(pdata)
                ami_just_connected = False
                print(f"✓ Seeded presence for {len(initial)} extensions from AstDB")

            # Get channels and extension states
            channels = await ami.get_channels()
            extension_states = await ami.get_extension_states()
            queue_status = await ami.get_queue_status()
            paused_extensions = await ami.get_queue_paused_members()
            # presence_states is now maintained by ami_event_listener()

            current_count = len(channels)

            # Reload DB stats on hangup or periodically
            current_time = time.time()
            if (last_channel_count > 0 and current_count < last_channel_count) or (current_time - last_db_reload >= DB_RELOAD_INTERVAL):
                load_db_stats()
                last_db_reload = current_time

            last_channel_count = current_count

            # Process and broadcast (presence_states maintained by ami_event_listener)
            data = process_channels(channels, extension_states, paused_extensions, presence_states)

            # Enrich KPI entries with break history data
            today_str = datetime.now().strftime('%Y-%m-%d')
            now_dt    = datetime.now()
            for kpi in data.get('extension_kpis', []):
                ext       = kpi.get('extension', '')
                hist      = [e for e in break_history.get(ext, []) if e.get('date') == today_str]
                completed = [e for e in hist if e['end'] is not None]
                open_brk  = next((e for e in reversed(hist) if e['end'] is None), None)
                break_secs = sum(e['duration'] or 0 for e in completed)
                if open_brk:
                    try:
                        start_dt = datetime.strptime(f"{today_str} {open_brk['start']}", '%Y-%m-%d %H:%M:%S')
                        break_secs += max(0, int((now_dt - start_dt).total_seconds()))
                    except Exception:
                        pass
                kpi['breaks_today']        = len(hist)
                kpi['break_seconds_today'] = break_secs
                kpi['break_history']       = [
                    {
                        'start':    e['start'],
                        'end':      e['end'] or '(ongoing)',
                        'duration': e['duration'],
                        'subtype':  e.get('subtype', ''),
                        'note':     e.get('note', ''),
                    }
                    for e in hist
                ]

            # Add queue data
            data['queues'] = process_queue_data(queue_status)

            await broadcast(data)

            print(f"[{datetime.now().strftime('%H:%M:%S')}] Active: {data['active_calls']}, Channels: {data['total_channels']}, Extensions: {len(data['extension_kpis'])}, Clients: {len(connected_clients)}")

            await asyncio.sleep(2)

        except Exception as e:
            print(f"✗ Monitor loop error: {e}")
            if ami:
                await ami.close()
                ami = None
            await asyncio.sleep(5)


async def ami_event_listener():
    """Dedicated AMI connection — watches UserEvent:FOP2ASTDB for real-time presence."""
    while True:
        writer = None
        try:
            reader, writer = await asyncio.open_connection(AMI_HOST, AMI_PORT)
            await asyncio.wait_for(reader.readline(), timeout=5)  # banner

            login = (
                f"Action: Login\r\nUsername: {AMI_USER}\r\nSecret: {AMI_SECRET}\r\n"
                f"Events: user\r\n\r\n"
            )
            writer.write(login.encode())
            await writer.drain()

            # Read login response
            resp = b""
            while b"\r\n\r\n" not in resp:
                resp += await asyncio.wait_for(reader.read(4096), timeout=5)
            if b"Success" not in resp:
                print("✗ AMI event listener login failed")
                await asyncio.sleep(10)
                continue

            print("✓ AMI event listener connected — watching FOP2ASTDB")
            buf = b""

            while True:
                try:
                    chunk = await asyncio.wait_for(reader.read(4096), timeout=60)
                except asyncio.TimeoutError:
                    continue   # keepalive timeout — connection still alive
                if not chunk:
                    break
                buf += chunk

                # Process every complete event (double CRLF separator)
                while b"\r\n\r\n" in buf:
                    raw, buf = buf.split(b"\r\n\r\n", 1)
                    fields = {}
                    for line in raw.decode('utf-8', errors='replace').split('\r\n'):
                        if ':' in line:
                            k, _, v = line.partition(':')
                            fields[k.strip()] = v.strip()

                    if (fields.get('Event') == 'UserEvent'
                            and fields.get('UserEvent') == 'FOP2ASTDB'):
                        key   = fields.get('Key',   '')   # e.g. "PJSIP/102"
                        value = fields.get('Value', '')   # e.g. "Break"
                        ext   = re.sub(r'^(PJSIP|SIP)/', '', key, flags=re.IGNORECASE)
                        if ext.isdigit():
                            apply_fop2_event(ext, value)

        except Exception as e:
            print(f"⚠ AMI event listener error: {e}")
            await asyncio.sleep(5)
        finally:
            if writer:
                try:
                    writer.close()
                except Exception:
                    pass


async def main():
    """Main entry point"""
    print("\n" + "="*60)
    print("Asterisk Realtime WebSocket Service")
    print("="*60)

    # Load initial DB stats
    load_db_stats()

    # Start WebSocket server
    print(f"\n🌐 Starting WebSocket server on ws://{WS_HOST}:{WS_PORT}")

    async with websockets.serve(handle_client, WS_HOST, WS_PORT):
        # Run monitor loop and event listener concurrently
        await asyncio.gather(
            ami_monitor_loop(),
            ami_event_listener(),
        )


if __name__ == '__main__':
    # Python 3.6 compatibility - asyncio.run() was added in 3.7
    try:
        loop = asyncio.get_event_loop()
        loop.run_until_complete(main())
    except KeyboardInterrupt:
        print("\n\n✓ Shutdown complete")
    finally:
        loop.close()
