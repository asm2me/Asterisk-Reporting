<?php
use function h;
/** @var array $USERS_DATA */
/** @var array $ME */
/** @var string $CSRF */
/** @var string $MSG */
/** @var string $EDIT_USER */
/** @var array|null $EDIT_REC */
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User Management - Supervisor CDR</title>
  <style>
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#0b1220;color:#e8eefc;padding:20px}
    h1{font-size:24px;margin-bottom:20px}
    .msg{padding:12px;background:rgba(68,209,157,.14);border:1px solid rgba(68,209,157,.25);border-radius:8px;margin-bottom:20px}
    .err{padding:12px;background:rgba(255,107,122,.14);border:1px solid rgba(255,107,122,.25);border-radius:8px;margin-bottom:20px}
    table{width:100%;border-collapse:collapse;background:rgba(15,26,48,.8);border:1px solid rgba(255,255,255,.08);border-radius:8px;overflow:hidden}
    th,td{padding:12px;text-align:left;border-bottom:1px solid rgba(255,255,255,.08)}
    th{background:rgba(255,255,255,.05);font-weight:600}
    tr:last-child td{border-bottom:none}
    a{color:#44d19d;text-decoration:none}
    a:hover{text-decoration:underline}
    .btn{display:inline-block;padding:8px 16px;background:rgba(68,209,157,.14);border:1px solid rgba(68,209,157,.25);color:#e8eefc;border-radius:8px;cursor:pointer;text-decoration:none;font-size:13px}
    .btn:hover{background:rgba(68,209,157,.20)}
    .btn-danger{background:rgba(255,107,122,.14);border:1px solid rgba(255,107,122,.25)}
    .btn-danger:hover{background:rgba(255,107,122,.20)}
    .form-card{background:rgba(15,26,48,.8);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:20px;margin-bottom:20px}
    label{display:block;margin-top:12px;font-size:13px;color:#9fb0d0}
    input,select{width:100%;box-sizing:border-box;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);color:#e8eefc;border-radius:6px;padding:8px;font-size:13px;margin-top:4px}
    button{margin-top:12px;background:rgba(68,209,157,.14);border:1px solid rgba(68,209,157,.25);color:#e8eefc;padding:10px 16px;border-radius:8px;cursor:pointer;font-size:13px}
    .form-actions{margin-top:16px;display:flex;gap:10px}
    .back-link{display:inline-block;margin-bottom:20px}
  </style>
</head>
<body>
  <a href="index2.php" class="back-link">← Back to Report</a>
  <h1>User Management</h1>

  <?php if ($MSG !== ''): ?>
    <div class="msg"><?= h($MSG) ?></div>
  <?php endif; ?>

  <?php if ($EDIT_USER !== ''): ?>
    <!-- Edit/Update User Form -->
    <div class="form-card">
      <h2 style="margin-top:0">Edit User: <?= h($EDIT_USER) ?></h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="username" value="<?= h($EDIT_USER) ?>">

        <label>
          Admin
          <select name="is_admin">
            <option value="0" <?= empty($EDIT_REC['is_admin']) ? 'selected' : '' ?>>No</option>
            <option value="1" <?= !empty($EDIT_REC['is_admin']) ? 'selected' : '' ?>>Yes</option>
          </select>
        </label>

        <label>
          Extensions (comma-separated)
          <input name="extensions" value="<?= h(implode(',', $EDIT_REC['extensions'] ?? [])) ?>" placeholder="101,102,103">
        </label>

        <div class="form-actions">
          <button type="submit" name="uaction" value="update">Update User</button>
          <a href="?page=users" class="btn">Cancel</a>
        </div>
      </form>

      <!-- Change Password Section -->
      <hr style="margin:20px 0;border:none;border-top:1px solid rgba(255,255,255,.08)">
      <h3>Change Password</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="username" value="<?= h($EDIT_USER) ?>">

        <label>
          New Password
          <input type="password" name="newpass1" autocomplete="new-password" required>
        </label>

        <label>
          Confirm Password
          <input type="password" name="newpass2" autocomplete="new-password" required>
        </label>

        <button type="submit" name="uaction" value="changepass">Change Password</button>
      </form>

      <?php if ($EDIT_USER !== 'admin'): ?>
        <!-- Delete User Section -->
        <hr style="margin:20px 0;border:none;border-top:1px solid rgba(255,255,255,.08)">
        <h3>Delete User</h3>
        <form method="post" onsubmit="return confirm('Are you sure you want to delete this user?')">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <input type="hidden" name="username" value="<?= h($EDIT_USER) ?>">
          <button type="submit" name="uaction" value="delete" class="btn-danger">Delete User</button>
        </form>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <!-- Add New User Form -->
    <div class="form-card">
      <h2 style="margin-top:0">Add New User</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

        <label>
          Username
          <input name="username" placeholder="Username" autocomplete="off" required>
        </label>

        <label>
          Password
          <input type="password" name="newpass1" autocomplete="new-password" required>
        </label>

        <label>
          Confirm Password
          <input type="password" name="newpass2" autocomplete="new-password" required>
        </label>

        <label>
          Admin
          <select name="is_admin">
            <option value="0">No</option>
            <option value="1">Yes</option>
          </select>
        </label>

        <label>
          Extensions (comma-separated)
          <input name="extensions" placeholder="101,102,103">
        </label>

        <button type="submit" name="uaction" value="add">Add User</button>
      </form>
    </div>

    <!-- Users List -->
    <h2>Existing Users</h2>
    <table>
      <thead>
        <tr>
          <th>Username</th>
          <th>Admin</th>
          <th>Extensions</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($USERS_DATA['users'] ?? [] as $username => $rec): ?>
          <tr>
            <td><?= h($username) ?></td>
            <td><?= !empty($rec['is_admin']) ? 'Yes' : 'No' ?></td>
            <td><?= h(implode(', ', $rec['extensions'] ?? [])) ?></td>
            <td>
              <a href="?page=users&edit=<?= urlencode($username) ?>" class="btn">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
