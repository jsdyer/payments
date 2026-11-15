<?php
// Copy to config.local.php (gitignored), or use environment variables.
return [
    'consumer_key' => getenv('RTR_CONSUMER_KEY') ?: '',
    'consumer_secret' => getenv('RTR_CONSUMER_SECRET') ?: '',
    // Required when serving beyond localhost. Use HTTPS and a private network.
    'app_password' => getenv('RTR_APP_PASSWORD') ?: '',
];
