// Static and non-executing operator-path tests. No database/service/EAI start.
import {readFile,writeFile,mkdir,mkdtemp} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import assert from 'node:assert/strict';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {ENDPOINTS,testTarget,summarize} from '../tools/test-local-delivery.mjs';
import {CODE_ALLOWLIST,assertNoPrivateContent} from '../tools/build-replication-package.mjs';

const root=path.dirname(path.dirname(fileURLToPath(import.meta.url)));let checks=0;
const check=value=>{assert.ok(value,'DELIVERY_LAUNCHER_CHECK');checks++;};
const names=['01-Installer.cmd','02-Demarrer.cmd','03-Statut.cmd','04-Ajouter-client.cmd','05-Tester.cmd','06-Arreter.cmd'];
const actions=['Install','Start','Status','AddClient','Test','Stop'];
for(let i=0;i<names.length;i++){
    const relative='Installation/'+names[i],text=await readFile(path.join(root,relative),'utf8');
    check(text.includes('"%~dp0Lancer.ps1"')&&text.includes('-Action '+actions[i]));
    check(!/\b(?:del|erase|rd|rmdir)\b/i.test(text));
    check(CODE_ALLOWLIST.includes(relative));assertNoPrivateContent(relative,Buffer.from(text));checks++;
}
const launcher=await readFile(path.join(root,'Installation/Lancer.ps1'),'utf8');
check(launcher.includes("Split-Path $PSScriptRoot -Parent"));
check(launcher.includes('[IO.FileMode]::CreateNew')&&!/Remove-Item|Clear-Content|Set-ExecutionPolicy|Stop-Process/.test(launcher));
check(launcher.includes('$references.Count -eq 1')&&launcher.includes('Select service-plan.json'));
check(launcher.includes('-ValidateOnly')||launcher.includes('$ValidateOnly'));
check(!/Install-Suite-Autostart|Register-ScheduledTask|New-Service/.test(launcher));
check(launcher.includes("tools/Add-Suite-Client.ps1")&&launcher.includes("tools/test-local-delivery.mjs"));
for(const endpoint of ENDPOINTS){
    const target=testTarget('example-account','test-private&value',endpoint,123,new Date('2026-10-20T12:34:56Z'));
    const url=new URL('http://127.0.0.1'+target);check(url.searchParams.get('password')==='test-private&value');
    check(url.pathname==='/cweai/example-account/'+endpoint);
    if(['positions','canbus','telemetry'].includes(endpoint)){
        check(url.searchParams.get('ymdto')==='2026-10-20 12:34:56');
        check(url.searchParams.get('ymdfrom')==='2026-10-20'&&url.searchParams.get('vehicleid')==='123');
    }else check(!url.searchParams.has('vehicleid')&&!url.searchParams.has('ymdto'));
}
assert.throws(()=>testTarget('../private','test','vehicles',123));checks++;
assert.throws(()=>testTarget('example','test','vehiclebase',123));checks++;
assert.throws(()=>testTarget('example','test','positions',undefined));checks++;
check(summarize('vehicles',200,'[]').result==='PASS');
check(summarize('vehicles',200,'[{"CWVehicleID":123}]').rows===1);
check(summarize('vehicles',200,'[{"CWVehicleID":"123"}]').result==='FAIL');
check(summarize('vehicles',503,'').category==='HTTP_503');
check(summarize('positions',200,'<html>documentation</html>').category==='NON_JSON_RESPONSE');
check(summarize('positions',200,'API ERROR: getPositionsHistory function limit request per 1 minute reached[]').result==='WAIT');
check(summarize('vehicles',429,'',{'retry-after':'61'}).retryAfterSeconds===61);
const tester=await readFile(path.join(root,'tools/test-local-delivery.mjs'),'utf8');
check(tester.includes("hostname:'127.0.0.1'")&&!/collectSuite\(|https\.get\(|console\.log\(.*password/.test(tester));
check(tester.includes('responseBodiesRecorded:false')&&tester.includes('credentialsRecorded:false'));

const schemas=await readFile(path.join(root,'src/Contract.php'),'utf8'),contract=await readFile(path.join(root,'docs/API_CONTRACT.md'),'utf8');
for(const match of schemas.matchAll(/'(vehiclesBase|vehicles|positions|canbus|telemetry)'\s*=>\s*'([^']+)'/g))check(contract.includes(match[2]));
check(contract.includes('EngineRunningTime=`0`')&&contract.includes('EngineRunningTimeHHMM=`""`'));
check(contract.includes('empty HTTP 503')&&contract.includes('no proven maximum upstream historical depth'));
for(const relative of ['Installation/Lancer.ps1','tools/test-local-delivery.mjs','docs/QUICKSTART_COLLEGUE.md','docs/API_CONTRACT.md']){
    check(CODE_ALLOWLIST.includes(relative));assertNoPrivateContent(relative,await readFile(path.join(root,relative)));checks++;
}

let psChecks=0;
if(process.platform==='win32'){
    // Parse all scripts, then run only the ValidateOnly branch on new fictitious
    // configuration. No native server/runtime/bootstrap is ever started.
    const folder=path.join(root,'test-results');await mkdir(folder,{recursive:true});const fixture=await mkdtemp(path.join(folder,'launcher-static-'));
    const script=path.join(root,'Installation/Lancer.ps1'),parser=path.join(fixture,'parse.ps1');
    await writeFile(parser,"param([string]$Target)\n$tokens=$null;$errors=$null;$null=[Management.Automation.Language.Parser]::ParseFile($Target,[ref]$tokens,[ref]$errors);if($errors.Count){exit 1};Write-Output 'PARSED'\n",{flag:'wx'});
    let r=spawnSync('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',parser,'-Target',script],{encoding:'utf8',windowsHide:true});check(r.status===0&&r.stdout.includes('PARSED'));psChecks++;
    const planFile=path.join(fixture,'service-plan.json'),plan={profile:'LOCAL_EAI_SUITE_V1',directory:fixture,node:process.execPath,intervalSeconds:60};await writeFile(planFile,JSON.stringify(plan),{flag:'wx'});
    for(const action of ['Start','Status','AddClient','Test','Stop']){
        r=spawnSync('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',script,'-Action',action,'-PlanPath',planFile,'-NoDialogs','-ValidateOnly'],{encoding:'utf8',windowsHide:true});
        check(r.status===0&&r.stdout.includes('VALIDATION ONLY'));psChecks++;
    }
    const options=path.join(fixture,'options.json');await writeFile(options,JSON.stringify({node:process.execPath}),{flag:'wx'});
    r=spawnSync('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',script,'-Action','Install','-OptionsPath',options,'-NoDialogs','-ValidateOnly'],{encoding:'utf8',windowsHide:true});check(r.status===0&&r.stdout.includes('VALIDATION ONLY'));psChecks++;
    check(!JSON.stringify(await readFile(planFile,'utf8')).includes('requested'));psChecks++;
}
console.log(JSON.stringify({result:'PASS',suite:'delivery-no-admin-ui',checks,powershellNonExecutingChecks:psChecks,eaiCalls:0,oldApiCalls:0,databasesOpened:0,servicesStarted:0,actualDoubleClickTested:false,filesDeleted:0,productionReady:false}));
