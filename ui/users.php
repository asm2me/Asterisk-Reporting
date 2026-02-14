<?php
declare(strict_types=1);
/**
 * Admin User Management UI
 *
 * Expected variables from handleUsersPage():
 * @var array  $usersData
 * @var array  $me
 * @var string $csrf
 * @var string $msg
 * @var string $editUser
 * @var array|null $edit
 */
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Supervisor CDR - User Management</title>
  <style>
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#0b1220;color:#e8eefc}
    .wrap{max-width:1050px;margin:0 auto;padding:20px}
    .top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
    .card{background:rgba(15,26,48,.8);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px;margin-bottom:12px}
    h1{margin:0 0 8px 0;font-size:18px}
    .muted{color:#9fb0d0;font-size:12px}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{border-bottom:1px solid rgba(255,255,255,.08);padding:10px;font-size:13px;text-align:left;vertical-align:top}
    input,textarea,select{width:100%;box-sizing:border-box;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);color:#e8eefc;border-radius:10px;padding:10px;font-size:13px}
    textarea{min-height:48px}
    .btn{display:inline-block;background:rgba(122,162,255,.14);border:1px solid rgba(122,162,255,.25);color:#e8eefc;padding:10px 12px;border-radius:12px;text-decoration:none}
    .btn.danger{background:rgba(255,107,122,.14);border-color:rgba(255,107,122,.25)}
    button{background:rgba(68,209,157,.14);border:1px solid rgba(68,209,157,.25);color:#e8eefc;padding:10px 12px;border-radius:12px;cursor:pointer}
    button.danger{background:rgba(255,107,122,.14);border-color:rgba(255,107,122,.25)}
    .mini{padding:7px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.06);color:#e8eefc;text-decoration:none;font-size:12px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:900px){.grid2{grid-template-columns:1fr}}
    .msg{margin-top:10px;font-size:13px}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1>User Management</h1>
      <div class="muted">
        Only <b>admin</b> users can access this page. Logged in as <b><?= h((string)$me['username']) ?></b>.
      </div>
      <?php if (!empty($msg)): ?>
        <div class="msg"><b><?= h($msg) ?></b></div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn" href="<?= h(buildUrl(['page'=>null,'edit'=>null])) ?>">← Back to report</a>
      <a class="btn danger" href="<?= h(buildUrl(['action'=>'logout','page'=>null,'edit'=>null])) ?>">🚪 Logout</a>
    </div>
  </div>

  <div class="grid2">
    <div class="card">
      <b><?= $edit ? ('Edit User: ' . h($editUser)) : 'Add User' ?></b>
      <div class="muted" style="margin-top:6px;">Extensions: comma/space separated digits (e.g. 1000,1001).</div>

      <form method="post" style="margin-top:10px;">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="uaction" value="<?= $edit ? 'update' : 'add' ?>">

        <label class="muted">Username</label>
        <input name="username" <?= $edit ? 'readonly' : '' ?> value="<?= h($editUser) ?>" placeholder="agent1">

        <div style="height:10px"></div>

        <label class="muted">Allowed Extensions</label>
        <textarea name="extensions" placeholder="1000,1001"><?= h($edit ? implode(', ', (array)($edit['extensions'] ?? [])) : '') ?></textarea>

        <div style="height:10px"></div>

        <label class="muted">
          <input type="checkbox" name="is_admin" value="1" <?= ($edit && !empty($edit['is_admin'])) ? 'checked' : '' ?>>
          Admin
        </label>

        <?php if (!$edit): ?>
          <div style="height:10px"></div>
          <label class="muted">Password</label>
          <input type="password" name="newpass1" placeholder="new password">
          <div style="height:8px"></div>
          <label class="muted">Repeat Password</label>
          <input type="password" name="newpass2" placeholder="repeat password">
        <?php endif; ?>

        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
          <button type="submit"><?= $edit ? 'Save Changes' : 'Add User' ?></button>
          <?php if ($edit): ?>
            <a class="mini" href="<?= h(buildUrl(['page'=>'users','edit'=>null])) ?>">Cancel</a>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($edit): ?>
        <div style="height:14px"></div>
        <b>Change Password</b>
        <form method="post" style="margin-top:10px;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="uaction" value="changepass">
          <input type="hidden" name="username" value="<?= h($editUser) ?>">

          <label class="muted">New Password</label>
          <input type="password" name="newpass1" placeholder="new password">
          <div style="height:8px"></div>
          <label class="muted">Repeat New Password</label>
          <input type="password" name="newpass2" placeholder="repeat new password">

          <div style="margin-top:12px;">
            <button type="submit">Change Password</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <b>Existing Users</b>
      <table>
        <thead>
          <tr><th>User</th><th>Admin</th><th>Extensions</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach (($usersData['users'] ?? []) as $u => $info): ?>
          <tr>
            <td><?= h((string)$u) ?></td>
            <td><?= !empty($info['is_admin']) ? 'Yes' : 'No' ?></td>
            <td class="muted"><?= h(implode(', ', (array)($info['extensions'] ?? []))) ?></td>
            <td style="white-space:nowrap;">
              <a class="mini" href="<?= h(buildUrl(['page'=>'users','edit'=>(string)$u])) ?>">Edit</a>
              <?php if ((string)$u !== 'admin'): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="uaction" value="delete">
                  <input type="hidden" name="username" value="<?= h((string)$u) ?>">
                  <button class="danger" type="submit" onclick="return confirm(<?= json_encode("Delete user {$u}?") ?>);">Delete</button>
                </form>
              <?php else: ?>
                <span class="muted">protected</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="muted" style="margin-top:10px;">
        Tip: Edit a user to change their extensions or password.
      </div>
    </div>
  </div>
</div>
</body>
</html>

