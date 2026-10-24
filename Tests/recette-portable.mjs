// Acceptance tests use fictitious accounts with local DPAPI, SQL and HTTP.
import {readFile,writeFile,mkdir,mkdtemp} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import {once} from 'node:events';
import {spawn} from 'node:child_process';
import http from 'node:http';
import {packageRoot,runtime,verifyPackage,runtimePreflight,restrictEnvironment} from '../Code/tools/portable.mjs';
import {apiRoot,execute,phpArgs} from '../Code/service/runtime.mjs';
import {localDependencies} from '../Code/tools/add-suite-client.mjs';
import {suiteDatabase} from '../Code/service/suite-runtime.mjs';
import {contractHttp} from './contract-http.mjs';
import {createConnection} from 'node:net';

const pause=ms=>new Promise(r=>setTimeout(r,ms));
const get=(port,target)=>new Promise((resolve,reject)=>{const q=http.get({host:'127.0.0.1',port,path:target},r=>{let body='';r.on('data',b=>body+=b);r.on('end',()=>resolve({status:r.statusCode,body}));});q.on('error',reject);q.setTimeout(4000,()=>q.destroy(Error('LOCAL_HTTP_TIMEOUT')));});
const target=(sheet,ep,id)=>'/cweai/'+sheet.username+'/'+ep+'?password='+encodeURIComponent(sheet.password)+(['positions','canbus','telemetry'].includes(ep)?'&vehicleid='+id+'&ymdfrom=2000-01-01&ymdto=2099-01-01':'');
let directory,web,sqlUp=false,deps,plan,backgroundStarted=false,checks=0;const report={result:'FAIL',suite:'portable-bundled-runtime-acceptance',fictitiousSourceOnly:true,realEaiCalls:0,oldApiCalls:0,existingDatabasesOpened:0,filesDeleted:0,actualOtherMachineTested:false,actualWindowsRebootTested:false,productionReady:false};
const check=(v,label='PORTABLE_ACCEPTANCE_CHECK')=>{assert.ok(v,label);checks++;};
async function control(action){return execute(runtime.node,[path.join(apiRoot,'service/control-suite.mjs'),path.join(plan.directory,'service-plan.json'),action],'',170000,apiRoot);}
async function portClosed(port){return new Promise(resolve=>{const c=createConnection({host:'127.0.0.1',port});c.setTimeout(1000);c.on('connect',()=>{c.destroy();resolve(false);});c.on('error',e=>{c.destroy();resolve(e.code==='ECONNREFUSED');});c.on('timeout',()=>{c.destroy();resolve(false);});});}
async function backgroundState(wanted){for(let i=0;i<100;i++){try{const s=JSON.parse(await readFile(path.join(plan.directory,'service-status.json'))),w=JSON.parse(await readFile(path.join(plan.directory,'supervisor-status.json')));if(s.stage===wanted&&w.stage===wanted&&Date.now()-Date.parse(w.utc)<15000)return {worker:s,supervisor:w};}catch{}await pause(500);}throw Error('BACKGROUND_STATE_NOT_CONFIRMED');}
try{
 restrictEnvironment();assert.ok(packageRoot.length<=80,'SHORT_EXTRACTED_PACKAGE_REQUIRED');report.package=await verifyPackage();report.runtime=await runtimePreflight();
 const parent=path.join(packageRoot,'Essais-locaux');await mkdir(parent,{recursive:true});directory=await mkdtemp(path.join(parent,'r-'));report.directory=directory;
 await mkdir(path.join(apiRoot,'test-results'),{recursive:true});
 console.log('0/4: HTTP contract, dates, errors and fixture mappings, with PHP 8.2 and 5.6.');
 report.offlineRegression=[];
 for(const php of [runtime.php,path.join(runtime.sdkDirectory,'php.exe')])for(const name of ['unit.php','json-integrity.php','eai-current-vehicle-signals.php','eai-suite.php','suite-api-policy.php','legacy-vehicle-counter-policy.php','suite-transport-policy.php','suite-history-policy.php','patricia-compatibility.php']){const r=await execute(php,['-n',path.join(apiRoot,'tests',name)]);check(r.result==='PASS');report.offlineRegression.push({test:name,...r});}
 report.publicHttpContract=await contractHttp();check(report.publicHttpContract.result==='PASS');
 console.log('1/4: fresh installation, fictitious clients, isolation, URLs and minute rules. No EAI call.');
 const child=await execute(runtime.node,[path.join(apiRoot,'tests/replication-package.mjs'),'--child'],JSON.stringify({parent:directory,php:runtime.php,mysqld:runtime.mysqld,sdkDirectory:runtime.sdkDirectory,clients:3,failedUsername:'failed.fixture'}),240000,apiRoot);
 check(child.result==='PASS'&&child.successfulFictitiousClients===3);report.replication=child;
 plan=JSON.parse(await readFile(path.join(child.directory,'service-plan.json')));const client=plan.clients[0],sheet=JSON.parse(await readFile(path.join(client.directory,'ACCES-CLIENT-PRIVE.json')));
 deps=localDependencies();const secrets=await deps.readSecrets(plan.directory);await deps.startSql(plan,secrets);sqlUp=true;
 const startWeb=async()=>{web=spawn(runtime.php,['-n','-d',`extension_dir="${path.dirname(runtime.php).replaceAll('\\','/')}/ext"`,'-d','extension=php_mysqli.dll','-S',`127.0.0.1:${plan.apiPort}`,'-t',path.join(apiRoot,'public'),path.join(apiRoot,'public/router.php')],{cwd:apiRoot,windowsHide:true,stdio:'ignore',env:{...process.env,FLEET_API_CONFIG:path.join(plan.directory,'http-config.json'),FLEET_API_LOCAL_TEST:'1',FLEET_API_DEMO:'',FLEET_API_AUDIT_LOG:''}});let ready=false;for(let i=0;i<40;i++){try{await get(plan.apiPort,'/');ready=true;break;}catch{await pause(100);}}check(ready);};
 const stopWeb=async()=>{if(web&&web.exitCode===null){web.kill();await Promise.race([once(web,'exit'),pause(3000)]);}check(web.exitCode!==null||web.signalCode!==null);};
 await startWeb();const id=JSON.parse(await readFile(path.join(client.directory,'pending-capture.json'))).bundle.feeds.vehicles[0].CWVehicleID;
 const access=[];for(const c of plan.clients)access.push({client:c,sheet:JSON.parse(await readFile(path.join(c.directory,'ACCES-CLIENT-PRIVE.json'))),id:JSON.parse(await readFile(path.join(c.directory,'pending-capture.json'))).bundle.feeds.vehicles[0].CWVehicleID});
 const notBefore=Math.max(...access.map(a=>Date.parse(a.sheet.firstRequestNotBeforeUtc||'')||0));const wait=Math.max(0,notBefore-Date.now()+300);if(wait){console.log('Waiting for the minute quota before HTTP reads: '+Math.ceil(wait/1000)+' s.');await pause(wait);}
 for(const ep of ['vehicles','vehiclesBase','positions','canbus','telemetry']){const r=await get(plan.apiPort,target(sheet,ep,id));check(r.status===200&&Array.isArray(JSON.parse(r.body)));}
 for(let i=1;i<access.length;i++)for(const ep of ['vehicles','vehiclesBase','positions','canbus','telemetry']){const a=access[i],r=await get(plan.apiPort,target(a.sheet,ep,a.id));check(r.status===200&&JSON.parse(r.body).every(row=>row.CWVehicleID===Number(a.id)));check((await get(plan.apiPort,target({...a.sheet,password:sheet.password},ep,a.id))).status===503);}
 report.threeTenantCombinedHttpIsolationVerified=true;
 for(const ep of ['vehicles','vehiclesBase','positions','canbus','telemetry']){const repeated=await get(plan.apiPort,target(sheet,ep,id));check(ep==='positions'?repeated.status===200&&repeated.body.startsWith('API ERROR: getPositionsHistory function limit request per 1 minute reached'):repeated.status===503&&repeated.body==='');}
 report.allFiveEndpointMinuteRulesVerified=true;check((await get(plan.apiPort,target({...sheet,password:'fictitious-invalid-token'},'vehicles',id))).status!==200);
 console.log('2/4: simulated new EAI event publication and verification after the minute.');
 const capture=JSON.parse(await readFile(path.join(client.directory,'pending-capture.json'))),stamp=new Date().toISOString().slice(0,19).replace('T',' ');capture.bundle.capturedUtc=stamp;capture.bundle.feeds.vehicles[0].Speed='84';
 for(const key of ['LastReportTime','LastLocationTime'])capture.bundle.feeds.vehicles[0][key]=stamp;
 for(const feed of ['positions','canbushistory','telemetry'])for(const row of capture.bundle.feeds[feed]){row.ID=(BigInt(row.ID)+1000n).toString();row.MeasuredTime=stamp;}
 capture.cursors.positions={'0':'2'};capture.cursors.canbushistory='2';capture.cursors.telemetry={'0':'2'};
 const connection=secrets.clients[client.tenant].publisherConnection,state=await suiteDatabase(plan,connection,'state',{tenant:client.tenant});const delay=Math.max(0,Date.parse(state.lastAttemptUtc.replace(' ','T')+'Z')+62000-Date.now());if(delay)await pause(delay);
 await suiteDatabase(plan,connection,'dispatch',{tenant:client.tenant,intervalSeconds:60});const accepted=await suiteDatabase(plan,connection,'accept',{tenant:client.tenant,capture,ruleProfilePath:client.ruleProfilePath,ruleProfileSha256:client.ruleProfileSha256});check(accepted.generation===2);
 await pause(62000);const updated=await get(plan.apiPort,target(sheet,'vehicles',id)),rows=JSON.parse(updated.body);check(updated.status===200&&rows[0].CurrentSpeed===84&&rows[0].LastReportTime===stamp);report.simulatedEaiUpdateObservedThroughHttp=true;
 for(const ep of ['positions','canbus','telemetry']){const foreign=await get(plan.apiPort,target(sheet,ep,access[1].id));check(foreign.status===503&&foreign.body==='');}report.foreignHistoryVehicleRejectedAfterQuotaExpiry=true;
 console.log('3/4: clean stop and restart of owned fictitious servers only.');await stopWeb();await deps.stopSql();sqlUp=false;await deps.startSql(plan,secrets);sqlUp=true;await startWeb();const after=await suiteDatabase(plan,connection,'state',{tenant:client.tenant});check(after.generation===2&&!after.inFlight);report.cleanSqlHttpRestartVerified=true;
 // Explicit persisted faults pause ALL fictitious accounts. The worker must
 // retain the last publication and never dispatch transport for these accounts.
 // This exercises the real hidden launcher, not a real upstream collection.
 const paused=[];for(const c of plan.clients){const cx=secrets.clients[c.tenant].publisherConnection;await suiteDatabase(plan,cx,'fault',{tenant:c.tenant,category:'FICTITIOUS_NO_TRANSPORT_TEST'});paused.push(await suiteDatabase(plan,cx,'state',{tenant:c.tenant}));}
 await stopWeb();await deps.stopSql();sqlUp=false;
 console.log('Checking hidden startup with every fictitious account persistently paused; no EAI transport.');
 backgroundStarted=true;
 {
  const started=await control('Start');check(started.launchMethod==='bundled-node-direct');const running=await backgroundState('RUNNING');check((await get(plan.apiPort,'/')).status>0);
  // A paused test account is deliberately degraded, not a healthy live feed.
  const visible=await control('Status');check(visible.state==='DEGRADED'&&visible.worker.dataHealth==='DEGRADED','PAUSED_ACCOUNTS_MUST_REPORT_DEGRADED');
  const repeated=await control('Start');check(repeated.alreadyRunning===true&&repeated.supervisor.pid===running.supervisor.pid,'REPEATED_START_MUST_REUSE_SUPERVISOR');
  for(let i=0;i<plan.clients.length;i++){const c=plan.clients[i],s=await suiteDatabase(plan,secrets.clients[c.tenant].publisherConnection,'state',{tenant:c.tenant});check(s.status==='FAULT_FICTITIOUS_NO_TRANSPORT_TEST'&&s.lastAttemptUtc===paused[i].lastAttemptUtc&&s.generation===paused[i].generation&&!s.inFlight);}
  check(running.supervisor.stage==='RUNNING');const stoppedControl=await control('Stop');check(stoppedControl.shutdownConfirmed);const stopped=await backgroundState('STOPPED');check(stopped.worker.shutdownConfirmed===true);check((await Promise.all([plan.apiPort,plan.sqlPort,plan.lockPort,plan.supervisorPort].map(portClosed))).every(Boolean));
  const stoppedStatus=await control('Status');check(stoppedStatus.state==='STOPPED'&&Object.values(stoppedStatus.ports).every(open=>!open),'STOPPED_STATUS_MUST_HAVE_CLOSED_PORTS');
  backgroundStarted=false;report.hiddenSupervisorStartStopVerified=true;report.backgroundAccountsWereExplicitlyPausedNotLiveCollection=true;
  report.startup={result:'PASS',method:'bundled-node-direct',windowsExecutionPolicyChanged:false,ownedPortsClosed:true};
 }
 console.log('4/4: backup, restore and crash on separate fictitious instances.');
 await mkdir(path.join(apiRoot,'test-results'),{recursive:true});
 const recovery=await execute(runtime.node,[path.join(apiRoot,'tests/storage-recovery.mjs'),runtime.php,runtime.mysqld,runtime.mysqldump,'portable-'+Date.now()], '',180000,apiRoot);check(recovery.result==='PASS'&&recovery.fixturePortsClosedAfterShutdown);report.crashAndRestore=recovery;
 report.result='PASS';
}catch(e){report.failureCategory=/^[A-Z0-9_]{1,100}$/.test(e.message)?e.message:'PORTABLE_RECETTE_NOT_CONFIRMED';process.exitCode=1;}
finally{
 if(backgroundStarted){try{await writeFile(path.join(plan.directory,'control.json'),'{"requested":"stop"}');const stopped=await backgroundState('STOPPED');if(!stopped.worker.shutdownConfirmed)throw Error('BACKGROUND_SHUTDOWN_UNCONFIRMED');}catch{report.shutdownUnconfirmed=true;report.result='FAIL';process.exitCode=1;}}
 if(web&&web.exitCode===null){web.kill();await Promise.race([once(web,'exit'),pause(3000)]);}
 if(sqlUp){try{await deps.stopSql();sqlUp=false;}catch{report.shutdownUnconfirmed=true;report.result='FAIL';process.exitCode=1;}}
 report.checks=checks;report.utc=new Date().toISOString();report.sourceUpdateWasSimulatedNotLiveEai=true;
 if(directory){const output=path.join(directory,'RESULTAT.json');await writeFile(output,JSON.stringify(report,null,2)+'\n',{flag:'wx'});console.log(JSON.stringify({result:report.result,checks,reportPath:output,failureCategory:report.failureCategory,realEaiCalls:0,filesDeleted:0,actualWindowsRebootTested:false,productionReady:false}));}
}
