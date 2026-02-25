#!/usr/bin/env python3.6
"""
Asterisk Realtime Report Service
Monitors Asterisk via AMI and provides real-time call data
Python 3.6+ required
"""

import socket
import json
import time
import sys
import re
import os
from datetime import datetime, date
try:
    import pymysql
    MYSQL_AVAILABLE = True
except ImportError:
    print("Warning: pymysql not available. Install with: pip3 install pymysql")
    MYSQL_AVAILABLE = False

# Load project configuration from config.json
CONFIG_FILE = '/var/www/html/supervisor2/config.json'
PROJECT_CONFIG = {}

try:
    with open(CONFIG_FILE, 'r') as f:
        PROJECT_CONFIG = json.load(f)
        print(f"Loaded configuration from {CONFIG_FILE}")
except Exception as e:
    print(f"Warning: Could not load config.json: {e}. Using defaults.")

# Configuration
AMI_HOST = PROJECT_CONFIG.get('asterisk', {}).get('ami', {}).get('host', '127.0.0.1')
AMI_PORT = PROJECT_CONFIG.get('asterisk', {}).get('ami', {}).get('port', 5038)
AMI_USER = PROJECT_CONFIG.get('asterisk', {}).get('ami', {}).get('username', 'reporting')
AMI_SECRET = PROJECT_CONFIG.get('asterisk', {}).get('ami', {}).get('secret', 'HfsobKSEPNQiiQWsFzzj')
DATA_FILE = PROJECT_CONFIG.get('realtime', {}).get('dataFile', '/var/www/html/supervisor2/data/asterisk-realtime-data.json')
DB_CONFIG_FILE = PROJECT_CONFIG.get('realtime', {}).get('dbConfigFile', '/etc/amportal.conf')
UPDATE_INTERVAL = PROJECT_CONFIG.get('realtime', {}).get('updateInterval', 2)

# Global extension stats (cumulative, loaded from DB on startup)
EXTENSION_STATS = {}
LAST_DB_RELOAD = 0
DB_RELOAD_INTERVAL = 30  # Reload from DB every 30 seconds (instead of 5 minutes)

# Track recently seen extensions (to keep them visible after call ends)
RECENTLY_SEEN_EXTENSIONS = {}  # {extension: {'last_seen': timestamp, 'caller_id': name}}
RECENT_EXTENSION_TIMEOUT = 300  # Keep extension visible for 5 minutes after last activity

# Track previous channel count to detect call hangups
LAST_CHANNEL_COUNT = 0

def parse_db_config():
    """Parse database credentials from amportal.conf"""
    config = {
        'host': 'localhost',
        'user': 'root',
        'password': '',
        'database': 'asteriskcdrdb',
        'port': 3306
    }

    try:
        with open(DB_CONFIG_FILE, 'r') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                if '=' in line:
                    key, value = line.split('=', 1)
                    key = key.strip()
                    value = value.strip().strip('"').strip("'")

                    if key == 'AMPDBHOST':
                        config['host'] = value
                    elif key == 'AMPDBUSER':
                        config['user'] = value
                    elif key == 'AMPDBPASS':
                        config['password'] = value
                    elif key == 'AMPDBPORT':
                        config['port'] = int(value) if value.isdigit() else 3306
                    elif key == 'AMPDBNAME':
                        config['database'] = value
    except Exception as e:
        print(f"Warning: Could not parse {DB_CONFIG_FILE}: {e}")

    return config


