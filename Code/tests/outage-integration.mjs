import {readFile,writeFile,mkdir,mkdtemp} from 'node:fs/promises';
import {spawn} from 'node:child_process';import {once} from 'node:events';import http from 'node:http';import path from 'node:path';
import {fileURLToPath} from 'node:url';import assert from 'node:assert/strict';
import {apiRoot,execute,phpArgs} from '../service/runtime.mjs';import {localDependencies} from '../tools/add-suite-client.mjs';
import {resumeOne} from '../tools/recovery-policy.mjs';
const [runtimeParent]=process.argv.slice(2);assert.ok(path.isAbsolute(runtimeParent||''));
const runtime={php:path.join(runtimeParent,'php/php.exe'),mysqld:path.join(runtimeParent,'mysql/bin/mysqld.exe'),sdkDirectory:path.join(runtimeParent,'EAI')};
await mkdir(path.join(apiRoot,'test-results'),{recursive:true});const parent=await mkdtemp(path.join(apiRoot,'test-results/outage-'));
const report={result:'FAIL',suite:'owned-sql-http-outage-recovery',eaiCalls:0,oldApiCalls:0,existingCustomerDatabasesOpened:0,filesDeleted:0,productionReady:false};
let checks=0,web,sql=false,deps,plan;const check=(v,label)=>{assert.ok(v,label);checks++;};const pause=ms=>new Promise(r=>setTimeout(r,ms));
const get=(port,target)=>new Promise((resolve,reject)=>{const req=http.get({host:'127.0.0.1',port,path:target},res=>{let body='';res.on('data',b=>body+=b);res.on('end',()=>resolve({status:res.statusCode,headers:res.headers,body}));});req.once('error',reject);req.setTimeout(10000,()=>req.destroy(Error('FIXTURE_HTTP_TIMEOUT')));});
try{
 console.log('Creating fictitious client; no real EAI transport.');
 const created=await execute(process.execPath,[path.join(apiRoot,'tests/replication-package.mjs'),'--child'],JSON.stringify({parent,...runtime,clients:1,failedUsername:'failed.fixture'}),240000,apiRoot);check(created.result==='PASS','FICTITIOUS_ONBOARDING');
 plan=JSON.parse(await readFile(path.join(created.directory,'service-plan.json')));const client=plan.clients[0];const configPath=path.join(plan.directory,'http-config.json'),config=JSON.parse(await readFile(configPath));
 const sheet=JSON.parse(await readFile(path.join(client.directory,'ACCES-CLIENT-PRIVE.json')));const capture=JSON.parse(await readFile(path.join(client.directory,'pending-capture.json')));
 check(config.source.tenants[client.tenant].staleReadPolicy==='last-known-with-warning','NEW_CLIENT_EXPLICIT_LAST_KNOWN_POLICY');
 deps=localDependencies();const secrets=await deps.readSecrets(plan.directory);await deps.startSql(plan,secrets);sql=true;
 const connection=secrets.clients[client.tenant].publisherConnection;
 const before=await deps.database(plan,connection,'inventory',{tenant:client.tenant});
 await execute(plan.php,phpArgs(plan.php,path.join(apiRoot,'tests/outage-sql-fixture.php')),JSON.stringify({fictitiousOnly:true,directory:plan.directory,connection,tenant:client.tenant}));
 const staleState=await deps.database(plan,connection,'state',{tenant:client.tenant});check(staleState.inFlight&&staleState.generation===1,'STALE_UNCONFIRMED_FIXTURE');
 web=spawn(plan.php,[...phpArgs(plan.php,'-S'),`127.0.0.1:${plan.apiPort}`,'-t',path.join(apiRoot,'public'),path.join(apiRoot,'public/router.php')],{cwd:apiRoot,windowsHide:true,stdio:'ignore',env:{...process.env,FLEET_API_CONFIG:configPath,FLEET_API_LOCAL_TEST:'1',FLEET_API_DEMO:'',FLEET_API_AUDIT_LOG:''}});await pause(750);
 const vehicle=capture.bundle.feeds.vehicles[0].CWVehicleID;const endpoints=['vehicles','vehiclesBase','positions','canbus','telemetry'];
 const target=ep=>'/cweai/'+sheet.username+'/'+ep+'?password='+encodeURIComponent(sheet.password)+(ep==='vehicles'||ep==='vehiclesBase'?'':'&vehicleid='+vehicle+'&ymdfrom=2000-01-01&ymdto=2099-01-01');
 const wait=Math.max(0,Date.parse(sheet.firstRequestNotBeforeUtc)-Date.now()+1000);if(wait){console.log('Waiting for the real API minute quota: '+Math.ceil(wait/1000)+' seconds.');await pause(wait);}
 for(const ep of endpoints){const r=await get(plan.apiPort,target(ep));check(r.status===200,'STALE_HTTP_AVAILABLE_'+ep);check(JSON.parse(r.body).length>0,'STORED_ROWS_'+ep);check(r.headers['x-data-freshness']==='STALE','STALE_WARNING_'+ep);check(r.headers['x-data-captured-at']==='2020-01-01T00:00:00Z','UNCHANGED_PUBLICATION_TIMESTAMP_'+ep);const repeated=await get(plan.apiPort,target(ep));check(ep==='positions'?repeated.status===200&&repeated.body.startsWith('API ERROR:'):repeated.status===503,'MINUTE_RULE_'+ep);}
 let fakeTransports=0;
 const result=await resumeOne({plan,client,connection,state:staleState,approval:'ONE_REVIEWED_EAI_ATTEMPT',quietConfirmed:true},{database:deps.database,collect:async()=>{fakeTransports++;const next=structuredClone(capture);next.bundle.capturedUtc=new Date().toISOString().slice(0,19).replace('T',' ');return next;},saveCapture:async()=>{}});
 check(result.result==='PASS'&&result.generation===2,'EXPLICIT_RECOVERY_PUBLISHES');check(fakeTransports===1,'ONE_FICTITIOUS_TRANSPORT_ONLY');
 const after=await deps.database(plan,connection,'inventory',{tenant:client.tenant});for(const ep of ['positions','canbus','telemetry'])check(after.history[ep].rows===before.history[ep].rows,'HISTORY_PRESERVED_'+ep);
 check(JSON.stringify(after.state.cursors)===JSON.stringify(before.state.cursors),'CURSORS_NOT_RESET');check(!after.state.inFlight&&after.state.status==='PUBLISHED','PENDING_CLEARED_ONLY_AFTER_REVIEW');
 console.log('Verifying recovered publication after the minute quota.');await pause(62000);
 for(const ep of endpoints){const r=await get(plan.apiPort,target(ep));check(r.status===200,'RECOVERED_HTTP_'+ep);check(r.headers['x-data-freshness']==='FRESH','FRESH_AFTER_REVIEW_'+ep);}
 report.result='PASS';report.lastKnownPublicationSurvivesOutage=true;report.explicitRecoveryPreservesHistoryAndCursors=true;report.automaticEaiRetries=0;report.fictitiousTransports=fakeTransports;
}catch(e){report.category=/^[A-Z0-9_]{1,100}$/.test(e.message)?e.message:'OUTAGE_INTEGRATION_NOT_CONFIRMED';process.exitCode=1;}
finally{if(web&&web.exitCode===null){web.kill();await Promise.race([once(web,'exit'),pause(3000)]);}if(sql){try{await deps.stopSql();report.sqlShutdownConfirmed=true;}catch{report.result='FAIL';report.shutdownUnconfirmed=true;process.exitCode=1;}}report.checks=checks;report.utc=new Date().toISOString();await writeFile(path.join(parent,'RESULT.json'),JSON.stringify(report,null,2)+'\n',{flag:'wx'});console.log(JSON.stringify({...report,reportPath:path.join(parent,'RESULT.json')}));}
