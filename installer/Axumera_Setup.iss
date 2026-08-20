; Requires Inno Setup 6. This source is intentionally not an updater.
#define ProductName "Axumera Exam Suite"
#define ProductVersion "1.0.0"
#define BuildRoot "..\distribution\staging\Axumera"

[Setup]
AppId={{4E5B38A1-9775-4BF5-9FA6-C450A1C1FEFE}
AppName={#ProductName}
AppVersion={#ProductVersion}
DefaultDirName={autopf}\Axumera Exam Suite
DefaultGroupName={#ProductName}
OutputDir=..\distribution
OutputBaseFilename=Axumera_Setup
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=admin
UninstallDisplayName={#ProductName}

[Files]
; Application/runtime files are replaceable. The setup intentionally ships no
; initialized MariaDB data, .env, license, logs, or synthetic test material.
Source: "{#BuildRoot}\runtime\*"; DestDir: "{app}\runtime"; Flags: recursesubdirs ignoreversion
Source: "{#BuildRoot}\application\*"; DestDir: "{app}\application"; Flags: recursesubdirs ignoreversion
Source: "{#BuildRoot}\AxumeraServer.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#BuildRoot}\Axumera_Admin.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#BuildRoot}\Axumera_Update.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#BuildRoot}\config\ports.json"; DestDir: "{app}\config"; Flags: onlyifdoesntexist

Source: "..\scripts\initialize-database.ps1"; DestDir: "{app}\scripts"; Flags: ignoreversion
Source: "..\scripts\Axumera.Runtime.psm1"; DestDir: "{app}\scripts"; Flags: ignoreversion

[Dirs]
Name: "{app}\data\mariadb"; Flags: uninsneveruninstall
Name: "{app}\data\tmp"; Flags: uninsneveruninstall
Name: "{app}\logs"; Flags: uninsneveruninstall
Name: "{app}\config"; Flags: uninsneveruninstall
Name: "{app}\license"; Flags: uninsneveruninstall

[Icons]
Name: "{group}\Axumera Server"; Filename: "{app}\AxumeraServer.exe"; Parameters: "start"
Name: "{group}\Axumera Admin"; Filename: "{app}\Axumera_Admin.exe"
Name: "{autodesktop}\Axumera Admin"; Filename: "{app}\Axumera_Admin.exe"
Name: "{group}\Complete Axumera Setup"; Filename: "http://127.0.0.1:8088/installer/install.php"

[Run]
; `setup` refuses an existing data directory, .env, or installed lock.  It
; starts the new private runtime and opens the loopback-only setup wizard;
; administrator credentials remain exclusively in that browser form.
Filename: "{app}\AxumeraServer.exe"; Parameters: "setup"; Flags: nowait postinstall skipifsilent
Filename: "{sys}\netsh.exe"; Parameters: "advfirewall firewall add rule name=""Axumera Exam Suite LAN"" dir=in action=allow protocol=TCP localport=8088 profile=private"; Flags: runhidden

[UninstallRun]
Filename: "{sys}\netsh.exe"; Parameters: "advfirewall firewall delete rule name=""Axumera Exam Suite LAN"""; Flags: runhidden

[Code]
function InitializeSetup(): Boolean;
begin
  Result := True;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if (CurPageID = wpSelectDir) and DirExists(ExpandConstant('{app}\data\mariadb')) then begin
    MsgBox('Existing Axumera data was detected. This installer is for a fresh installation only and will not modify that directory. Use the future update/repair workflow instead.', mbError, MB_OK);
    Result := False;
  end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usUninstall then
    MsgBox('Customer data, configuration, activation state, and logs are preserved. Remove them only through a separate, explicit data-removal operation.', mbInformation, MB_OK);
end;