def load_extension_stats_from_db():
    """Load today's extension statistics from CDR database"""
    global EXTENSION_STATS

    if not MYSQL_AVAILABLE:
        print("MySQL not available, skipping historical stats load")
        return

    try:
        db_config = parse_db_config()
        print(f"Connecting to database: {db_config['host']}/{db_config['database']}")

        conn = pymysql.connect(
            host=db_config['host'],
            user=db_config['user'],
            password=db_config['password'],
            database=db_config['database'],
            port=db_config['port']
        )

        cursor = conn.cursor(pymysql.cursors.DictCursor)

        # Get today's date range
        today = date.today().strftime('%Y-%m-%d')

        # Load gateway patterns for direction detection
        gateway_patterns = ['%' + gw + '%' for gw in GATEWAYS]

        # Build gateway pattern for SQL LIKE
        gateway_conditions = []
        for gw in GATEWAYS:
            gateway_conditions.append(f"'%{gw}%'")
        gateway_like = " OR ".join([f"channel LIKE {g} OR dstchannel LIKE {g}" for g in gateway_conditions])

        # Query to get per-extension statistics with direction breakdown
        query = f"""
        SELECT
            extension,
            SUM(total_calls) as total_calls,
            SUM(answered_calls) as answered_calls,
            SUM(total_duration) as total_duration,
            SUM(missed_calls) as missed_calls,
            SUM(inbound_calls) as inbound_calls,
            SUM(outbound_calls) as outbound_calls,
            SUM(internal_calls) as internal_calls
        FROM (
            -- Extensions from source channel
            SELECT
                SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1) AS extension,
                COUNT(*) as total_calls,
                SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered_calls,
                SUM(billsec) as total_duration,
                SUM(CASE WHEN disposition IN ('NO ANSWER', 'NOANSWER') THEN 1 ELSE 0 END) as missed_calls,
                -- Direction detection: check if dstchannel is gateway
                SUM(CASE WHEN ({gateway_like}) AND dstchannel REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as outbound_calls,
                0 as inbound_calls,
                SUM(CASE WHEN NOT ({gateway_like}) OR dstchannel NOT REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as internal_calls
            FROM cdr
            WHERE calldate >= %s
            AND calldate < DATE_ADD(%s, INTERVAL 1 DAY)
            AND (channel LIKE 'PJSIP/%%' OR channel LIKE 'SIP/%%')
            AND channel REGEXP '^(PJSIP|SIP)/[0-9]+'
            GROUP BY extension

            UNION ALL

            -- Extensions from destination channel (inbound: only answered if bridged to local extension)
            SELECT
                SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', -1), '-', 1) AS extension,
                COUNT(*) as total_calls,
                SUM(CASE WHEN disposition = 'ANSWERED' AND dstchannel REGEXP '^(PJSIP|SIP)/[0-9]+' THEN 1 ELSE 0 END) as answered_calls,
                SUM(billsec) as total_duration,
                SUM(CASE WHEN disposition IN ('NO ANSWER', 'NOANSWER') THEN 1 ELSE 0 END) as missed_calls,
                0 as outbound_calls,
                -- Direction detection: check if channel is gateway
                SUM(CASE WHEN ({gateway_like}) AND channel REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as inbound_calls,
                SUM(CASE WHEN NOT ({gateway_like}) OR channel NOT REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as internal_calls
            FROM cdr
            WHERE calldate >= %s
            AND calldate < DATE_ADD(%s, INTERVAL 1 DAY)
            AND (dstchannel LIKE 'PJSIP/%%' OR dstchannel LIKE 'SIP/%%')
            AND dstchannel REGEXP '^(PJSIP|SIP)/[0-9]+'
            GROUP BY extension
        ) combined
        WHERE extension REGEXP '^[0-9]+$'
        GROUP BY extension
        """

        cursor.execute(query, (today, today, today, today))
        results = cursor.fetchall()

        print(f"Loaded historical stats for {len(results)} extensions from database")

        for row in results:
            ext = row['extension'].replace('PJSIP/', '').replace('SIP/', '')
            if ext.isdigit():
                EXTENSION_STATS[ext] = {
                    'total_calls_today': row['total_calls'] or 0,
                    'answered_calls_today': row['answered_calls'] or 0,
                    'missed_calls_today': row['missed_calls'] or 0,
                    'total_duration_today': row['total_duration'] or 0,
                    'inbound_today': row['inbound_calls'] or 0,
                    'outbound_today': row['outbound_calls'] or 0,
                    'internal_today': row['internal_calls'] or 0,
                }
                print(f"  Ext {ext}: {row['total_calls']} calls ({row['inbound_calls']} in, {row['outbound_calls']} out, {row['internal_calls']} int)")

        cursor.close()
        conn.close()

    except Exception as e:
        print(f"Error loading extension stats from database: {e}")
        import traceback
        traceback.print_exc()


# Load gateway patterns from config
def load_gateways():
    """Load gateway patterns from config.json"""
    gateways_raw = PROJECT_CONFIG.get('asterisk', {}).get('gateways', [])

    # Extract just the gateway identifier (remove PJSIP/ or SIP/ prefix)
    gateway_list = []
    for gw in gateways_raw:
        # Extract the identifier after PJSIP/ or SIP/
        if 'PJSIP/' in gw:
            gateway_list.append(gw.replace('PJSIP/', ''))
        elif 'SIP/' in gw:
            gateway_list.append(gw.replace('SIP/', ''))
        else:
            gateway_list.append(gw)

    if gateway_list:
        print(f"Loaded gateways from config: {gateway_list}")
        return gateway_list

    # Default fallback gateway patterns
    print("No gateways in config, using defaults")
    return ['we', 'trunk', 'gateway', 'pstn', 'did']

