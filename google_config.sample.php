<?php
/**
 * Google Sign-In setup
 *
 * 1. Copy this file to  google_config.php  (that copy is git-ignored; never commit secrets).
 * 2. Go to https://console.cloud.google.com/ and create/select a project.
 * 3. APIs & Services > OAuth consent screen: configure it (External is fine for testing; add your
 *    Google accounts under "Test users").
 * 4. APIs & Services > Credentials > Create credentials > OAuth client ID > "Web application".
 * 5. Under "Authorized redirect URIs" add EXACTLY the URI shown on the login page setup notice, e.g.
 *      http://localhost/Capstone_(Updated)/google_callback.php
 * 6. Paste the Client ID and Client secret below.
 *
 * Until google_config.php exists with real values, the login page stays in setup mode and shows a
 * temporary email/password form so nobody is locked out. Once configured, password sign-in is disabled.
 */
return [
    'client_id'     => 'YOUR_CLIENT_ID.apps.googleusercontent.com',
    'client_secret' => 'YOUR_CLIENT_SECRET',
    // Leave empty to build the redirect URI from the current address automatically.
    'redirect_uri'  => '',
];
