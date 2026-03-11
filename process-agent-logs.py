#!/usr/bin/env python3.6
"""
Process Asterisk logs and backfill agent_event table.

Run manually:
    python3.6 process-agent-logs.py

Or with options:
    python3.6 process-agent-logs.py --force        # Re-process all logs including archived/rotated
    python3.6 process-agent-logs.py --full-only     # Only process full log
    python3.6 process-agent-logs.py --queue-only    # Only process queue_log

This is the same logic the realtime websocket service runs on startup.
"""

import asyncio
import glob
import gzip
import json
import os
import re
import sys
import time
from datetime import datetime

try:
    import aiomysql
except ImportError:
    print("ERROR: aiomysql not installed. Install with: pip3 install aiomysql")
    sys.exit(1)

# ── Configuration ─────────────────────────────────────────────────

CONFIG_FILE = '/var/www/html/supervisor2/config.json'
try:
    with open(CONFIG_FILE, 'r') as f:
        CONFIG = json.load(f)
        print("Loaded configuration from {}".format(CONFIG_FILE))
except Exception as e:
    print("Could not load config.json: {}".format(e))
    CONFIG = {}

QUEUE_LOG_PATH = CONFIG.get('asterisk', {}).get('queueLogPath', '/var/log/asterisk/queue_log')
FULL_LOG_PATH  = CONFIG.get('asterisk', {}).get('fullLogPath', '/var/log/asterisk/full')
DB_CONFIG_FILE = CONFIG.get('realtime', {}).get('dbConfigFile', '/etc/amportal.conf')

db_pool = None


# ── DB helpers ────────────────────────────────────────────────────

def get_db_config():
    """Parse DB credentials from FreePBX/amportal config file."""
    db_config = {'host': 'localhost', 'user': 'root', 'password': '', 'db': 'asteriskcdrdb', 'port': 3306}
    try:
        with open(DB_CONFIG_FILE, 'r') as f:
            for line in f:
                line = line.strip()
                if '=' in line and not line.startswith('#'):
                    key, value = line.split('=', 1)
                    key, value = key.strip(), value.strip().strip('"').strip("'")
                    if key == 'AMPDBHOST':   db_config['host'] = value
                    elif key == 'AMPDBUSER': db_config['user'] = value
                    elif key == 'AMPDBPASS': db_config['password'] = value
                    elif key == 'AMPDBPORT': db_config['port'] = int(value) if value.isdigit() else 3306
    except Exception:
        pass
    return db_config


async def init_db_pool():
    """Create aiomysql connection pool."""
    global db_pool
    cfg = get_db_config()
    try:
        db_pool = await aiomysql.create_pool(
            host=cfg['host'], port=cfg['port'],
            user=cfg['user'], password=cfg['password'],
            db=cfg['db'], minsize=1, maxsize=5,
            autocommit=True
        )
        print("DB pool created ({}:{}/{})".format(cfg['host'], cfg['port'], cfg['db']))
    except Exception as e:
        print("Failed to create DB pool: {}".format(e))
        sys.exit(1)


async def ensure_agent_event_table():
    """Create the agent_event table if it doesn't exist."""
    if db_pool is None:
        return
    create_sql = """
    CREATE TABLE IF NOT EXISTS `agent_event` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `event_time` DATETIME        NOT NULL,
        `extension`  VARCHAR(20)     NOT NULL,
        `event_type` ENUM('LOGIN','LOGOUT','PAUSE','UNPAUSE') NOT NULL,
        `queue`      VARCHAR(64)     DEFAULT NULL,
        `reason`     VARCHAR(128)    DEFAULT NULL,
        `source`     ENUM('queue_log','ami','full_log','fop2') NOT NULL,
        `extra`      VARCHAR(255)    DEFAULT NULL,
        PRIMARY KEY (`id`),
        INDEX `idx_ext_time`   (`extension`, `event_time`),
        INDEX `idx_type_time`  (`event_type`, `event_time`),
        INDEX `idx_event_time` (`event_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """
    try:
        async with db_pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(create_sql)
        print("agent_event table ready")
    except Exception as e:
        print("ensure_agent_event_table error: {}".format(e))
        sys.exit(1)


