<?php
use function h;
use function buildUrl;
use function fmtTime;

/** @var array $CONFIG */
/** @var array $me */
/** @var bool $isAdmin */
/** @var array $kpiData */
/** @var string $from */
/** @var string $to */
/** @var int $totalCalls */
/** @var int $totalAnswered */
/** @var int $totalMissed */
/** @var int $totalBusy */
/** @var int $totalBillsec */
/** @var array $agentEvents */
/** @var int $totalPauseCount */
/** @var int $totalPauseSec */
/** @var int $totalOnlineSec */
/** @var array $dailyData */
/** @var array $dailyAgentEvents */
/** @var array $selectedExts */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Extension KPIs - Supervisor CDR</title>

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
  .v{font-size:18px;margin-top:6px;font-weight:650;}

  .grid{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:12px;}
  @media(min-width:520px){.grid{grid-template-columns:repeat(2, minmax(0,1fr));}}
  @media(min-width:900px){.grid{grid-template-columns:repeat(3, minmax(0,1fr));}}
  @media(min-width:1100px){.grid{grid-template-columns:repeat(4, minmax(0,1fr));}}
  @media(min-width:1400px){.grid{grid-template-columns:repeat(5, minmax(0,1fr));}}

  .filters{display:grid;grid-template-columns:1fr;gap:10px;margin-bottom:12px;}
  @media(min-width:520px){.filters{grid-template-columns:repeat(2, minmax(0,1fr));}}
  @media(min-width:900px){.filters{grid-template-columns:repeat(3, minmax(0,1fr));}}

  label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
  input,select{width:100%;background:rgba(255,255,255,.06);border:1px solid var(--line);color:var(--text);border-radius:12px;padding:10px;font-size:13px;outline:none}

  .actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
  button{background:rgba(68,209,157,.12);border:1px solid rgba(68,209,157,.25);color:var(--text);padding:10px 12px;border-radius:12px;font-size:13px;cursor:pointer;white-space:nowrap}
  button:hover{background:rgba(68,209,157,.18)}

  table{width:100%;border-collapse:separate;border-spacing:0;}
  thead th{position:sticky;top:0;background:rgba(15,26,48,.92);border-bottom:1px solid var(--line);
       font-size:12px;color:var(--muted);text-align:left;padding:12px;white-space:nowrap}
  tbody td{border-bottom:1px solid var(--line);padding:12px;font-size:13px;}
  tbody tr:hover{background:rgba(255,255,255,.03)}

  .tableWrap{padding:0;overflow:auto;-webkit-overflow-scrolling:touch}

  .badge{display:inline-flex;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;}
  .badge.high{background:rgba(68,209,157,.15);color:var(--ok);border:1px solid rgba(68,209,157,.3);}
  .badge.medium{background:rgba(255,204,102,.15);color:var(--warn);border:1px solid rgba(255,204,102,.3);}
  .badge.low{background:rgba(255,107,122,.15);color:var(--bad);border:1px solid rgba(255,107,122,.3);}

  .num{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-weight:600;}

  /* Expandable details */
  .expand-toggle{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;cursor:pointer;
                 background:rgba(122,162,255,.12);border:1px solid rgba(122,162,255,.25);border-radius:6px;user-select:none;}
  .expand-toggle:hover{background:rgba(122,162,255,.20)}
  .expand-icon{font-weight:bold;font-size:16px;line-height:1;transition:transform 0.2s;}
  .expand-toggle.expanded .expand-icon{transform:rotate(45deg);}

  .daily-row td{background:rgba(122,162,255,.04);font-size:12px;padding:8px 12px !important;}
  .daily-row td:first-child{padding-left:40px !important;}
  .events-table{width:auto;min-width:320px;max-width:600px;border-collapse:collapse;margin:6px 0;border:1px solid var(--line);border-radius:8px;overflow:hidden;}
  .events-table thead th{background:rgba(15,26,48,.8);font-size:11px;color:var(--muted);text-align:left;padding:6px 10px;border-bottom:1px solid var(--line);white-space:nowrap;position:static;}
  .events-table tbody td{padding:5px 10px;font-size:12px;border-bottom:1px solid rgba(255,255,255,.04);white-space:nowrap;}
  .events-table tbody tr:last-child td{border-bottom:none;}
  .events-table tbody tr:hover{background:rgba(255,255,255,.03);}

  /* Multi-select extension dropdown */
  .multiselect-wrap{position:relative;}
  .multiselect-btn{width:100%;background:rgba(255,255,255,.06);border:1px solid var(--line);color:var(--text);border-radius:12px;padding:10px;font-size:13px;outline:none;cursor:pointer;text-align:left;display:flex;justify-content:space-between;align-items:center;}
  .multiselect-btn:hover{border-color:var(--accent);}
  .multiselect-dropdown{display:none;position:absolute;top:100%;left:0;right:0;z-index:50;background:var(--card);border:1px solid var(--line);border-radius:12px;max-height:240px;overflow:auto;margin-top:4px;padding:6px 0;}
  .multiselect-dropdown.open{display:block;}
  .multiselect-dropdown label{display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px;margin:0;}
  .multiselect-dropdown label:hover{background:rgba(255,255,255,.04);}
  .multiselect-dropdown input[type="checkbox"]{accent-color:var(--accent);width:16px;height:16px;}
  .ext-tag{display:inline-flex;padding:2px 6px;border-radius:4px;background:rgba(122,162,255,.15);font-size:11px;color:var(--accent);margin:1px 2px;}

  @media (max-width: 900px){
    thead{display:none;}
    tbody tr.kpi-main{display:block;border-bottom:1px solid var(--line);padding:10px;margin-bottom:10px;background:rgba(15,26,48,.5);border-radius:8px;}
    tbody tr.daily-row{display:block;border-bottom:1px solid var(--line);padding:8px 10px;margin-bottom:4px;background:rgba(122,162,255,.04);border-radius:6px;margin-left:20px;}
    tbody td{display:flex;gap:10px;justify-content:space-between;border-bottom:none;padding:8px 0;}
    tbody td::before{content: attr(data-label);color: var(--muted);font-size:12px;font-weight:600;}
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <h1>📊 Extension KPIs</h1>
      <div class="sub">
        <span class="pill">User: <b><?= h($me['username']) ?></b><?= $isAdmin ? ' (admin)' : '' ?></span>
        <span class="pill">From <?= h($from) ?> to <?= h($to) ?></span>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn" href="index.php">📞 CDR Report</a>
      <?php if ($isAdmin): ?><a class="btn" href="?page=users">👤 User Management</a><?php endif; ?>
      <a class="btn" href="<?= h(buildUrl(['format'=>'excel'])) ?>">📊 Export Excel</a>
      <a class="btn danger" href="<?= h(buildUrl(['action'=>'logout'])) ?>">🚪 Logout</a>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="grid">
    <div class="card">
      <div class="k">Total Calls</div>
      <div class="v"><?= (int)$totalCalls ?></div>
    </div>
    <div class="card">
      <div class="k">Answered</div>
      <div class="v" style="color:var(--ok)"><?= (int)$totalAnswered ?></div>
    </div>
    <div class="card">
      <div class="k">Missed (Not Bridged)</div>
      <div class="v" style="color:var(--bad)"><?= (int)$totalMissed ?></div>
    </div>
    <div class="card">
      <div class="k">Abandoned (Queue)</div>
      <div class="v" style="color:var(--warn)"><?= (int)$totalAbandoned ?></div>
    </div>
    <div class="card">
      <div class="k">Busy</div>
      <div class="v" style="color:var(--warn)"><?= (int)$totalBusy ?></div>
    </div>
    <div class="card">
      <div class="k">Total Talk Time</div>
      <div class="v"><?= h(fmtTime($totalBillsec)) ?></div>
    </div>
    <div class="card">
      <div class="k">Total Online Time</div>
      <div class="v" style="color:var(--accent)"><?= h(fmtTime($totalOnlineSec)) ?></div>
    </div>
    <div class="card">
      <div class="k">Total Pauses</div>
      <div class="v" style="color:var(--warn)"><?= (int)$totalPauseCount ?></div>
    </div>
    <div class="card">
      <div class="k">Total Pause Time</div>
      <div class="v" style="color:var(--warn)"><?= h(fmtTime($totalPauseSec)) ?></div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card">
    <form method="get" action="">
      <div class="filters">
        <div>
          <label>From</label>
          <input type="date" name="from" value="<?= h($from) ?>" required>
        </div>

        <div>
          <label>To</label>
          <input type="date" name="to" value="<?= h($to) ?>" required>
        </div>

        <div><label>Search</label><input name="q" value="<?= h($q ?? '') ?>" placeholder="src, dst, clid, uniqueid, channel"></div>

        <div><label>Src (number OR channel)</label><input name="src" value="<?= h($src ?? '') ?>" placeholder="1001"></div>
        <div><label>Dst (number OR dstchannel)</label><input name="dst" value="<?= h($dst ?? '') ?>" placeholder="2000"></div>

        <div class="multiselect-wrap" id="extMultiWrap">
          <label>Extension</label>
          <div class="multiselect-btn" id="extMultiBtn" onclick="toggleExtDropdown()">
            <span id="extMultiLabel"><?= empty($selectedExts) ? 'All Extensions' : implode(', ', array_map(fn($e) => h($e), $selectedExts)) ?></span>
            <span style="font-size:10px;color:var(--muted);">▼</span>
          </div>
          <div class="multiselect-dropdown" id="extMultiDrop">
            <?php foreach ($availableExtensions as $e): ?>
              <label>
                <input type="checkbox" name="ext[]" value="<?= h($e) ?>" <?= in_array($e, $selectedExts) ? 'checked' : '' ?> onchange="updateExtLabel()">
                <?= h($e) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <label>Disposition</label>
          <select name="disposition">
            <option value="">Any</option>
            <?php foreach (['ANSWERED','NO ANSWER','BUSY','FAILED','CONGESTION'] as $opt): ?>
              <option value="<?= h($opt) ?>" <?= (($disp ?? '')===$opt)?'selected':''; ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div><label>Min billsec</label><input name="mindur" value="<?= h($minDur ?? '') ?>" placeholder="0"></div>

        <div>
          <label>Preset</label>
          <select name="preset">
            <option value="">All Calls</option>
            <option value="inbound" <?= (($preset ?? '')==='inbound')?'selected':''; ?>>Inbound</option>
            <option value="outbound" <?= (($preset ?? '')==='outbound')?'selected':''; ?>>Outbound</option>
            <option value="missed" <?= (($preset ?? '')==='missed')?'selected':''; ?>>Missed</option>
            <option value="missed_in" <?= (($preset ?? '')==='missed_in')?'selected':''; ?>>Missed (Inbound)</option>
            <option value="missed_out" <?= (($preset ?? '')==='missed_out')?'selected':''; ?>>Missed (Outbound)</option>
            <option value="internal" <?= (($preset ?? '')==='internal')?'selected':''; ?>>Internal</option>
            <option value="abandoned" <?= (($preset ?? '')==='abandoned')?'selected':''; ?>>Abandoned (Queue)</option>
          </select>
        </div>

        <div>
          <label>Gateway</label>
          <select name="gateway">
            <option value="">All Gateways</option>
            <?php foreach ($availableGateways as $gw): ?>
              <option value="<?= h($gw) ?>" <?= (($gateway ?? '')===$gw)?'selected':''; ?>><?= h($gw) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="actions" style="align-items:flex-end;">
          <button type="submit">Apply Filters</button>
        </div>
      </div>
    </form>
  </div>

  <!-- KPI Table -->
  <div class="card tableWrap">
    <table>
      <thead>
        <tr>
          <th style="width:30px;"></th>
          <th>Extension</th>
          <th>Total Calls</th>
          <th>Answered</th>
          <th>Missed</th>
          <th>Abandoned</th>
          <th>Busy</th>
          <th>Failed</th>
          <th>Answer Rate</th>
          <th>Avg Wait Time</th>
          <th>Avg Talk Time</th>
          <th>Total Talk Time</th>
          <th>First Login</th>
          <th>Last Logout</th>
          <th>Online Time</th>
          <th>Pauses</th>
          <th>Pause Time</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($kpiData)): ?>
          <tr><td colspan="17" style="color:var(--muted);padding:16px;text-align:center;">No data for this period.</td></tr>
        <?php else: ?>
          <?php $extIdx = 0; foreach ($kpiData as $ext):
            $extIdx++;
            $totalCalls = (int)($ext['total_calls'] ?? 0);
            $answered = (int)($ext['answered'] ?? 0);
            $missed = (int)($ext['missed'] ?? 0);
            $abandoned = (int)($ext['abandoned'] ?? 0);
            $busy = (int)($ext['busy'] ?? 0);
            $failed = (int)($ext['failed'] ?? 0);
            $answerRate = $totalCalls > 0 ? round(($answered / $totalCalls) * 100, 1) : 0;
            $avgWait = (int)($ext['avg_wait_time'] ?? 0);
            $avgTalk = (int)($ext['avg_talk_time'] ?? 0);
            $totalBillsec = (int)($ext['total_billsec'] ?? 0);

            $rateBadge = 'low';
            if ($answerRate >= 80) $rateBadge = 'high';
            elseif ($answerRate >= 60) $rateBadge = 'medium';

            $ae = $agentEvents[$ext['extension']] ?? [];
            $firstLogin  = $ae['first_login'] ?? '';
            $lastLogout  = $ae['last_logout'] ?? '';
            $onlineSec   = (int)($ae['online_sec'] ?? 0);
            $pauseCount  = (int)($ae['pause_count'] ?? 0);
            $pauseSec    = (int)($ae['total_pause_sec'] ?? 0);

            $extDays = $dailyData[$ext['extension']] ?? [];
            $extEvents = $dailyAgentEvents[$ext['extension']] ?? [];
            // Merge all dates from both call data and agent events
            $allDates = array_unique(array_merge(array_keys($extDays), array_keys($extEvents)));
            sort($allDates);
            $hasDays = !empty($allDates);
            $dayRowId = 'days-' . $extIdx;
          ?>
            <tr class="kpi-main">
              <td style="text-align:center;">
                <?php if ($hasDays): ?>
                  <span class="expand-toggle" onclick="toggleKpiDetails('<?= h($dayRowId) ?>')" title="Show daily details">
                    <span class="expand-icon">+</span>
                  </span>
                <?php endif; ?>
              </td>
              <td data-label="Extension"><strong><?= h($ext['extension']) ?></strong></td>
              <td data-label="Total Calls"><span class="num"><?= (int)$totalCalls ?></span></td>
              <td data-label="Answered"><span class="num" style="color:var(--ok)"><?= (int)$answered ?></span></td>
              <td data-label="Missed"><span class="num" style="color:var(--bad)"><?= (int)$missed ?></span></td>
              <td data-label="Abandoned"><span class="num" style="color:var(--warn)"><?= (int)$abandoned ?></span></td>
              <td data-label="Busy"><span class="num" style="color:var(--warn)"><?= (int)$busy ?></span></td>
              <td data-label="Failed"><span class="num" style="color:var(--bad)"><?= (int)$failed ?></span></td>
              <td data-label="Answer Rate">
                <span class="badge <?= h($rateBadge) ?>"><?= number_format($answerRate, 1) ?>%</span>
              </td>
              <td data-label="Avg Wait Time"><span class="num"><?= (int)$avgWait ?></span> sec</td>
              <td data-label="Avg Talk Time"><span class="num"><?= (int)$avgTalk ?></span> sec</td>
              <td data-label="Total Talk Time"><?= h(fmtTime($totalBillsec)) ?></td>
              <td data-label="First Login"><?= $firstLogin ? h(substr($firstLogin, 11, 8)) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td data-label="Last Logout"><?= $lastLogout ? h(substr($lastLogout, 11, 8)) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td data-label="Online Time" style="color:var(--accent)"><?= $onlineSec > 0 ? h(fmtTime($onlineSec)) : '<span style="color:var(--muted)">—</span>' ?></td>
              <td data-label="Pauses"><span class="num" style="color:var(--warn)"><?= $pauseCount ?></span></td>
              <td data-label="Pause Time" style="color:var(--warn)"><?= $pauseSec > 0 ? h(fmtTime($pauseSec)) : '<span style="color:var(--muted)">—</span>' ?></td>
            </tr>
            <?php if ($hasDays): foreach ($allDates as $date):
              $day = $extDays[$date] ?? null;
              $dayEvents = $extEvents[$date] ?? [];
              $dTotal = $day ? (int)($day['total_calls'] ?? 0) : 0;
              $dAns   = $day ? (int)($day['answered'] ?? 0) : 0;
              $dMiss  = $day ? (int)($day['missed'] ?? 0) : 0;
              $dAband = $day ? (int)($day['abandoned'] ?? 0) : 0;
              $dBusy  = $day ? (int)($day['busy'] ?? 0) : 0;
              $dFail  = $day ? (int)($day['failed'] ?? 0) : 0;
              $dRate  = $dTotal > 0 ? round(($dAns / $dTotal) * 100, 1) : 0;
              $dRBadge = $dRate >= 80 ? 'high' : ($dRate >= 60 ? 'medium' : 'low');
              $dAvgW  = $day ? (int)($day['avg_wait_time'] ?? 0) : 0;
              $dAvgT  = $day ? (int)($day['avg_talk_time'] ?? 0) : 0;
              $dBill  = $day ? (int)($day['total_billsec'] ?? 0) : 0;
            ?>
              <tr class="daily-row <?= h($dayRowId) ?>" style="display:none;">
                <td></td>
                <td data-label="Date" style="color:var(--muted);padding-left:24px;">📅 <?= h($date) ?></td>
                <td data-label="Total Calls"><span class="num"><?= $dTotal ?></span></td>
                <td data-label="Answered"><span class="num" style="color:var(--ok)"><?= $dAns ?></span></td>
                <td data-label="Missed"><span class="num" style="color:var(--bad)"><?= $dMiss ?></span></td>
                <td data-label="Abandoned"><span class="num" style="color:var(--warn)"><?= $dAband ?></span></td>
                <td data-label="Busy"><span class="num" style="color:var(--warn)"><?= $dBusy ?></span></td>
                <td data-label="Failed"><span class="num" style="color:var(--bad)"><?= $dFail ?></span></td>
                <td data-label="Answer Rate"><span class="badge <?= h($dRBadge) ?>"><?= number_format($dRate, 1) ?>%</span></td>
                <td data-label="Avg Wait Time"><span class="num"><?= $dAvgW ?></span> sec</td>
                <td data-label="Avg Talk Time"><span class="num"><?= $dAvgT ?></span> sec</td>
                <td data-label="Total Talk Time"><?= h(fmtTime($dBill)) ?></td>
                <td colspan="5"></td>
              </tr>
              <?php if (!empty($dayEvents)): ?>
              <tr class="daily-row <?= h($dayRowId) ?>" style="display:none;">
                <td></td>
                <td colspan="16" style="padding:0 8px 8px 24px !important;">
                  <table class="events-table">
                    <thead>
                      <tr>
                        <th>Time</th>
                        <th>Event</th>
                        <th>Reason</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($dayEvents as $ev):
                        $evType = $ev['type'];
                        $evTime = $ev['time'];
                        $evReason = $ev['reason'];
                        if ($evType === 'LOGIN')       { $evIcon = '🟢'; $evColor = 'var(--ok)'; $evLabel = 'Login'; }
                        elseif ($evType === 'LOGOUT')  { $evIcon = '🔴'; $evColor = 'var(--bad)'; $evLabel = 'Logout'; }
                        elseif ($evType === 'PAUSE')   { $evIcon = '⏸️'; $evColor = 'var(--warn)'; $evLabel = 'Break'; }
                        elseif ($evType === 'UNPAUSE') { $evIcon = '▶️'; $evColor = 'var(--accent)'; $evLabel = 'Resume'; }
                        else { $evIcon = '⚪'; $evColor = 'var(--muted)'; $evLabel = $evType; }
                      ?>
                        <tr>
                          <td style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;"><?= h($evTime) ?></td>
                          <td><span style="color:<?= $evColor ?>;"><?= $evIcon ?> <?= h($evLabel) ?></span></td>
                          <td style="color:var(--muted);"><?= $evReason ? h($evReason) : '—' ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </td>
              </tr>
              <?php endif; ?>
            <?php endforeach; endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<script>
function toggleKpiDetails(className) {
  const rows = document.querySelectorAll('.' + className);
  const toggle = event.currentTarget;
  const isExpanded = toggle.classList.contains('expanded');

  rows.forEach(r => { r.style.display = isExpanded ? 'none' : 'table-row'; });
  toggle.classList.toggle('expanded');
}

function toggleExtDropdown() {
  document.getElementById('extMultiDrop').classList.toggle('open');
}

function updateExtLabel() {
  const checks = document.querySelectorAll('#extMultiDrop input[type="checkbox"]:checked');
  const label = document.getElementById('extMultiLabel');
  if (checks.length === 0) {
    label.textContent = 'All Extensions';
  } else {
    const vals = Array.from(checks).map(c => c.value);
    label.innerHTML = vals.map(v => '<span class="ext-tag">' + v + '</span>').join(' ');
  }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('extMultiWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('extMultiDrop').classList.remove('open');
  }
});
</script>
</body>
</html>
