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
  @media(min-width:1100px){.grid{grid-template-columns:repeat(5, minmax(0,1fr));}}

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

  @media (max-width: 900px){
    thead{display:none;}
    tbody tr{display:block;border-bottom:1px solid var(--line);padding:10px;margin-bottom:10px;background:rgba(15,26,48,.5);border-radius:8px;}
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
      <a class="btn" href="index2.php">📞 CDR Report</a>
      <?php if ($isAdmin): ?><a class="btn" href="?page=users">👤 User Management</a><?php endif; ?>
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
        </tr>
      </thead>
      <tbody>
        <?php if (empty($kpiData)): ?>
          <tr><td colspan="11" style="color:var(--muted);padding:16px;text-align:center;">No data for this period.</td></tr>
        <?php else: ?>
          <?php foreach ($kpiData as $ext):
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
          ?>
            <tr>
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
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
</body>
</html>