async def insert_agent_event(extension, event_type, event_time=None,
                              queue=None, reason=None,
                              source='full_log', extra=None):
    """Insert a row into agent_event table (no dedup for backfill)."""
    if db_pool is None:
        return
    if event_time is None:
        event_time = datetime.now()
    try:
        async with db_pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "INSERT INTO agent_event (event_time, extension, event_type, queue, reason, source, extra) "
                    "VALUES (%s, %s, %s, %s, %s, %s, %s)",
                    (event_time, extension, event_type, queue, reason, source,
                     extra[:255] if extra else None)
                )
    except Exception as e:
        print("insert_agent_event error: {}".format(e))


# ── Archived Log Discovery ────────────────────────────────────────

def find_log_files(base_path):
    """Find the base log and all rotated/archived copies, sorted oldest first.
    Handles patterns:
      - Date-based: full-20260305, queue_log-20260210
      - Numeric rotation: full.1, full.2, full.1.gz, full.2.gz
    """
    files = []
    parent = os.path.dirname(base_path)
    base_name = os.path.basename(base_path)

    # Pattern 1: date-based archives (e.g. full-20260305, queue_log-20260210)
    for entry in glob.glob(os.path.join(parent, base_name + '-*')):
        fname = os.path.basename(entry)
        suffix = fname[len(base_name) + 1:]  # after the dash
        date_str = suffix.replace('.gz', '')
        if re.match(r'^\d{8}$', date_str):
            files.append((date_str, entry))

    # Pattern 2: numeric rotation (e.g. full.1, full.2.gz)
    for entry in glob.glob(os.path.join(parent, base_name + '.*')):
        fname = os.path.basename(entry)
        suffix = fname[len(base_name) + 1:]  # after the dot
        num_str = suffix.replace('.gz', '')
        if num_str.isdigit():
            # Convert to sortable string (pad so numeric sorts after dates)
            files.append(('N{:010d}'.format(99999999 - int(num_str)), entry))

    # Sort ascending by key (dates sort chronologically, highest rotation number = oldest first)
    files.sort(key=lambda x: x[0])
    ordered = [f[1] for f in files]

    # Append the current (non-rotated) file last (it's the newest)
    if os.path.isfile(base_path):
        ordered.append(base_path)
    return ordered


def open_log_file(path):
    """Open a log file, handling .gz transparently."""
    if path.endswith('.gz'):
        return gzip.open(path, 'rt', errors='replace')
    return open(path, 'r', errors='replace')


# ── Full Log Parser ───────────────────────────────────────────────

