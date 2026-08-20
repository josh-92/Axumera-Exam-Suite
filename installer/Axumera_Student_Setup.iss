; Student-only LAN client. It intentionally contains no PHP, Apache, MariaDB, or application files.
#define ProductName "Axumera Student"
#define ProductVersion "1.0.0"
#define BuildRoot "..\distribution\staging\Axumera_Student"

[Setup]
AppId={{D6EB14AE-8B5B-482B-B06D-2E58E4CF4D18}
AppName={#ProductName}
AppVersion={#ProductVersion}
DefaultDirName={autopf}\Axumera Student
DefaultGroupName={#ProductName}
OutputDir=..\distribution
OutputBaseFilename=Axumera_Student_Setup
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=lowest

[Files]
Source: "{#BuildRoot}\Axumera_Student.exe"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{group}\Axumera Student"; Filename: "{app}\Axumera_Student.exe"
Name: "{autodesktop}\Axumera Student"; Filename: "{app}\Axumera_Student.exe"
