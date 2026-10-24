// Check startup-script structure and parse its PowerShell syntax without registering a task.
import {readFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.dirname(path.dirname(fileURLToPath(import.meta.url))),target=path.join(root,'service/Install-Server-Startup.ps1'),text=await readFile(target,'utf8');let checks=0;
const check=v=>{assert.ok(v,'SERVER_STARTUP_STATIC_CHECK');checks++;};
check(text.includes('if(-not $Register)'));
for(const operation of ['Get-Credential -UserName','Register-ScheduledTask -TaskName','Write-NewJson $intent'])check(text.indexOf('if(-not $Register)')<text.indexOf(operation));
check(text.includes('$ValidateOnly -and $Register'));
check(text.includes('BOOTSTRAP_WINDOWS_IDENTITY_REQUIRED')&&text.includes('CREDENTIAL_MUST_MATCH_BOOTSTRAP_IDENTITY'));
check(text.includes('-AtStartup')&&text.includes('-LogonType Password')&&text.includes('-RunLevel Limited'));
check(!/Register-ScheduledTask[^\r\n]*-Force|Start-ScheduledTask|Unregister-ScheduledTask|Remove-Item|RunAs|ExecutionPolicy Bypass/.test(text));
check(text.includes('OWN_SCHEDULED_TASK_ALREADY_EXISTS_NO_OVERWRITE'));
check(text.includes('SERVER_STARTUP_TRIGGER_VERIFICATION_FAILED')&&text.includes('SERVER_STARTUP_PRINCIPAL_VERIFICATION_FAILED'));
check(text.includes('task-before-server-startup-')&&text.includes('[IO.FileMode]::CreateNew'));
check(text.includes('scheduledTaskRegistered=$false;osConfigurationChanged=$false;filesWritten=0;credentialPrompted=$false'));
check(text.includes('windowsRebootOrLogonTested=$false')&&text.includes('$plainPassword=$null'));
let syntaxParsed=false;
if(process.platform==='win32'){
    const code="$tokens=$null;$errors=$null;$null=[Management.Automation.Language.Parser]::ParseFile('"+target.replaceAll("'","''")+"',[ref]$tokens,[ref]$errors);if($errors.Count){[Console]::Out.Write('FAIL');exit 1};[Console]::Out.Write('PARSED')";
    const r=spawnSync('powershell.exe',['-NoProfile','-NonInteractive','-Command',code],{encoding:'utf8',windowsHide:true});
    check(r.status===0&&r.stdout==='PARSED');syntaxParsed=true;
}
console.log(JSON.stringify({result:'PASS',suite:'server-startup-static-only',checks,syntaxParsed,installerInvoked:false,actualDryRunTested:false,actualRegistrationTested:false,actualRebootTested:false,installerExecution:'NOT_RUN_BY_STATIC_TEST',tasksRegistered:0,filesWritten:0,eaiCalls:0,productionReady:false}));
