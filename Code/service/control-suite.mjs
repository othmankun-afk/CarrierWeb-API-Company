import {readFile,writeFile,lstat,open,rename} from 'node:fs/promises';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createConnection} from 'node:net';
import {randomUUID} from 'node:crypto';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import {apiRoot,serviceRoot} from './runtime.mjs';

const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const readState=async filename=>{try{return JSON.parse(await readFile(filename,'utf8'));}catch(e){if(e.code==='ENOENT')return null;throw Error('LOCAL_STATE_NOT_READABLE');}};
export function stateAge(stamp,now=Date.now()){
 const time=typeof stamp==='string'?Date.parse(stamp):NaN;
 return Number.isFinite(time)?(now-time)/1000:Infinity;
}
const fresh=state=>state&&stateAge(state.utc)>=-5&&stateAge(state.utc)<=30;
export function validateControlPlan(file,plan){
 assert.ok(path.isAbsolute(file),'ABSOLUTE_PLAN_REQUIRED');
 assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1','SUITE_PLAN_REQUIRED');
 assert.equal(plan.directory,path.dirname(file),'PLAN_DIRECTORY_MISMATCH');
 assert.ok(Number.isInteger(plan.intervalSeconds)&&plan.intervalSeconds>=60,'ONE_MINUTE_MINIMUM_REQUIRED');
 assert.ok(path.isAbsolute(plan.node||''),'NODE_RUNTIME_REQUIRED');
 const ports=[plan.sqlPort,plan.apiPort,plan.lockPort,plan.supervisorPort];
 assert.ok(ports.every(p=>Number.isInteger(p)&&p>=1024&&p<=65535)&&new Set(ports).size===4,'OWN_DISTINCT_PORTS_REQUIRED');
 assert.ok(Array.isArray(plan.clients)&&plan.clients.length<=100,'CLIENT_LIST_REQUIRED');
 return plan;
}
export function localPortOpen(port){
 return new Promise((resolve,reject)=>{
  const socket=createConnection({host:'127.0.0.1',port});let settled=false;
  const finish=(error,value)=>{if(settled)return;settled=true;socket.destroy();if(error)reject(Error(error));else resolve(value);};
  socket.once('connect',()=>finish(null,true));
  socket.once('error',e=>e.code==='ECONNREFUSED'?finish(null,false):finish('LOCAL_PORT_PROBE_DENIED'));
  socket.setTimeout(700,()=>finish('LOCAL_PORT_PROBE_TIMEOUT'));
 });
}
function ownedPorts(plan){return [plan.sqlPort,plan.lockPort,plan.supervisorPort,...(!plan.deployment||plan.deployment.httpMode==='managed-local'?[plan.apiPort]:[])];}
async function snapshot(plan){
 const [worker,supervisor,http,sql,guard,watch]=await Promise.all([
  readState(path.join(plan.directory,'service-status.json')),readState(path.join(plan.directory,'supervisor-status.json')),
  localPortOpen(plan.apiPort),localPortOpen(plan.sqlPort),localPortOpen(plan.lockPort),localPortOpen(plan.supervisorPort)
 ]);
 const managedHttp=!plan.deployment||plan.deployment.httpMode==='managed-local';
 let state='NOT STARTED';
 if(worker){
  state=!fresh(worker)?'STALE STATUS':worker.stage;
  if(state==='RUNNING'&&(!sql||!guard||!watch||managedHttp&&!http))state='UNAVAILABLE';
  if(state==='RUNNING'&&(!fresh(supervisor)||supervisor.stage!=='RUNNING'))state='SUPERVISOR NOT READY';
  if(state==='RUNNING'&&worker.dataHealth==='DEGRADED')state='DEGRADED';
 }
 return {result:'PASS',state,worker,supervisor,ports:{http,sql,guard,supervisor:watch},intervalSeconds:plan.intervalSeconds,managedHttp};
}
export function statusText(status){
 const lines=['State: '+status.state,'Local HTTP: '+status.ports.http+'; Local MySQL: '+status.ports.sql,
  'Minimum EAI interval: '+status.intervalSeconds+' seconds; no automatic retry of ambiguous EAI attempts'];
 if(status.worker){
  lines.push('Last update UTC: '+status.worker.utc,'Data health: '+(status.worker.dataHealth||'UNKNOWN'));
  for(const [tenant,value] of Object.entries(status.worker.clients||{})){
   lines.push(tenant+' : '+value.status+'; generation '+value.generation);
   lines.push('Publication: '+value.publicationFreshness+'; age seconds: '+value.publicationAgeSeconds);
   lines.push('Last successful publication UTC: '+value.capturedUtc);
   if(value.attention)lines.push('Action required: '+value.attention);
  }
 }
 if(status.supervisor)lines.push('Supervisor: '+(fresh(status.supervisor)?status.supervisor.stage:'STALE STATUS')+(status.supervisor.reason?'; '+status.supervisor.reason:''));
 return lines.join('\n');
}
async function waitStopped(plan,timeout){
 const deadline=Date.now()+timeout;
 do{
  if((await Promise.all(ownedPorts(plan).map(localPortOpen))).every(value=>!value))return;
  await pause(250);
 }while(Date.now()<deadline);
 throw Error('GRACEFUL_STOP_NOT_CONFIRMED_NO_FORCE_KILL');
}
export async function controlSuite(planFile,action,{startupTimeoutMs=60000,stopTimeoutMs=95000}={}){
 assert.ok(path.isAbsolute(planFile||''),'ABSOLUTE_PLAN_REQUIRED');
 assert.ok(['Start','Stop','Status'].includes(action),'KNOWN_CONTROL_ACTION_REQUIRED');
 const file=path.resolve(planFile),entry=await lstat(file);assert.ok(entry.isFile()&&!entry.isSymbolicLink(),'REGULAR_PLAN_FILE_REQUIRED');
 const plan=validateControlPlan(file,JSON.parse(await readFile(file,'utf8')));
 if(action==='Status')return snapshot(plan);
 const lockFile=path.join(plan.directory,'operator-control.lock');let lock;
 try{lock=await open(lockFile,'wx',0o600);}catch(e){if(e.code==='EEXIST')throw Error('OPERATOR_ACTION_IN_PROGRESS_REVIEW_REQUIRED');throw e;}
 try{
  await lock.writeFile(JSON.stringify({action,pid:process.pid,utc:new Date().toISOString()}));
  const controlFile=path.join(plan.directory,'control.json');
  if(action==='Stop'){
   await writeFile(controlFile,JSON.stringify({requested:'stop'}),{mode:0o600});
   await waitStopped(plan,stopTimeoutMs);
   const status=await snapshot(plan);
   return {...status,state:'STOPPED',message:'Local services stopped; owned ports are closed.',shutdownConfirmed:true};
  }
  assert.ok(plan.clients.length>=1,'ADD_CLIENT_FIRST');
  try{await lstat(path.join(plan.directory,'registration.lock'));throw Error('CLIENT_REGISTRATION_IN_PROGRESS_NO_START');}catch(e){if(e.code!=='ENOENT')throw e;}
  const before=await snapshot(plan);
  if(before.ports.supervisor){
   if(fresh(before.supervisor)&&before.supervisor.stage==='RUNNING'&&fresh(before.worker)&&before.worker.pid===before.supervisor.workerPid&&['RUNNING','DEGRADED'].includes(before.state))return {...before,alreadyRunning:true,message:'Local services are already running. Check Status for synchronization health.'};
   throw Error('SUPERVISOR_PORT_IN_USE_REVIEW_REQUIRED');
  }
  assert.ok((await Promise.all(ownedPorts(plan).map(localPortOpen))).every(value=>!value),'OWN_SERVICE_PORT_OCCUPIED');
  const node=await lstat(plan.node);assert.ok(node.isFile()&&!node.isSymbolicLink(),'NODE_RUNTIME_REQUIRED');
  await writeFile(controlFile,JSON.stringify({requested:'run'}),{mode:0o600});
  const started=Date.now();let child;
  try{
   child=spawn(plan.node,[path.join(serviceRoot,'suite-supervisor.mjs'),file],{cwd:apiRoot,detached:true,windowsHide:true,stdio:'ignore',env:{...process.env,NODE_OPTIONS:'',NODE_PATH:''}});
   await once(child,'spawn');child.unref();
   const deadline=started+startupTimeoutMs;
   do{
    const current=await snapshot(plan);
    if(current.supervisor?.pid===child.pid&&Date.parse(current.supervisor.utc)>=started){
     if(current.supervisor.stage==='MANUAL_REVIEW_REQUIRED')throw Error(current.supervisor.reason||'SUPERVISOR_REVIEW_REQUIRED');
     if(current.supervisor.stage==='RUNNING'&&fresh(current.supervisor)&&current.worker?.pid===current.supervisor.workerPid&&fresh(current.worker)&&['RUNNING','DEGRADED'].includes(current.state))return {...current,started:true,launchMethod:'bundled-node-direct',message:'Local services started. Check Status for synchronization health.'};
    }
    await pause(250);
   }while(Date.now()<deadline);
   throw Error('BACKGROUND_START_NOT_CONFIRMED');
  }catch(error){
   const supervisor=await readState(path.join(plan.directory,'supervisor-status.json'));
   // Only stop the process started by this operation. Never stop another listener.
   if(Number.isInteger(child?.pid)&&supervisor?.pid===child.pid){await writeFile(controlFile,JSON.stringify({requested:'stop'}),{mode:0o600});await waitStopped(plan,stopTimeoutMs);}
   throw error;
  }
 }finally{
  await lock.close();await rename(lockFile,path.join(plan.directory,'operator-control.closed-'+randomUUID()+'.json'));
 }
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 try{console.log(JSON.stringify(await controlSuite(process.argv[2],process.argv[3]||'Status')));}
 catch(error){console.log(JSON.stringify({result:'FAIL',category:/^[A-Z0-9_]{1,100}$/.test(error.message)?error.message:'LOCAL_CONTROL_NOT_CONFIRMED',credentialsExposed:false}));process.exitCode=1;}
}
