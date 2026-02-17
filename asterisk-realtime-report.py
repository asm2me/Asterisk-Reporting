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
from datetime import datetime

# Configuration
AMI_HOST = '127.0.0.1'
AMI_PORT = 5038
AMI_USER = 'reporting'
AMI_SECRET = 'HfsobKSEPNQiiQWsFzzj'
DATA_FILE = '/var/www/html/supervisor2/data/asterisk-realtime-data.json'
GATEWAY_CONFIG = '/var/www/html/supervisor2/config.php'  # PHP config file with gateways
UPDATE_INTERVAL = 2  # seconds

# Load gateway patterns from config
def load_gateways():
    """Load gateway patterns from PHP config file"""
    try:
        with open(GATEWAY_CONFIG, 'r') as f:
            content = f.read()
            # Extract gateway names from PHP config
            # Looking for patterns like: 'gateways' => ['we', 'trunk1', ...]
            import re
            matches = re.findall(r"'gateways'\s*=>\s*\[(.*?)\]", content, re.DOTALL)
            if matches:
                # Extract quoted strings
                gateway_list = re.findall(r"'([^']+)'", matches[0])
                print(f"Loaded gateways from config: {gateway_list}")
                return gateway_list
    except Exception as e:
        print(f"Warning: Could not load gateways from config: {e}")

    # Default fallback gateway patterns
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

    return {
        'status': 'ok',
        'active_calls': active_count,
        'total_channels': len(channels),
        'calls': calls,
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
    print(f"Asterisk Realtime Report Service Starting...")
    print(f"AMI Connection: {AMI_HOST}:{AMI_PORT}")
    print(f"Data File: {DATA_FILE}")

    ami = None

    while True:
        try:
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

            # Process and write data
            data = process_channels(channels)
            write_data_file(data)

            print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Active: {data['active_calls']}, Total Channels: {data['total_channels']}")

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
