<#
.SYNOPSIS
  Keeps the stack's processes out of Windows power throttling ("efficiency mode"). Phase 8.

.DESCRIPTION
  Windows 11 runs processes it considers background - hidden windows, anything started from a
  background session such as Task Scheduler or the supervisor - at low quality of service: on a
  laptop with performance and efficiency cores they are held on the efficiency cores. Measured on
  the reference laptop: PHP under a throttled Apache ran 7 times slower (a dashboard answer went
  from about 50 ms to about 300 ms). This opts the given processes out, per process, through the
  documented SetProcessInformation(ProcessPowerThrottling) call: no administrator rights, nothing
  changed in the system's power settings, and it ends with the process.

  Called by scripts\api_server.ps1 (Apache), scripts\db.bat (PostgreSQL) and scripts\supervisor.ps1
  (every round, which also covers PostgreSQL's per-connection processes and restarted services).

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File scripts\no_throttle.ps1
  powershell -NoProfile -ExecutionPolicy Bypass -File scripts\no_throttle.ps1 -Names httpd
#>
[CmdletBinding()]
param(
    [string[]]$Names = @('httpd', 'postgres'),
    # The ai-service (python), the dashboards and the field server (node): by the ports they listen
    # on, so no other Python or Node program on the machine is touched.
    [int[]]$Ports = @(8001, 5173, 5180, 5443),
    [switch]$Quiet
)

if (-not ('SmgQos' -as [type])) {
    Add-Type -TypeDefinition @'
using System; using System.Runtime.InteropServices;
public static class SmgQos {
  [StructLayout(LayoutKind.Sequential)] struct State { public uint Version; public uint ControlMask; public uint StateMask; }
  [DllImport("kernel32.dll", SetLastError = true)] static extern IntPtr OpenProcess(uint access, bool inherit, int pid);
  [DllImport("kernel32.dll", SetLastError = true)] static extern bool SetProcessInformation(IntPtr h, int infoClass, ref State info, int size);
  [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr h);
  // ProcessPowerThrottling = 4; control EXECUTION_SPEED (1) with state 0 = never throttle this process.
  public static bool Exempt(int pid) {
    IntPtr h = OpenProcess(0x0200 /* PROCESS_SET_INFORMATION */, false, pid);
    if (h == IntPtr.Zero) return false;
    State s = new State(); s.Version = 1; s.ControlMask = 1; s.StateMask = 0;
    bool ok = SetProcessInformation(h, 4, ref s, Marshal.SizeOf(typeof(State)));
    CloseHandle(h);
    return ok;
  }
}
'@
}

$ids = @(Get-Process -Name $Names -ErrorAction SilentlyContinue | ForEach-Object Id)
if ($Ports) {
    $ids += @(Get-NetTCPConnection -LocalPort $Ports -State Listen -ErrorAction SilentlyContinue | ForEach-Object OwningProcess)
}
$done = 0
foreach ($id in ($ids | Where-Object { $_ -gt 4 } | Select-Object -Unique)) {
    if ([SmgQos]::Exempt([int]$id)) { $done++ }
}
if (-not $Quiet) { Write-Host "no power throttling for $done process(es)" }
