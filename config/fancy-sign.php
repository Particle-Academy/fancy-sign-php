<?php

declare(strict_types=1);

/*
 * fancy-sign. Every limit has a secure default in FancySign\Config::DEFAULTS
 * and can be overridden under `limits` here, by the same keys.
 */
return [
    // Routes live under this prefix: /{prefix}/envelopes, /{prefix}/s/{token}/..., /{prefix}/verify.
    'prefix' => 'fancy-sign',

    // The host authenticates senders. The default needs a logged-in user.
    'sender_middleware' => ['web', 'auth'],

    // Signers have no account: their link token and a session after the view
    // code are the credentials. Add throttling here if the host wants more
    // than the built-in per-signer and per-IP code limits.
    'signer_middleware' => [],

    // The public verify endpoint and the public key.
    'public_middleware' => [],

    // The database connection for the fancy_sign_* tables (null: the default).
    'connection' => null,

    // The seal key: a base64 Ed25519 secret key (64 bytes). Generate one with
    //   php -r "echo base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), PHP_EOL;"
    // and keep it out of the repository. Its public key is served at /{prefix}/public-key.
    'seal' => [
        'secret_key' => env('FANCY_SIGN_SEAL_KEY'),
        // Older public keys (key_id => base64), so records sealed before a key
        // rotation still verify.
        'previous_public_keys' => [],
    ],

    // The host's signing page, where <SignerCeremony> runs. {token} is replaced
    // by the signer's link token. Default: {app.url}/sign/{token}.
    'signer_url' => env('FANCY_SIGN_SIGNER_URL'),

    // Largest upload the public verify endpoint reads, PDF and record together.
    'verify_max_bytes' => 20 * 1024 * 1024,

    // Overrides for FancySign\Config::DEFAULTS (otp, session_ttl_seconds,
    // mark, page_views, consent, adoption_text, ...).
    'limits' => [],
];
