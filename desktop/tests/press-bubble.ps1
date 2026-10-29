# Presses a button in a bubble of the Windows app's WebView2 (Edge's own windows, such as
# "Save your password?"), the way a person does, through UI Automation. Used by app_test.mjs.
#   powershell -File press-bubble.ps1 <app pid> [title] [button]   -> pressed | no bubble | buttons: ...
param([int]$procId, [string]$title = 'Save your password?', [string]$button = 'Save')
Add-Type -Namespace W -Name E -MemberDefinition @'
public delegate bool P(System.IntPtr h, System.IntPtr l);
[System.Runtime.InteropServices.DllImport("user32.dll")] public static extern bool EnumWindows(P p, System.IntPtr l);
[System.Runtime.InteropServices.DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(System.IntPtr h, out uint pid);
[System.Runtime.InteropServices.DllImport("user32.dll")] public static extern bool IsWindowVisible(System.IntPtr h);
[System.Runtime.InteropServices.DllImport("user32.dll", CharSet=System.Runtime.InteropServices.CharSet.Unicode)] public static extern int GetClassName(System.IntPtr h, System.Text.StringBuilder s, int n);
[System.Runtime.InteropServices.DllImport("user32.dll", CharSet=System.Runtime.InteropServices.CharSet.Unicode)] public static extern int GetWindowText(System.IntPtr h, System.Text.StringBuilder s, int n);
[System.Runtime.InteropServices.DllImport("user32.dll")] public static extern bool GetWindowRect(System.IntPtr h, out RECT r);
public struct RECT { public int L, T, R, B; }
'@
$all = Get-CimInstance Win32_Process | Select-Object ProcessId, ParentProcessId
$ids = @($procId); $more = $true
while ($more) { $kids = @($all | Where-Object { $ids -contains $_.ParentProcessId -and $ids -notcontains $_.ProcessId } | ForEach-Object { [int]$_.ProcessId }); $more = $kids.Count -gt 0; $ids += $kids }
$bubble = $null
for ($k = 0; $k -lt 40 -and -not $bubble; $k++) {
  $script:out = New-Object System.Collections.ArrayList
  [W.E]::EnumWindows({ param($h, $l) $p = 0; [void][W.E]::GetWindowThreadProcessId($h, [ref]$p); if ($ids -contains [int]$p -and [W.E]::IsWindowVisible($h)) { $c = New-Object System.Text.StringBuilder 256; $t = New-Object System.Text.StringBuilder 256; [void][W.E]::GetClassName($h, $c, 256); [void][W.E]::GetWindowText($h, $t, 256); $r = New-Object W.E+RECT; [void][W.E]::GetWindowRect($h, [ref]$r); [void]$script:out.Add("$h pid=$p class=$c text=[$t] rect=$($r.L),$($r.T),$($r.R),$($r.B)") }; $true }, [System.IntPtr]::Zero) | Out-Null
  
  $bubble = $script:out | Where-Object { $_.Contains("text=[$title]") } | Select-Object -First 1
  if (-not $bubble) { Start-Sleep -Milliseconds 250 }
}
Add-Type -AssemblyName UIAutomationClient, UIAutomationTypes
if (-not $bubble) { 'no bubble'; exit }
$h = [System.IntPtr][long]($bubble -split ' ')[0]
$el = [System.Windows.Automation.AutomationElement]::FromHandle($h)
$cond = New-Object System.Windows.Automation.AndCondition(
  (New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ControlTypeProperty, [System.Windows.Automation.ControlType]::Button)),
  (New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, $button)))
for ($i = 0; $i -lt 10; $i++) {
  $b = $el.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $cond)
  if ($b) { $b.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke(); 'pressed'; exit }
  Start-Sleep -Milliseconds 300
}
'buttons: ' + (($el.FindAll([System.Windows.Automation.TreeScope]::Descendants, (New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ControlTypeProperty, [System.Windows.Automation.ControlType]::Button))) | ForEach-Object { $_.Current.Name }) -join '|')
