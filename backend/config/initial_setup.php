<?php

return [
    // Store only a SHA-256 digest of the one-time owner setup key in the
    // deployment secret manager. The plaintext key is entered once in the UI.
    'key_hash' => env('INITIAL_SUPER_ADMIN_SETUP_KEY_HASH'),
];
