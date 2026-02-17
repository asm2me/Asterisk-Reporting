<?php
use function h;
use function buildUrl;

/** @var array $CONFIG */
/** @var array $me */
/** @var bool $isAdmin */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Realtime Report - Supervisor CDR</title>

<style>
  :root{
    --bg:#0b1220;--card:#0f1a30;--muted:#9fb0d0;--text:#e8eefc;--line:rgba(255,255,255,.08);
    --accent:#7aa2ff;--ok:#44d19d;--warn:#ffcc66;--bad:#ff6b7a;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    background:linear-gradient(180deg,#070b14 0%, #0b1220 40%, #0b1220 100%);
    color:var(--text);
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;
  }
  .wrap{max-width:1400px;margin:0 auto;padding:18px;}
  @media(min-width:900px){.wrap{padding:22px;}}

  .topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap;}
  h1{margin:0;font-size:20px;}

  .sub{color:var(--muted);font-size:12px;margin-top:6px;display:flex;gap:8px;flex-wrap:wrap}
  .pill{display:inline-flex;padding:2px 8px;border-radius:999px;background:rgba(255,255,255,.06);font-size:12px;color:var(--muted);border:1px solid var(--line)}

  .btn{display:inline-flex;align-items:center;gap:8px;background:rgba(122,162,255,.12);border:1px solid rgba(122,162,255,.25);
       color:var(--text);padding:10px 12px;border-radius:12px;text-decoration:none;font-size:13px;white-space:nowrap;}
  .btn:hover{background:rgba(122,162,255,.18)}
  .btn.danger{background:rgba(255,107,122,.14);border-color:rgba(255,107,122,.25)}
  .btn.danger:hover{background:rgba(255,107,122,.22)}

  .card{background:rgba(15,26,48,.75);border:1px solid var(--line);border-radius:16px;padding:16px;}
  .k{font-size:12px;color:var(--muted);}
  .v{font-size:24px;margin-top:6px;font-weight:650;}

  .grid{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:12px;}
  @media(min-width:520px){.grid{grid-template-columns:repeat(2, minmax(0,1fr))}}
  @media(min-width:900px){.grid{grid-template-columns:repeat(4, minmax(0,1fr))}}

  table{width:100%;border-collapse:separate;border-spacing:0;}
  thead th{position:sticky;top:0;background:rgba(15,26,48,.92);border-bottom:1px solid var(--line);
       font-size:12px;color:var(--muted);text-align:left;padding:12px;white-space:nowrap}
  tbody td{border-bottom:1px solid var(--line);padding:12px;font-size:13px;}
  tbody tr:hover{background:rgba(255,255,255,.03)}

  .tableWrap{padding:0;overflow:auto;-webkit-overflow-scrolling:touch}

  .status{display:inline-flex;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;}
  .status.active{background:rgba(68,209,157,.15);color:var(--ok);border:1px solid rgba(68,209,157,.3);}
  .status.ringing{background:rgba(255,204,102,.15);color:var(--warn);border:1px solid rgba(255,204,102,.3);}
  .status.warn{background:rgba(255,204,102,.15);color:var(--warn);border:1px solid rgba(255,204,102,.3);}
  .status.busy{background:rgba(255,107,122,.15);color:var(--bad);border:1px solid rgba(255,107,122,.3);}
  .status.paused{background:rgba(159,176,208,.15);color:var(--muted);border:1px solid rgba(159,176,208,.3);}
  .status.online{background:rgba(122,162,255,.15);color:var(--accent);border:1px solid rgba(122,162,255,.3);}
  .status.offline{background:rgba(80,80,90,.15);color:#888;border:1px solid rgba(80,80,90,.3);}

  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}

  .pulse{animation:pulse 2s cubic-bezier(0.4,0,0.6,1) infinite;}
  @keyframes pulse{0%,100%{opacity:1} 50%{opacity:.5}}

  .connection-status{height:3px;width:100%;position:fixed;top:0;left:0;z-index:9999;transition:background-color 0.3s ease;}
  .connection-status.connected{background:var(--ok);}
  .connection-status.disconnected{background:var(--bad);}

  @media (max-width: 900px){
    thead{display:none;}
    tbody tr{display:block;border-bottom:1px solid var(--line);padding:10px;margin-bottom:10px;background:rgba(15,26,48,.5);border-radius:8px;}
    tbody td{display:flex;gap:10px;justify-content:space-between;border-bottom:none;padding:8px 0;}
    tbody td::before{content: attr(data-label);color: var(--muted);font-size:12px;font-weight:600;}
  }
