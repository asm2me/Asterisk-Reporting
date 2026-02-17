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
<title>Queue Realtime - Supervisor CDR</title>

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
  .wrap{max-width:1600px;margin:0 auto;padding:18px;}
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

  .card{background:rgba(15,26,48,.75);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:12px;}
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
  .status.ok{background:rgba(68,209,157,.15);color:var(--ok);border:1px solid rgba(68,209,157,.3);}
  .status.warn{background:rgba(255,204,102,.15);color:var(--warn);border:1px solid rgba(255,204,102,.3);}
  .status.bad{background:rgba(255,107,122,.15);color:var(--bad);border:1px solid rgba(255,107,122,.3);}
  .status.paused{background:rgba(159,176,208,.15);color:var(--muted);border:1px solid rgba(159,176,208,.3);}
  .status.busy{background:rgba(122,162,255,.15);color:var(--accent);border:1px solid rgba(122,162,255,.3);}

  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;}

  .pulse{animation:pulse 2s cubic-bezier(0.4,0,0.6,1) infinite;}
  @keyframes pulse{0%,100%{opacity:1} 50%{opacity:.5}}

  .connection-status{height:3px;width:100%;position:fixed;top:0;left:0;z-index:9999;transition:background-color 0.3s ease;}
  .connection-status.connected{background:var(--ok);}
  .connection-status.disconnected{background:var(--bad);}

  .queue-section{margin-bottom:24px;}
  .queue-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
  .queue-name{font-size:18px;font-weight:600;}
  .queue-stats{display:flex;gap:16px;font-size:13px;}
  .queue-stat{display:flex;flex-direction:column;gap:4px;}
  .queue-stat-label{font-size:11px;color:var(--muted);}
  .queue-stat-value{font-size:16px;font-weight:600;}

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
      <h1>📞 Queue Realtime Monitor</h1>
      <div class="sub">
        <span class="pill">User: <b><?= h($me['username']) ?></b><?= $isAdmin ? ' (admin)' : '' ?></span>
        <span class="pill pulse" id="updateStatus">Live Updates Active</span>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn" href="index.php">📊 CDR Report</a>
      <a class="btn" href="realtime.php">📡 Extensions Realtime</a>
      <a class="btn" href="kpi.php">📈 Extension KPIs</a>
      <?php if ($isAdmin): ?><a class="btn" href="?page=users">👤 User Management</a><?php endif; ?>
      <a class="btn danger" href="<?= h(buildUrl(['action'=>'logout'])) ?>">🚪 Logout</a>
    </div>
  </div>

  <!-- Overall Queue Summary -->
  <div class="grid">
    <div class="card">
      <div class="k">Total Queues</div>
      <div class="v" id="totalQueues">0</div>
    </div>
    <div class="card">
      <div class="k">Calls Waiting</div>
      <div class="v" style="color:var(--warn)" id="totalWaiting">0</div>
    </div>
    <div class="card">
      <div class="k">Available Agents</div>
      <div class="v" style="color:var(--ok)" id="availableAgents">0</div>
    </div>
    <div class="card">
      <div class="k">Busy Agents</div>
      <div class="v" style="color:var(--accent)" id="busyAgents">0</div>
    </div>
  </div>

  <!-- Queue Details -->
  <div id="queuesList"></div>

</div>

<script>
let ws = null;
let reconnectInterval = null;

