<?php
require_once __DIR__ . '/init.php';
require_login();
if (is_role_in(['head_admin'])) { header('Location: admin_dashboard.php'); exit; } // staff chat is handled by the DRRMC Admin

$is_admin = is_role_in(['head_admin', 'official', 'admin']);
$current_user_id = (int)$_SESSION['user_id'];
$message_search = $is_admin ? trim((string)($_GET['q'] ?? $_POST['q'] ?? '')) : '';
$selected_user_id = $is_admin ? (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0) : $current_user_id;
$message_error = '';
$admin_profile = null;
if (!$is_admin) {
  // The DRRMC staff account that answers barangay chats (same account the automatic reminders are sent from).
  $admin_profile_stmt = $conn->prepare("SELECT fullname, position, profile_logo FROM users WHERE role IN ('admin', 'official') AND status = 'approved' ORDER BY id ASC LIMIT 1");
  if ($admin_profile_stmt) {
    $admin_profile_stmt->execute();
    $admin_profile = $admin_profile_stmt->get_result()->fetch_assoc() ?: null;
    $admin_profile_stmt->close();
  }
}

// Photos attached to chat messages (kept private: served only through message_image.php).
$msg_col = $conn->query("SHOW COLUMNS FROM contact_messages LIKE 'image_path'");
if ($msg_col && $msg_col->num_rows === 0) $conn->query('ALTER TABLE contact_messages ADD image_path VARCHAR(255) NULL DEFAULT NULL');
// Edit and delete: an edited message keeps its time of edit; a deleted message stays in the database (only hidden in the chat).
foreach (['edited_at' => 'DATETIME NULL DEFAULT NULL', 'deleted_at' => 'DATETIME NULL DEFAULT NULL'] as $msg_c => $msg_def) {
    $msg_col = $conn->query("SHOW COLUMNS FROM contact_messages LIKE '$msg_c'");
    if ($msg_col && $msg_col->num_rows === 0) $conn->query("ALTER TABLE contact_messages ADD $msg_c $msg_def");
}

// Edit or delete one of your own messages (never an automatic reminder). The old text goes to the audit log.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['edit_message']) || isset($_POST['delete_message']))) {
    $msg_id = (int)($_POST['message_id'] ?? 0);
    $my_role = $is_admin ? 'admin' : 'user';
    $own = $conn->prepare('SELECT id, user_id, message, image_path FROM contact_messages WHERE id = ? AND sender_id = ? AND sender_role = ? AND auto_sent = 0 AND deleted_at IS NULL LIMIT 1');
    $own->bind_param('iis', $msg_id, $current_user_id, $my_role);
    $own->execute();
    $own_msg = $own->get_result()->fetch_assoc();
    $own->close();
    if (!csrf_valid($_POST['csrf'] ?? '')) {
        $message_error = 'Your session expired. Please try again.';
    } elseif (!$own_msg) {
        $message_error = 'You can only edit or delete your own messages.';
    } elseif (isset($_POST['delete_message'])) {
        $del = $conn->prepare('UPDATE contact_messages SET deleted_at = NOW(), is_read = 1 WHERE id = ?');
        $del->bind_param('i', $msg_id);
        $del->execute();
        $del->close();
        audit_log('MESSAGE_DELETED', 'message #' . $msg_id, 'Removed text: ' . mb_substr($own_msg['message'], 0, 400));
    } else {
        $new_text = trim((string)($_POST['message'] ?? ''));
        if ($new_text === '' && !empty($own_msg['image_path'])) $new_text = MSG_PHOTO_TEXT;
        if ($new_text === '') {
            $message_error = 'A message cannot be empty. Use Delete to remove it.';
        } elseif ($new_text !== $own_msg['message']) {
            $upd = $conn->prepare('UPDATE contact_messages SET message = ?, edited_at = NOW() WHERE id = ?');
            $upd->bind_param('si', $new_text, $msg_id);
            $upd->execute();
            $upd->close();
            audit_log('MESSAGE_EDITED', 'message #' . $msg_id, 'Before: ' . mb_substr($own_msg['message'], 0, 400) . ' | After: ' . mb_substr($new_text, 0, 400));
        }
    }
    if ($message_error === '') {
        $back_user = $own_msg ? (int)$own_msg['user_id'] : $selected_user_id;
        header('Location: messages.php' . ($is_admin ? '?' . http_build_query(['user_id' => $back_user, 'q' => $message_search]) : ''));
        exit;
    }
}
define('MSG_PHOTO_TEXT', '📷 Photo');
// A message that is only 1 to 3 emoji is shown bigger, like in Messenger.
// Request-form addresses in automatic messages become a button that opens the printable form (text is escaped first).
function msg_links($escaped) { return preg_replace('~request_print\.php\?id=(\d+)~', '<a class="msg-doc" href="request_print.php?id=$1" target="_blank" rel="noopener">📄 Open request form #$1</a>', $escaped); }
function msg_emoji_only($t) { $t = trim((string)$t); return $t !== '' && !preg_match('/[\p{L}\p{N}\s]/u', $t) && preg_match_all('/\X/u', $t) <= 3; } // stored as the text of a photo sent without a caption, so previews elsewhere still read well

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $message_error = 'The photo is too large. Please choose a photo under 5 MB.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message_text = trim((string)($_POST['message'] ?? ''));
    $target_user_id = $is_admin ? (int)($_POST['user_id'] ?? 0) : $current_user_id;
    $sender_role = $is_admin ? 'admin' : 'user';
    // Optional photo: JPG, PNG, GIF or WEBP, up to 5 MB, checked by its real content (not the file name).
    $image_path = null;
    $photo = $_FILES['image'] ?? null;
    if ($photo && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $info = ($photo['error'] === UPLOAD_ERR_OK && is_uploaded_file($photo['tmp_name'])) ? @getimagesize($photo['tmp_name']) : false;
        if ($photo['error'] === UPLOAD_ERR_INI_SIZE || $photo['error'] === UPLOAD_ERR_FORM_SIZE || ($photo['size'] ?? 0) > 5 * 1024 * 1024) {
            $message_error = 'The photo is too large. Please choose a photo under 5 MB.';
        } elseif (!$info || !isset($types[$info['mime']])) {
            $message_error = 'Only JPG, PNG, GIF or WEBP photos can be sent.';
        } else {
            $dir = __DIR__ . '/uploads/messages';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            $name = 'msg_' . bin2hex(random_bytes(12)) . '.' . $types[$info['mime']];
            if (move_uploaded_file($photo['tmp_name'], $dir . '/' . $name)) $image_path = 'uploads/messages/' . $name;
            else $message_error = 'Unable to save the photo. Please try again.';
        }
    }
    if ($message_text === '' && $image_path !== null) $message_text = MSG_PHOTO_TEXT;
    if ($message_error !== '') {
        // keep the error message
    } elseif ($message_text === '' || ($is_admin && $target_user_id < 1)) {
        $message_error = 'Choose a conversation and enter a message.';
    } else {
        $message_stmt = $conn->prepare('INSERT INTO contact_messages (user_id, sender_id, sender_role, message, image_path) VALUES (?, ?, ?, ?, ?)');
        if ($message_stmt) {
            $message_stmt->bind_param('iisss', $target_user_id, $current_user_id, $sender_role, $message_text, $image_path);
            if (!$message_stmt->execute()) $message_error = 'Unable to send your message.';
            $message_stmt->close();
        } else {
            $message_error = 'Unable to send your message.';
        }
        $selected_user_id = $target_user_id;
        if ($message_error === '') { // post/redirect/get: a refresh or the live refresh never resends the message
            header('Location: messages.php' . ($is_admin ? '?' . http_build_query(['user_id' => $target_user_id, 'q' => $message_search]) : ''));
            exit;
        }
    }
}

