<?php
declare(strict_types=1);

/**
 * ui/report.php
 * Inputs provided by index2.php:
 *  - $me_for_ui, $isAdmin_for_ui, $allowedExts_for_ui
 *  - $settings_for_ui
 *  - $filters
 *  - $summary
 *  - $rows (group=call)
 *  - $extRows (group=ext)
 *  - $pages, $pageNo, $per
 */

use function Supervisor\Lib\h;
use function Supervisor\Lib\buildUrl;
use function Supervisor\Lib\fmtTime;

if (!isset($me_for_ui) || !is_array($me_for_ui)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: Auth context not initialized (bootstrap did not provide \$me)\n";
    exit;
}

$me = $me_for_ui;
$isAdmin = (bool)($isAdmin_for_ui ?? false);
$allowedExts = (array)($allowedExts_for_ui ?? []);
$settings = (array)($settings_for_ui ?? []);

$filters = is_array($filters ?? null) ? $filters : [];
$summary = is_array($summary ?? null) ? $summary : ['total'=>0,'answered'=>0,'missed'=>0,'total_billsec'=>0];

$group   = (string)($filters['group'] ?? 'call');
$preset  = (string)($filters['preset'] ?? '');
$gateway = (string)($filters['gateway'] ?? '');
$from    = (string)($filters['from'] ?? date('Y-m-d'));
$to      = (string)($filters['to'] ?? date('Y-m-d'));

$q       = (string)($filters['q'] ?? '');
$src     = (string)($filters['src'] ?? '');
$dst     = (string)($filters['dst'] ?? '');
$disp    = (string)($filters['disposition'] ?? '');
$mindur  = (string)($filters['mindur'] ?? '');

$pageNo  = (int)($filters['page'] ?? 1);
$per     = (int)($filters['per'] ?? 50);
$sort    = (string)($filters['sort'] ?? 'start_calldate');
$dir     = (string)($filters['dir'] ?? 'desc');

$pages   = (int)($pages ?? 1);

$gateways = [];
if (isset($settings['gateways']) && is_array($settings['gateways'])) {
    foreach ($settings['gateways'] as $g) {
        $g = trim((string)$g);
        if ($g !== '') $gateways[] = $g;
    }
}

$presets = [
    ''         => 'All calls',
    'inbound'  => 'Inbound (from trunk)',
    'outbound' => 'Outbound (to trunk)',
    'missed'   => 'Missed (no answered legs)',
];

function dispClass(string $status): string {
    $s = strtoupper($status);
    if ($s === 'ANSWERED') return 'ok';
    if ($s === 'MISSED' || $s === 'FAILED' || $s === 'BUSY' || $s === 'CONGESTED' || $s === 'CONGESTION') return 'bad';
    return 'warn';
}