GATEWAYS = load_gateways()

def parse_duration(duration_str):
    """Convert HH:MM:SS or seconds string to integer seconds"""
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
    except (ValueError, AttributeError, TypeError):
        return 0


class AsteriskAMI:
    def __init__(self, host, port, username, secret):
        self.host = host
        self.port = port
        self.username = username
        self.secret = secret
        self.socket = None
        self.logged_in = False

    def connect(self):
        """Connect to Asterisk Manager Interface"""
        try:
            self.socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            self.socket.settimeout(10)
            self.socket.connect((self.host, self.port))

            # Read welcome message
            response = self._read_response()
            print(f"Connected to AMI: {response.get('Asterisk Call Manager', 'Unknown')}")

            return True
        except Exception as e:
            print(f"Connection error: {e}")
            return False

    def login(self):
        """Login to AMI"""
        try:
            command = (
                f"Action: Login\r\n"
                f"Username: {self.username}\r\n"
                f"Secret: {self.secret}\r\n"
                f"\r\n"
            )
            self.socket.send(command.encode())

            response = self._read_response()
            if response.get('Response') == 'Success':
                print("Successfully logged in to AMI")
                self.logged_in = True
                return True
            else:
                print(f"Login failed: {response.get('Message', 'Unknown error')}")
                return False
        except Exception as e:
            print(f"Login error: {e}")
            return False

    def get_active_channels(self):
        """Get list of active channels"""
        try:
            command = "Action: CoreShowChannels\r\n\r\n"
            self.socket.send(command.encode())

            channels = []
            response = self._read_multi_response()

            for event in response:
                if event.get('Event') == 'CoreShowChannel':
                    channels.append({
                        'channel': event.get('Channel', ''),
                        'callerid': event.get('CallerIDNum', ''),
                        'calleridname': event.get('CallerIDName', ''),
                        'extension': event.get('Exten', ''),
                        'context': event.get('Context', ''),
                        'state': event.get('ChannelStateDesc', ''),
                        'duration': parse_duration(event.get('Duration', 0)),
                        'application': event.get('Application', ''),
                        'bridged': event.get('BridgedChannel', ''),
                    })

            return channels
        except Exception as e:
            print(f"Error getting channels: {e}")
            return []

    def _read_response(self):
        """Read a single AMI response"""
        response = {}
        try:
            while True:
                line = self.socket.recv(1024).decode('utf-8', errors='ignore')
                if not line:
                    break

                for l in line.split('\r\n'):
                    if ':' in l:
                        key, value = l.split(':', 1)
                        response[key.strip()] = value.strip()
                    elif l.strip() == '':
                        return response

                if '\r\n\r\n' in line:
                    break

        except socket.timeout:
            pass
        except Exception as e:
            print(f"Read error: {e}")

        return response

    def _read_multi_response(self):
        """Read multiple AMI events until completion"""
        events = []
        current_event = {}
        buffer = ""

        try:
            self.socket.settimeout(3)
            while True:
                data = self.socket.recv(4096).decode('utf-8', errors='ignore')
                if not data:
                    break

                buffer += data

                # Process complete lines
                while '\r\n' in buffer:
                    line, buffer = buffer.split('\r\n', 1)

                    if ':' in line:
                        key, value = line.split(':', 1)
                        current_event[key.strip()] = value.strip()
                    elif line.strip() == '':
                        if current_event:
                            events.append(current_event)

                            # Check if we're done
                            if current_event.get('Event') == 'CoreShowChannelsComplete':
                                return events

                            current_event = {}

        except socket.timeout:
            pass
        except Exception as e:
            print(f"Read multi error: {e}")

        return events

    def close(self):
        """Close AMI connection"""
        if self.socket:
            try:
                command = "Action: Logoff\r\n\r\n"
                self.socket.send(command.encode())
            except:
                pass
            self.socket.close()
            self.logged_in = False


def extract_extension_from_channel(channel):
    """Extract extension number from PJSIP/SIP channel name"""
    try:
        # Pattern: PJSIP/1234-... or SIP/1234-...
        import re
        match = re.search(r'(?:PJSIP|SIP)/(\d+)', channel)
        if match:
            return match.group(1)
    except:
        pass
    return None


