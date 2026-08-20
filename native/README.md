# Axumera 2.0 — Native Foundation (Phase 1)

Development area for the Axumera 2.0 family of native Windows applications.
This is a **foundation phase**: nothing here is a production component, and
nothing here touches the installed Axumera system.

## Phase 2 completion (2026-08-14)

`Axumera.Server` now controls the isolated development runtime only
(`native/dev-runtime`, Apache `8090`, MariaDB `3310`). It does not read,
start, stop, or modify the production installation or its ports (`8088` /
`3308`).

- It validates runtime files, reports port conflicts without killing their
  owners, generates runtime-specific configuration, verifies MariaDB via
  `mysqladmin ping`, Apache via TCP, and PHP/database via `health.php`.
- Stop/restart stops Apache then MariaDB, verifies free ports, clears state,
  and rolls back partial startup failures. PID state is cross-checked with
  real process, port, and health evidence so stale state is ignored.
- The light native Server UI exposes status, health, ports, controls, and
  diagnostics. Closing its UI does not stop the development runtime.
- Verification: build succeeded; all **48/48** unit tests passed; isolated
  integration **TEST A through TEST L** passed. Results are retained in
  `native/build/server-integration-results.txt`.

Verification fixes: stale development `apache.pid` is removed before Apache
launch; the PowerShell 5.1 integration harness normalizes duplicate `Path` /
`PATH` variables and tracks the managed GUI process directly.

## Phase 3: Control Panel integration (2026-08-14)

The native Control Panel is now a branded WinForms/WebView2 shell for the
existing PHP administrator application. It reuses the Phase 2
`ServerController` and loads the existing `adminlogin.php` only after the
configured runtime reports a healthy Running state.

- No address bar, tabs, browser chrome, external browser, credential bypass,
  or replacement PHP workflow is introduced.
- The exact approved application URL is constructed from `ports.json` through
  `ServerConfiguration` (`http://127.0.0.1:<configured-port>/adminlogin.php`);
  development verification uses 8090, never production 8088.
- WebView2 permits the virtual placeholder origin plus the single configured
  loopback Axumera application origin. Its user-data directory remains the
  isolated `WebView2/ControlPanel` profile.
- A compact native status strip exposes server, Apache, MariaDB, and health
  information with Start, Restart, and Diagnostics actions. Closing the
  Control Panel does not stop the runtime.
- Verification: the Control Panel compiled; the full suite passed (**49/49**:
  Core 15, Licensing 10, Server 24); development server and PHP
  `adminlogin.php` returned HTTP 200; a real Control Panel launch recorded
  splash, window, WebView2 initialization, approved admin-login navigation,
  page load, and clean exit code 0. The runtime was then stopped and 8090/3310
  were verified free.

- Production install: `C:\Program Files (x86)\Axumera Exam Suite` — **untouched**
- Production project artifacts (updater, launchers, PHP app, licenses) — **untouched**
- Official branding source: `C:\Axumera-Enginnering\branding` — **unmodified** (copies live in `assets/branding`)

---

## 1. C# architecture overview

```
Native shell (C# / WinForms)                     Existing PHP layer (unchanged foundation)
┌─────────────────────────────────────────────┐   ┌──────────────────────────────────────────┐
│ Axumera.Core      constants · branding ·    │   │ Apache 2.4.58 + PHP 8.2                  │
│                   paths · logging · IPC     │   │   ↓                                      │
│ Axumera.Ui        theme · splash ·          │   │ Axumera application (auth, exams,        │
│                   WebView2 host             │   │ question bank, grading, analytics,       │
│ Axumera.Licensing contracts (models only)   │   │ student mgmt)                            │
│ Axumera.Server    status dev shell          │   │   ↓                                      │
│ Axumera.ControlPanel  WebView2 shell        │   │ MariaDB 10.4                            │
│ Axumera.Student   WebView2 shell            │   └──────────────────────────────────────────┘
└─────────────────────────────────────────────┘
        │  Phase 2+: controlled local URLs (http://127.0.0.1:<port>/…)
        ▼
  WebView2 (Microsoft Edge runtime, embedded)
```

Phase 1 rule: the native shells do **not** load, start, stop, or modify the
production system. They demonstrate the foundation: branding, splash, theme,
WebView2 hosting with navigation restrictions, and native ↔ web messaging.

## 2. Project structure

```
native/
├── Axumera.slnx                      # .NET 10 solution (modern XML format)
├── Directory.Build.props             # common build settings (version 0.1.0, nullable, etc.)
├── assets/branding/                  # copies of the official assets (originals untouched)
│   ├── icon/  (axumera, server, student, control panel .ico)
│   └── logo/  (horizontal + symbol, PNG/SVG)
├── src/
│   ├── Axumera.Core/                 # shared, UI-free library (net10.0)
│   ├── Axumera.Ui/                   # shared WinForms components (net10.0-windows)
│   ├── Axumera.Licensing/            # licensing contracts & pure rules (net10.0)
│   ├── Axumera.Server/               # status dev shell (WinExe)
│   ├── Axumera.ControlPanel/         # WebView2 shell (WinExe)
│   └── Axumera.Student/              # WebView2 shell (WinExe)
├── tests/
│   ├── Axumera.Core.Tests/           # xunit (14 tests)
│   ├── Axumera.Licensing.Tests/      # xunit (10 tests)
│   └── Axumera.Server.Tests/         # xunit (4 tests)
└── build/                            # isolated dev output + DEVELOPMENT_BUILD.txt
```