if ($is_admin && $selected_user_id > 0) {
    $read_stmt = $conn->prepare("UPDATE contact_messages SET is_read = 1 WHERE user_id = ? AND sender_role = 'user' AND is_read = 0");
    if ($read_stmt) {
        $read_stmt->bind_param('i', $selected_user_id);
        $read_stmt->execute();
        $read_stmt->close();
    }
} elseif (!$is_admin) {
    $read_stmt = $conn->prepare("UPDATE contact_messages SET is_read = 1 WHERE user_id = ? AND sender_role IN ('admin', 'system') AND is_read = 0");
    if ($read_stmt) {
        $read_stmt->bind_param('i', $current_user_id);
        $read_stmt->execute();
        $read_stmt->close();
    }
}

$chat_users = [];
if ($is_admin) {
  $user_sql = "SELECT u.id, u.fullname, u.barangay, u.status, u.profile_logo,
    (SELECT cm.sender_role FROM contact_messages cm WHERE cm.user_id = u.id ORDER BY cm.id DESC LIMIT 1) AS sender_role,
    (SELECT IF(cm.deleted_at IS NULL, cm.message, 'Message removed') FROM contact_messages cm WHERE cm.user_id = u.id ORDER BY cm.id DESC LIMIT 1) AS latest_message,
    (SELECT cm.created_at FROM contact_messages cm WHERE cm.user_id = u.id ORDER BY cm.id DESC LIMIT 1) AS last_at,
    (SELECT MAX(cm.id) FROM contact_messages cm WHERE cm.user_id = u.id) AS last_id,
    (SELECT COUNT(*) FROM contact_messages cm WHERE cm.user_id = u.id AND cm.sender_role = 'user' AND cm.is_read = 0) AS unread
    FROM users u WHERE u.role = 'user'";
  if ($message_search !== '') $user_sql .= " AND (u.fullname LIKE ? OR u.barangay LIKE ?)";
  $user_sql .= " ORDER BY last_id IS NULL, last_id DESC, u.fullname ASC"; // newest activity first, like Messenger
  $user_stmt = $conn->prepare($user_sql);
  if ($user_stmt) {
    if ($message_search !== '') {
      $search_like = '%' . $message_search . '%';
      $user_stmt->bind_param('ss', $search_like, $search_like);
    }
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
  } else {
    $user_result = false;
  }
    if ($user_result) {
        while ($row = $user_result->fetch_assoc()) {
            $user_id = (int)$row['id'];
            if (!isset($chat_users[$user_id])) {
                $chat_users[$user_id] = [
                    'fullname' => $row['fullname'],
                    'barangay' => $row['barangay'],
                    'profile_logo' => $row['profile_logo'] ?? '',
          'status' => $row['status'],
          'preview' => $row['latest_message'] ?: 'No messages yet. Start the conversation.',
          'last_at' => $row['last_at'],
          'unread' => (int)$row['unread'],
                ];
            }
        }
    }
  if ($user_stmt) $user_stmt->close();
}

$messages = [];
// Sender name and photo are read live from users, so a profile change shows up in every conversation right away.
$thread_stmt = $conn->prepare('SELECT cm.id, cm.sender_role, cm.message, cm.image_path, cm.edited_at, cm.deleted_at, cm.created_at, cm.auto_sent, cm.sender_id, s.fullname AS sender_name, s.profile_logo AS sender_logo
  FROM contact_messages cm LEFT JOIN users s ON s.id = cm.sender_id
  WHERE cm.user_id = ? ORDER BY cm.created_at ASC, cm.id ASC');
if ($thread_stmt) {
    $thread_stmt->bind_param('i', $selected_user_id);
    $thread_stmt->execute();
    $thread_result = $thread_stmt->get_result();
    while ($row = $thread_result->fetch_assoc()) $messages[] = $row;
    $thread_stmt->close();
}

$selected_user = null;
if ($is_admin && $selected_user_id > 0) {
    $selected_stmt = $conn->prepare("SELECT fullname, barangay, profile_logo FROM users WHERE id = ? AND role = 'user' LIMIT 1");
    if ($selected_stmt) {
        $selected_stmt->bind_param('i', $selected_user_id);
        $selected_stmt->execute();
        $selected_user = $selected_stmt->get_result()->fetch_assoc() ?: null;
        $selected_stmt->close();
    }
}

// "Barangay 10 User" -> "BU", "Mary Joy Olmedo" -> "MO": shown when an account has no profile photo.
function msg_initials($name) {
  $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\s]/u', ' ', (string)$name))) ?: [];
  $words = array_values(array_filter($words, 'strlen'));
  if (!$words) return '?';
  $first = mb_substr($words[0], 0, 1);
  $last = count($words) > 1 ? mb_substr(end($words), 0, 1) : '';
  return mb_strtoupper($first . $last);
}

