<?php
use function h;
use function buildUrl;
use function fmtFromTableStyle;
use function fmtToTableStyle;
use function fmtTime;
use function sortLink;

/** @var array $CONFIG */
/** @var array $me */
/** @var bool $isAdmin */
/** @var array $filters */
/** @var array $summary */
/** @var int $total */
/** @var int $pages */
/** @var array $rows */
/** @var array $availableGateways */

$answered     = (int)($summary['answered'] ?? 0);
$busy         = (int)($summary['busy'] ?? 0);
$noanswer     = (int)($summary['noanswer'] ?? 0);
$failed       = (int)($summary['failed'] ?? 0);
$congested    = (int)($summary['congested'] ?? 0);
$totalBillsec = (int)($summary['total_billsec'] ?? 0);

$pageNo = (int)$filters['page'];
$per    = (int)$filters['per'];
$sort   = (string)$filters['sort'];
$dir    = (string)$filters['dir'];

$from = (string)$filters['from'];
$to   = (string)$filters['to'];
$q    = (string)$filters['q'];
$src  = (string)$filters['src'];
$dst  = (string)$filters['dst'];
$disp = (string)$filters['disposition'];
$minDur = (string)$filters['mindur'];
$preset = (string)($filters['preset'] ?? '');
$gateway = (string)($filters['gateway'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Supervisor CDR</title>

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

  .card{background:rgba(15,26,48,.75);border:1px solid var(--line);border-radius:16px;padding:12px;}
  .k{font-size:12px;color:var(--muted);}
  .v{font-size:18px;margin-top:6px;font-weight:650;}

  .grid{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:12px;}
  @media(min-width:520px){.grid{grid-template-columns:repeat(2, minmax(0,1fr));}}
  @media(min-width:900px){.grid{grid-template-columns:repeat(3, minmax(0,1fr));}}
  @media(min-width:1100px){.grid{grid-template-columns:repeat(6, minmax(0,1fr));}}

  .filters{display:grid;grid-template-columns:1fr;gap:10px}
  @media(min-width:520px){.filters{grid-template-columns:repeat(2, minmax(0,1fr));}}
  @media(min-width:900px){.filters{grid-template-columns:repeat(3, minmax(0,1fr));}}
  @media(min-width:1100px){.filters{grid-template-columns:repeat(6, minmax(0,1fr));}}

  label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
  input,select{width:100%;background:rgba(255,255,255,.06);border:1px solid var(--line);color:var(--text);border-radius:12px;padding:10px;font-size:13px;outline:none}

  .muted{color:var(--muted);font-size:12px}
  .dtHint{margin-top:6px;color:var(--muted);font-size:12px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}

  .actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
  button{background:rgba(68,209,157,.12);border:1px solid rgba(68,209,157,.25);color:var(--text);padding:10px 12px;border-radius:12px;font-size:13px;cursor:pointer;white-space:nowrap}
  button:hover{background:rgba(68,209,157,.18)}
  .actionsWide{grid-column:auto;}
  @media(min-width:900px){.actionsWide{grid-column:span 3;}}
  @media(min-width:1100px){.actionsWide{grid-column:span 2;}}

  table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px;}
  thead th{position:sticky;top:0;background:rgba(15,26,48,.92);border-bottom:1px solid var(--line);
       font-size:12px;color:var(--muted);text-align:left;padding:10px;white-space:nowrap}
  tbody td{border-bottom:1px solid var(--line);padding:10px;font-size:13px;vertical-align:top}
  tbody tr:hover{background:rgba(255,255,255,.03)}
  a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}

  .disp{font-size:12px;padding:3px 8px;border-radius:999px;display:inline-flex;border:1px solid var(--line);background:rgba(255,255,255,.04);white-space:nowrap}
  .disp.ok{border-color:rgba(68,209,157,.25);color:var(--ok);background:rgba(68,209,157,.08)}
  .disp.warn{border-color:rgba(255,204,102,.25);color:var(--warn);background:rgba(255,204,102,.08)}
  .disp.bad{border-color:rgba(255,107,122,.25);color:var(--bad);background:rgba(255,107,122,.08)}

  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:rgba(232,238,252,.85);word-break:break-all}

  .tableWrap{padding:0;overflow:auto;-webkit-overflow-scrolling:touch}
  audio{width:220px;max-width:100%}

  .pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px;flex-wrap:wrap}
  .pager .left{color:var(--muted);font-size:12px}
  .pager .right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .mini{padding:8px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.06);color:var(--text);font-size:12px;white-space:nowrap}

  @media (max-width: 900px){
    table{min-width:0 !important;}
    thead{display:none;}
    tbody tr{display:block;border-bottom:1px solid var(--line);padding:10px 10px 2px 10px;}
    tbody td{display:flex;gap:10px;justify-content:space-between;border-bottom:none;padding:8px 0;}
    tbody td::before{content: attr(data-label);color: var(--muted);font-size:12px;font-weight:600;padding-right:10px;white-space:nowrap;}
    tbody td[data-label="Recording"]{flex-direction:column;align-items:flex-start;}
    audio{width:100%;}
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <h1>Supervisor CDR</h1>
      <div class="sub">
        <span class="pill">User: <b><?= h($me['username']) ?></b><?= $isAdmin ? ' (admin)' : '' ?></span>
        <span class="pill">From <?= h($from) ?> to <?= h($to) ?></span>
        <?php if (!$isAdmin): ?><span class="pill">Allowed: <?= h(implode(', ', (array)$me['extensions'])) ?></span><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <?php if ($isAdmin): ?><a class="btn" href="<?= h(buildUrl(['page'=>'users'])) ?>">👤 User Management</a><?php endif; ?>
      <a class="btn" href="<?= h(buildUrl(['format'=>'csv','page'=>1])) ?>">⬇ Export CSV</a>
      <a class="btn danger" href="<?= h(buildUrl(['action'=>'logout'])) ?>">🚪 Logout</a>
    </div>
  </div>

  <div class="grid">
    <div class="card"><div class="k">Total Calls</div><div class="v"><?= (int)$total ?></div></div>
    <div class="card"><div class="k">Answered</div><div class="v" style="color:var(--ok)"><?= (int)$answered ?></div></div>
    <div class="card"><div class="k">No Answer</div><div class="v" style="color:var(--warn)"><?= (int)$noanswer ?></div></div>
    <div class="card"><div class="k">Busy</div><div class="v" style="color:var(--bad)"><?= (int)$busy ?></div></div>
    <div class="card"><div class="k">Failed</div><div class="v" style="color:var(--bad)"><?= (int)$failed ?></div></div>
    <div class="card"><div class="k">Congested</div><div class="v" style="color:var(--bad)"><?= (int)$congested ?></div></div>
  </div>

  <div class="card" style="margin-bottom:12px;">
    <div class="k" style="margin-bottom:10px;">Totals Bar Chart</div>
    <div style="height:260px;"><canvas id="chartTotals"></canvas></div>
    <div id="chartColorMap" class="muted" style="margin-top:10px; display:flex; flex-wrap:wrap;"></div>
    <div class="muted" style="margin-top:10px;">
      Total=<?= (int)$total ?> |
      Answered=<?= (int)$answered ?> |
      NoAnswer=<?= (int)$noanswer ?> |
      Busy=<?= (int)$busy ?> |
      Failed=<?= (int)$failed ?> |
      Congested=<?= (int)$congested ?> |
      TalkTime=<?= h(fmtTime($totalBillsec)) ?>
    </div>
  </div>

  <div class="card" style="margin-bottom:12px;">
    <form method="get" action="">
      <div class="filters">
        <div>
          <label>From</label>
          <input type="date" id="fromDate" name="from" value="<?= h($from) ?>" required>
          <div class="dtHint" id="fromPretty"><?= h(fmtFromTableStyle($from)) ?></div>
        </div>

        <div>
          <label>To</label>
          <input type="date" id="toDate" name="to" value="<?= h($to) ?>" required>
          <div class="dtHint" id="toPretty"><?= h(fmtToTableStyle($to)) ?></div>
        </div>

        <div><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="src, dst, clid, uniqueid, channel"></div>

        <div><label>Src (number OR channel)</label><input name="src" value="<?= h($src) ?>" placeholder="1001"></div>
        <div><label>Dst (number OR dstchannel)</label><input name="dst" value="<?= h($dst) ?>" placeholder="2000"></div>

        <div>
          <label>Disposition</label>
          <select name="disposition">
            <option value="">Any</option>
            <?php foreach (['ANSWERED','NO ANSWER','BUSY','FAILED','CONGESTION'] as $opt): ?>
              <option value="<?= h($opt) ?>" <?= ($disp===$opt)?'selected':''; ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div><label>Min billsec</label><input name="mindur" value="<?= h($minDur) ?>" placeholder="0"></div>

        <div>
          <label>Preset</label>
          <select name="preset">
            <option value="">All Calls</option>
            <option value="inbound" <?= ($preset==='inbound')?'selected':''; ?>>Inbound</option>
            <option value="outbound" <?= ($preset==='outbound')?'selected':''; ?>>Outbound</option>
            <option value="missed" <?= ($preset==='missed')?'selected':''; ?>>Missed</option>
            <option value="missed_in" <?= ($preset==='missed_in')?'selected':''; ?>>Missed (Inbound)</option>
            <option value="missed_out" <?= ($preset==='missed_out')?'selected':''; ?>>Missed (Outbound)</option>
            <option value="internal" <?= ($preset==='internal')?'selected':''; ?>>Internal</option>
          </select>
        </div>

        <div>
          <label>Gateway</label>
          <select name="gateway">
            <option value="">All Gateways</option>
            <?php foreach ($availableGateways as $gw): ?>
              <option value="<?= h($gw) ?>" <?= ($gateway===$gw)?'selected':''; ?>><?= h($gw) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Per page</label>
          <select name="per">
            <?php foreach ([25,50,100,200] as $n): ?>
              <option value="<?= (int)$n ?>" <?= ($per===$n)?'selected':''; ?>><?= (int)$n ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Sort</label>
          <select name="sort">
            <?php foreach (['calldate'=>'Call Date','src'=>'Src','dst'=>'Dst','disposition'=>'Disposition','duration'=>'Duration','billsec'=>'Billsec'] as $k=>$lbl): ?>
              <option value="<?= h($k) ?>" <?= ($sort===$k)?'selected':''; ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Direction</label>
          <select name="dir">
            <option value="desc" <?= ($dir==='desc')?'selected':''; ?>>DESC</option>
            <option value="asc"  <?= ($dir==='asc')?'selected':''; ?>>ASC</option>
          </select>
        </div>

        <div class="actions actionsWide">
          <button type="submit">Apply</button>
          <a class="btn" href="<?= h(buildUrl(['q'=>null,'src'=>null,'dst'=>null,'disposition'=>null,'mindur'=>null,'preset'=>null,'gateway'=>null,'page'=>1,'format'=>'html'])) ?>">Reset</a>
        </div>
      </div>
      <input type="hidden" name="format" value="html">
    </form>
  </div>

  <div class="card tableWrap">
    <table>
      <thead>
        <tr>
          <th><?= sortLink('calldate','Call Date',$sort,$dir) ?></th>
          <th>CLID</th>
          <th><?= sortLink('src','SRC',$sort,$dir) ?></th>
          <th><?= sortLink('dst','DST',$sort,$dir) ?></th>
          <th>Channel</th>
          <th>DstChannel</th>
          <th>Context</th>
          <th><?= sortLink('disposition','Disposition',$sort,$dir) ?></th>
          <th><?= sortLink('billsec','Billsec',$sort,$dir) ?></th>
          <th class="mono">UniqueID</th>
          <th>Recording</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $shown = 0;
        foreach ($rows as $r):
          $shown++;
          $d = strtoupper((string)($r['disposition'] ?? ''));
          $cls = 'warn';
          if ($d === 'ANSWERED') $cls = 'ok';
          elseif ($d === 'BUSY' || $d === 'FAILED' || $d === 'CONGESTION' || $d === 'CONGESTED') $cls = 'bad';

          $uidVal = (string)($r['uniqueid'] ?? '');
          $recVal = trim((string)($r['recordingfile'] ?? ''));
          $hasRec = ($uidVal !== '' && $recVal !== '');
          $playUrl = buildUrl(['action'=>'play','uid'=>$uidVal]);
          $dlUrl   = buildUrl(['action'=>'download','uid'=>$uidVal]);
        ?>
          <tr>
            <td data-label="Call Date"><?= h((string)($r['calldate'] ?? '')) ?></td>
            <td data-label="CLID"><?= h((string)($r['clid'] ?? '')) ?></td>
            <td data-label="SRC"><?= h((string)($r['src'] ?? '')) ?></td>
            <td data-label="DST"><?= h((string)($r['dst'] ?? '')) ?></td>
            <td data-label="Channel" class="mono"><?= h((string)($r['channel'] ?? '')) ?></td>
            <td data-label="DstChannel" class="mono"><?= h((string)($r['dstchannel'] ?? '')) ?></td>
            <td data-label="Context"><?= h((string)($r['dcontext'] ?? '')) ?></td>
            <td data-label="Disposition"><span class="disp <?= h($cls) ?>"><?= h((string)($r['disposition'] ?? '')) ?></span></td>
            <td data-label="Billsec"><?= h((string)($r['billsec'] ?? '0')) ?></td>
            <td data-label="UniqueID" class="mono"><?= h($uidVal) ?></td>
            <td data-label="Recording">
              <?php if ($hasRec): ?>
                <div class="mono" style="opacity:.7; margin-bottom:6px;"><?= h($recVal) ?></div>
                <audio controls preload="none" src="<?= h($playUrl) ?>"></audio><br>
                <a href="<?= h($playUrl) ?>" target="_blank">Listen</a> ·
                <a href="<?= h($dlUrl) ?>">Download</a>
              <?php else: ?>
                <span class="pill">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($shown === 0): ?>
          <tr><td colspan="11" style="color:var(--muted);padding:16px;">No records for this filter.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="pager">
    <div class="left">Showing <?= (int)$shown ?> of <?= (int)$total ?> · Page <?= (int)$pageNo ?> / <?= (int)$pages ?></div>
    <div class="right">
      <?php $prev = max(1, $pageNo - 1); $next = min($pages, $pageNo + 1); ?>
      <a class="mini" href="<?= h(buildUrl(['page'=>1])) ?>">⟪ First</a>
      <a class="mini" href="<?= h(buildUrl(['page'=>$prev])) ?>">‹ Prev</a>
      <span class="mini">Page <?= (int)$pageNo ?></span>
      <a class="mini" href="<?= h(buildUrl(['page'=>$next])) ?>">Next ›</a>
      <a class="mini" href="<?= h(buildUrl(['page'=>$pages])) ?>">Last ⟫</a>
    </div>
  </div>

</div>

<script src="<?= h($CONFIG['assetsUrl']) ?>/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const from = document.getElementById('fromDate');
  const to   = document.getElementById('toDate');
  const fromPretty = document.getElementById('fromPretty');
  const toPretty   = document.getElementById('toPretty');

  function refreshPretty(){
    if (fromPretty) fromPretty.textContent = (from && from.value ? (from.value + ' 00:00:00') : '');
    if (toPretty)   toPretty.textContent   = (to && to.value ? (to.value + ' 23:59:59') : '');
  }

  if (from && to) {
    if (from.value) to.min = from.value;
    if (to.value) from.max = to.value;
    refreshPretty();

    from.addEventListener('change', () => {
      to.min = from.value || '';
      if (to.value && from.value && to.value < from.value) to.value = from.value;
      refreshPretty();
    });

    to.addEventListener('change', () => {
      from.max = to.value || '';
      if (from.value && to.value && from.value > to.value) from.value = to.value;
      refreshPretty();
    });
  }

  if (typeof Chart === 'undefined') return;

  const totals = <?= json_encode([
      'total'      => (int)$total,
      'answered'   => (int)$answered,
      'noanswer'   => (int)$noanswer,
      'busy'       => (int)$busy,
      'failed'     => (int)$failed,
      'congested'  => (int)$congested,
  ], JSON_NUMERIC_CHECK) ?>;

  const labels = ['Total','Answered','No Answer','Busy','Failed','Congested'];
  const values = [totals.total, totals.answered, totals.noanswer, totals.busy, totals.failed, totals.congested];

  const colorMap = {
    'Total':     { bg: 'rgba(122,162,255,0.35)', border: 'rgba(122,162,255,0.95)' },
    'Answered':  { bg: 'rgba(68,209,157,0.35)', border: 'rgba(68,209,157,0.95)' },
    'No Answer': { bg: 'rgba(255,204,102,0.35)', border: 'rgba(255,204,102,0.95)' },
    'Busy':      { bg: 'rgba(255,107,122,0.35)', border: 'rgba(255,107,122,0.95)' },
    'Failed':    { bg: 'rgba(255,107,122,0.25)', border: 'rgba(255,107,122,0.85)' },
    'Congested': { bg: 'rgba(255,94,0,0.30)',    border: 'rgba(255,94,0,0.95)' },
  };

  const backgroundColors = labels.map(l => (colorMap[l] ? colorMap[l].bg : 'rgba(255,255,255,0.2)'));
  const borderColors     = labels.map(l => (colorMap[l] ? colorMap[l].border : 'rgba(255,255,255,0.6)'));

  const mapEl = document.getElementById('chartColorMap');
  if (mapEl) {
    mapEl.innerHTML = labels.map(l => {
      const c = colorMap[l] || { border: 'rgba(255,255,255,0.6)' };
      return `
        <span style="display:inline-flex;align-items:center;gap:8px;margin:6px 14px 0 0;">
          <span style="width:12px;height:12px;border-radius:3px;background:${c.border};display:inline-block;"></span>
          <span>${l}</span>
        </span>
      `;
    }).join('');
  }

  const el = document.getElementById('chartTotals');
  if (!el) return;

  new Chart(el, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Calls',
        data: values,
        backgroundColor: backgroundColors,
        borderColor: borderColors,
        borderWidth: 2,
        borderRadius: 10
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.parsed.y}` } }
      },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } },
        x: { ticks: { maxRotation: 0 } }
      }
    }
  });
});
</script>

</body>
</html>

