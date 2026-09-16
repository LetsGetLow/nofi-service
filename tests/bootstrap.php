<?php

namespace Nofi\Tests;

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Default the row-lock test's DSN to the dev database, same fallbacks as
// compose.yaml. getenv(), not Dotenv, since APP_ENV=test skips .env.local;
// putenv() so a proc_open() worker inherits it too.
if (getenv('NOFI_TEST_POSTGRES_DSN') === false) {
    putenv(sprintf(
        'NOFI_TEST_POSTGRES_DSN=postgresql://%s:%s@database:5432/%s?serverVersion=%s&charset=utf8',
        getenv('POSTGRES_USER') ?: 'app',
        getenv('POSTGRES_PASSWORD') ?: '!ChangeMe!',
        getenv('POSTGRES_DB') ?: 'app',
        getenv('POSTGRES_VERSION') ?: '16',
    ));
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
