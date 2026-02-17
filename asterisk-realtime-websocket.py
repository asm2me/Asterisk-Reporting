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
            SUM(internal_calls) as internal_calls
        FROM (
            SELECT
                SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1) AS extension,
                COUNT(*) as total_calls,
                SUM(CASE WHEN dstchannel IS NOT NULL AND dstchannel != '' THEN 1 ELSE 0 END) as answered_calls,
                SUM(billsec) as total_duration,
                SUM(CASE WHEN disposition IN ('NO ANSWER', 'NOANSWER') THEN 1 ELSE 0 END) as missed_calls,
                SUM(CASE WHEN ({gateway_like}) AND dstchannel REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as outbound_calls,
                0 as inbound_calls,
                SUM(CASE WHEN NOT ({gateway_like}) OR dstchannel NOT REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as internal_calls
            FROM cdr
            WHERE calldate >= %s AND calldate < DATE_ADD(%s, INTERVAL 1 DAY)
            AND (channel LIKE 'PJSIP/%%%%' OR channel LIKE 'SIP/%%%%')
            AND channel REGEXP '^(PJSIP|SIP)/[0-9]+'
            GROUP BY extension

            UNION ALL

            SELECT
                SUBSTRING_INDEX(SUBSTRING_INDEX(dstchannel, '/', -1), '-', 1) AS extension,
                COUNT(*) as total_calls,
                SUM(CASE WHEN dstchannel IS NOT NULL AND dstchannel != '' THEN 1 ELSE 0 END) as answered_calls,
                SUM(billsec) as total_duration,
                SUM(CASE WHEN disposition IN ('NO ANSWER', 'NOANSWER') THEN 1 ELSE 0 END) as missed_calls,
                0 as outbound_calls,
                SUM(CASE WHEN ({gateway_like}) AND channel REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as inbound_calls,
                SUM(CASE WHEN NOT ({gateway_like}) OR channel NOT REGEXP '^(PJSIP|SIP)/.*' THEN 1 ELSE 0 END) as internal_calls
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
                extension_stats_db[ext] = {
                    'total_calls_today': int(row['total_calls'] or 0),
                    'answered_today': int(row['answered_calls'] or 0),
                    'missed_today': int(row['missed_calls'] or 0),
                    'total_duration_today': int(row['total_duration'] or 0),
                    'inbound_today': int(row['inbound_calls'] or 0),
                    'outbound_today': int(row['outbound_calls'] or 0),
                    'internal_today': int(row['internal_calls'] or 0),
                }

        cursor.close()
        conn.close()
        print(f"✓ Loaded DB stats for {len(extension_stats_db)} extensions")

    except Exception as e:
        print(f"⚠ Database stats load failed: {e}")


def process_channels(channels):
    """Process channel data into structured format"""
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

    # Process each group
    for duration, group in call_groups.items():
        gateway_legs = [ch for ch in group if any(gw in ch['channel'].lower() for gw in GATEWAYS)]
        extension_legs = [ch for ch in group if not any(gw in ch['channel'].lower() for gw in GATEWAYS)]

        if gateway_legs and extension_legs:
            # Bridged call
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
                    extension_kpis[ext] = {'extension': ext, 'caller_id': ext_ch['calleridname'], 'active': 0, 'in': 0, 'out': 0, 'int': 0, 'status': 'available'}
                if direction == 'outbound':
                    extension_kpis[ext]['out'] += 1
                else:
                    extension_kpis[ext]['in'] += 1
                if ext_ch['state'] == 'Up':
                    extension_kpis[ext]['active'] += 1
                    extension_kpis[ext]['status'] = 'on_call'

    # Merge with DB stats
    for ext in list(extension_stats_db.keys()):
        if ext not in extension_kpis:
            extension_kpis[ext] = {'extension': ext, 'caller_id': ext, 'active': 0, 'in': 0, 'out': 0, 'int': 0, 'status': 'available'}

    kpi_list = []
    for ext, stats in sorted(extension_kpis.items()):
        db = extension_stats_db.get(ext, {})
        avg_dur = db.get('total_duration_today', 0) // db.get('total_calls_today', 1) if db.get('total_calls_today', 0) > 0 else 0

        kpi_list.append({
            'extension': stats['extension'],
            'caller_id': stats['caller_id'],
            'status': stats['status'],
            'active_calls': stats['active'],
            'total_calls_today': db.get('total_calls_today', 0),
            'inbound_today': db.get('inbound_today', 0) + stats['in'],
            'outbound_today': db.get('outbound_today', 0) + stats['out'],
            'internal_today': db.get('internal_today', 0) + stats['int'],
            'answered_today': db.get('answered_today', 0),
            'missed_today': db.get('missed_today', 0),
            'avg_duration': avg_dur,
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
    global last_db_reload

    ami = None
    last_channel_count = 0

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

            # Get channels
            channels = await ami.get_channels()
            current_count = len(channels)

            # Reload DB stats on hangup or periodically
            current_time = time.time()
            if (last_channel_count > 0 and current_count < last_channel_count) or (current_time - last_db_reload >= DB_RELOAD_INTERVAL):
                load_db_stats()
                last_db_reload = current_time

            last_channel_count = current_count

            # Process and broadcast
            data = process_channels(channels)
            await broadcast(data)

            print(f"[{datetime.now().strftime('%H:%M:%S')}] Active: {data['active_calls']}, Channels: {data['total_channels']}, Extensions: {len(data['extension_kpis'])}, Clients: {len(connected_clients)}")

            await asyncio.sleep(2)

        except Exception as e:
            print(f"✗ Monitor loop error: {e}")
            if ami:
                await ami.close()
                ami = None
            await asyncio.sleep(5)


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
        # Start AMI monitor
        await ami_monitor_loop()


if __name__ == '__main__':
    # Python 3.6 compatibility - asyncio.run() was added in 3.7
    try:
        loop = asyncio.get_event_loop()
        loop.run_until_complete(main())
    except KeyboardInterrupt:
        print("\n\n✓ Shutdown complete")
    finally:
        loop.close()
