import {readFile,writeFile,mkdir,mkdtemp} from 'node:fs/promises';import path from 'node:path';import assert from 'node:assert/strict';
import {apiRoot,execute,phpArgs} from '../service/runtime.mjs';import {localDependencies} from '../tools/add-suite-client.mjs';import {recoverLocal} from '../tools/recover-local-sync.mjs';
const runtimeParent=process.argv[2];assert.ok(path.isAbsolute(runtimeParent||''));await mkdir(path.join(apiRoot,'test-results'),{recursive:true});const parent=await mkdtemp(path.join(apiRoot,'test-results/recover-'));
let checks=0,plan,deps,sql=false,transports=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const report={result:'FAIL',suite:'operator-recovery-local-sql-http',eaiCalls:0,filesDeleted:0,productionReady:false};
try{
 const created=await execute(process.execPath,[path.join(apiRoot,'tests/replication-package.mjs'),'--child'],JSON.stringify({parent,php:path.join(runtimeParent,'php/php.exe'),mysqld:path.join(runtimeParent,'mysql/bin/mysqld.exe'),sdkDirectory:path.join(runtimeParent,'EAI'),clients:1,failedUsername:'failed.fixture'}),240000,apiRoot);check(created.result==='PASS','FICTITIOUS_ONBOARDING');
 const planFile=path.join(created.directory,'service-plan.json');plan=JSON.parse(await readFile(planFile));const client=plan.clients[0];const capture=JSON.parse(await readFile(path.join(client.directory,'pending-capture.json')));
 deps=localDependencies();const secrets=await deps.readSecrets(plan.directory);await deps.startSql(plan,secrets);sql=true;const connection=secrets.clients[client.tenant].publisherConnection;
 const before=await deps.database(plan,connection,'inventory',{tenant:client.tenant});
 await execute(plan.php,phpArgs(plan.php,path.join(apiRoot,'tests/outage-sql-fixture.php')),JSON.stringify({fictitiousOnly:true,directory:plan.directory,connection,tenant:client.tenant}));
 await deps.stopSql();sql=false;
 const dependencies={...deps,collect:async()=>{transports++;const next=structuredClone(capture);next.bundle.capturedUtc=new Date().toISOString().slice(0,19).replace('T',' ');return next;}};
 const result=await recoverLocal(planFile,{approveOne:true,quietConfirmed:true},dependencies);
 check(result.result==='PASS','OPERATOR_TOOL_PASS');check(result.results[0].localHttpVerified,'FIVE_REAL_LOCAL_HTTP_ROUTES_VERIFIED');check(transports===1,'ONLY_ONE_FICTITIOUS_SEND');check(result.serviceRestarted===false,'NO_AUTOMATIC_BACKGROUND_START');check(Date.parse(result.firstBrowserRequestNotBeforeUtc)>Date.now(),'BROWSER_QUOTA_WARNING');
 await deps.startSql(plan,secrets);sql=true;const after=await deps.database(plan,connection,'inventory',{tenant:client.tenant});check(after.state.generation===2&&!after.state.inFlight,'FRESH_GENERATION');for(const ep of ['positions','canbus','telemetry'])check(after.history[ep].rows===before.history[ep].rows,'HISTORY_NOT_DELETED');check(JSON.stringify(after.state.cursors)===JSON.stringify(before.state.cursors),'CURSORS_PRESERVED');
 await assert.rejects(recoverLocal(planFile,{approveOne:false},dependencies));checks++;
 await deps.stopSql();sql=false;
 const review=await recoverLocal(planFile,{approveOne:false},dependencies);check(review.mode==='READ_ONLY_REVIEW'&&review.eaiCalls===0,'REVIEW_NO_TRANSPORT');check(transports===1,'REVIEW_DID_NOT_REPEAT_SEND');
 report.result='PASS';report.fictitiousTransports=transports;report.fiveLocalHttpChecksVerified=true;report.pendingReservationRecoveredOnlyAfterApproval=true;report.historyAndCursorsPreserved=true;
}catch(e){report.category=String(e.message).split('\n')[0];process.exitCode=1;}
finally{if(sql){try{await deps.stopSql();}catch{report.result='FAIL';report.sqlShutdownUnconfirmed=true;process.exitCode=1;}}report.checks=checks;report.utc=new Date().toISOString();await writeFile(path.join(parent,'RESULT.json'),JSON.stringify(report,null,2)+'\n',{flag:'wx'});console.log(JSON.stringify({...report,reportPath:path.join(parent,'RESULT.json')}));}