header('Content-Type: text/html; charset=utf-8');
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
  body{margin:0;background:linear-gradient(180deg,#070b14 0%, #0b1220 40%, #0b1220 100%);color:var(--text);
       font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;}
  .wrap{max-width:1250px;margin:0 auto;padding:18px;}
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
  .card{background:rgba(15,26,48,.75);border:1px solid var(--line);border-radius:16px;padding:12px;margin-bottom:12px;}
  .k{font-size:12px;color:var(--muted);}
  .v{font-size:18px;margin-top:6px;font-weight:650;}
  .grid{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:12px;}
  @media(min-width:520px){.grid{grid-template-columns:repeat(2, minmax(0,1fr));}}
  @media(min-width:900px){.grid{grid-template-columns:repeat(4, minmax(0,1fr));}}
  @media(min-width:1100px){.grid{grid-template-columns:repeat(4, minmax(0,1fr));}}

  .filters{display:grid;grid-template-columns:1fr;gap:10px}
  @media(min-width:520px){.filters{grid-template-columns:repeat(2, minmax(0,1fr));}}
  @media(min-width:900px){.filters{grid-template-columns:repeat(4, minmax(0,1fr));}}
  @media(min-width:1100px){.filters{grid-template-columns:repeat(6, minmax(0,1fr));}}
  label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
  input,select{width:100%;background:rgba(255,255,255,.06);border:1px solid var(--line);color:var(--text);border-radius:12px;padding:10px;font-size:13px;outline:none}
  .actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
  button{background:rgba(68,209,157,.12);border:1px solid rgba(68,209,157,.25);color:var(--text);padding:10px 12px;border-radius:12px;font-size:13px;cursor:pointer;white-space:nowrap}
  button:hover{background:rgba(68,209,157,.18)}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:rgba(232,238,252,.85);word-break:break-all}
  table{width:100%;border-collapse:separate;border-spacing:0;}
  thead th{position:sticky;top:0;background:rgba(15,26,48,.92);border-bottom:1px solid var(--line);
       font-size:12px;color:var(--muted);text-align:left;padding:10px;white-space:nowrap}
  tbody td{border-bottom:1px solid var(--line);padding:10px;font-size:13px;vertical-align:top}
  tbody tr:hover{background:rgba(255,255,255,.03)}
  a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}
  .disp{font-size:12px;padding:3px 8px;border-radius:999px;display:inline-flex;border:1px solid var(--line);background:rgba(255,255,255,.04);white-space:nowrap}
  .disp.ok{border-color:rgba(68,209,157,.25);color:var(--ok);background:rgba(68,209,157,.08)}
  .disp.warn{border-color:rgba(255,204,102,.25);color:var(--warn);background:rgba(255,204,102,.08)}
  .disp.bad{border-color:rgba(255,107,122,.25);color:var(--bad);background:rgba(255,107,122,.08)}
  .pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px;flex-wrap:wrap}
  .pager .left{color:var(--muted);font-size:12px}
  .pager .right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .mini{padding:8px 10px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.06);color:var(--text);font-size:12px;white-space:nowrap}
  .plus{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:8px;
        border:1px solid var(--line);background:rgba(255,255,255,.06);cursor:pointer}
  .legsRow{display:none;background:rgba(255,255,255,.02)}
  .legsBox{padding:10px 10px 14px 10px}
  .debugBox{display:none;margin-top:10px}
  pre{white-space:pre-wrap;word-break:break-word;margin:0;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:12px;padding:10px;color:#dbe7ff;font-size:12px}
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div>
      <h1>Supervisor CDR</h1>
      <div class="sub">
        <span class="pill">User: <b><?= h((string)$me['username']) ?></b><?= $isAdmin ? ' (admin)' : '' ?></span>
        <span class="pill">From <?= h($from) ?> to <?= h($to) ?></span>
        <span class="pill">Preset: <b><?= h($presets[$preset] ?? 'All calls') ?></b></span>
        <?php if ($gateway !== ''): ?><span class="pill">Gateway: <b><?= h($gateway) ?></b></span><?php endif; ?>
        <?php if (!$isAdmin): ?><span class="pill">Allowed: <?= h(implode(', ', $allowedExts)) ?></span><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn" href="<?= h(buildUrl(['format'=>'csv','page'=>1])) ?>">⬇ Export CSV</a>
      <a class="btn" href="#" id="btnDebug">🪵 Debug</a>
      <a class="btn danger" href="<?= h(buildUrl(['action'=>'logout'])) ?>">🚪 Logout</a>
    </div>
  </div>

  <div class="grid">
    <div class="card"><div class="k">Total (groups)</div><div class="v"><?= (int)($summary['total'] ?? 0) ?></div></div>
    <div class="card"><div class="k">Answered</div><div class="v" style="color:var(--ok)"><?= (int)($summary['answered'] ?? 0) ?></div></div>
    <div class="card"><div class="k">Missed</div><div class="v" style="color:var(--bad)"><?= (int)($summary['missed'] ?? 0) ?></div></div>
    <div class="card"><div class="k">Talk Time</div><div class="v"><?= h(fmtTime((int)($summary['total_billsec'] ?? 0))) ?></div></div>
  </div>

  <div class="card debugBox" id="debugBox">
    <div class="k" style="margin-bottom:8px;">Debug Console</div>
    <pre id="debugPre"><?= h(json_encode([
        'filters' => $filters,
        'group' => $group,
        'page' => $pageNo,
        'pages' => $pages,
        'per' => $per,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>

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
        <div>
          <label>Preset</label>
          <select name="preset">
            <?php foreach ($presets as $k => $lbl): ?>
              <option value="<?= h($k) ?>" <?= ($preset===$k)?'selected':''; ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Gateway</label>
          <select name="gateway">
            <option value="">(auto)</option>
            <?php foreach ($gateways as $g): ?>
              <option value="<?= h($g) ?>" <?= ($gateway===$g)?'selected':''; ?>><?= h($g) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Group</label>
          <select name="group">
            <option value="call" <?= ($group==='call')?'selected':''; ?>>Grouped calls</option>
            <option value="ext"  <?= ($group==='ext')?'selected':''; ?>>By extension</option>
          </select>
        </div>

        <div>
          <label>Search</label>
          <input name="q" value="<?= h($q) ?>" placeholder="src, dst, clid, channel">
        </div>

        <div><label>Src</label><input name="src" value="<?= h($src) ?>" placeholder="1001"></div>
        <div><label>Dst</label><input name="dst" value="<?= h($dst) ?>" placeholder="2000"></div>

        <div><label>Disposition</label><input name="disposition" value="<?= h($disp) ?>" placeholder="ANSWERED"></div>
        <div><label>Min billsec</label><input name="mindur" value="<?= h($mindur) ?>" placeholder="0"></div>

        <div>
          <label>Per page</label>
          <select name="per">
            <?php foreach ([25,50,100,200] as $n): ?>
              <option value="<?= (int)$n ?>" <?= ($per===$n)?'selected':''; ?>><?= (int)$n ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="actions">
          <button type="submit">Apply</button>
          <a class="btn" href="<?= h(buildUrl([
              'preset'=>'','gateway'=>'','group'=>'call','q'=>'','src'=>'','dst'=>'','disposition'=>'','mindur'=>'',
              'page'=>1,'format'=>'html'
          ])) ?>">Reset</a>
        </div>
      </div>

      <input type="hidden" name="sort" value="<?= h($sort) ?>">
      <input type="hidden" name="dir" value="<?= h($dir) ?>">
      <input type="hidden" name="format" value="html">
      <input type="hidden" name="page" value="1">
    </form>
  </div>

  <?php if ($group === 'ext'): ?>
    <div class="card">
      <table>
        <thead>
          <tr>
            <th>Extension</th>
            <th>Last Call</th>
            <th>Calls</th>
            <th>Answered</th>
            <th>Missed</th>
            <th>Billsec</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($extRows)): foreach ($extRows as $r): ?>
            <tr>
              <td class="mono"><?= h((string)($r['ext'] ?? '')) ?></td>
              <td><?= h((string)($r['last_calldate'] ?? '')) ?></td>
              <td><?= (int)($r['calls'] ?? 0) ?></td>
              <td style="color:var(--ok)"><?= (int)($r['answered_calls'] ?? 0) ?></td>
              <td style="color:var(--bad)"><?= (int)($r['missed_calls'] ?? 0) ?></td>
              <td><?= (int)($r['total_billsec'] ?? 0) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" style="color:var(--muted);padding:16px;">No records for this filter.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="card">
      <table>
        <thead>
          <tr>
            <th></th>
            <th>Start</th>
            <th>CLID</th>
            <th>SRC</th>
            <th>DST</th>
            <th>Channel</th>
            <th>DstChannel</th>
            <th>Status</th>
            <th>Billsec</th>
            <th class="mono">GroupKey</th>
            <th>Legs</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)): foreach ($rows as $r): ?>
            <?php
              $grpkey = (string)($r['grpkey'] ?? '');
              $legs = (int)($r['legs'] ?? 0);
              $status = (string)($r['status'] ?? '');
            ?>
            <tr>
              <td>
                <?php if ($legs > 1): ?>
                  <span class="plus" data-grpkey="<?= h($grpkey) ?>" title="Show transitions">+</span>
                <?php else: ?>
                  <span class="pill">—</span>
                <?php endif; ?>
              </td>
              <td><?= h((string)($r['start_calldate'] ?? '')) ?></td>
              <td><?= h((string)($r['clid'] ?? '')) ?></td>
              <td><?= h((string)($r['src'] ?? '')) ?></td>
              <td><?= h((string)($r['dst'] ?? '')) ?></td>
              <td class="mono"><?= h((string)($r['channel'] ?? '')) ?></td>
              <td class="mono"><?= h((string)($r['dstchannel'] ?? '')) ?></td>
              <td><span class="disp <?= h(dispClass($status)) ?>"><?= h($status) ?></span></td>
              <td><?= (int)($r['total_billsec'] ?? 0) ?></td>
              <td class="mono"><?= h($grpkey) ?></td>
              <td><?= $legs ?></td>
            </tr>
            <tr class="legsRow" id="legs-<?= h($grpkey) ?>">
              <td colspan="11">
                <div class="legsBox">
                  <div class="k" style="margin-bottom:8px;">Call transitions (legs) for <?= h($grpkey) ?></div>
                  <div class="muted" id="legsStatus-<?= h($grpkey) ?>">Loading…</div>
                  <div id="legsBody-<?= h($grpkey) ?>" style="margin-top:10px;"></div>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="11" style="color:var(--muted);padding:16px;">No records for this filter.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="pager">
      <div class="left">Page <?= (int)$pageNo ?> / <?= (int)$pages ?></div>
      <div class="right">
        <?php $prev = max(1, $pageNo - 1); $next = min($pages, $pageNo + 1); ?>
        <a class="mini" href="<?= h(buildUrl(['page'=>1])) ?>">⟪ First</a>
        <a class="mini" href="<?= h(buildUrl(['page'=>$prev])) ?>">‹ Prev</a>
        <span class="mini">Page <?= (int)$pageNo ?></span>
        <a class="mini" href="<?= h(buildUrl(['page'=>$next])) ?>">Next ›</a>
        <a class="mini" href="<?= h(buildUrl(['page'=>$pages])) ?>">Last ⟫</a>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
