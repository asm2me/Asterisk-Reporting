<?php
use function h;
use function buildUrl;
use function fmtTime;

/** @var array $me */
/** @var bool $isAdmin */
/** @var string $from */
/** @var string $to */
/** @var array $attendanceData */
/** @var array $availableExtensions */
/** @var array $selectedExts */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Extension Attendance - Supervisor CDR</title>

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
  .wrap{max-width:1200px;margin:0 auto;padding:18px;}
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

  .filters{display:grid;grid-template-columns:1fr;gap:10px;margin-bottom:12px;}
  @media(min-width:520px){.filters{grid-template-columns:repeat(2, minmax(0,1fr));}}
  @media(min-width:900px){.filters{grid-template-columns:repeat(4, minmax(0,1fr));}}

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
  .num{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-weight:600;}

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

  .ext-group td:first-child{font-weight:700;color:var(--accent);}
  .ext-group-alt{background:rgba(122,162,255,.03);}

  @media(max-width:900px){
    thead{display:none;}
    tbody tr{display:block;border-bottom:1px solid var(--line);padding:10px;margin-bottom:8px;background:rgba(15,26,48,.5);border-radius:8px;}
    tbody td{display:flex;gap:10px;justify-content:space-between;border-bottom:none;padding:6px 0;}
    tbody td::before{content:attr(data-label);color:var(--muted);font-size:12px;font-weight:600;}
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <h1>🕐 Extension Attendance</h1>
      <div class="sub">
        <span class="pill">User: <b><?= h($me['username']) ?></b><?= $isAdmin ? ' (admin)' : '' ?></span>
        <span class="pill">From <?= h($from) ?> to <?= h($to) ?></span>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn" href="index.php">📞 CDR Report</a>
      <a class="btn" href="kpi.php">📊 Extension KPIs</a>
      <a class="btn" href="breaks.php">⏸️ Breaks</a>
      <a class="btn" href="<?= h(buildUrl(['format'=>'excel'])) ?>">📊 Export Excel</a>
      <a class="btn danger" href="<?= h(buildUrl(['action'=>'logout'])) ?>">🚪 Logout</a>
    </div>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom:12px;">
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
        <div class="actions" style="align-items:flex-end;">
          <button type="submit">Apply Filters</button>
        </div>
      </div>
    </form>
  </div>

  <!-- Attendance Table -->
  <div class="card tableWrap">
    <table>
      <thead>
        <tr>
          <th>Extension</th>
          <th>Date</th>
          <th>First Login</th>
          <th>Last Logout</th>
          <th>Online Time</th>
          <th>Logins</th>
          <th>Logouts</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($attendanceData)): ?>
          <tr><td colspan="7" style="color:var(--muted);padding:16px;text-align:center;">No attendance data for this period.</td></tr>
        <?php else:
          $rowClass = 0;
          foreach ($attendanceData as $extNum => $dates):
            $sortedDates = array_keys($dates);
            sort($sortedDates);
            $rowClass++;
            foreach ($sortedDates as $date):
              $d = $dates[$date];
        ?>
          <tr class="<?= $rowClass % 2 === 0 ? 'ext-group-alt' : '' ?>">
            <td data-label="Extension"><strong style="color:var(--accent);"><?= h($extNum) ?></strong></td>
            <td data-label="Date" style="color:var(--muted);"><?= h($date) ?></td>
            <td data-label="First Login" style="color:var(--ok);">
              <?= $d['first_login'] ? '🟢 ' . h($d['first_login']) : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td data-label="Last Logout" style="color:var(--bad);">
              <?= $d['last_logout'] ? '🔴 ' . h($d['last_logout']) : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td data-label="Online Time" style="color:var(--accent);">
              <?= $d['online_sec'] > 0 ? h(fmtTime($d['online_sec'])) : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td data-label="Logins"><span class="num" style="color:var(--ok);"><?= (int)$d['login_count'] ?></span></td>
            <td data-label="Logouts"><span class="num" style="color:var(--bad);"><?= (int)$d['logout_count'] ?></span></td>
          </tr>
        <?php endforeach; endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</div>

<script>
function toggleExtDropdown() {
  document.getElementById('extMultiDrop').classList.toggle('open');
}
function updateExtLabel() {
  const checks = document.querySelectorAll('#extMultiDrop input[type="checkbox"]:checked');
  const label = document.getElementById('extMultiLabel');
  if (checks.length === 0) {
    label.textContent = 'All Extensions';
  } else {
    label.innerHTML = Array.from(checks).map(c => '<span class="ext-tag">' + c.value + '</span>').join(' ');
  }
}
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('extMultiWrap');
  if (wrap && !wrap.contains(e.target)) document.getElementById('extMultiDrop').classList.remove('open');
});
</script>
</body>
</html>
