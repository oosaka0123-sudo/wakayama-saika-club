<?php
/* Copy to config.local.php on the server; never commit real secrets. */
return [
    'channel_secret' => '',
    'channel_access_token' => '',
    'admin_token' => '', // Set a randomly generated 32+ character token for admin login
    'public_base_url' => '', // e.g. https://example.org/saika/line-api
    'allowed_user_ids' => [], // LINE userIds for members; empty means nobody can post
    'github_origin' => 'https://oosaka0123-sudo.github.io'
];
