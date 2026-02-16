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
      <a class="btn" href="index.php">📞 CDR Report</a>
      <a class="btn" href="kpi.php">📊 Extension KPIs</a>
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
let lastUpdate = 0;
let eventSource = null;

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

  // Update table
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

  lastUpdate = Date.now();
  document.getElementById('updateStatus').textContent = 'Last Update: ' + new Date().toLocaleTimeString();
}

function pollData() {
  console.log('Polling data at', new Date().toLocaleTimeString());
  fetch('?action=getdata')
    .then(res => {
      console.log('Response status:', res.status, res.statusText);
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      return res.json();
    })
    .then(data => {
      console.log('Data received successfully:', data);
      document.getElementById('connectionStatus').className = 'connection-status connected';
      updateDisplay(data);
    })
    .catch(err => {
      document.getElementById('connectionStatus').className = 'connection-status disconnected';
      console.error('Error fetching realtime data:', err);
      document.getElementById('updateStatus').textContent = 'Error: ' + err.message;
      document.getElementById('debugData').textContent = 'Error: ' + err.message + '\n\nCheck browser console (F12) for details.';
    });
}

function connectSSE() {
  if (eventSource) {
    eventSource.close();
  }

  console.log('Connecting to SSE stream...');
  eventSource = new EventSource('?action=stream');

  eventSource.addEventListener('connected', function(e) {
    console.log('SSE connected');
    document.getElementById('connectionStatus').className = 'connection-status connected';
    document.getElementById('updateStatus').textContent = 'Connected via SSE - Waiting for updates';
  });

  eventSource.addEventListener('update', function(e) {
    const data = JSON.parse(e.data);
    document.getElementById('connectionStatus').className = 'connection-status connected';
    updateDisplay(data);
  });

  eventSource.addEventListener('error', function(e) {
    console.error('SSE error:', e);
    document.getElementById('connectionStatus').className = 'connection-status disconnected';
    document.getElementById('updateStatus').textContent = 'Disconnected - Reconnecting...';

    // Reconnect after 5 seconds
    if (eventSource) {
      eventSource.close();
    }
    setTimeout(connectSSE, 5000);
  });

  eventSource.onerror = function(e) {
    console.error('SSE connection error:', e);
    document.getElementById('connectionStatus').className = 'connection-status disconnected';
    document.getElementById('updateStatus').textContent = 'Connection Error - Reconnecting...';
  };
}

// Use simple polling (more reliable than SSE)
console.log('Starting realtime polling every 2 seconds...');
pollData();
setInterval(pollData, 2000);
</script>
</body>
</html>