async def parse_full_log_history(force=False):
    """Parse /var/log/asterisk/full and backfill LOGIN/LOGOUT events.
    Skips if data already exists unless force=True."""
    if db_pool is None:
        return

    if not force:
        try:
            async with db_pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute(
                        "SELECT COUNT(*) FROM agent_event WHERE source='full_log'"
                    )
                    row = await cur.fetchone()
                    if row and row[0] > 0:
                        print("[FullLog] Already have {} full_log events, skipping (use --force to re-process)".format(row[0]))
                        return
        except Exception:
            pass

    # Discover log files: current + archived/rotated
    if force:
        log_files = find_log_files(FULL_LOG_PATH)
    else:
        log_files = [FULL_LOG_PATH] if os.path.isfile(FULL_LOG_PATH) else []

    if not log_files:
        print("[FullLog] {} not found, skipping".format(FULL_LOG_PATH))
        return

    print("[FullLog] Will process {} file(s): {}".format(len(log_files), ', '.join(os.path.basename(f) for f in log_files)))

    re_timestamp = re.compile(r'^\[(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})\]')
    re_endpoint_status = re.compile(
        r"Endpoint\s+(\d+)\s+is\s+now\s+(Reachable|Unreachable)", re.IGNORECASE)
    re_contact_reachable = re.compile(
        r"Contact\s+(\d+)/\S+\s+is\s+now\s+(Reachable|Unreachable)", re.IGNORECASE)
    re_contact_deleted = re.compile(
        r"Contact\s+(\d+)/\S+\s+has\s+been\s+deleted", re.IGNORECASE)
    re_contact_added = re.compile(
        r"Added\s+contact\s+.+?\s+to\s+AOR\s+'(\d+)'", re.IGNORECASE)
    re_contact_removed = re.compile(
        r"Removed\s+contact\s+.+?\s+from\s+AOR\s+'(\d+)'", re.IGNORECASE)
    re_peer_status = re.compile(
        r"Peer\s+'(?:SIP|PJSIP)/(\d+)'\s+is\s+now\s+(Reachable|Unreachable|Registered|Unregistered)",
        re.IGNORECASE)

    inserted = 0
    lines_read = 0

    for log_file in log_files:
        print("[FullLog] Processing {}...".format(log_file))
        try:
            with open_log_file(log_file) as f:
                for raw_line in f:
                    lines_read += 1
                    if lines_read % 50000 == 0:
                        print("[FullLog] Processing... {} lines read, {} events found".format(lines_read, inserted))

                    ts_match = re_timestamp.match(raw_line)
                    if not ts_match:
                        continue
                    log_date = ts_match.group(1)
                    log_time = ts_match.group(2)

                    try:
                        event_time = datetime.strptime("{} {}".format(log_date, log_time), '%Y-%m-%d %H:%M:%S')
                    except ValueError:
                        continue

                    m = re_endpoint_status.search(raw_line)
                    if m:
                        ext, status = m.group(1), m.group(2)
                        event_type = 'LOGIN' if status == 'Reachable' else 'LOGOUT'
                        await insert_agent_event(ext, event_type, event_time=event_time,
                                                  source='full_log', reason=status,
                                                  extra=raw_line.strip()[:255])
                        inserted += 1
                        continue

                    m = re_contact_reachable.search(raw_line)
                    if m:
                        ext, status = m.group(1), m.group(2)
                        event_type = 'LOGIN' if status == 'Reachable' else 'LOGOUT'
                        await insert_agent_event(ext, event_type, event_time=event_time,
                                                  source='full_log', reason=status,
                                                  extra=raw_line.strip()[:255])
                        inserted += 1
                        continue

                    m = re_contact_deleted.search(raw_line)
                    if m:
                        ext = m.group(1)
                        await insert_agent_event(ext, 'LOGOUT', event_time=event_time,
                                                  source='full_log', reason='Deleted',
                                                  extra=raw_line.strip()[:255])
                        inserted += 1
                        continue

                    m = re_contact_added.search(raw_line)
                    if m:
                        ext = m.group(1)
                        await insert_agent_event(ext, 'LOGIN', event_time=event_time,
                                                  source='full_log', reason='ContactAdded',
                                                  extra=raw_line.strip()[:255])
                        inserted += 1
                        continue

                    m = re_contact_removed.search(raw_line)
                    if m:
                        ext = m.group(1)
                        await insert_agent_event(ext, 'LOGOUT', event_time=event_time,
                                                  source='full_log', reason='ContactRemoved',
                                                  extra=raw_line.strip()[:255])
                        inserted += 1
                        continue

                    m = re_peer_status.search(raw_line)
                    if m:
                        ext, status = m.group(1), m.group(2)
                        event_type = 'LOGIN' if status in ('Reachable', 'Registered') else 'LOGOUT'
                        await insert_agent_event(ext, event_type, event_time=event_time,
                                                  source='full_log', reason=status,
                                                  extra=raw_line.strip()[:255])
                        inserted += 1

        except FileNotFoundError:
            print("[FullLog] {} not found, skipping".format(log_file))
        except Exception as e:
            print("[FullLog] Error reading {}: {}".format(log_file, e))

    print("[FullLog] Done - {} lines read, {} events inserted across {} file(s)".format(lines_read, inserted, len(log_files)))


# ── Queue Log Parser ──────────────────────────────────────────────

async def process_queue_log_line(line):
    """Parse a queue_log line and insert PAUSE/UNPAUSE events."""
    parts = line.split('|')
    if len(parts) < 5:
        return

    timestamp_str = parts[0]
    queue_name    = parts[2]
    agent         = parts[3]
    event         = parts[4].strip().upper()
    reason        = parts[5].strip() if len(parts) > 5 and parts[5].strip() else None

    if event not in ('PAUSE', 'UNPAUSE', 'PAUSEALL', 'UNPAUSEALL'):
        return

    ext_match = re.search(r'(?:Agent|PJSIP|SIP|Local)/(\d+)', agent, re.IGNORECASE)
    if not ext_match:
        ext_match = re.match(r'^(\d+)$', agent.strip())
    if not ext_match:
        return
    extension = ext_match.group(1)

    try:
        event_time = datetime.fromtimestamp(int(timestamp_str))
    except (ValueError, OSError):
        event_time = datetime.now()

    event_type = 'PAUSE' if event in ('PAUSE', 'PAUSEALL') else 'UNPAUSE'
    queue = None if event.endswith('ALL') or queue_name in ('NONE', '') else queue_name

    await insert_agent_event(
        extension=extension,
        event_type=event_type,
        event_time=event_time,
        queue=queue,
        reason=reason if reason else None,
        source='queue_log',
        extra=line[:255]
    )


