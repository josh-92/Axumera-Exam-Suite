# Student deployment (Axumera 1.0)

Install `Axumera_Student_Setup.exe` on each student computer. Its package contains only `Axumera_Student.exe`; it contains no Apache, PHP, MariaDB, database, PHP application files, license, or server runtime.

On first launch, the student client proposes the remembered school server name (initially `axumera`). Enter the school server IP address or resolvable host name if it cannot connect. The client validates `/health.php`, remembers the successful address under the current user's Local AppData, then opens the existing `slogin.php` page in the default browser. Subsequent launches reuse the saved address.

If connection fails, check that the workstation is on the school LAN, the server is running, and TCP 8088 is reachable. The client deliberately does not scan arbitrary subnets; the current server product has no authenticated discovery protocol. This avoids noisy or unsafe network probing while retaining a simple manual recovery path.

The client is a launcher, not a secure exam browser. Fullscreen enforcement, focus monitoring, and suspicious-activity reporting remain future secure-browser work and are not claimed here.
