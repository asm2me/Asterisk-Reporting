<?php
use function h;
/** @var string $LOGIN_ERROR */
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Supervisor CDR - Login</title>
  <style>
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#0b1220;color:#e8eefc}
    .wrap{max-width:420px;margin:0 auto;padding:28px}
    .card{background:rgba(15,26,48,.8);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px}
    h1{margin:0 0 10px 0;font-size:18px}
    .muted{color:#9fb0d0;font-size:12px}
    input{width:100%;box-sizing:border-box;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);
          color:#e8eefc;border-radius:10px;padding:10px;font-size:13px;margin-top:8px;outline:none}
    button{margin-top:12px;background:rgba(68,209,157,.14);border:1px solid rgba(68,209,157,.25);
           color:#e8eefc;padding:10px 12px;border-radius:12px;cursor:pointer;width:100%;font-size:13px}
    .err{margin-top:10px;color:#ff6b7a;font-size:13px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Supervisor CDR</h1>
      <div class="muted">Please sign in.</div>
      <?php if (!empty($LOGIN_ERROR)): ?><div class="err"><?= h($LOGIN_ERROR) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="login" value="1">
        <input name="username" placeholder="Username" autocomplete="username" required>
        <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
        <button type="submit">Login</button>
      </form>
    </div>
  </div>
</body>
</html>