</style>
</head>
<body>
<div class="connection-status connected" id="connectionStatus"></div>
<div class="wrap">
  <div class="topbar">
    <div>
      <h1>📡 Realtime Report</h1>
      <div class="sub">
        <span class="pill">User: <b><?= h($me['username']) ?></b><?= $isAdmin ? ' (admin)' : '' ?></span>
        <span class="pill pulse" id="updateStatus">Live Updates Active</span>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn" href="index.php">📊 CDR Report</a>
      <a class="btn" href="realtime-queues.php">📞 Queue Realtime</a>
      <a class="btn" href="kpi.php">📈 Extension KPIs</a>
      <?php if ($isAdmin): ?><a class="btn" href="?page=users">👤 User Management</a><?php endif; ?>
      <a class="btn danger" href="<?= h(buildUrl(['action'=>'logout'])) ?>">🚪 Logout</a>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="grid">
    <div class="card">
      <div class="k">Active Calls</div>
      <div class="v" id="activeCalls">0</div>
    </div>
    <div class="card">
      <div class="k">Total Channels</div>
      <div class="v" id="totalChannels">0</div>
    </div>
    <div class="card">
      <div class="k">Inbound Calls</div>
      <div class="v" style="color:var(--ok)" id="inboundCalls">0</div>
    </div>
    <div class="card">
      <div class="k">Outbound Calls</div>
      <div class="v" style="color:var(--accent)" id="outboundCalls">0</div>
    </div>
  </div>

  <!-- Debug Info -->
  <div class="card" style="margin-bottom:12px;">
    <details>
      <summary style="cursor:pointer;font-weight:600;">🔍 Debug Info (click to expand)</summary>
      <pre id="debugData" style="margin-top:10px;font-size:11px;max-height:300px;overflow:auto;background:rgba(0,0,0,.3);padding:10px;border-radius:6px;white-space:pre-wrap;">Loading...</pre>
    </details>
  </div>

  <!-- Extension KPIs -->
  <div class="card tableWrap" style="margin-bottom:12px;">
    <h3 style="margin:0 0 12px 0;font-size:16px;">📊 Live Extension KPIs</h3>
    <table>
      <thead>
        <tr>
          <th>Extension</th>
          <th>Name</th>
          <th>Status</th>
          <th>Active Now</th>
          <th>Today Total</th>
          <th>Inbound</th>
          <th>Outbound</th>
          <th>Internal</th>
          <th>Answered</th>
          <th>Missed</th>
          <th>THT</th>
          <th>AHT</th>
          <th>First Call</th>
          <th>Last Call</th>
        </tr>
      </thead>
      <tbody id="kpisTable">
        <tr><td colspan="14" style="color:var(--muted);padding:16px;text-align:center;">No extension data</td></tr>
      </tbody>
    </table>
  </div>

  <!-- Active Calls Table -->
  <div class="card tableWrap">
    <h3 style="margin:0 0 12px 0;font-size:16px;">Active Calls</h3>
    <table>
      <thead>
        <tr>
          <th>Channel</th>
          <th>Caller ID</th>
          <th>Extension</th>
          <th>Destination</th>
          <th>Status</th>
          <th>Duration</th>
        </tr>
      </thead>
      <tbody id="callsTable">
        <tr><td colspan="6" style="color:var(--muted);padding:16px;text-align:center;">No active calls</td></tr>
      </tbody>
    </table>
  </div>

</div>

<script>
let ws = null;
let reconnectInterval = null;

