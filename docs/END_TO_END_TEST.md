# End-to-end test record

## Isolation design

The E2E environment is a disposable `build/e2e-runtime` clone of a locally initialized synthetic runtime. It uses different loopback ports (Apache 8090, MariaDB 3310), a synthetic database and test administrator, and an ephemeral test-only RSA key pair and hardware-bound license inside the clone. The private signing key is deleted immediately after the test license is written. The production application source public key and production license state are not changed.

`scripts/new-e2e-environment.ps1` creates the clone. It must only be run after the base runtime has been initialized with synthetic credentials/data. The controller in the clone starts it, preserving the production licensing flow rather than bypassing it.

## Execution checklist

| Flow | Status | Evidence |
|---|---|---|
| Controller starts isolated stack | PASS | AxumeraServer.exe started clone on 8090/3310; `/health.php` returned HTTP 200 |
| Test-license verification | PASS | Clone-only RSA key and hardware-bound signed license accepted by the application |
| Admin login and dashboard | PASS | Synthetic administrator authenticated and admin dashboard loaded |
| Student login/session | PASS | Synthetic student session created |
| Exam loading, navigation and timer | PASS | Synthetic two-question live exam rendered with question payload and timer element |
| Autosave | PASS | `autosave.php` returned `status: success` and 1800 seconds remaining |
| Submission/result/report | PASS | `submit_exam.php` returned score 2/2; `review.php` and admin `analytics.php` returned HTTP 200 |

## Executed result — 2026-08-03

The clone was built from the synthetic base runtime, used the isolated 8090/3310 loopback ports, and did not read or copy customer data or a production license. The controller itself compiled and passed a separate base-runtime start/health/stop test.

The initial workflow attempt was blocked at synthetic-license creation. Investigation identified the cause: the bundled OpenSSL configuration was omitted from the generated PHP runtime, so `openssl_pkey_new` had no configuration path. The runtime build now includes `runtime/php/extras/openssl/openssl.cnf`; the E2E bootstrap sets `OPENSSL_CONF` only while generating its ephemeral test key. The helper is file-based (`scripts/e2e-sign-test-license.php`) so no fragile PHP CLI `-r` argument passing is involved.

The full HTTP workflow was executed on 2026-08-03 using only the disposable clone. Because the sandbox denies PHP’s normal WMI query, its clone-only test PATH contained a `wmic` shim returning the same synthetic machine identity used in the signed test license. This exercised the unmodified signature and hardware-ID comparison; production binaries, source, activation state, and signing keys were not changed. The shim must never ship.