async def parse_queue_log_history(force=False):
    """Parse existing queue_log to backfill PAUSE/UNPAUSE events.
    Skips if data already exists unless force=True."""
    if db_pool is None:
        return

    if not force:
        try:
            async with db_pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute(
                        "SELECT COUNT(*) FROM agent_event WHERE source='queue_log'"
                    )
                    row = await cur.fetchone()
                    if row and row[0] > 0:
                        print("[QueueLog] Already have {} queue_log events, skipping (use --force to re-process)".format(row[0]))
                        return
        except Exception:
            pass

    # Discover log files: current + archived/rotated
    if force:
        log_files = find_log_files(QUEUE_LOG_PATH)
    else:
        log_files = [QUEUE_LOG_PATH] if os.path.isfile(QUEUE_LOG_PATH) else []

    if not log_files:
        print("[QueueLog] {} not found, skipping".format(QUEUE_LOG_PATH))
        return

    print("[QueueLog] Will process {} file(s): {}".format(len(log_files), ', '.join(os.path.basename(f) for f in log_files)))

    inserted = 0
    lines_read = 0
    for log_file in log_files:
        print("[QueueLog] Processing {}...".format(log_file))
        try:
            with open_log_file(log_file) as f:
                for line in f:
                    lines_read += 1
                    if lines_read % 50000 == 0:
                        print("[QueueLog] Processing... {} lines read, {} events found".format(lines_read, inserted))
                    line = line.strip()
                    if not line:
                        continue
                    parts = line.split('|')
                    if len(parts) < 5:
                        continue
                    event = parts[4].strip().upper()
                    if event in ('PAUSE', 'UNPAUSE', 'PAUSEALL', 'UNPAUSEALL'):
                        await process_queue_log_line(line)
                        inserted += 1
        except FileNotFoundError:
            print("[QueueLog] {} not found, skipping".format(log_file))
        except Exception as e:
            print("[QueueLog] Error reading {}: {}".format(log_file, e))

    print("[QueueLog] Done - {} lines read, {} pause events inserted across {} file(s)".format(lines_read, inserted, len(log_files)))


# ── Main ──────────────────────────────────────────────────────────

async def run(force=False, full_only=False, queue_only=False):
    """Main processing entry point. Called by CLI and by the service."""
    await init_db_pool()
    await ensure_agent_event_table()

    # When forcing, clear existing data for the sources we're about to re-process
    if force and db_pool is not None:
        try:
            async with db_pool.acquire() as conn:
                async with conn.cursor() as cur:
                    if not queue_only:
                        await cur.execute("DELETE FROM agent_event WHERE source='full_log'")
                        print("[Force] Cleared existing full_log events")
                    if not full_only:
                        await cur.execute("DELETE FROM agent_event WHERE source='queue_log'")
                        print("[Force] Cleared existing queue_log events")
        except Exception as e:
            print("[Force] Error clearing old data: {}".format(e))

    if not queue_only:
        await parse_full_log_history(force=force)
    if not full_only:
        await parse_queue_log_history(force=force)

    if db_pool:
        db_pool.close()
        await db_pool.wait_closed()

    print("\nLog processing complete.")


def main():
    force = '--force' in sys.argv
    full_only = '--full-only' in sys.argv
    queue_only = '--queue-only' in sys.argv

    if '--help' in sys.argv or '-h' in sys.argv:
        print(__doc__)
        sys.exit(0)

    print("=" * 60)
    print("Asterisk Agent Log Processor")
    print("=" * 60)
    if force:
        print("Mode: FORCE (will re-process all logs including archived/rotated)")
    print("")

    loop = asyncio.get_event_loop()
    try:
        loop.run_until_complete(run(force=force, full_only=full_only, queue_only=queue_only))
    except KeyboardInterrupt:
        print("\nAborted.")
    finally:
        loop.close()


if __name__ == '__main__':
    main()