**Structure adjustment (documented):** an extra `src/Axumera.Ui` project was added to the
proposed layout. Rationale: the shared UI (theme, splash, WebView2 host) is real shared code,
but putting it in `Axumera.Core` would make Core Windows-only and untestable without WinForms.
`Axumera.Core` stays pure (net10.0, no UI dependency); all Windows/WinForms code lives in
`Axumera.Ui`. The solution file is `Axumera.slnx` (the .NET 10 default XML format).

## 3. Framework choice and reasoning

- **.NET 10 (LTS, supported to Nov 2028)** — current long-term-support release, chosen over
  .NET Framework 4.8 (the only in-box option, whose compiler is C# 5) to satisfy the
  "modern supported C#/.NET" requirement. The SDK is installed **per-user** at
  `%LOCALAPPDATA%\Microsoft\dotnet` (no admin, no machine-wide change).
- **WinForms over WPF** — deliberate choice after inspecting the existing launchers
  (`launchers/AxumeraAdmin.cs`, `AxumeraStudent.cs`): they are WinForms. The new shells are
  thin branded hosts around WebView2 with simple status surfaces — no data-bound XAML
  complexity is needed, WinForms keeps the technology family consistent, and it is fully
  supported on modern .NET. WPF remains an option for richer internal UI in later phases.
- **WebView2** — Microsoft-supported embedded browser engine (Evergreen runtime, same engine
  as Edge). Installed runtime on this machine: 151.0.4129.78; SDK pinned to the matching
  `1.0.4129.50`. Suitable for offline school environments (the runtime is a single Microsoft
  component; bundling strategy is a later-phase deployment decision).
- **No third-party UI frameworks** — dependencies are Microsoft-only: WebView2 SDK and the
  xunit test stack.

## 4. Server architecture (`Axumera.Server.exe`)

Phase 1 is a safe **status dev shell** (no process control):
- Splash → main form with a status card listing Apache / PHP / MariaDB as "not managed in
  dev shell", reference ports (Apache 8088, MariaDB 3308), licensing row, and a note.
- Start / Stop / Restart buttons are **disabled** with tooltips explaining Phase 2 wiring.
- `Diagnostics/ServerDevStatus.cs` holds the pure, unit-tested status report model.
- The production `AxumeraServer.exe` controller is never invoked, replaced, or modified.

## 5. Control Panel architecture (`Axumera.ControlPanel.exe`)

- Branded shell (gold accent header, official logo, "DEVELOPMENT BUILD" badge, light theme,
  official control-panel icon), WebView2 filling the content area, native status bar, footer.
- Phase 1 loads **only** the safe local placeholder page (`https://axumera.dev/index.html`
  via a virtual-host folder mapping). It does **not** navigate to the production admin panel.
- No address bar, no browser chrome; hardened WebView2 settings (no devtools, no context
  menus, no zoom, no external drops).

## 6. Student architecture (`Axumera.Student.exe`)

Same branded WebView2 shell as the Control Panel with the student icon and naming. Loads the
safe placeholder only. The production exam UI and `Axumera_Student.exe` are untouched.

## 7. WebView2 architecture (`Axumera.Ui/AxumeraWebView2Host.cs`)

Reusable host control providing:
- **Strict navigation restrictions** — allowlist of origins (`http://127.0.0.1`,
  `http://localhost`, `https://axumera.dev`); anything else is cancelled and reported.
- **Session isolation** — per-application user-data folder under
  `%LOCALAPPDATA%\Axumera 2.0\WebView2\<App>`.
- **Loading states & error handling** — native overlay while loading; visible error text on
  navigation failure.
- **Native ↔ web messaging** — `WebMessage` / `WebMessageChannel` (Axumera.Core, UI-free and
  unit-tested): pages post `{type:"ready"}` etc.; native handlers reply; round-trips logged.
- **Hardened settings** — devtools, context menus, zoom, browser accelerator keys, and
  external drops all disabled; `DenyCors` on the virtual host mapping.
- Content in Phase 1: a self-contained, branded **safe placeholder page** (embedded
  resource) — never the production UI.

## 8. Native / PHP responsibility boundary

| Native (C#) — now and future | Existing PHP — unchanged |
|---|---|
| Windows app lifecycle, windows, splash, theme | Authentication (admin + student) |
| WebView2 host control, navigation policy, messaging | Exam logic, question bank, exam creation |
| Server/process control (Phase 2+, via existing controller semantics) | Grading, results, analytics |
| Licensing authority (future; contracts only today) | Student management, workflows |
| Updates / installer integration (future) | Database operations |

Phase 1 does **not** duplicate any PHP business logic in C#.

## 9. Licensing boundary

`Axumera.Licensing` is **contracts and pure rules only** — no enforcement, no keys, no
production `license.lic` / `app/Keys` / `License.php` interaction:
- `LicenseState`, `ActivationState`, `LicenseInfo`, `LicenseValidationResult`
- `ILicenseStore`, `ILicenseValidator`, `ILicenseProvider`, `IMachineIdProvider`
- `LicensingRules` (pure expiry/state derivation) and `MachineId` (fingerprint normalization)
- Fakes used by tests live **in the test project only** — nothing resembling a bypass is
  shipped in the library. Production licensing is untouched and remains authoritative.

## 10. Branding implementation

- Official palette constants in `Axumera.Core.Branding.AxumeraBrand`: Gold `#D3A029`,
  Deep Navy `#0C2036`, White `#FFFFFF`; also exposed as WinForms `Color`s in
  `Axumera.Ui.Theme` (light theme only — no dark mode, no OS theme switching).
- Official icons embedded per app (`ApplicationIcon` + embedded resource, used for the
  window icon) and the official horizontal logo on each header; the symbol is used on the
  splash. Assets are copies; the `branding/` originals are byte-identical (verified).

## 11. Build instructions

Prerequisites: Windows 10/11, .NET SDK 10 (per-user install via
`dotnet-install.ps1 -Channel LTS` works), WebView2 Evergreen runtime, nuget.org reachable
for the first restore.

```powershell
cd C:\Axumera-Enginnering\native
$env:PATH = "$env:LOCALAPPDATA\Microsoft\dotnet;$env:PATH"
dotnet build Axumera.slnx -c Release        # builds all 9 projects
dotnet test  Axumera.slnx -c Release        # runs all unit tests
dotnet publish src/Axumera.Server/Axumera.Server.csproj        -c Release -o build/Axumera.Server
dotnet publish src/Axumera.ControlPanel/Axumera.ControlPanel.csproj -c Release -o build/Axumera.ControlPanel
dotnet publish src/Axumera.Student/Axumera.Student.csproj      -c Release -o build/Axumera.Student
```

Dev output goes to `native/build/` (clearly marked `DEVELOPMENT_BUILD.txt`). To run the
framework-dependent dev builds, set `DOTNET_ROOT=%LOCALAPPDATA%\Microsoft\dotnet`.

## 12. Test results (2026-08-13)

- Solution build: **succeeded, 0 errors** (9 projects).
- Unit tests: **28/28 passed** — Core 14, Licensing 10, Server 4.
- Launch verification (automated driver): all three apps show a window, log
  `splash-shown` + `main-form-shown`, WebView2 apps log `webview-init-complete`,
  `page-loaded`, and `message-roundtrip-ok`, close cleanly via `CloseMainWindow` with
  **exit code 0**, and embed their official icons (verified 32×32).

## 13. Dependencies

- `Microsoft.Web.WebView2` 1.0.4129.50 (WinForms + Core)
- `Microsoft.NET.Test.Sdk` 18.8.1, `xunit` 2.9.3, `xunit.runner.visualstudio` 3.1.5 (tests)
- .NET 10 SDK (10.0.400) — per-user toolchain, not part of the project or product
- No other third-party packages.

## 14. Known limitations

- Framework-dependent dev builds require the .NET 10 runtime; self-contained packaging for
  offline schools is a Phase 2+ deployment decision.
- WebView2 host loads only the safe placeholder; production URLs are allowlisted but not yet
  wired (Phase 2).
- Server shell shows status placeholders; no real health/process integration yet (Phase 2).
- One benign build warning (MSB3277 WindowsBase unification from the WebView2 package's WPF
  lib; the WinForms runtime is unaffected).
- `ProductVersion` in UI code must be fully qualified (`Axumera.Core.Versioning.ProductVersion`)
  because the WinForms implicit `global using System.Windows.Forms;` shadows the name.
- The messaging round-trip currently answers `ready`/`ping` with `pong` only; the real
  native ↔ PHP API contract is designed in Phase 2.

## 15. Recommended Phase 2 plan

1. **Server** — wire Start/Stop/Restart through the production controller semantics
   (spawn `AxumeraServer.exe start|stop`, wait for ports), surface real Apache/PHP/MariaDB
   status, health-check polling, LAN IP and log tailing.
2. **Control Panel** — controlled navigation to the production admin panel at
   `http://127.0.0.1:<port>/adminlogin.php` with the allowlist enforced; session hand-off;
   native confirmation dialogs around destructive admin actions (improving UX without
   changing workflows).
3. **Student** — controlled navigation to the exam UI; fullscreen/exit-protection handled
   natively; keep the familiar exam workflow.
4. **Licensing** — implement `ILicenseValidator`/`IMachineIdProvider` against the existing
   license format (read-only initially), moving validation authority into C# while the PHP
   gate remains until parity is proven.
5. **Packaging** — self-contained publish or runtime-bundle strategy for offline schools;
   installer/updater integration; icon/version resources finalized.
6. **Integration tests** — full matrix against a scratch installation before anything is
   considered for production replacement.
