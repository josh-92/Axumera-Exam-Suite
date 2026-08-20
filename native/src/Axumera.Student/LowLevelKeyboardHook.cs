using System.Diagnostics;
using System.Runtime.InteropServices;

namespace Axumera.Student;

/// <summary>
/// Application-level kiosk keyboard guard (WH_KEYBOARD_LL).
///
/// Blocks OS/browser escapes that the WebView2 settings cannot reach:
/// Windows key, Alt+Tab, Alt+Esc, Ctrl+Esc, Ctrl+Shift+Esc, F11, and the
/// browser shortcut family (Ctrl+L/N/T/W/R, Ctrl+Shift+Tab).
///
/// Alt+F4 is deliberately NOT blocked — it is routed to the shell's close
/// interception so the "exam in progress" confirmation is always shown.
///
/// SECURITY REALITY: this is user-mode software. It strongly deters casual
/// escape during an exam, but it cannot defend against the Windows secure
/// attention sequence, an administrator, a system shutdown, or physical access.
/// A managed-device kiosk (Windows Assigned Access) is a future phase.
///
/// The hook is installed only while kiosk mode is active and removed on exit,
/// so the machine is never left in a locked state outside an exam.
/// </summary>
public sealed class LowLevelKeyboardHook : IDisposable
{
    private const int WhKeyboardLl = 13;
    private const int WmKeyDown = 0x0100;
    private const int WmSysKeyDown = 0x0104;

    // Virtual keys.
    private const int VkTab = 0x09;
    private const int VkReturn = 0x0D;
    private const int VkEscape = 0x1B;
    private const int VkLWin = 0x5B;
    private const int VkRWin = 0x5C;
    private const int VkMenu = 0x12;      // Alt
    private const int VkControl = 0x11;   // Ctrl
    private const int VkShift = 0x10;
    private const int VkF4 = 0x73;
    private const int VkF11 = 0x7A;
    private const int VkF12 = 0x7B;
    private const int VkL = 0x4C;
    private const int VkN = 0x4E;
    private const int VkT = 0x54;
    private const int VkW = 0x57;
    private const int VkR = 0x52;

    private HookProc? _hookProc;
    private IntPtr _hookHandle = IntPtr.Zero;

    public bool IsInstalled => _hookHandle != IntPtr.Zero;

    /// <summary>Installs the hook. Safe to call repeatedly (idempotent).</summary>
    public void Install()
    {
        if (_hookHandle != IntPtr.Zero)
        {
            return;
        }

        _hookProc = HookCallback;
        using var currentProcess = Process.GetCurrentProcess();
        using var currentModule = currentProcess.MainModule;
        if (currentModule is null)
        {
            return;
        }

        _hookHandle = SetWindowsHookEx(
            WhKeyboardLl,
            _hookProc,
            GetModuleHandle(currentModule.ModuleName),
            0);
    }

    /// <summary>Removes the hook. Safe to call repeatedly.</summary>
    public void Uninstall()
    {
        if (_hookHandle == IntPtr.Zero)
        {
            return;
        }

        UnhookWindowsHookEx(_hookHandle);
        _hookHandle = IntPtr.Zero;
        _hookProc = null;
    }

    private IntPtr HookCallback(int nCode, IntPtr wParam, IntPtr lParam)
    {
        if (nCode >= 0 && (wParam == WmKeyDown || wParam == WmSysKeyDown))
        {
            var data = Marshal.PtrToStructure<KbdLlHookStruct>(lParam);
            if (ShouldBlock(data.VkCode))
            {
                return (IntPtr)1; // swallow the key
            }
        }

        return CallNextHookEx(_hookHandle, nCode, wParam, lParam);
    }

    private static bool ShouldBlock(uint vkCode)
    {
        // Windows key.
        if (vkCode is VkLWin or VkRWin)
        {
            return true;
        }

        bool alt = IsKeyDown(VkMenu);
        bool ctrl = IsKeyDown(VkControl);
        bool shift = IsKeyDown(VkShift);

        // Alt+Tab / Alt+Esc — window switching.
        if (alt && vkCode is VkTab or VkEscape)
        {
            return true;
        }

        // Ctrl+Esc — Start menu. Ctrl+Shift+Esc — Task Manager.
        if (ctrl && vkCode == VkEscape)
        {
            return true;
        }

        // F11 — fullscreen toggle; F12 — developer tools (WebView2 already
        // disables devtools; this is defense in depth).
        if (vkCode is VkF11 or VkF12)
        {
            return true;
        }

        // Browser shortcut family.
        if (ctrl && vkCode is VkL or VkN or VkT or VkW or VkR)
        {
            return true;
        }

        // Ctrl+Tab / Ctrl+Shift+Tab — tab switching.
        if (ctrl && vkCode == VkTab)
        {
            return true;
        }

        // Alt+F4 is intentionally allowed through so the shell's close
        // interception (with its confirmation) always runs.
        _ = VkF4;
        _ = VkReturn;
        _ = shift;

        return false;
    }

    private static bool IsKeyDown(int vk) =>
        (GetAsyncKeyState(vk) & 0x8000) != 0;

    public void Dispose() => Uninstall();

    private delegate IntPtr HookProc(int nCode, IntPtr wParam, IntPtr lParam);

    [StructLayout(LayoutKind.Sequential)]
    private struct KbdLlHookStruct
    {
        public uint VkCode;
        public uint ScanCode;
        public uint Flags;
        public uint Time;
        public UIntPtr ExtraInfo;
    }

    [DllImport("user32.dll", SetLastError = true)]
    private static extern IntPtr SetWindowsHookEx(int idHook, HookProc lpfn, IntPtr hMod, uint dwThreadId);

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool UnhookWindowsHookEx(IntPtr hhk);

    [DllImport("user32.dll")]
    private static extern IntPtr CallNextHookEx(IntPtr hhk, int nCode, IntPtr wParam, IntPtr lParam);

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr GetModuleHandle(string? lpModuleName);

    [DllImport("user32.dll")]
    private static extern short GetAsyncKeyState(int vKey);
}
