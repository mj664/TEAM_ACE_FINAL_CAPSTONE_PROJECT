<?php
/**
 * AI setup for ID card screening (registration) and receipt reading (Budget & Expenses).
 *
 * 1. Copy this file to  ai_config.php  (keep that copy private; never commit or share API keys).
 * 2. Gemini (default, free tier): open https://aistudio.google.com/apikey, sign in with a Google account,
 *    click "Create API key", and paste it into gemini_api_key below.
 *
 * Without a key the site still works: applicants type their details and the Head Admin reads the ID by eye.
 * Each scan sends the uploaded ID / receipt image to the chosen provider.
 */
return [
    // 'gemini' (Google) or 'anthropic' (Claude).
    'provider' => 'gemini',

    // Google Gemini
    'gemini_api_key' => 'YOUR_GEMINI_API_KEY',
    'gemini_model'   => 'gemini-2.5-flash', // if Google retires it, the newest available "flash" model is used automatically

    // Anthropic Claude (only used when provider is 'anthropic'; needs `php composer.phar install`)
    'api_key'  => 'YOUR_ANTHROPIC_API_KEY',
    'model'    => 'claude-opus-5',
    'base_url' => '',

    // Scans allowed per registration session (limits API use).
    'max_scans_per_session' => 5,
];
