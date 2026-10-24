// Explicit operator recovery. Review is the default; no implicit remote retry.
import {readFile,writeFile,mkdir,lstat} from 'node:fs/promises';
import {createServer} from 'node:net';
import {randomUUID,createHash} from 'node:crypto';
import {createInterface} from 'node:readline/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
import {localDependencies} from './add-suite-client.mjs';
import {resumeOne} from './recovery-policy.mjs';

export async function recoverLocal(planFile,{approveOne=false,quietConfirmed=false,replayLocal=false,tenant=null}={},deps=localDependencies()){
 assert.ok(path.isAbsolute(planFile),'ABSOLUTE_PLAN_REQUIRED');const plan=JSON.parse(await readFile(planFile));
 assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1');assert.equal(path.dirname(planFile),plan.directory);
 const selected=tenant===null?plan.clients:plan.clients.filter(c=>c.tenant===tenant);assert.ok(selected.length>0,'RECOVERY_CLIENT_NOT_FOUND');
 assert.ok(!(approveOne&&replayLocal),'SELECT_ONE_RECOVERY_MODE');
 await deps.assertStopped(plan);
 // Keep another worker from entering while the owned database is maintained.
 const guards=[];
 try{for(const port of [plan.lockPort,plan.supervisorPort]){const guard=createServer(s=>s.destroy());await new Promise((resolve,reject)=>{guard.once('error',reject);guard.listen(port,'127.0.0.1',resolve);});guards.push(guard);}}
 catch(e){for(const guard of guards)if(guard.listening)await new Promise(r=>guard.close(r));throw Error('SUPERVISOR_STOP_CONFIRMATION_REQUIRED');}
 let secrets,sqlStarted=false;const results=[];
 try{
  secrets=await deps.readSecrets(plan.directory);sqlStarted=true;await deps.startSql(plan,secrets);
  const config=JSON.parse(await readFile(path.join(plan.directory,'http-config.json')));
  assert.ok(!(approveOne||replayLocal)||selected.length===1,'SELECT_ONE_CLIENT_BEFORE_RECOVERY');
  for(const client of selected){const connection=secrets.clients[client.tenant].publisherConnection;
   const state=await deps.database(plan,connection,'state',{tenant:client.tenant});
   if(replayLocal){
    assert.equal(JSON.parse(await readFile(path.join(plan.directory,'control.json'))).requested,'stop','SERVICE_STOP_CONFIRMATION_REQUIRED');
    const capturePath=path.join(client.directory,'pending-capture.json'),info=await lstat(capturePath);
    assert.ok(info.isFile()&&!info.isSymbolicLink()&&info.size<=20971520,'LOCAL_CAPTURE_FILE_REQUIRED');
    const bytes=await readFile(capturePath),capture=JSON.parse(bytes);
    assert.equal(capture.tenant,client.tenant,'LOCAL_CAPTURE_TENANT_MISMATCH');
    assert.ok(state.inFlight===true&&state.lastAttemptUtc&&capture.bundle?.capturedUtc>=state.lastAttemptUtc&&(!state.capturedUtc||capture.bundle.capturedUtc>state.capturedUtc),'LOCAL_CAPTURE_WRONG_ATTEMPT');
    const review={expectedGeneration:state.generation,expectedStatus:state.status,expectedLastAttemptUtc:state.lastAttemptUtc,reason:'LOCAL_PENDING_CAPTURE_REPLAY'};
    const accepted=await deps.database(plan,connection,'replay-pending',{tenant:client.tenant,capture,ruleProfilePath:client.ruleProfilePath,ruleProfileSha256:client.ruleProfileSha256,review});
    results.push({tenant:client.tenant,...accepted,transportAttempts:0,captureSha256:createHash('sha256').update(bytes).digest('hex'),capturedUtcPreserved:true,freshnessAsserted:false,historyDeleted:false,cursorsReset:false});
    continue;
   }
   if(!approveOne){results.push({tenant:client.tenant,status:state.status,inFlight:state.inFlight,generation:state.generation,capturedUtc:state.capturedUtc,lastAttemptUtc:state.lastAttemptUtc});continue;}
   assert.equal(JSON.parse(await readFile(path.join(plan.directory,'control.json'))).requested,'stop','SERVICE_STOP_CONFIRMATION_REQUIRED');
   const reservationFolder=path.join(plan.directory,'recovery-reports');await mkdir(reservationFolder,{recursive:true});
   await writeFile(path.join(reservationFolder,'approval-'+randomUUID()+'.json'),JSON.stringify({profile:'EXPLICIT_ONE_ATTEMPT_APPROVAL_V1',tenant:client.tenant,expectedGeneration:state.generation,expectedStatus:state.status,expectedLastAttemptUtc:state.lastAttemptUtc,quietConfirmed,utc:new Date().toISOString(),automaticRetry:false},null,2)+'\n',{flag:'wx',mode:0o600});
   const captureFile=path.join(client.directory,'recovery-capture-'+randomUUID()+'.json');let capture;
   const result=await resumeOne({plan,client,connection,state,approval:'ONE_REVIEWED_EAI_ATTEMPT',quietConfirmed},{
    database:deps.database,
    collect:()=>deps.collect(plan,client,secrets.clients[client.tenant].eaiPassword,state.generation===0?client.initialCursors:state.cursors,false),
    saveCapture:async value=>{capture=value;await writeFile(captureFile,JSON.stringify(value),{flag:'wx',mode:0o600});}
   });
   const identity=Object.entries(config.accounts).find(([,a])=>a.tenant===client.tenant);assert.ok(identity,'LOCAL_API_ACCOUNT_REQUIRED');
   let waitSeconds=0;for(const endpoint of identity[1].endpoints){const filename=path.join(config.rateLimitDirectory,createHash('sha256').update(client.tenant+'/'+endpoint).digest('hex')+'.limit');try{const value=(await readFile(filename,'utf8')).trim();assert.match(value,/^\d{1,10}$/,'LOCAL_MINUTE_STATE_INVALID');waitSeconds=Math.max(waitSeconds,Number(value)+Math.max(60,config.options.minIntervalSeconds)-Math.floor(Date.now()/1000));}catch(e){if(e.code!=='ENOENT')throw e;}}
   if(waitSeconds>0){console.log('Waiting '+(waitSeconds+1)+' seconds for the local API minute quota; no additional EAI request.');let ms=(waitSeconds+1)*1000;while(ms>0){const piece=Math.min(ms,30000);await new Promise(r=>setTimeout(r,piece));ms-=piece;}}
   const httpStage=path.join(client.directory,'recovery-http-'+randomUUID());await mkdir(httpStage);
   await deps.verifyHttp(plan,httpStage,config,{account:identity[0],apiPassword:secrets.clients[client.tenant].apiPassword,tenant:client.tenant,capture});
   results.push({tenant:client.tenant,...result,localHttpVerified:true});
  }
 }finally{try{if(sqlStarted)await deps.stopSql();}finally{secrets=null;for(const guard of guards)if(guard.listening)await new Promise(r=>guard.close(r));}}
 const result={result:'PASS',profile:'REVIEWED_SYNCHRONIZATION_RECOVERY_V1',utc:new Date().toISOString(),mode:replayLocal?'LOCAL_CAPTURE_REPLAY':approveOne?'ONE_REVIEWED_ATTEMPT':'READ_ONLY_REVIEW',results,eaiCalls:approveOne?1:0,automaticRetry:false,serviceRestarted:false,filesDeleted:0,productionReady:false,...(approveOne?{firstBrowserRequestNotBeforeUtc:new Date(Date.now()+61000).toISOString(),message:'Five local URLs were tested. Wait at least one quiet minute before Test/browser. Start the service and check synchronization health.'}:replayLocal?{message:'The received page was published at its original capture time. It may still be STALE. No EAI call was made and no service was started.'}:{})};
 const folder=path.join(plan.directory,'recovery-reports');await mkdir(folder,{recursive:true});const reportPath=path.join(folder,'recovery-'+randomUUID()+'.json');await writeFile(reportPath,JSON.stringify(result,null,2)+'\n',{flag:'wx',mode:0o600});
 return {...result,reportPath};
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 try{const [planFile,...flags]=process.argv.slice(2);let approveOne=flags.includes('--approve-one-eai-attempt'),quietConfirmed=flags.includes('--quiet-confirmed'),replayLocal=flags.includes('--replay-local-capture'),tenant=flags.find(f=>f.startsWith('--tenant='))?.slice(9)||null;
  if(flags.includes('--interactive')){const prompt=createInterface({input:process.stdin,output:process.stdout});try{console.log('Stop the service first. This preserves history and cursors. One EAI attempt, no retry.');const p=JSON.parse(await readFile(planFile));if(p.clients.length>1){console.log(p.clients.map(c=>c.tenant).join('\n'));tenant=await prompt.question('Client tenant to review/recover: ');}const choice=await prompt.question('Type REPLAY LOCAL to reprocess a received page without EAI, or RESUME ONE to authorize one new EAI attempt: ');replayLocal=choice==='REPLAY LOCAL';approveOne=choice==='RESUME ONE';if(approveOne)quietConfirmed=(await prompt.question('No other collector or EAI call for this account for at least one minute? Type QUIET: '))==='QUIET';}finally{prompt.close();}}
  const result=await recoverLocal(planFile,{approveOne,quietConfirmed,replayLocal,tenant});console.log(JSON.stringify(result));
 }catch(e){console.log(JSON.stringify({result:'FAIL',category:/^[A-Z0-9_]{1,100}$/.test(e.message)?e.message:'RECOVERY_REQUIRES_REVIEW',automaticRetry:false,filesDeleted:0,productionReady:false}));process.exitCode=1;}
}
