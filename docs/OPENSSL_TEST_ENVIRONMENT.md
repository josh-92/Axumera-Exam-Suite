# OpenSSL test-environment finding

Both the development PHP 8.2.12 runtime and generated PHP runtime loaded the OpenSSL extension and shipped matching `libcrypto-3-x64.dll` / `libssl-3-x64.dll`. A minimal `openssl_pkey_new` probe failed identically in both without an OpenSSL configuration path, reporting `error:80000003:system library::No such process`.

With `OPENSSL_CONF` set to the supplied `extras/openssl/openssl.cnf`, the same 2048-bit RSA probe succeeded in both runtimes. This rules out PHP extension loading, DLL mismatch, filesystem write permission, parameter selection, and unavailable entropy as the immediate cause.

The runtime builder now packages this configuration at `runtime/php/extras/openssl/openssl.cnf`. Production license verification does not generate keys and remains unchanged. The E2E bootstrap uses the configuration only to generate an ephemeral test key, then deletes that private key after signing a clone-local test license.
