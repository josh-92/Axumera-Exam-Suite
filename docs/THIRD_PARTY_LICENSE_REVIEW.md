# Third-party license review

This is an inventory for counsel/release review, not legal advice or a statement of compliance.

| Component | Source included | License evidence to verify before commercial shipment |
|---|---|---|
| Apache HTTP Server 2.4.58 | `runtime-source/apache` | Apache License 2.0 and bundled module notices |
| PHP 8.2.12 | `runtime-source/php/license.txt` | PHP License and bundled dependency notices |
| MariaDB 10.4.32 | `runtime-source/mariadb/COPYING`, `THIRDPARTY` | GPL obligations, commercial distribution position, and dependency notices |
| MathJax | `application/.../assets/vendor/mathjax` | MathJax license and included package notice |
| OpenSSL/cURL/APR/PCRE and runtime DLLs | Apache/PHP runtime trees | exact notices and redistribution terms |

Before release, inventory every copied DLL, frontend asset, font and any later installer library; preserve required notices and obtain legal confirmation for the intended commercial distribution.
