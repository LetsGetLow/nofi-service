<?php

namespace Nofi\Tests;

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// A throwaway JWT keypair for the test suite, so tests never depend on the
// real one in config/jwt and a fresh checkout can run them straight away.
$jwtDir = dirname(__DIR__) . '/var/jwt-test';
if (!is_file($jwtDir . '/private.pem')) {
    if (!is_dir($jwtDir)) {
        mkdir($jwtDir, 0o775, true);
    }

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private, $_SERVER['JWT_PASSPHRASE'] ?? 'testing');
    file_put_contents($jwtDir . '/private.pem', $private);
    file_put_contents($jwtDir . '/public.pem', openssl_pkey_get_details($key)['key']);
    chmod($jwtDir . '/private.pem', 0o600);
}
