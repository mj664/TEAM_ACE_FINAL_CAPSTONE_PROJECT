<?php
require_once 'init.php';
require_login();
require_role(['admin']); // DRRMC Admin only

$admin_id = (int)$_SESSION['user_id'];
$notice = '';
$error = '';
$upload_dir = __DIR__ . '/uploads/advertisements';
$upload_url = 'uploads/advertisements/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_advertisement'])) {
  $advertisement_id = (int)($_POST['advertisement_id'] ?? 0);
  $delete_stmt = $conn->prepare('SELECT image_path FROM advertisements WHERE id=? LIMIT 1');
  if ($delete_stmt) {
    $delete_stmt->bind_param('i', $advertisement_id);
    $delete_stmt->execute();
    $advertisement = $delete_stmt->get_result()->fetch_assoc();
    $delete_stmt->close();
    if ($advertisement) {
      $remove_stmt = $conn->prepare('DELETE FROM advertisements WHERE id=?');
      $remove_stmt->bind_param('i', $advertisement_id);
      $remove_stmt->execute();
      $remove_stmt->close();
      $file_path = __DIR__ . '/' . $advertisement['image_path'];
      if (is_file($file_path)) @unlink($file_path);
      audit_log('ADVERTISEMENT_REMOVED', 'advertisement #' . $advertisement_id);
      $notice = 'Advertisement removed.';
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_advertisement'])) {
  $title = trim((string)($_POST['title'] ?? ''));
  $image = $_FILES['advertisement_image'] ?? null;
  if ($title === '' || !$image || $image['error'] === UPLOAD_ERR_NO_FILE) {
    $error = 'Enter a title and choose an advertisement image.';
  } elseif ($image['error'] !== UPLOAD_ERR_OK || $image['size'] > 8 * 1024 * 1024) {
    $error = 'The advertisement image must be no larger than 8 MB.';
  } else {
    $image_info = @getimagesize($image['tmp_name']);
    $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = $image_info['mime'] ?? '';
    if (!$image_info || !isset($allowed_types[$mime])) {
      $error = 'Upload a JPG, PNG, GIF, or WEBP image.';
    } else {
      if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
      $filename = 'advertisement_' . bin2hex(random_bytes(8)) . '.' . $allowed_types[$mime];
      $target = $upload_dir . '/' . $filename;
      if (!move_uploaded_file($image['tmp_name'], $target)) {
        $error = 'Unable to save the advertisement image.';
      } else {
        $image_path = $upload_url . $filename;
        $post_stmt = $conn->prepare('INSERT INTO advertisements (title, image_path, created_by) VALUES (?, ?, ?)');
        $post_stmt->bind_param('ssi', $title, $image_path, $admin_id);
        $post_stmt->execute();
        $post_stmt->close();
        audit_log('ADVERTISEMENT_POSTED', $title);
        $notice = 'Advertisement posted for barangay users.';
      }
    }
  }
}

$advertisements = [];
$advertisement_result = $conn->query('SELECT id, title, image_path, created_at FROM advertisements ORDER BY created_at DESC, id DESC');
if ($advertisement_result) while ($advertisement = $advertisement_result->fetch_assoc()) $advertisements[] = $advertisement;

// Which section opens first: after posting or removing, show the posted list; on an error, stay on the form.
$ad_tab = 'post';
if (isset($_POST['delete_advertisement']) || (isset($_POST['post_advertisement']) && !$error)) $ad_tab = 'posted';
elseif (($_GET['tab'] ?? '') === 'posted') $ad_tab = 'posted';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"><title>Advertisements</title><?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>"><meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .ad-page { max-width:900px; margin:0 auto; }
    .ad-panel { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:22px; margin-bottom:20px; box-shadow:0 2px 8px rgba(16,24,40,.06); }
    .ad-form { display:grid; gap:12px; }
    .ad-form label { display:grid; gap:6px; color:#344054; font-size:13px; font-weight:600; }
    .ad-form input { box-sizing:border-box; width:100%; padding:10px; border:1px solid #d0d5dd; border-radius:6px; font:inherit; }
    .ad-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; }
    .ad-item { border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
    .ad-item img { display:block; width:100%; height:140px; object-fit:cover; background:#f2f4f7; }
    .ad-item-body { padding:12px; color:#667085; font-size:12px; }
    .ad-item-body strong { display:block; color:#1f2937; margin-bottom:6px; }
    .ad-delete { margin-top:10px; border:0; border-radius:5px; padding:7px 10px; background:#ef4444; color:#fff; cursor:pointer; }

    /* Two section buttons side by side; each shows its own panel. */
    .ad-tabs { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); margin-bottom:16px; padding:6px; background:rgba(255,255,255,.9); border-radius:14px; box-shadow:0 8px 24px rgba(120,60,0,.12); }
    .ad-tab { display:flex; align-items:center; justify-content:center; gap:8px; padding:14px 12px; border:0; border-radius:10px; background:transparent; color:#344054; font:inherit; font-size:14px; font-weight:700; cursor:pointer; transition:background .15s, color .15s; }
    .ad-tab:hover { background:#fff7ed; color:#9a4a00; }
    .ad-tab:focus-visible { outline:2px solid #ff8c00; outline-offset:2px; }
    .ad-tab.active { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); color:#fff; box-shadow:0 4px 12px rgba(234,122,0,.3); }
    .ad-tab .tab-count { min-width:22px; padding:1px 7px; border-radius:999px; background:#f2f4f7; color:#475467; font-size:11px; }
    .ad-tab.active .tab-count { background:rgba(255,255,255,.28); color:#fff; }
    .ad-panel[hidden] { display:none !important; }
    .layout .ad-panel { background:rgba(255,255,255,.95); border:1px solid rgba(255,255,255,.7); border-radius:14px; box-shadow:0 8px 24px rgba(120,60,0,.12); }
    /* Theme turns every "section h3" into a gradient bar; keep these as plain headings. */
    .layout .ad-panel h3 { background:none; padding:0; border-radius:0; color:#1f2937; margin:0 0 4px; font-size:17px; }
    .ad-panel-sub { margin:0 0 14px; color:#667085; font-size:13px; }
    .ad-form input:focus { outline:none; border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
    .ad-form input[type=file]::file-selector-button { margin-right:10px; padding:6px 12px; border:1px solid #f5c48a; border-radius:6px; background:#fff7ed; color:#9a4a00; font-weight:700; cursor:pointer; }
    .ad-preview { display:none; max-height:220px; max-width:100%; border-radius:10px; border:1px solid #f0e6da; object-fit:contain; background:#fafafa; }
    .ad-item { border-color:#f0e6da; background:#fff; }
    @media (max-width:560px) { .ad-tabs { grid-template-columns:1fr; } }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="page-scroll-shell">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;"><h2 style="margin:0;font-size:24px;">Advertisements</h2><p style="margin:4px 0 0;color:#666;font-size:13px;">Post photos and announcements visible in the barangay user dashboard.</p></header>
    <main class="main-content" style="flex:1;overflow-y:auto;padding:24px;">
      <div class="ad-page">
        <?php if ($notice): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="errors"><?= e($error) ?></div><?php endif; ?>
        <div class="ad-tabs" role="tablist" aria-label="Advertisement sections">
          <button type="button" class="ad-tab<?= $ad_tab === 'post' ? ' active' : '' ?>" role="tab" id="tab-post" aria-controls="panel-post" aria-selected="<?= $ad_tab === 'post' ? 'true' : 'false' ?>" data-tab="post">📣 Post Advertisement</button>
          <button type="button" class="ad-tab<?= $ad_tab === 'posted' ? ' active' : '' ?>" role="tab" id="tab-posted" aria-controls="panel-posted" aria-selected="<?= $ad_tab === 'posted' ? 'true' : 'false' ?>" data-tab="posted">🖼️ Posted Advertisements <span class="tab-count"><?= count($advertisements) ?></span></button>
        </div>
        <section class="ad-panel" role="tabpanel" id="panel-post" aria-labelledby="tab-post"<?= $ad_tab === 'post' ? '' : ' hidden' ?>><h3>Post Advertisement</h3><p class="ad-panel-sub">Shown as a popup and in the side panel of every barangay user's dashboard.</p><form class="ad-form" method="post" enctype="multipart/form-data"><input type="hidden" name="post_advertisement" value="1"><label>Advertisement title<input name="title" maxlength="180" required placeholder="Emergency announcement or public safety message"></label><label>Photo<input type="file" name="advertisement_image" id="adImageInput" accept="image/jpeg,image/png,image/gif,image/webp" required></label><img class="ad-preview" id="adPreview" alt="Selected photo preview"><button class="btn" type="submit">Post Advertisement</button></form></section>
        <section class="ad-panel" role="tabpanel" id="panel-posted" aria-labelledby="tab-posted"<?= $ad_tab === 'posted' ? '' : ' hidden' ?>><h3>Posted Advertisements</h3><p class="ad-panel-sub">Currently visible to barangay users. Remove one to take it down.</p><?php if (!$advertisements): ?><p style="color:#667085;font-size:13px;">No advertisements posted yet.</p><?php else: ?><div class="ad-list"><?php foreach ($advertisements as $advertisement): ?><article class="ad-item"><img src="<?= e($advertisement['image_path']) ?>" alt="<?= e($advertisement['title']) ?>"><div class="ad-item-body"><strong><?= e($advertisement['title']) ?></strong>Posted <?= e(date('M j, Y', strtotime($advertisement['created_at']))) ?><form method="post" onsubmit="return confirm('Remove this advertisement?');"><input type="hidden" name="delete_advertisement" value="1"><input type="hidden" name="advertisement_id" value="<?= (int)$advertisement['id'] ?>"><button class="ad-delete" type="submit">Remove</button></form></div></article><?php endforeach; ?></div><?php endif; ?></section>
      </div>
    </main>
  </div>
</div>
<script>
  // Section buttons: show one panel at a time, remembered in the URL so a refresh stays on the same section.
  (function () {
    const tabs = Array.prototype.slice.call(document.querySelectorAll('.ad-tab'));
    function show(name, focus) {
      tabs.forEach(function (tab) {
        const on = tab.dataset.tab === name;
        tab.classList.toggle('active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        tab.tabIndex = on ? 0 : -1;
        document.getElementById('panel-' + tab.dataset.tab).hidden = !on;
        if (on && focus) tab.focus();
      });
      try { history.replaceState(null, '', name === 'post' ? 'admin_advertisements.php' : 'admin_advertisements.php?tab=' + name); } catch (err) {}
    }
    tabs.forEach(function (tab, i) {
      tab.tabIndex = tab.classList.contains('active') ? 0 : -1;
      tab.addEventListener('click', function () { show(tab.dataset.tab, false); });
      tab.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        show(tabs[(i + 1) % tabs.length].dataset.tab, true);
      });
    });

    // Preview the chosen photo before posting.
    const input = document.getElementById('adImageInput'), preview = document.getElementById('adPreview');
    if (input && preview) input.addEventListener('change', function () {
      const file = input.files && input.files[0];
      if (!file) { preview.style.display = 'none'; preview.removeAttribute('src'); return; }
      preview.src = URL.createObjectURL(file);
      preview.style.display = 'block';
    });
  })();
</script>
</body>
</html>
