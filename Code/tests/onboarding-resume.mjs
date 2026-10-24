// Offline registration-state tests use injected transport, SQL and credential storage.
import {readFile,writeFile,mkdir,mkdtemp,readdir,rename} from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {addLiveClient,pendingClientReview} from '../tools/add-suite-client-core.mjs';
import {onboardInteractive} from '../tools/onboard-interactive.mjs';

const root=path.dirname(path.dirname(fileURLToPath(import.meta.url)));
await mkdir(path.join(root,'test-results'),{recursive:true});
const parent=await mkdtemp(path.join(root,'test-results/onboarding-resume-'));
let checks=0;const check=value=>{assert.ok(value);checks++;};
const realNow=Date.now;let clock=realNow();Date.now=()=>clock;
async function fixture(){
 const directory=await mkdtemp(path.join(parent,'instance-')),planFile=path.join(directory,'service-plan.json');
 const manifest=path.join(directory,'template.json');await writeFile(manifest,JSON.stringify({sourceDirectory:directory,archiveSha256:'fixture',sourceSha256:{},bridgeRootSha256:{}}));
 const plan={profile:'LOCAL_EAI_SUITE_V1',directory,clients:[],intervalSeconds:60,apiPort:18001,sqlPort:18002,sdkManifestPath:manifest};
 const config={source:{driver:'eai-suite-live-mysqli',tenants:{}},accounts:{},options:{}};
 await writeFile(planFile,JSON.stringify(plan));await writeFile(path.join(directory,'http-config.json'),JSON.stringify(config));await writeFile(path.join(directory,'secrets.dpapi'),'FICTITIOUS PROTECTED ENVELOPE');
 const protectedValues=new Map([[path.join(directory,'secrets.dpapi'),{clients:{},rootPassword:'fixture-root'}]]),states=new Map();
 let calls=0,provisions=0,httpChecks=0,failTransport=false,failAccept=false,failHttp=false;
 const connection={host:'127.0.0.1',port:plan.sqlPort,database:'suite_0123456789abcdef',username:'fixture',password:'fixture'};
 const deps={assertStopped:async()=>{},verifySources:async()=>{},sdkExtra:async()=>'<fixture/>',
  readSecrets:async(dir,file='secrets.dpapi')=>structuredClone(protectedValues.get(path.join(dir,file))),
  writeSecrets:async(file,value)=>{await writeFile(file,'FICTITIOUS PROTECTED ENVELOPE',{flag:'wx'});protectedValues.set(file,structuredClone(value));},
  startSql:async()=>{},stopSql:async()=>{},
  provision:async(_plan,_secrets,client)=>{provisions++;states.set(client.tenant,{generation:0,status:'WAITING_FIRST_SYNC',inFlight:false,lastAttemptUtc:null,capturedUtc:null,cursors:{}});return {readerConnection:connection,publisherConnection:{...connection,tenant:client.tenant}};},
  database:async(_plan,_connection,action,args)=>{
   const state=states.get(args.tenant);assert.ok(state);
   if(action==='state')return structuredClone(state);
   if(action==='recover'){assert.equal(args.review.expectedGeneration,state.generation);assert.equal(args.review.expectedStatus,state.status);assert.equal(args.review.expectedLastAttemptUtc,state.lastAttemptUtc);state.inFlight=false;state.status='RECOVERY_REVIEWED';return {result:'PASS'};}
   if(action==='dispatch'){assert.equal(state.inFlight,false);if(state.lastAttemptUtc)assert.ok(clock-Date.parse(state.lastAttemptUtc+'Z')>60000);state.inFlight=true;state.status='COLLECTING';state.lastAttemptUtc=new Date(clock).toISOString().slice(0,19).replace('T',' ');return {result:'PASS'};}
   if(action==='accept'){if(failAccept)throw Error('FICTITIOUS_ACCEPT_FAILURE');assert.equal(state.inFlight,true);assert.equal(state.generation,0);state.generation=1;state.inFlight=false;state.status='PUBLISHED';state.capturedUtc=args.capture.bundle.capturedUtc;return {result:'PASS',generation:1,vehicleRows:1};}
   throw Error('UNEXPECTED_FIXTURE_ACTION');
  },
  collect:async(_plan,client,_password,_cursors,preflight)=>{if(preflight)return {transportAttempts:0};calls++;if(failTransport)throw Error('FICTITIOUS_EAI_FAILURE');return {result:'PASS',tenant:client.tenant,transportAttempts:1,serverWrites:0,bundle:{tenant:client.tenant,timeBasis:'UTC',capturedUtc:new Date(clock).toISOString().slice(0,19).replace('T',' '),feeds:{vehicles:[{CWVehicleID:'123'}],divisions:[],positions:[],canbushistory:[],telemetry:[],wiredtelemetry:[]}}};},
  verifyHttp:async()=>{httpChecks++;if(failHttp)throw Error('FICTITIOUS_HTTP_FAILURE');}
 };
 const input={name:'Synthetic client',eaiUsername:'synthetic.fixture',eaiPassword:'fictitious-only',noKnownEaiCallInLastMinute:true,approvedUnknownPolicy:'legacy-markers-v1',approvedCounterPolicy:'approved-compatibility-zero-empty-v1'};
 const review=()=>pendingClientReview(planFile,input.eaiUsername);
 const resume=async()=>({...input,resumeApproval:'RESUME_STAGED_CLIENT',resumeTenant:(await review()).tenant});
 return {directory,planFile,deps,input,review,resume,states,protectedValues,stats:()=>({calls,provisions,httpChecks}),failTransport:v=>{failTransport=v;},failAccept:v=>{failAccept=v;},failHttp:v=>{failHttp=v;}};
}
try{
 const f=await fixture(),watched=[f.planFile,path.join(f.directory,'http-config.json'),path.join(f.directory,'secrets.dpapi')],before=await Promise.all(watched.map(file=>readFile(file)));
 f.failTransport(true);await assert.rejects(addLiveClient(f.planFile,f.input,f.deps),/FICTITIOUS_EAI_FAILURE/);checks++;
 check(f.stats().calls===1&&f.stats().provisions===1);
 for(let i=0;i<watched.length;i++)check((await readFile(watched[i])).equals(before[i]));
 const pending=await f.review();check(pending.phase==='FIRST_EAI_ATTEMPT'&&!pending.registryReviewRequired);
 await assert.rejects(addLiveClient(f.planFile,f.input,f.deps),/EAI_ACCOUNT_STAGED_REVIEW_REQUIRED_NO_RETRY/);checks++;
 await assert.rejects(addLiveClient(f.planFile,{...await f.resume(),resumeTenant:'other'},f.deps),/ONBOARDING_RESUME_IDENTITY_CHANGED/);checks++;
 check(f.stats().calls===1);
 let secretReads=0;const answers=['YES','Synthetic client','synthetic.fixture',''];
 await assert.rejects(onboardInteractive(f.planFile,{ask:async()=>answers.shift(),secret:async()=>{secretReads++;},register:async()=>{throw Error('MUST_NOT_REGISTER');},output:{write:()=>{}}}),/OPERATOR_CANCELLED/);checks++;check(secretReads===0&&f.stats().calls===1);
 await assert.rejects(addLiveClient(f.planFile,await f.resume(),f.deps),/RECOVERY_QUIET_MINUTE_REQUIRED/);checks++;check(f.stats().calls===1);
 clock+=62000;f.failTransport(false);const acceptedAnswers=['YES','Synthetic client','synthetic.fixture','RESUME'];let log='';
 const resumed=await onboardInteractive(f.planFile,{ask:async()=>acceptedAnswers.shift(),secret:async()=>f.input.eaiPassword,dependencies:f.deps,output:{write:value=>{log+=value;}}});
 check(acceptedAnswers.length===0&&!log.includes(f.input.eaiPassword));check(log.includes('unfinished registration')&&log.includes('same client and database'));
 check(resumed.result==='PASS'&&resumed.resumed&&resumed.eaiCalls===1&&resumed.tenant===pending.tenant);
 check(f.stats().provisions===1&&f.stats().calls===2&&f.stats().httpChecks===1);
 check((JSON.parse(await readFile(f.planFile))).clients.length===1);check(await f.review()===null);
 await assert.rejects(addLiveClient(f.planFile,f.input,f.deps),/EAI_ACCOUNT_ALREADY_REGISTERED/);checks++;
 check(f.stats().calls===2);
 // A pre-transport SDK failure can resume preparation without another schema.
 const pre=await fixture(),normalCollect=pre.deps.collect;let failPreflight=true;
 pre.deps.collect=async(...args)=>{if(args[4]&&failPreflight)throw Error('FICTITIOUS_SDK_PREFLIGHT_FAILURE');return normalCollect(...args);};
 await assert.rejects(addLiveClient(pre.planFile,pre.input,pre.deps),/FICTITIOUS_SDK_PREFLIGHT_FAILURE/);checks++;check(pre.stats().calls===0&&pre.stats().provisions===0);check((await pre.review()).phase==='PREPARED');
 failPreflight=false;check((await addLiveClient(pre.planFile,await pre.resume(),pre.deps)).result==='PASS');check(pre.stats().calls===1&&pre.stats().provisions===1);
 // A valid capture survives local publication failure and needs no new send.
 const saved=await fixture();saved.failAccept(true);await assert.rejects(addLiveClient(saved.planFile,saved.input,saved.deps),/FICTITIOUS_ACCEPT_FAILURE/);checks++;
 saved.failAccept(false);const reused=await addLiveClient(saved.planFile,await saved.resume(),saved.deps);check(reused.eaiCalls===0&&saved.stats().calls===1&&saved.stats().provisions===1);
 // An expired, unpublished capture is retained while a reviewed fresh read runs.
 const expired=await fixture();expired.failAccept(true);await assert.rejects(addLiveClient(expired.planFile,expired.input,expired.deps));checks++;
 const expiredDir=path.join(expired.directory,'client-onboarding',(await readdir(path.join(expired.directory,'client-onboarding')))[0]),originalCapture=await readFile(path.join(expiredDir,'pending-capture.json'));
 clock+=3601000;expired.failAccept(false);check((await addLiveClient(expired.planFile,await expired.resume(),expired.deps)).eaiCalls===1);check(expired.stats().provisions===1&&expired.stats().calls===2);check((await readFile(path.join(expiredDir,'pending-capture.json'))).equals(originalCapture));
 // HTTP retry uses the already committed first publication and API identity.
 const http=await fixture();http.failHttp(true);await assert.rejects(addLiveClient(http.planFile,http.input,http.deps),/FICTITIOUS_HTTP_FAILURE/);checks++;
 const stage=(await readdir(path.join(http.directory,'client-onboarding')))[0],stageDir=path.join(http.directory,'client-onboarding',stage),apiIdentity=http.protectedValues.get(path.join(stageDir,'api-access.dpapi'));
 http.failHttp(false);const checked=await addLiveClient(http.planFile,await http.resume(),http.deps);check(checked.eaiCalls===0&&http.stats().calls===1&&http.stats().provisions===1);check(checked.account===apiIdentity.account);check((JSON.parse(await readFile(checked.accessSheetPath))).password===apiIdentity.apiPassword);
 // Legacy failed stages (no client.json or persisted attempt clock) are resumable.
 const legacy=await fixture();legacy.failTransport(true);await assert.rejects(addLiveClient(legacy.planFile,legacy.input,legacy.deps),/FICTITIOUS_EAI_FAILURE/);checks++;
 const legacyDir=path.join(legacy.directory,'client-onboarding',(await readdir(path.join(legacy.directory,'client-onboarding')))[0]);
 await rename(path.join(legacyDir,'client.json'),path.join(legacyDir,'client-preserved-for-legacy-fixture.json'));
 const legacyState=JSON.parse(await readFile(path.join(legacyDir,'state.json')));delete legacyState.attemptCount;delete legacyState.lastAttemptUtc;delete legacyState.secretFile;await writeFile(path.join(legacyDir,'state.json'),JSON.stringify(legacyState));
 clock+=62000;legacy.failTransport(false);check((await addLiveClient(legacy.planFile,await legacy.resume(),legacy.deps)).resumed);check(legacy.stats().provisions===1&&legacy.stats().calls===2);
 // A potentially partial registry commit is never retried by the menu.
 const partial=await fixture();partial.failTransport(true);await assert.rejects(addLiveClient(partial.planFile,partial.input,partial.deps));checks++;
 const partialDir=path.join(partial.directory,'client-onboarding',(await readdir(path.join(partial.directory,'client-onboarding')))[0]),state=JSON.parse(await readFile(path.join(partialDir,'state.json')));state.registryReviewRequired=true;await writeFile(path.join(partialDir,'state.json'),JSON.stringify(state));
 await assert.rejects(addLiveClient(partial.planFile,await partial.resume(),partial.deps),/ONBOARDING_REGISTRY_REVIEW_REQUIRED/);checks++;check(partial.stats().calls===1);
 const damaged=await fixture();damaged.failAccept(true);await assert.rejects(addLiveClient(damaged.planFile,damaged.input,damaged.deps));checks++;
 const damagedDir=path.join(damaged.directory,'client-onboarding',(await readdir(path.join(damaged.directory,'client-onboarding')))[0]);await writeFile(path.join(damagedDir,'pending-capture.json'),'{}');
 await assert.rejects(addLiveClient(damaged.planFile,await damaged.resume(),damaged.deps),/ONBOARDING_CAPTURE_CHANGED/);checks++;check(damaged.stats().calls===1&&damaged.stats().provisions===1);
 console.log(JSON.stringify({result:'PASS',suite:'onboarding-explicit-resume',checks,realEaiCalls:0,filesDeleted:0,fixtureDirectory:parent}));
}finally{Date.now=realNow;}