function formatDuration(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${s.toString().padStart(2, '0')}`;
}

function formatTime(seconds) {
  if (seconds < 60) return `${seconds}s`;
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}m ${s}s`;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function updateDisplay(data) {
  console.log('Received data:', data);

  const queues = data.queues || [];

  // Update summary cards
  document.getElementById('totalQueues').textContent = queues.length;

  let totalWaiting = 0;
  let totalAvailable = 0;
  let totalBusy = 0;

  queues.forEach(q => {
    totalWaiting += q.calls_waiting || 0;
    totalAvailable += q.available_members || 0;
    totalBusy += q.busy_members || 0;
  });

  document.getElementById('totalWaiting').textContent = totalWaiting;
  document.getElementById('availableAgents').textContent = totalAvailable;
  document.getElementById('busyAgents').textContent = totalBusy;

  // Render each queue
  const queuesList = document.getElementById('queuesList');

  if (queues.length === 0) {
    queuesList.innerHTML = '<div class="card" style="text-align:center;color:var(--muted);padding:32px;">No queues configured</div>';
  } else {
    queuesList.innerHTML = queues.map(queue => {
      const waitingCalls = queue.waiting_calls || [];
      const members = queue.members || [];

      // Determine queue health status
      let queueStatusClass = 'ok';
      let queueStatusText = 'Healthy';

      if (queue.calls_waiting > 0 && queue.available_members === 0) {
        queueStatusClass = 'bad';
        queueStatusText = 'Critical';
      } else if (queue.calls_waiting > queue.available_members) {
        queueStatusClass = 'warn';
        queueStatusText = 'Busy';
      }

      return `
        <div class="card queue-section">
          <div class="queue-header">
            <div class="queue-name">${escapeHtml(queue.name)}</div>
            <div class="queue-stats">
              <div class="queue-stat">
                <div class="queue-stat-label">Status</div>
                <div class="queue-stat-value"><span class="status ${queueStatusClass}">${queueStatusText}</span></div>
              </div>
              <div class="queue-stat">
                <div class="queue-stat-label">Waiting</div>
                <div class="queue-stat-value" style="color:var(--warn)">${queue.calls_waiting || 0}</div>
              </div>
              <div class="queue-stat">
                <div class="queue-stat-label">Longest Wait</div>
                <div class="queue-stat-value" style="color:${queue.longest_wait > 60 ? 'var(--bad)' : 'var(--muted)'}">${formatTime(queue.longest_wait || 0)}</div>
              </div>
              <div class="queue-stat">
                <div class="queue-stat-label">Available</div>
                <div class="queue-stat-value" style="color:var(--ok)">${queue.available_members || 0}</div>
              </div>
              <div class="queue-stat">
                <div class="queue-stat-label">Busy</div>
                <div class="queue-stat-value" style="color:var(--accent)">${queue.busy_members || 0}</div>
              </div>
            </div>
          </div>

          <!-- Waiting Calls -->
          ${waitingCalls.length > 0 ? `
            <h3 style="margin:16px 0 8px 0;font-size:14px;">📋 Calls in Queue (${waitingCalls.length})</h3>
            <div class="tableWrap">
              <table>
                <thead>
                  <tr>
                    <th>Position</th>
                    <th>Caller ID</th>
                    <th>Wait Time</th>
                  </tr>
                </thead>
                <tbody>
                  ${waitingCalls.map(call => `
                    <tr>
                      <td data-label="Position"><strong>#${call.position}</strong></td>
                      <td data-label="Caller ID">${escapeHtml(call.calleridname || call.callerid || '-')}</td>
                      <td data-label="Wait Time"><span style="color:${call.wait_time > 60 ? 'var(--bad)' : 'var(--warn)'}"><strong>${formatTime(call.wait_time || 0)}</strong></span></td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          ` : '<p style="color:var(--muted);font-size:13px;margin:8px 0;">No calls waiting</p>'}

          <!-- Queue Members -->
          <h3 style="margin:16px 0 8px 0;font-size:14px;">👥 Queue Members (${members.length})</h3>
          ${members.length > 0 ? `
            <div class="tableWrap">
              <table>
                <thead>
                  <tr>
                    <th>Extension</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Calls Taken</th>
                    <th>Last Call</th>
                  </tr>
                </thead>
                <tbody>
                  ${members.map(member => {
                    let memberStatus, memberStatusClass;
                    if (member.paused) {
                      memberStatus = 'Paused';
                      memberStatusClass = 'paused';
                    } else if (member.in_call) {
                      memberStatus = 'On Call';
                      memberStatusClass = 'busy';
                    } else {
                      memberStatus = 'Available';
                      memberStatusClass = 'ok';
                    }

                    const lastCallText = member.last_call > 0 ? formatTime(member.last_call) + ' ago' : 'Never';

                    return `
                      <tr>
                        <td data-label="Extension"><strong>${escapeHtml(member.extension || '-')}</strong></td>
                        <td data-label="Name">${escapeHtml(member.name || '-')}</td>
                        <td data-label="Status"><span class="status ${memberStatusClass}">${memberStatus}</span></td>
                        <td data-label="Calls Taken">${member.calls_taken || 0}</td>
                        <td data-label="Last Call">${lastCallText}</td>
                      </tr>
                    `;
                  }).join('')}
                </tbody>
              </table>
            </div>
          ` : '<p style="color:var(--muted);font-size:13px;">No members</p>'}
        </div>
      `;
    }).join('');
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