def calculate_extension_kpis(calls, channels):
    """Calculate KPIs per extension from call data, merging with database stats"""
    global EXTENSION_STATS, RECENTLY_SEEN_EXTENSIONS

    current_time = time.time()

    # Start with all extensions from database stats
    extension_stats = {}

    # Initialize from database stats first (so all extensions from today show up)
    for ext, db_stats in EXTENSION_STATS.items():
        extension_stats[ext] = {
            'extension': ext,
            'active_calls': 0,
            'inbound': 0,
            'outbound': 0,
            'internal': 0,
            'total_duration': 0,
            'status': 'available',
            'caller_id': ext,  # Will be updated if we find live channel
        }

    # Also include recently seen extensions (even if no DB stats yet)
    # Remove stale entries older than timeout
    stale_exts = [ext for ext, info in RECENTLY_SEEN_EXTENSIONS.items()
                  if current_time - info['last_seen'] > RECENT_EXTENSION_TIMEOUT]
    for ext in stale_exts:
        del RECENTLY_SEEN_EXTENSIONS[ext]

    # Add recently seen extensions to the stats
    for ext, info in RECENTLY_SEEN_EXTENSIONS.items():
        if ext not in extension_stats:
            extension_stats[ext] = {
                'extension': ext,
                'active_calls': 0,
                'inbound': 0,
                'outbound': 0,
                'internal': 0,
                'total_duration': 0,
                'status': 'available',
                'caller_id': info.get('caller_id', ext),
            }

    # Process all SIP/PJSIP channels to find extensions
    sip_channels = [ch for ch in channels if 'SIP/' in ch['channel'] or 'PJSIP/' in ch['channel']]

    for ch in sip_channels:
        ext = extract_extension_from_channel(ch['channel'])
        if ext and ext.isdigit():
            caller_id_name = ch.get('calleridname', ext)

            # Track this extension as recently seen
            RECENTLY_SEEN_EXTENSIONS[ext] = {
                'last_seen': current_time,
                'caller_id': caller_id_name
            }

            if ext not in extension_stats:
                extension_stats[ext] = {
                    'extension': ext,
                    'active_calls': 0,
                    'inbound': 0,
                    'outbound': 0,
                    'internal': 0,
                    'total_duration': 0,
                    'status': 'available',
                    'caller_id': caller_id_name,
                }
            else:
                # Update caller_id if we have a live channel
                extension_stats[ext]['caller_id'] = caller_id_name

    # Aggregate call statistics (live only)
    for call in calls:
        # Extract extension from channel
        ext = None
        caller_id = None

        if 'PJSIP/' in call.get('channel', '') or 'SIP/' in call.get('channel', ''):
            ext = extract_extension_from_channel(call['channel'])
            caller_id = call.get('callerid', ext).split('<')[0].strip()

        # Also check destination channel
        if not ext and call.get('dstchannel'):
            if 'PJSIP/' in call['dstchannel'] or 'SIP/' in call['dstchannel']:
                ext = extract_extension_from_channel(call['dstchannel'])
                caller_id = call.get('callerid', ext).split('<')[0].strip()

        if ext and ext.isdigit():
            # Track this extension as recently seen
            if caller_id:
                RECENTLY_SEEN_EXTENSIONS[ext] = {
                    'last_seen': current_time,
                    'caller_id': caller_id
                }

            if ext not in extension_stats:
                extension_stats[ext] = {
                    'extension': ext,
                    'active_calls': 0,
                    'inbound': 0,
                    'outbound': 0,
                    'internal': 0,
                    'total_duration': 0,
                    'status': 'available',
                    'caller_id': caller_id or ext,
                }

            # Update caller_id if available
            if caller_id and extension_stats[ext]['caller_id'] == ext:
                extension_stats[ext]['caller_id'] = caller_id

            stats = extension_stats[ext]

            # Count call by direction (live calls)
            direction = call.get('direction', 'internal')
            if direction == 'inbound':
                stats['inbound'] += 1
            elif direction == 'outbound':
                stats['outbound'] += 1
            else:
                stats['internal'] += 1

            # Count active calls
            if call.get('status') == 'Up':
                stats['active_calls'] += 1
                stats['status'] = 'on_call'
            elif call.get('status') in ('Ringing', 'Ring'):
                stats['status'] = 'ringing'

            # Add duration (live only)
            stats['total_duration'] += call.get('duration', 0)

    # Convert to list and merge with database stats
    kpi_list = []
    for ext, stats in sorted(extension_stats.items()):
        # Live call counts
        live_calls = stats['inbound'] + stats['outbound'] + stats['internal']

        # Get historical stats from database
        db_stats = EXTENSION_STATS.get(ext, {})
        total_calls_today = db_stats.get('total_calls_today', 0)
        answered_today = db_stats.get('answered_calls_today', 0)
        missed_today = db_stats.get('missed_calls_today', 0)
        total_duration_today = db_stats.get('total_duration_today', 0)
        inbound_today = db_stats.get('inbound_today', 0)
        outbound_today = db_stats.get('outbound_today', 0)
        internal_today = db_stats.get('internal_today', 0)

        # Calculate average duration (total duration from DB / total calls from DB)
        # Don't include live duration in average as calls are still in progress
        avg_duration = total_duration_today // total_calls_today if total_calls_today > 0 else 0

        kpi_list.append({
            'extension': stats['extension'],
            'caller_id': stats['caller_id'],
            'status': stats['status'],
            'active_calls': stats['active_calls'],
            'total_calls': live_calls,  # Live calls count (currently active)
            'inbound': stats['inbound'],  # Live inbound calls
            'outbound': stats['outbound'],  # Live outbound calls
            'internal': stats['internal'],  # Live internal calls
            'avg_duration': avg_duration,
            # Add today's totals from DB (always include even if 0)
            'total_calls_today': total_calls_today or 0,
            'answered_today': answered_today or 0,
            'missed_today': missed_today or 0,
            'inbound_today': inbound_today or 0,
            'outbound_today': outbound_today or 0,
            'internal_today': internal_today or 0,
        })

    return kpi_list


