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
    calls = []
    active_count = 0
    seen_calls = {}  # Track calls to deduplicate

    for ch in channels:
        channel = ch['channel']
        context = ch['context'].lower()
        extension = ch['extension']
        bridged = ch.get('bridged', '')

        # Skip Local channels and other non-SIP channels (they're intermediary legs)
        if not ('SIP/' in channel or 'PJSIP/' in channel):
            continue

        # Check if channel or bridged channel contains a configured gateway
        channel_is_gateway = any(gw in channel.lower() for gw in GATEWAYS)
        bridged_is_gateway = any(gw in bridged.lower() for gw in GATEWAYS) if bridged else False

        # Determine direction based on gateway position
        if channel_is_gateway:
            # Channel FROM gateway → INBOUND
            direction = 'inbound'
            print(f"DEBUG: {channel} FROM gateway → INBOUND")
        elif bridged_is_gateway:
            # Channel TO gateway (bridged to gateway) → OUTBOUND
            direction = 'outbound'
            print(f"DEBUG: {channel} TO gateway ({bridged}) → OUTBOUND")
        else:
            # No gateway involved → INTERNAL
            direction = 'internal'
            print(f"DEBUG: {channel} → INTERNAL")

        # Only count active calls (Up state)
        if ch['state'] == 'Up':
            active_count += 1

        # Create call info
        call_info = {
            'channel': channel,
            'callerid': f"{ch['calleridname']} <{ch['callerid']}>",
            'extension': ch['extension'],
            'destination': ch['extension'],
            'context': ch['context'],
            'status': ch['state'],
            'duration': ch['duration'],
            'direction': direction,
        }

        # Deduplicate: Create a key based on callerid and duration (rounded to 5s intervals)
        # This groups legs of the same call together
        duration_bucket = (ch['duration'] // 5) * 5
        call_key = f"{ch['callerid']}_{ch['extension']}_{duration_bucket}"

        # If we haven't seen this call, or if this is a gateway channel (prefer gateway over extension)
        if call_key not in seen_calls:
            seen_calls[call_key] = call_info
        elif channel_is_gateway and not any(gw in seen_calls[call_key]['channel'].lower() for gw in GATEWAYS):
            # Replace with gateway channel (more informative)
            seen_calls[call_key] = call_info

    # Convert deduplicated calls to list
    calls = list(seen_calls.values())

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
