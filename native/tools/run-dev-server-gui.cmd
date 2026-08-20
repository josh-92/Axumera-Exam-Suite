@echo off
rem Launches the Phase 2 development Axumera Server GUI.
rem The dev build is framework-dependent; the per-user .NET install is the runtime.
set "DOTNET_ROOT=%LOCALAPPDATA%\Microsoft\dotnet"
set "DOTNET_ROOT_X64=%LOCALAPPDATA%\Microsoft\dotnet"
"%~dp0..\build\Axumera.Server\Axumera.Server.exe" %*