(function(){
  const btn = document.getElementById('btnDebug');
  const box = document.getElementById('debugBox');
  if (btn && box) {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      box.style.display = (box.style.display === 'block') ? 'none' : 'block';
    });
  }

  function qs(params){
    const u = new URL(window.location.href);
    Object.keys(params).forEach(k => u.searchParams.set(k, params[k]));
    return u.toString();
  }

  async function loadLegs(grpkey){
    const row = document.getElementById('legs-' + grpkey);
    const statusEl = document.getElementById('legsStatus-' + grpkey);
    const bodyEl = document.getElementById('legsBody-' + grpkey);
    if (!row || !statusEl || !bodyEl) return;

    row.style.display = 'table-row';
    statusEl.textContent = 'Loading…';
    bodyEl.innerHTML = '';

    try {
      const u = new URL(window.location.href);
      u.searchParams.set('action', 'transitions');
      u.searchParams.set('grpkey', grpkey);

      const resp = await fetch(u.toString(), {headers: {'Accept':'application/json'}});
      const data = await resp.json();

      if (!data || !data.ok) {
        statusEl.textContent = 'Failed to load transitions.';
        return;
      }

      const legs = Array.isArray(data.legs) ? data.legs : [];
      if (!legs.length) {
        statusEl.textContent = 'No legs found.';
        return;
      }

      statusEl.textContent = '';

      const tbl = document.createElement('table');
      tbl.innerHTML = `
        <thead>
          <tr>
            <th>calldate</th>
            <th>src</th>
            <th>dst</th>
            <th>channel</th>
            <th>dstchannel</th>
            <th>disposition</th>
            <th>billsec</th>
            <th>uniqueid</th>
          </tr>
        </thead>
        <tbody></tbody>
      `;
      const tb = tbl.querySelector('tbody');

      legs.forEach(l => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${escapeHtml(l.calldate || '')}</td>
          <td>${escapeHtml(l.src || '')}</td>
          <td>${escapeHtml(l.dst || '')}</td>
          <td><span class="mono">${escapeHtml(l.channel || '')}</span></td>
          <td><span class="mono">${escapeHtml(l.dstchannel || '')}</span></td>
          <td>${escapeHtml(l.disposition || '')}</td>
          <td>${escapeHtml(String(l.billsec ?? ''))}</td>
          <td><span class="mono">${escapeHtml(l.uniqueid || '')}</span></td>
        `;
        tb.appendChild(tr);
      });

      bodyEl.appendChild(tbl);

    } catch (e) {
      statusEl.textContent = 'Failed to load transitions.';
    }
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  document.querySelectorAll('.plus').forEach(el => {
    el.addEventListener('click', () => {
      const grpkey = el.getAttribute('data-grpkey');
      if (!grpkey) return;

      const row = document.getElementById('legs-' + grpkey);
      if (row && row.style.display === 'table-row') {
        row.style.display = 'none';
        return;
      }
      loadLegs(grpkey);
    });
  });
})();
</script>

</body>
</html>