$sidebar_unread = 0;
$unread_stmt = $is_admin
    ? $conn->query("SELECT COUNT(*) AS total FROM contact_messages WHERE sender_role = 'user' AND is_read = 0")
    : $conn->prepare("SELECT COUNT(*) AS total FROM contact_messages WHERE user_id = ? AND sender_role IN ('admin', 'system') AND is_read = 0");
if ($is_admin) {
    if ($unread_stmt) $sidebar_unread = (int)$unread_stmt->fetch_assoc()['total'];
} elseif ($unread_stmt) {
    $unread_stmt->bind_param('i', $current_user_id);
    $unread_stmt->execute();
    $sidebar_unread = (int)$unread_stmt->get_result()->fetch_assoc()['total'];
    $unread_stmt->close();
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Messages - DRRMC Caloocan Hub</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?php echo e(asset_v('assets/style.css')); ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .messages-page { width:100%; max-width: 1280px; height:100%; margin: 0 auto; padding: 22px 24px; display:flex; flex-direction:column; }
    .messages-page > h1 { margin-bottom:2px; }
    .messages-page > h1 + p { margin-bottom:14px; }
    .messages-layout { display: grid; grid-template-columns: minmax(270px, 0.72fr) minmax(420px, 1.7fr); gap: 18px; align-items: stretch; flex:1; min-height:0; }
    .messages-panel { background: #fff; border: 1px solid #e4e9e6; border-radius: 10px; box-shadow: 0 5px 16px rgba(30,77,55,.08); overflow: hidden; }
    .messages-layout > .messages-panel:not(.thread) { display:flex; min-height:0; flex-direction:column; }
    .messages-panel-header { padding: 16px 18px; background: linear-gradient(135deg, #2d7a5c, #1e4d37); color: #fff; }
    .messages-panel-header h2, .messages-panel-header h3 { margin: 0; color: #fff; }
    .conversation-list { flex:1; min-height:0; max-height:none; overflow-y: scroll; scrollbar-color:#9aa7b5 #eef1f4; scrollbar-width:thin; }
    .messages-panel::-webkit-scrollbar, .conversation-list::-webkit-scrollbar, .thread-messages::-webkit-scrollbar { width:10px; }
    .messages-panel::-webkit-scrollbar-track, .conversation-list::-webkit-scrollbar-track, .thread-messages::-webkit-scrollbar-track { background:#eef1f4; }
    .messages-panel::-webkit-scrollbar-thumb, .conversation-list::-webkit-scrollbar-thumb, .thread-messages::-webkit-scrollbar-thumb { background:#9aa7b5; border:2px solid #eef1f4; border-radius:5px; }
    .messages-panel::-webkit-scrollbar-thumb:hover, .conversation-list::-webkit-scrollbar-thumb:hover, .thread-messages::-webkit-scrollbar-thumb:hover { background:#687585; }
    .conversation-link { display: block; padding: 14px 16px; border: 0; border-bottom: 1px solid #edf0ee; background: #fff; color: #24362d; text-decoration: none; }
    .conversation-link:hover, .conversation-link.selected { background: #eef8f2; }
    .conversation-link strong { display: block; color: #1e4d37; }
    .conversation-link small { display: block; margin-top: 3px; color: #6b756f; }
    .conversation-link p { overflow: hidden; margin: 7px 0 0; color: #59635e; font-size: 13px; text-overflow: ellipsis; white-space: nowrap; }
    .conversation-person { display:flex; align-items:center; gap:10px; min-width:0; }
    .conversation-avatar, .thread-avatar { width:38px; height:38px; flex:0 0 38px; border-radius:50%; object-fit:cover; border:2px solid #d9e1dc; background:#eef8f2; }
    .conversation-avatar-placeholder, .thread-avatar-placeholder { display:grid; place-items:center; color:#2d7a5c; font-size:18px; font-weight:700; }
    .conversation-person > span:last-child { min-width:0; }
    .message-search { display: flex; gap: 8px; padding: 12px; border-bottom: 1px solid #edf0ee; }
    .message-search input { min-width: 0; flex: 1; padding: 9px 10px; border: 1px solid #d9e1dc; border-radius: 5px; }
    .message-search button { border: 0; border-radius: 5px; padding: 9px 12px; background: #e8753e; color: #fff; font-weight: 700; cursor: pointer; }
    .unread-badge { float: right; min-width: 20px; padding: 2px 6px; border-radius: 12px; background: #e8753e; color: #fff; text-align: center; font-size: 11px; font-weight: 700; }
    .thread { display: flex; min-height:0; height:100%; flex-direction: column; }
    .thread-header { display:flex; align-items:center; gap:11px; padding: 16px 18px; border-bottom: 1px solid #edf0ee; flex-shrink:0; }
    .thread-header h3 { margin: 0; color: #1e4d37; }
    .thread-header small { color: #6b756f; }
    .thread-profile-meta { display:flex; flex-direction:column; gap:2px; }
    .thread-profile-meta small { color:#6b756f; }
    .thread-messages { flex: 1; min-height:0; max-height:none; overflow-y: auto; padding: 18px; background: #fbfcfb; scrollbar-color:#9aa7b5 #eef1f4; scrollbar-width:thin; }
    .message-bubble { max-width: 78%; margin: 0 0 12px; padding: 10px 12px; border-radius: 9px; background: #e8eee9; color: #283b31; }
    .message-bubble.mine { margin-left: auto; background: #dff1e7; border-right: 3px solid #2d7a5c; }
    .message-bubble.theirs { border-left: 3px solid #e8753e; }
    .message-bubble.system { max-width: 88%; background: #fff7e6; border-left: 3px solid #f59e0b; color: #5b3a06; }
    .message-bubble.system small { color: #92600a; font-weight: 700; }
    .message-bubble p { margin: 0; white-space: pre-wrap; }
    .message-bubble small { display: block; margin-top: 5px; color: #6b756f; font-size: 11px; }
    .message-form { display: flex; gap: 8px; padding: 14px 18px; border-top: 1px solid #edf0ee; flex-shrink:0; }
    .message-form textarea { min-height: 46px; flex: 1; resize: vertical; }
    .message-form button { align-self: flex-end; border: 0; border-radius: 5px; padding: 11px 17px; background: #2d7a5c; color: #fff; font-weight: 700; cursor: pointer; }
    .message-empty { padding: 42px 22px; color: #6b756f; text-align: center; }
    .message-error { margin: 0 18px 12px; padding: 9px 12px; background: #fff1ed; color: #a33e28; border-radius: 5px; }
    /* Both roles: the page fits the screen and only the message list scrolls inside the thread panel. */
    .layout:has(.messages-shell) { height:100vh; min-height:0; overflow:hidden; }
    .messages-shell { min-height:0; overflow:hidden; }
    .messages-admin-shell { overflow:hidden; scrollbar-color:#9aa7b5 #eef1f4; scrollbar-width:thin; }
    .messages-admin-shell::-webkit-scrollbar { width:10px; }
    .messages-admin-shell::-webkit-scrollbar-track { background:#eef1f4; }
    .messages-admin-shell::-webkit-scrollbar-thumb { background:#9aa7b5; border:2px solid #eef1f4; border-radius:5px; }
    .messages-admin-shell::-webkit-scrollbar-thumb:hover { background:#687585; }
    @media (max-width:1200px) {
      /* Sidebar stacks above the content here: let the page scroll, but keep the thread a fixed size. */
      .layout:has(.messages-shell) { height:auto; min-height:100vh; overflow:visible; }
      .messages-shell, .messages-admin-shell { overflow:visible; }
      .messages-page { height:auto; }
      .messages-layout { flex:none; }
      .thread { height:70vh; min-height:420px; }
    }
    /* ---- Orange -> green theme ---- */
    .layout .messages-panel { border:1px solid rgba(255,255,255,.7); border-radius:14px; box-shadow:0 8px 24px rgba(120,60,0,.14); }
    .messages-panel-header { background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); }
    .layout .messages-panel-header h2 { background:none; padding:0; }
    .messages-page > h1 { color:#1f2937; }
    .messages-page > h1 + p { color:#475467; }
    .message-search input { border-color:#d0d5dd; border-radius:8px; }
    .message-search input:focus, .message-form textarea:focus { outline:none; border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
    .message-search button, .message-form button { border-radius:8px; background:linear-gradient(135deg,#ea7a00 0%,#b8860b 50%,#3f9b46 100%); }
    .message-search button:hover, .message-form button:hover { filter:brightness(1.08); }
    .conversation-link:hover { background:#fff7ed; }
    .conversation-link.selected { background:#fff7ed; box-shadow:inset 4px 0 0 #ff8c00; }
    .conversation-link strong { color:#1f2937; }
    .conversation-avatar, .thread-avatar { border-color:#f5c48a; }
    .conversation-avatar-placeholder, .thread-avatar-placeholder { background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; font-size:13px; letter-spacing:.02em; }
    .unread-badge { background:#ea7a00; }
    .thread-header h3, .layout .thread-header h3 { color:#1f2937; background:none !important; padding:0; border-radius:0; box-shadow:none; }
    .thread-messages { background:#fffaf4; scrollbar-color:#f0b060 transparent; }
    .conversation-list { scrollbar-color:#f0b060 transparent; }
    .conversation-list::-webkit-scrollbar-thumb, .thread-messages::-webkit-scrollbar-thumb { background:#f0b060; border-color:transparent; }
    .conversation-list::-webkit-scrollbar-track, .thread-messages::-webkit-scrollbar-track { background:transparent; }
    /* Each message shows the sender's current profile photo (or initials). */
    .message-row { display:flex; align-items:flex-end; gap:8px; margin:0 0 12px; }
    .message-row.mine { flex-direction:row-reverse; }
    .message-row .message-bubble { margin:0; }
    .bubble-avatar { width:30px; height:30px; flex:0 0 30px; border-radius:50%; object-fit:cover; border:2px solid #fff; box-shadow:0 1px 4px rgba(0,0,0,.15); background:#fff; }
    .bubble-avatar-initials { display:grid; place-items:center; background:linear-gradient(135deg,#ea7a00,#3f9b46); color:#fff; font-size:11px; font-weight:700; }
    .message-bubble { background:#fff; border:1px solid #f0e6da; box-shadow:0 1px 3px rgba(16,24,40,.05); }
    .message-bubble.mine { background:#fff1e0; border-color:#f8d3a8; border-right:3px solid #ea7a00; }
    .message-bubble.theirs { border-left:3px solid #3f9b46; }
    @media (max-width: 760px) { .messages-page { height:auto; min-height:100%; padding: 18px 12px; } .messages-layout { grid-template-columns: 1fr; flex:none; } .conversation-list { max-height: 260px; } .thread { min-height: 500px; height:500px; } }
    /* Chat input: photo, emoji picker, thumbs up (Messenger style) */
    .message-form { flex-direction:row !important; align-items:flex-end; gap:10px; }
    .chat-compose { flex:1; min-width:0; display:flex; flex-direction:column; gap:8px; }
    .chat-bar { position:relative; display:flex; align-items:center; gap:6px; padding:4px 6px 4px 8px; border:1px solid #e4e7ec; border-radius:24px; background:#f4f5f7; }
    .chat-bar:focus-within { border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.15); background:#fff; }
    .message-form .chat-bar textarea { flex:1; min-height:0 !important; height:38px; max-height:120px; margin:0; padding:9px 4px !important; border:0 !important; background:transparent !important; box-shadow:none !important; resize:none; font:inherit; font-size:15px; line-height:20px; }
    .message-form .chat-icon-btn { flex:none; display:inline-grid; place-items:center; width:36px; height:36px; margin:0; padding:0 !important; border:0 !important; border-radius:50% !important; background:transparent !important; color:#ea7a00 !important; font-size:19px; cursor:pointer; box-shadow:none !important; align-self:center; }
    .message-form .chat-icon-btn:hover { background:#ffe9cf !important; filter:none !important; }
    .message-form button.chat-send, .message-form button.chat-like { flex:none; align-self:flex-end; width:44px; height:44px; padding:0 !important; border-radius:50% !important; display:grid; place-items:center; font-size:17px; }
    .message-form button.chat-like { background:transparent !important; font-size:26px; box-shadow:none; }
    .message-form button.chat-like:hover { transform:scale(1.12); filter:none; }
    .message-form button[hidden] { display:none !important; }
    .chat-emoji-panel { position:absolute; right:0; bottom:calc(100% + 8px); z-index:30; width:320px; max-width:80vw; max-height:240px; overflow-y:auto; padding:8px; display:grid; grid-template-columns:repeat(8, 1fr); gap:2px; background:#fff; border:1px solid #f0e6da; border-radius:12px; box-shadow:0 12px 28px rgba(16,24,40,.18); }
    .chat-emoji-panel[hidden] { display:none; }
    .message-form .chat-emoji-panel button { width:auto; height:auto; margin:0; padding:4px 0 !important; border:0 !important; border-radius:8px !important; background:transparent !important; font-size:22px; line-height:1.2; cursor:pointer; box-shadow:none !important; color:inherit !important; }
    .message-form .chat-emoji-panel button:hover { background:#fff1df !important; filter:none !important; }
    .chat-photo-preview { position:relative; align-self:flex-start; }
    .chat-photo-preview[hidden] { display:none; }
    .chat-photo-preview img { display:block; max-width:160px; max-height:120px; border-radius:10px; border:1px solid #f0e6da; }
    .message-form .chat-photo-preview button { position:absolute; top:-8px; right:-8px; width:24px; height:24px; padding:0 !important; border:0 !important; border-radius:50% !important; background:#1f2937 !important; color:#fff !important; font-size:15px; line-height:1; cursor:pointer; }
    .message-photo { display:block; margin:-2px 0 6px; }
    .message-photo img { display:block; max-width:min(260px, 100%); max-height:260px; border-radius:10px; object-fit:cover; }
    .msg-doc { display:inline-block; margin-top:2px; padding:5px 11px; border:1px solid #f5c48a; border-radius:8px; background:#fff7ed; color:#9a4a00 !important; font-weight:700; text-decoration:none; white-space:nowrap; }
    .msg-doc:hover { background:#ffedd5; }
    .message-bubble p.emoji-only { font-size:34px; line-height:1.15; }
    /* Edit / delete your own messages */
    .message-row.mine .message-bubble { order:1; }
    .message-row.mine .msg-menu { order:2; }
    .msg-menu { position:relative; align-self:center; opacity:0; transition:opacity .15s; }
    .message-row:hover .msg-menu, .msg-menu:focus-within, .msg-menu:has(.msg-menu-list:not([hidden])) { opacity:1; }
    @media (hover:none) { .msg-menu { opacity:1; } }
    .msg-menu .msg-menu-btn { width:30px; height:30px; padding:0 !important; border:0 !important; border-radius:50% !important; background:#fff !important; color:#667085 !important; box-shadow:0 1px 3px rgba(16,24,40,.12) !important; cursor:pointer; display:grid; place-items:center; }
    .msg-menu .msg-menu-btn:hover { background:#fff1df !important; color:#c56a00 !important; filter:none !important; }
    .msg-menu-list { position:absolute; right:0; bottom:calc(100% + 6px); z-index:20; min-width:130px; padding:5px; background:#fff; border:1px solid #f0e6da; border-radius:10px; box-shadow:0 10px 24px rgba(16,24,40,.16); }
    .msg-menu-list[hidden] { display:none; }
    .msg-menu-list button { display:flex; align-items:center; gap:8px; width:100%; margin:0; padding:8px 10px !important; border:0 !important; border-radius:7px !important; background:transparent !important; color:#1f2937 !important; font:inherit; font-size:13px; text-align:left; cursor:pointer; box-shadow:none !important; }
    .msg-menu-list button:hover { background:#fff7ed !important; filter:none !important; }
    .msg-menu-list .msg-delete { color:#b42318 !important; }
    .message-bubble.message-removed { background:#f9fafb !important; border:1px dashed #d0d5dd !important; box-shadow:none; }
    .message-bubble.message-removed p { color:#98a2b3; font-style:italic; font-size:14px; }
    .msg-edited { font-style:italic; }
    .msg-edit-box { display:flex; flex-direction:column; gap:6px; min-width:min(320px, 60vw); }
    .msg-edit-box textarea { width:100%; min-height:60px; padding:8px 10px; border:1px solid #f5c48a; border-radius:8px; font:inherit; font-size:14px; resize:vertical; }
    .msg-edit-actions { display:flex; justify-content:flex-end; gap:6px; }
    .msg-edit-actions button { margin:0; padding:6px 14px !important; border-radius:7px !important; font:inherit; font-size:12px; font-weight:700; cursor:pointer; }
    .msg-edit-actions .msg-edit-cancel { background:#fff !important; color:#475467 !important; border:1px solid #d0d5dd !important; }
    .msg-edit-actions .msg-edit-save { background:#ea7a00 !important; color:#fff !important; border:0 !important; }
  </style>
</head>
<body>
<div class="layout">
<?php if ($is_admin): ?>
  <?php require __DIR__ . '/sidebar.php'; ?>
  <main class="main-content messages-shell messages-admin-shell">
<?php else: ?>
  <?php require __DIR__ . '/sidebar.php'; ?>
  <main class="main-content messages-shell">
<?php endif; ?>
  <div class="messages-page">
    <h1><?php echo $is_admin ? 'Messages from Barangay Staff' : 'Messages with DRRM Office'; ?></h1>
    <p>Ask questions and continue your conversation with the DRRM team.</p>
    <?php if ($message_error): ?><div class="message-error"><?php echo e($message_error); ?></div><?php endif; ?>
    <div class="messages-layout">
      <?php if ($is_admin): ?>
        <section class="messages-panel">
          <div class="messages-panel-header"><h2>Staff Conversations</h2></div>
          <form class="message-search" method="get">
            <input type="search" name="q" value="<?php echo e($message_search); ?>" placeholder="Search name or barangay" aria-label="Search name or barangay">
            <button type="submit">Search</button>
          </form>
          <div class="conversation-list">
            <?php if (!$chat_users): ?><div class="message-empty"><?php echo $message_search ? 'No users match your search.' : 'No barangay users registered yet.'; ?></div><?php endif; ?>
            <?php foreach ($chat_users as $user_id => $chat_user): ?>
              <a class="conversation-link <?php echo $selected_user_id === (int)$user_id ? 'selected' : ''; ?>" href="messages.php?<?php echo e(http_build_query(['user_id' => (int)$user_id, 'q' => $message_search])); ?>">
                <?php if ($chat_user['unread']): ?><span class="unread-badge"><?php echo (int)$chat_user['unread'] > 99 ? '99+' : (int)$chat_user['unread']; ?></span><?php endif; ?>
                <span class="conversation-person">
                  <?php if (!empty($chat_user['profile_logo'])): ?><img class="conversation-avatar" src="<?php echo e($chat_user['profile_logo']); ?>" alt="">
                  <?php else: ?><span class="conversation-avatar conversation-avatar-placeholder" aria-hidden="true"><?php echo e(msg_initials($chat_user['fullname'])); ?></span><?php endif; ?>
                  <span><strong><?php echo e($chat_user['fullname']); ?></strong>
                  <small><?php echo e($chat_user['barangay'] !== '' && $chat_user['barangay'] !== null ? barangay_label($chat_user['barangay']) : 'Barangay not specified'); ?> · <?php echo e(ucfirst((string)$chat_user['status'])); ?></small></span>
                </span>
                <p><?php echo e($chat_user['preview']); ?></p>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
      <section class="messages-panel thread" style="<?php echo $is_admin ? '' : 'grid-column: 1 / -1;'; ?>">
        <?php if ($is_admin && !$selected_user): ?>
          <div class="message-empty">Select a staff member to open the conversation.</div>
        <?php else: ?>
          <div class="thread-header">
            <?php if ($is_admin && !empty($selected_user['profile_logo'])): ?><img class="thread-avatar" src="<?php echo e($selected_user['profile_logo']); ?>" alt="">
            <?php elseif (!$is_admin && !empty($admin_profile['profile_logo'])): ?><img class="thread-avatar" src="<?php echo e($admin_profile['profile_logo']); ?>" alt="DRRM Office profile">
            <?php else: ?><span class="thread-avatar thread-avatar-placeholder" aria-hidden="true"><?php echo e(msg_initials($is_admin ? $selected_user['fullname'] : 'DRRM Office')); ?></span><?php endif; ?>
            <div class="thread-profile-meta"><h3><?php echo $is_admin ? e($selected_user['fullname']) : e($admin_profile['fullname'] ?? 'DRRM Office'); ?></h3>
            <small><?php echo $is_admin ? e(($selected_user['barangay'] ?? '') !== '' ? barangay_label($selected_user['barangay']) : 'Barangay not specified') : e(($admin_profile['position'] ?? '') ?: 'DRRM Office'); ?></small>
            <?php if (!$is_admin): ?><small>Official DRRM Office account</small><?php endif; ?></div>
          </div>
          <div class="thread-messages">
            <?php if (!$messages): ?><div class="message-empty">No messages yet. Start the conversation below.</div><?php endif; ?>
            <?php foreach ($messages as $thread_message): ?>
              <?php
                $is_system_message = $thread_message['sender_role'] === 'system';
                // Automatic reminders are sent by the DRRMC staff account: the barangay receives them as messages from the
                // DRRM Office, and the DRRMC side sees them as messages it sent (tagged so they can tell they went out automatically).
                $bubble_class = $is_system_message ? 'theirs' : ($thread_message['sender_role'] === ($is_admin ? 'admin' : 'user') ? 'mine' : 'theirs');
                $auto_tag = ($is_admin && !empty($thread_message['auto_sent'])) ? ' · sent automatically' : '';
                $from_office = $is_system_message || $thread_message['sender_role'] === 'admin';
                $sender_name = (string)($thread_message['sender_name'] ?? '');
                if ($from_office) {
                  // Barangay staff see "DRRM Office"; the DRRMC side sees which admin account replied.
                  $bubble_author = ($is_admin && $sender_name !== '' ? $sender_name : 'DRRM Office') . $auto_tag;
                  $bubble_logo = (string)($thread_message['sender_logo'] ?? '') ?: ($is_admin ? '' : (string)($admin_profile['profile_logo'] ?? ''));
                } else {
                  $bubble_author = $is_admin ? ($sender_name ?: ($selected_user['fullname'] ?? 'Barangay staff')) : 'You';
                  $bubble_logo = (string)($thread_message['sender_logo'] ?? '');
                  if ($sender_name === '') $sender_name = $selected_user['fullname'] ?? '';
                }
                $initials_source = $from_office && !$is_admin ? 'DRRM Office' : ($sender_name ?: $bubble_author);
              ?>
              <div class="message-row <?php echo $bubble_class; ?>">
                <?php if ($bubble_logo !== ''): ?><img class="bubble-avatar" src="<?php echo e($bubble_logo); ?>" alt=""><?php else: ?><span class="bubble-avatar bubble-avatar-initials" aria-hidden="true"><?php echo e(msg_initials($initials_source)); ?></span><?php endif; ?>
                <?php $msg_removed = !empty($thread_message['deleted_at']);
                  $msg_mine = $bubble_class === 'mine' && !$is_system_message && empty($thread_message['auto_sent']) && (int)$thread_message['sender_id'] === $current_user_id; ?>
                <?php if ($msg_removed): ?>
                <div class="message-bubble message-removed <?php echo $bubble_class; ?>">
                  <p><i class="fa-solid fa-ban"></i> <?php echo $bubble_class === 'mine' ? 'You removed a message' : 'This message was removed'; ?></p>
                  <small><?php echo e($bubble_author); ?> · <?php echo e(date('M j, g:i A', strtotime($thread_message['created_at']))); ?></small>
                </div>
                <?php else: ?>
                <?php if ($msg_mine): ?>
                <div class="msg-menu">
                  <button type="button" class="msg-menu-btn" title="More" aria-label="Message options"><i class="fa-solid fa-ellipsis"></i></button>
                  <div class="msg-menu-list" hidden>
                    <button type="button" class="msg-edit" data-id="<?php echo (int)$thread_message['id']; ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                    <button type="button" class="msg-delete" data-id="<?php echo (int)$thread_message['id']; ?>"><i class="fa-solid fa-trash"></i> Delete</button>
                  </div>
                </div>
                <?php endif; ?>
                <div class="message-bubble <?php echo $bubble_class; ?>" data-msg-id="<?php echo (int)$thread_message['id']; ?>" data-msg-text="<?php echo e($thread_message['message'] === MSG_PHOTO_TEXT && !empty($thread_message['image_path']) ? '' : $thread_message['message']); ?>">
                  <?php if (!empty($thread_message['image_path'])): ?><a class="message-photo" href="message_image.php?id=<?php echo (int)$thread_message['id']; ?>" target="_blank" rel="noopener" title="Open photo"><img src="message_image.php?id=<?php echo (int)$thread_message['id']; ?>" alt="Photo" loading="lazy"></a><?php endif; ?>
                  <?php if (empty($thread_message['image_path']) || $thread_message['message'] !== MSG_PHOTO_TEXT): ?><p<?php echo msg_emoji_only($thread_message['message']) ? ' class="emoji-only"' : ''; ?>><?php echo msg_links(e($thread_message['message'])); ?></p><?php endif; ?>
                  <small><?php echo e($bubble_author); ?> · <?php echo e(date('M j, g:i A', strtotime($thread_message['created_at']))); ?><?php echo !empty($thread_message['edited_at']) ? ' · <span class="msg-edited" title="Edited ' . e(date('M j, g:i A', strtotime($thread_message['edited_at']))) . '">Edited</span>' : ''; ?></small>
                </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <form method="post" id="msgActionForm" hidden><input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="message_id" id="msgActionId"><input type="hidden" name="message" id="msgActionText"><?php if ($is_admin): ?><input type="hidden" name="user_id" value="<?php echo (int)$selected_user_id; ?>"><input type="hidden" name="q" value="<?php echo e($message_search); ?>"><?php endif; ?><input type="hidden" id="msgActionKind"></form>
          <form class="message-form" method="post" enctype="multipart/form-data">
            <?php if ($is_admin): ?><input type="hidden" name="user_id" value="<?php echo (int)$selected_user_id; ?>"><input type="hidden" name="q" value="<?php echo e($message_search); ?>">
            <?php endif; ?><div class="chat-compose">
              <div class="chat-photo-preview" id="chatPhotoPreview" hidden><img id="chatPhotoImg" alt="Selected photo"><button type="button" id="chatPhotoRemove" aria-label="Remove photo" title="Remove photo">&times;</button></div>
              <div class="chat-bar">
                <label class="chat-icon-btn" title="Send a photo"><input type="file" name="image" id="chatPhoto" accept="image/jpeg,image/png,image/gif,image/webp" hidden><i class="fa-solid fa-image"></i></label>
                <textarea name="message" id="chatText" rows="1" placeholder="Aa"></textarea>
                <button type="button" class="chat-icon-btn" id="chatEmojiBtn" title="Choose an emoji" aria-expanded="false"><i class="fa-regular fa-face-smile"></i></button>
                <div class="chat-emoji-panel" id="chatEmojiPanel" hidden></div>
              </div>
            </div>
            <button type="submit" name="send_message" id="chatSend" class="chat-send" title="Send" value="1"><i class="fa-solid fa-paper-plane"></i></button>
            <button type="submit" name="send_message" id="chatLike" class="chat-like" title="Send a thumbs up" value="1">&#128077;</button>
          </form>
        <?php endif; ?>
      </section>
    </div>
  </div>
</main>
</div>
<script>
(function () {
  // Messenger-style behaviour: open thread scrolls to the latest message, and every few seconds the thread list
  // (newest activity on top, numeric unread badges) and the open thread refresh without a page reload.
  var thread = document.querySelector('.thread-messages');
  if (thread) thread.scrollTop = thread.scrollHeight;

  function swap(doc, selector) {
    var current = document.querySelector(selector), next = doc.querySelector(selector);
    if (!current || !next || current.innerHTML === next.innerHTML) return;
    var atBottom = current.scrollHeight - current.scrollTop - current.clientHeight < 80;
    current.innerHTML = next.innerHTML;
    if (selector === '.thread-messages' && atBottom) current.scrollTop = current.scrollHeight;
  }
  function refresh() {
    if (document.hidden) return;
    fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        swap(doc, '.conversation-list');
        if (!document.querySelector('.msg-editing, .msg-menu-list:not([hidden])')) swap(doc, '.thread-messages');
      })
      .catch(function () {});
  }
  setInterval(refresh, 7000);
})();
</script>
<script>
(function () {
  // Message box: emoji picker, photo attach with preview, and a thumbs up button (Messenger style).
  var form = document.querySelector('.message-form');
  if (!form || !document.getElementById('chatText')) return;
  var text = document.getElementById('chatText'), photo = document.getElementById('chatPhoto');
  var send = document.getElementById('chatSend'), like = document.getElementById('chatLike');
  var panel = document.getElementById('chatEmojiPanel'), emojiBtn = document.getElementById('chatEmojiBtn');
  var preview = document.getElementById('chatPhotoPreview'), previewImg = document.getElementById('chatPhotoImg');
  var EMOJI = '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😋 😜 🤪 😎 🤩 🥳 😏 😌 😴 🤔 🤗 🤭 😐 😶 🙄 😬 😮 😯 😲 😳 🥺 😢 😭 😤 😠 😡 🤯 😱 😨 😰 😥 🤒 🤕 🤧 😷 🥵 🥶 👍 👎 👌 ✌️ 🤞 🙏 👏 🙌 💪 👋 🤝 ❤️ 🧡 💛 💚 💙 💜 💯 ✅ ❌ ⚠️ 🔥 ⭐ 🎉 🚑 🚒 🩺 💊 🩹 🧯 🔦 📦 📋 📞 🌧️ ⛈️ 🌀 🌊 🏠'.split(' ');
  EMOJI.forEach(function (em) {
    var b = document.createElement('button');
    b.type = 'button'; b.textContent = em; b.title = em;
    b.addEventListener('click', function () {
      var st = text.selectionStart, en = text.selectionEnd;
      if (st == null) { st = en = text.value.length; }
      text.value = text.value.slice(0, st) + em + text.value.slice(en);
      text.focus();
      text.selectionStart = text.selectionEnd = st + em.length;
      update();
    });
    panel.appendChild(b);
  });
  function togglePanel(open) {
    panel.hidden = !open;
    emojiBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  emojiBtn.addEventListener('click', function (e) { e.stopPropagation(); togglePanel(panel.hidden); });
  document.addEventListener('click', function (e) {
    if (!panel.hidden && !e.target.closest('#chatEmojiPanel')) togglePanel(false);
  });

  // The send button shows when there is something to send; the thumbs up shows otherwise.
  function update() {
    var has = text.value.trim() !== '' || (photo.files && photo.files.length > 0);
    send.hidden = !has;
    like.hidden = has;
    text.style.height = '38px';
    text.style.height = Math.min(text.scrollHeight, 120) + 'px';
  }
  text.addEventListener('input', update);
  // Enter sends, Shift+Enter starts a new line.
  text.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (!send.hidden) send.click(); }
  });
  photo.addEventListener('change', function () {
    var f = photo.files && photo.files[0];
    if (f && f.size > 5 * 1024 * 1024) { alert('The photo is too large. Please choose a photo under 5 MB.'); photo.value = ''; f = null; }
    if (f) { previewImg.src = URL.createObjectURL(f); preview.hidden = false; } else { preview.hidden = true; }
    update();
  });
  document.getElementById('chatPhotoRemove').addEventListener('click', function () {
    photo.value = ''; preview.hidden = true; update();
  });
  like.addEventListener('click', function () { text.value = '👍'; });
  form.addEventListener('submit', function () {
    setTimeout(function () { send.disabled = true; like.disabled = true; }, 0);
  });
  update();
})();
</script>
<script>
(function () {
  // Edit / delete your own messages. Delegated, so it keeps working after the live refresh replaces the thread.
  var actForm = document.getElementById('msgActionForm');
  if (!actForm) return;
  function closeMenus(except) {
    document.querySelectorAll('.msg-menu-list').forEach(function (l) { if (l !== except) l.hidden = true; });
  }
  function submitAction(kind, id, text) {
    document.getElementById('msgActionId').value = id;
    document.getElementById('msgActionText').value = text || '';
    var k = document.getElementById('msgActionKind');
    k.name = kind; k.value = '1';
    actForm.submit();
  }
  document.addEventListener('click', function (e) {
    var menuBtn = e.target.closest('.msg-menu-btn');
    if (menuBtn) {
      var list = menuBtn.parentNode.querySelector('.msg-menu-list');
      closeMenus(list); list.hidden = !list.hidden; return;
    }
    if (!e.target.closest('.msg-menu-list')) closeMenus(null);

    var del = e.target.closest('.msg-delete');
    if (del) {
      closeMenus(null);
      if (window.confirm('Delete this message? It will show as removed for everyone in this conversation.')) submitAction('delete_message', del.dataset.id);
      return;
    }
    var edit = e.target.closest('.msg-edit');
    if (edit) {
      closeMenus(null);
      var bubble = document.querySelector('.message-bubble[data-msg-id="' + edit.dataset.id + '"]');
      if (!bubble || bubble.classList.contains('msg-editing')) return;
      bubble.classList.add('msg-editing');
      var p = bubble.querySelector('p'); if (p) p.hidden = true;
      var box = document.createElement('div'); box.className = 'msg-edit-box';
      var ta = document.createElement('textarea'); ta.value = bubble.getAttribute('data-msg-text') || ''; ta.maxLength = 5000;
      var acts = document.createElement('div'); acts.className = 'msg-edit-actions';
      acts.innerHTML = '<button type="button" class="msg-edit-cancel">Cancel</button><button type="button" class="msg-edit-save">Save</button>';
      box.appendChild(ta); box.appendChild(acts);
      bubble.insertBefore(box, bubble.querySelector('small'));
      ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length);
      acts.querySelector('.msg-edit-cancel').addEventListener('click', function () { box.remove(); if (p) p.hidden = false; bubble.classList.remove('msg-editing'); });
      var save = function () { submitAction('edit_message', edit.dataset.id, ta.value); };
      acts.querySelector('.msg-edit-save').addEventListener('click', save);
      ta.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); save(); }
        if (ev.key === 'Escape') acts.querySelector('.msg-edit-cancel').click();
      });
    }
  });
})();
</script>
</body>
</html>
