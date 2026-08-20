using System.Net.Sockets;
using System.Runtime.InteropServices;

namespace Axumera.Core.Server;

/// <summary>
/// Read-only port diagnostics. Used before startup to detect conflicts and during
/// monitoring to verify liveness. Never kills or touches the owning process.
/// </summary>
public static class PortProbe
{
    public static bool IsListening(int port, int timeoutMs = 1200)
    {
        try
        {
            using var client = new TcpClient();
            var result = client.BeginConnect("127.0.0.1", port, null, null);
            if (!result.AsyncWaitHandle.WaitOne(timeoutMs))
            {
                return false;
            }

            client.EndConnect(result);
            return true;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// Owning PID of the TCP listener on <paramref name="port"/> (IPv4), or null
    /// when the port is free or the owner cannot be determined.
    /// </summary>
    public static int? GetOwningProcessId(int port)
    {
        if (!OperatingSystem.IsWindows())
        {
            return null;
        }

        int bufferSize = 0;
        uint result = GetExtendedTcpTable(IntPtr.Zero, ref bufferSize, false, 2 /* AF_INET */, TcpTableClass.TcpTableOwnerPidAll, 0);
        if (result != 0 && result != 122 /* ERROR_INSUFFICIENT_BUFFER */)
        {
            return null;
        }

        var buffer = Marshal.AllocHGlobal(bufferSize);
        try
        {
            result = GetExtendedTcpTable(buffer, ref bufferSize, false, 2, TcpTableClass.TcpTableOwnerPidAll, 0);
            if (result != 0)
            {
                return null;
            }

            int rowCount = Marshal.ReadInt32(buffer);
            IntPtr row = buffer + 4;
            for (int i = 0; i < rowCount; i++)
            {
                // MIB_TCPROW_OWNER_PID (24 bytes): dwState(4) dwLocalAddr(4) dwLocalPort(4)
                // dwRemoteAddr(4) dwRemotePort(4) dwOwningPid(4). Ports are network byte order.
                int state = Marshal.ReadInt32(row);
                int localPortNetwork = Marshal.ReadInt32(row + 8);
                int localPort = ((localPortNetwork & 0xFF) << 8) | ((localPortNetwork >> 8) & 0xFF);
                if (state == 2 /* MIB_TCP_STATE_LISTEN */ && localPort == port)
                {
                    return Marshal.ReadInt32(row + 20);
                }

                row += 24;
            }

            return null;
        }
        finally
        {
            Marshal.FreeHGlobal(buffer);
        }
    }

    private enum TcpTableClass
    {
        TcpTableBasicListener = 0,
        TcpTableOwnerPidAll = 5,
    }

    [DllImport("iphlpapi.dll", SetLastError = true)]
    private static extern uint GetExtendedTcpTable(
        IntPtr tcpTable,
        ref int tcpTableLength,
        bool sort,
        int ipVersion,
        TcpTableClass tableClass,
        int reserved);
}