def process_channels(channels):
    """Process raw channel data into call information"""
    # Filter to SIP/PJSIP channels only
    sip_channels = [ch for ch in channels if 'SIP/' in ch['channel'] or 'PJSIP/' in ch['channel']]

    # Group channels by duration (same call has same/similar duration)
    call_groups = {}
    for ch in sip_channels:
        duration_bucket = (ch['duration'] // 3) * 3  # Group by 3-second intervals
        if duration_bucket not in call_groups:
            call_groups[duration_bucket] = []
        call_groups[duration_bucket].append(ch)

    calls = []
    active_count = 0

    # Process each group
    for duration, group_channels in call_groups.items():
        # Separate gateway and extension channels
        gateway_legs = []
        extension_legs = []

        for ch in group_channels:
            is_gateway = any(gw in ch['channel'].lower() for gw in GATEWAYS)
            if is_gateway:
                gateway_legs.append(ch)
            else:
                extension_legs.append(ch)

        # Determine call type and merge
        if gateway_legs and extension_legs:
            # Bridged call between gateway and extension
            gateway_ch = gateway_legs[0]
            extension_ch = extension_legs[0]

            # Check context to determine direction
            ext_context = extension_ch['context'].lower()
            is_outbound_context = any(p in ext_context for p in ['macro-dialout', 'outbound', 'dialout-trunk'])

            if is_outbound_context:
                # OUTBOUND: Show extension channel with destination from gateway
                direction = 'outbound'
                call_info = {
                    'channel': extension_ch['channel'],
                    'dstchannel': gateway_ch['channel'],  # Add destination channel
                    'callerid': f"{extension_ch['calleridname']} <{extension_ch['callerid']}>",
                    'extension': extension_ch['extension'],
                    'destination': gateway_ch['extension'],  # Destination number
                    'context': extension_ch['context'],
                    'status': extension_ch['state'],
                    'duration': extension_ch['duration'],
                    'direction': direction,
                }
                print(f"DEBUG: Merged OUTBOUND call - {extension_ch['channel']} → {gateway_ch['channel']} ({gateway_ch['extension']})")
            else:
                # INBOUND: Show gateway channel
                direction = 'inbound'
                call_info = {
                    'channel': gateway_ch['channel'],
                    'dstchannel': extension_ch['channel'],  # Add destination channel
                    'callerid': f"{gateway_ch['calleridname']} <{gateway_ch['callerid']}>",
                    'extension': gateway_ch['extension'],
                    'destination': extension_ch['extension'],  # Destination extension
                    'context': gateway_ch['context'],
                    'status': gateway_ch['state'],
                    'duration': gateway_ch['duration'],
                    'direction': direction,
                }
                print(f"DEBUG: Merged INBOUND call - {gateway_ch['channel']} → {extension_ch['channel']} (ext {extension_ch['extension']})")

            if call_info['status'] == 'Up':
                active_count += 1
            calls.append(call_info)

        elif gateway_legs:
            # Gateway only - INBOUND
            for ch in gateway_legs:
                call_info = {
                    'channel': ch['channel'],
                    'callerid': f"{ch['calleridname']} <{ch['callerid']}>",
                    'extension': ch['extension'],
                    'destination': ch['extension'],
                    'context': ch['context'],
                    'status': ch['state'],
                    'duration': ch['duration'],
                    'direction': 'inbound',
                }
                if ch['state'] == 'Up':
                    active_count += 1
                calls.append(call_info)
                print(f"DEBUG: INBOUND call - {ch['channel']}")

        elif extension_legs:
            # Extension only - INTERNAL
            for ch in extension_legs:
                call_info = {
                    'channel': ch['channel'],
                    'callerid': f"{ch['calleridname']} <{ch['callerid']}>",
                    'extension': ch['extension'],
                    'destination': ch['extension'],
                    'context': ch['context'],
                    'status': ch['state'],
                    'duration': ch['duration'],
                    'direction': 'internal',
                }
                if ch['state'] == 'Up':
                    active_count += 1
                calls.append(call_info)
                print(f"DEBUG: INTERNAL call - {ch['channel']}")

    # Calculate extension KPIs
    extension_kpis = calculate_extension_kpis(calls, channels)

    return {
        'status': 'ok',
        'active_calls': active_count,
        'total_channels': len(channels),
        'calls': calls,
        'extension_kpis': extension_kpis,
        'timestamp': int(time.time())
    }


def write_data_file(data):
    """Write data to JSON file"""
    try:
        # Create directory if it doesn't exist
        data_dir = os.path.dirname(DATA_FILE)
        if not os.path.exists(data_dir):
            os.makedirs(data_dir, mode=0o755)
            print(f"Created data directory: {data_dir}")

        with open(DATA_FILE, 'w') as f:
            json.dump(data, f, indent=2)

        # Set permissions to 644 (readable by all, writable by owner)
        os.chmod(DATA_FILE, 0o644)
        return True
    except Exception as e:
        print(f"Error writing data file: {e}")
        import traceback
        traceback.print_exc()
        return False


def main():
    """Main service loop"""
    global LAST_DB_RELOAD, LAST_CHANNEL_COUNT

    print(f"Asterisk Realtime Report Service Starting...")
    print(f"AMI Connection: {AMI_HOST}:{AMI_PORT}")
    print(f"Data File: {DATA_FILE}")

    # Load historical stats from database on startup
    print("Loading historical extension statistics from database...")
    load_extension_stats_from_db()
    LAST_DB_RELOAD = time.time()

    ami = None

    while True:
        try:
            current_time = time.time()

            # Connect to AMI
            if not ami or not ami.logged_in:
                ami = AsteriskAMI(AMI_HOST, AMI_PORT, AMI_USER, AMI_SECRET)

                if not ami.connect():
                    print("Failed to connect to AMI, retrying in 10 seconds...")
                    time.sleep(10)
                    continue

                if not ami.login():
                    print("Failed to login to AMI, retrying in 10 seconds...")
                    time.sleep(10)
                    continue

            # Get active channels
            channels = ami.get_active_channels()
            current_channel_count = len(channels)

            # Detect call hangup (channel count decreased)
            if LAST_CHANNEL_COUNT > 0 and current_channel_count < LAST_CHANNEL_COUNT:
                print(f"Call hangup detected (channels: {LAST_CHANNEL_COUNT} → {current_channel_count}), reloading DB stats...")
                load_extension_stats_from_db()
                LAST_DB_RELOAD = current_time
            # Periodic reload (every 30 seconds)
            elif current_time - LAST_DB_RELOAD >= DB_RELOAD_INTERVAL:
                print("Periodic reload of extension statistics from database...")
                load_extension_stats_from_db()
                LAST_DB_RELOAD = current_time

            LAST_CHANNEL_COUNT = current_channel_count

            # Process and write data
            data = process_channels(channels)
            write_data_file(data)

            print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Active: {data['active_calls']}, Total Channels: {data['total_channels']}, Extensions: {len(data.get('extension_kpis', []))}")

            # Wait before next update
            time.sleep(UPDATE_INTERVAL)

        except KeyboardInterrupt:
            print("\nShutting down...")
            if ami:
                ami.close()
            sys.exit(0)

        except Exception as e:
            print(f"Error in main loop: {e}")
            if ami:
                ami.close()
                ami = None
            time.sleep(5)


if __name__ == '__main__':
    main()
