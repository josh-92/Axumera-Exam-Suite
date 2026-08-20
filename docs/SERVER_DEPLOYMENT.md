# Server deployment (Axumera 1.0)

Install `Axumera_Setup.exe` once on the school server. It installs the private Apache/PHP/MariaDB runtime, application, `AxumeraServer.exe`, and `Axumera_Admin.exe`. It does not install XAMPP.

Complete first-run setup locally. The wizard initializes only an empty private MariaDB directory, imports `database/schema.sql`, creates the first administrator using PHP password hashing, and permanently locks itself after completion. Activate using a legitimate supplied license; no license is included with the installer.

Apache listens on the configured HTTP port (default `8088`) for trusted-school LAN clients. The installer adds one Private-profile inbound Windows Firewall rule for TCP `8088`. MariaDB remains bound to `127.0.0.1` and is never exposed to student computers. Record the server IPv4 address from the controller/Windows network settings and distribute it to student workstations.

`Axumera_Admin.exe` opens the local administrator login. If local health is unavailable, it prompts to start `AxumeraServer.exe` and waits for health before opening the default browser.

LAN HTTP is an approved 1.0 deployment model for trusted school networks only. Do not expose the port to the public Internet. Future HTTPS may be added at the Apache layer without application redesign.
