# Build and run process

From the repository root in PowerShell:

```powershell
.\scripts\build-runtime.ps1
.\scripts\initialize-database.ps1 -AdminUsername owner -AdminPassword (Read-Host -AsSecureString)
.\scripts\start-axumera.ps1
.\scripts\verify-runtime.ps1
.\scripts\stop-axumera.ps1
```

`build-runtime.ps1 -Force` replaces only generated `build/runtime`; it never changes the protected source application. Database initialization refuses an existing data directory. Its schema and migrations run only against the generated private MariaDB data directory. The current controller is a Windows-native PowerShell controller architecture; a future `AxumeraServer.exe` wrapper may invoke these commands without changing the runtime contract.
