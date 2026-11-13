<?php
/**
 * Outgoing email. Sends through Gmail SMTP when mail_config.php is filled in (see mail_config.sample.php);
 * otherwise every email is written to logs/mail.log so nothing is lost while testing on XAMPP.
 */

function mail_config() {
    static $config = null;
    if ($config !== null) return $config;
    $file = __DIR__ . '/mail_config.php';
    $loaded = is_file($file) ? include $file : [];
    $config = (is_array($loaded) ? $loaded : []) + ['host' => 'smtp.gmail.com', 'port' => 465, 'username' => '', 'password' => '', 'from_name' => 'Caloocan DRRM Office'];
    return $config;
}

function mail_configured() {
    $c = mail_config();
    return $c['username'] !== '' && $c['password'] !== '' && stripos($c['username'], 'YOUR_') === false && stripos($c['password'], 'YOUR_') === false;
}

// Minimal SMTP client (implicit SSL, AUTH LOGIN). Returns true when the server accepted the message.
function mail_smtp_send($to, $subject, $html_body) {
    $c = mail_config();
    $fp = @stream_socket_client('ssl://' . $c['host'] . ':' . (int)$c['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
    if (!$fp) { mail_log_error('connect: ' . $errstr); return false; }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) { $data .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
        return $data;
    };
    $cmd = function ($line, $expect) use ($fp, $read) {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $reply = $read();
        if ((int)substr($reply, 0, 3) !== $expect) throw new RuntimeException(trim($reply) ?: 'no reply');
        return $reply;
    };
    $user = (string)$c['username'];
    try {
        $cmd(null, 220);
        $cmd('EHLO drrmc.local', 250);
        $cmd('AUTH LOGIN', 334);
        $cmd(base64_encode($user), 334);
        $cmd(base64_encode(str_replace(' ', '', (string)$c['password'])), 235);
        $cmd('MAIL FROM:<' . $user . '>', 250);
        $cmd('RCPT TO:<' . $to . '>', 250);
        $cmd('DATA', 354);
        $headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode((string)$c['from_name']) . '?= <' . $user . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@drrmc.local>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $cmd(implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($html_body), 76, "\r\n")) . "\r\n.", 250);
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        // Never log the password; the server reply is enough to see what went wrong.
        mail_log_error('to ' . $to . ': ' . $e->getMessage());
        @fclose($fp);
        return false;
    }
}

function mail_log_error($text) {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/mail_errors.log', '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Branded email: orange/green header, a heading, the message, an optional button and the office footer.
 * $paragraphs are already-escaped HTML strings.
 */
function mail_layout($heading, array $paragraphs, $button_text = '', $button_url = '') {
    $p = '';
    foreach ($paragraphs as $html) $p .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#344054;">' . $html . '</p>';
    $btn = $button_text !== ''
        ? '<p style="margin:22px 0 6px;"><a href="' . e($button_url) . '" style="display:inline-block;padding:12px 22px;border-radius:8px;background:#ea7a00;color:#ffffff;font-weight:bold;text-decoration:none;font-size:15px;">' . e($button_text) . '</a></p>'
        : '';
    return '<!doctype html><html><body style="margin:0;padding:24px 12px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="padding:20px 28px;background:#ea7a00;background-image:linear-gradient(135deg,#ea7a00,#3f9b46);color:#ffffff;">'
        . '<div style="font-size:13px;letter-spacing:.5px;opacity:.9;">CALOOCAN CITY DRRM OFFICE</div>'
        . '<div style="font-size:20px;font-weight:bold;margin-top:4px;">' . e($heading) . '</div></td></tr>'
        . '<tr><td style="padding:26px 28px 18px;">' . $p . $btn . '</td></tr>'
        . '<tr><td style="padding:16px 28px 22px;border-top:1px solid #eaecf0;font-size:12px;line-height:1.5;color:#98a2b3;">'
        . 'DRRMC Inventory Management System &middot; South Caloocan<br>This is an automatic message. Please do not reply to this email.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Account emails, one place for the wording.
 * $kind: 'registered' (pending review) | 'approved' | 'rejected'. $u: fullname, email, role/position, barangay. $extra: reason (rejected).
 */
function mail_account_status($kind, array $u, $extra = '') {
    $name = e((string)($u['fullname'] ?? ''));
    $role = (string)($u['role'] ?? '');
    $is_drrmc = in_array($role, ['admin', 'official'], true) || in_array((string)($u['position'] ?? ''), ['DRRMC Staff', 'DRRMC Admin'], true);
    $barangay = trim((string)($u['barangay'] ?? ''));
    $account = $is_drrmc ? 'DRRMC Admin' : ('Barangay Staff' . (ctype_digit($barangay) ? ' &ndash; ' . e(barangay_label((int)$barangay)) : ''));
    $login = app_url('login.php');
    if ($kind === 'registered') {
        $subject = 'Registration received - waiting for approval';
        $html = mail_layout('Registration received', [
            'Hi ' . $name . ',',
            'Thank you for registering in the DRRMC Inventory Management System. Your account (<strong>' . $account . '</strong>) is now <strong style="color:#b54708;">pending approval</strong>.',
            'The City Head Admin will check your details and ID. We will email you again as soon as your account is approved.',
            'You do not need to register again. Until then, signing in with this Google account will show your pending status.',
        ]);
    } elseif ($kind === 'approved') {
        $subject = 'Your DRRMC account is approved';
        $html = mail_layout('Your account is approved', [
            'Hi ' . $name . ',',
            'Good news! Your account (<strong>' . $account . '</strong>) has been <strong style="color:#067647;">approved</strong> by the City Head Admin.',
            'You can now sign in with your Google account (<strong>' . e((string)$u['email']) . '</strong>).',
        ], 'Sign in to the DRRMC portal', $login);
    } else {
        $subject = 'Your DRRMC registration was not approved';
        $html = mail_layout('Registration not approved', array_merge([
            'Hi ' . $name . ',',
            'We are sorry, but your registration (<strong>' . $account . '</strong>) was <strong style="color:#b42318;">not approved</strong>.',
        ], trim((string)$extra) !== '' ? ['<strong>Reason:</strong> ' . e(trim((string)$extra))] : [], [
            'For questions, please contact the Caloocan City DRRM Office.',
        ]));
    }
    return send_mail((string)$u['email'], $subject, $html);
}