function formatDuration(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${s.toString().padStart(2, '0')}`;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function updateDisplay(data) {
  console.log('Received data:', data);

  // Update debug display
  document.getElementById('debugData').textContent = JSON.stringify(data, null, 2);

  // Update summary cards
  document.getElementById('activeCalls').textContent = data.active_calls || 0;
  document.getElementById('totalChannels').textContent = data.total_channels || 0;

  // Count inbound/outbound
  const calls = data.calls || [];
  console.log('Total calls:', calls.length);

  const inbound = calls.filter(c => c.direction === 'inbound').length;
  const outbound = calls.filter(c => c.direction === 'outbound').length;

  document.getElementById('inboundCalls').textContent = inbound;
  document.getElementById('outboundCalls').textContent = outbound;

  // Update Extension KPIs table
  const kpisTable = document.getElementById('kpisTable');
  const kpis = data.extension_kpis || [];
  if (kpis.length === 0) {
    kpisTable.innerHTML = '<tr><td colspan="14" style="color:var(--muted);padding:16px;text-align:center;">No extension data</td></tr>';
  } else {
    kpisTable.innerHTML = kpis.map(kpi => {
      let statusClass, statusText;

      switch(kpi.status) {
        case 'in-call':
          statusClass = 'active';
          statusText = 'In Call';
          break;
        case 'on-hold':
          statusClass = 'warn';
          statusText = 'On Hold';
          break;
        case 'ringing':
          statusClass = 'ringing';
          statusText = 'Ringing';
          break;
        case 'busy':
          statusClass = 'busy';
          statusText = 'Busy';
          break;
        case 'paused':
          statusClass = 'paused';
          statusText = 'Paused';
          break;
        case 'online':
          statusClass = 'online';
          statusText = 'Online';
          break;
        case 'offline':
          statusClass = 'offline';
          statusText = 'Offline';
          break;
        default:
          statusClass = 'online';
          statusText = 'Available';
      }

      // Show today's totals with live calls in parentheses if active
      const inboundDisplay = kpi.inbound_today + (kpi.inbound > 0 ? ` (+${kpi.inbound})` : '');
      const outboundDisplay = kpi.outbound_today + (kpi.outbound > 0 ? ` (+${kpi.outbound})` : '');
      const internalDisplay = kpi.internal_today + (kpi.internal > 0 ? ` (+${kpi.internal})` : '');

      return `
      <tr>
        <td data-label="Extension"><strong>${escapeHtml(kpi.extension || '-')}</strong></td>
        <td data-label="Name">${escapeHtml(kpi.caller_id || '-')}</td>
        <td data-label="Status"><span class="status ${statusClass}">${statusText}</span></td>
        <td data-label="Active Now"><strong style="color:var(--accent)">${kpi.active_calls || 0}</strong></td>
        <td data-label="Today Total"><strong>${kpi.total_calls_today || 0}</strong></td>
        <td data-label="Inbound" style="color:var(--ok)">${inboundDisplay}</td>
        <td data-label="Outbound" style="color:var(--accent)">${outboundDisplay}</td>
        <td data-label="Internal" style="color:var(--warn)">${internalDisplay}</td>
        <td data-label="Answered" style="color:var(--ok)">${kpi.answered_today || 0}</td>
        <td data-label="Missed" style="color:var(--bad)">${kpi.missed_today || 0}</td>
        <td data-label="THT">${formatDuration(kpi.tht || 0)}</td>
        <td data-label="AHT">${formatDuration(kpi.aht || 0)}</td>
        <td data-label="First Call">${escapeHtml(kpi.first_call_start || '-')}</td>
        <td data-label="Last Call">${escapeHtml(kpi.last_call_end || '-')}</td>
      </tr>
    `;
    }).join('');
  }

  // Update Active Calls table
  const tbody = document.getElementById('callsTable');
  if (calls.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="color:var(--muted);padding:16px;text-align:center;">No active calls</td></tr>';
  } else {
    console.log('Rendering calls:', calls);
    tbody.innerHTML = calls.map(call => `
      <tr>
        <td data-label="Channel" class="mono">${escapeHtml(call.channel || '-')}</td>
        <td data-label="Caller ID">${escapeHtml(call.callerid || '-')}</td>
        <td data-label="Extension">${escapeHtml(call.extension || '-')}</td>
        <td data-label="Destination">${escapeHtml(call.destination || '-')}</td>
        <td data-label="Status"><span class="status ${call.status === 'Up' ? 'active' : 'ringing'}">${escapeHtml(call.status || 'Unknown')}</span></td>
        <td data-label="Duration">${formatDuration(call.duration || 0)}</td>
      </tr>
    `).join('');
  }

  document.getElementById('updateStatus').textContent = 'Last Update: ' + new Date().toLocaleTimeString();
}

function connectWebSocket() {
  if (ws && ws.readyState === WebSocket.OPEN) {
    return;
  }

  // Construct WebSocket URL - use current hostname with port 8765
  const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  const wsUrl = `${wsProtocol}//${window.location.hostname}:8765`;

  console.log('Connecting to WebSocket:', wsUrl);
  document.getElementById('updateStatus').textContent = 'Connecting to WebSocket...';

  try {
    ws = new WebSocket(wsUrl);

    ws.onopen = function() {
      console.log('✓ WebSocket connected');
      document.getElementById('connectionStatus').className = 'connection-status connected';
      document.getElementById('updateStatus').textContent = 'Connected - Waiting for updates';

      // Clear reconnect interval if exists
      if (reconnectInterval) {
        clearInterval(reconnectInterval);
        reconnectInterval = null;
      }
    };

    ws.onmessage = function(event) {
      try {
        const data = JSON.parse(event.data);
        document.getElementById('connectionStatus').className = 'connection-status connected';
        updateDisplay(data);
      } catch (e) {
        console.error('Error parsing WebSocket message:', e);
      }
    };

    ws.onerror = function(error) {
      console.error('WebSocket error:', error);
      document.getElementById('connectionStatus').className = 'connection-status disconnected';
      document.getElementById('updateStatus').textContent = 'Connection Error';
    };

    ws.onclose = function() {
      console.log('✗ WebSocket disconnected');
      document.getElementById('connectionStatus').className = 'connection-status disconnected';
      document.getElementById('updateStatus').textContent = 'Disconnected - Reconnecting...';

      // Attempt to reconnect every 5 seconds
      if (!reconnectInterval) {
        reconnectInterval = setInterval(function() {
          console.log('Attempting to reconnect...');
          connectWebSocket();
        }, 5000);
      }
    };

  } catch (e) {
    console.error('Failed to create WebSocket:', e);
    document.getElementById('connectionStatus').className = 'connection-status disconnected';
    document.getElementById('updateStatus').textContent = 'Failed to connect';
  }
}

// Initialize WebSocket connection
console.log('Starting WebSocket realtime connection...');
connectWebSocket();
</script>
</body>
</html>
