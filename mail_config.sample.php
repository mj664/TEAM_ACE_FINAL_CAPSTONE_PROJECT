<?php
/**
 * Email (Gmail SMTP) setup: used for "registration received", "account approved" and "registration rejected" emails.
 *
 * 1. Copy this file to  mail_config.php  (that copy is git-ignored; never commit secrets).
 * 2. Use a Gmail account for the system (for example the DRRM Office's Gmail).
 * 3. Turn on 2-Step Verification for that Gmail: https://myaccount.google.com/security
 * 4. Create an App Password: https://myaccount.google.com/apppasswords  (name it "DRRMC System").
 *    Google shows a 16-letter password; paste it below (spaces are fine).
 * 5. Put the same Gmail address in 'username'.
 *
 * Until mail_config.php has real values, emails are not sent; they are written to logs/mail.log instead.
 */
return [
    'host'      => 'smtp.gmail.com',
    'port'      => 465,                        // SSL
    'username'  => 'YOUR_SYSTEM_GMAIL@gmail.com',
    'password'  => 'YOUR_16_LETTER_APP_PASSWORD',
    'from_name' => 'Caloocan DRRM Office',
];
