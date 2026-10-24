import {readFile,writeFile,rename,access} from 'node:fs/promises';
import {spawn} from 'node:child_process';
import {createServer} from 'node:net';
import http from 'node:http';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import {restartDecision,healthDecision} from './supervisor-policy.mjs';
import {statusWriter} from './status-writer.mjs';

const root=path.dirname(fileURLToPath(import.meta.url)),file=path.resolve(process.argv[2]);
const plan=JSON.parse(await readFile(file,'utf8'));
assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1');assert.equal(path.dirname(file),plan.directory);
assert.ok(plan.intervalSeconds>=60,'ONE_MINUTE_MINIMUM_REQUIRED');
const port=plan.supervisorPort??plan.lockPort+1;
assert.ok(Number.isInteger(port)&&port>=1024&&port<=65535&&![plan.lockPort,plan.apiPort,plan.sqlPort].includes(port),'SUPERVISOR_PORT_INVALID');
const stateFile=path.join(plan.directory,'supervisor-status.json'),controlFile=path.join(plan.directory,'control.json');
const workerFile=path.join(root,'suite-worker.mjs'),lock=createServer(socket=>socket.destroy());
let child,stage='STARTING',reason,stopSignal=false,restarts=0;
const read=async file=>{try{return JSON.parse(await readFile(file,'utf8'));}catch{return null;}};
const writeStatus=statusWriter(stateFile);
const status=()=>writeStatus({profile:'LOCAL_EAI_SUITE_SUPERVISOR_V1',stage,reason,pid:process.pid,workerPid:child?.pid??null,utc:new Date().toISOString(),restarts,rateLimitSeconds:plan.intervalSeconds,eaiFaultsAutomaticallyRetried:false,windowsRebootOrLogonTested:false,productionReady:false});
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const responds=()=>new Promise(resolve=>{let done=false;const finish=value=>{if(!done){done=true;resolve(value);}};const request=http.get({host:'127.0.0.1',port:plan.apiPort,path:'/',timeout:3000},response=>{response.resume();finish(true);});request.on('timeout',()=>{request.destroy();finish(false);});request.on('error',()=>finish(false));});
for(const signal of ['SIGINT','SIGTERM'])process.on(signal,()=>stopSignal=true);
try {
    await new Promise((resolve,reject)=>{lock.once('error',reject);lock.listen(port,'127.0.0.1',resolve);});
    const failures=[];
    while(true){
        const command=await read(controlFile);
        try{await access(path.join(plan.directory,'registration.lock'));stage='MANUAL_REVIEW_REQUIRED';reason='CLIENT_REGISTRATION_IN_PROGRESS';break;}catch(error){if(error.code!=='ENOENT')throw error;}
        if(stopSignal||command?.requested!=='run'){stage='STOPPED';reason='OPERATOR_STOP';break;}
        child=spawn(plan.node,[workerFile,file],{cwd:path.dirname(root),windowsHide:true,stdio:'ignore'});
        const startedAt=Date.now();let launchError=false,failedHealthChecks=0;child.once('error',()=>launchError=true);
        stage='STARTING';reason=undefined;await status();let stopRequestedAt;
        while(child.exitCode===null&&child.signalCode===null&&!launchError){
            const command=await read(controlFile),workerState=await read(path.join(plan.directory,'service-status.json'));
            const ownRestartStop=command?.requested==='stop'&&command?.supervisorPid===process.pid&&command?.restartAfterShutdown===true;
            if(stopSignal||command?.requested!=='run'&&!ownRestartStop){
                await writeFile(controlFile,JSON.stringify({requested:'stop'}));stopRequestedAt??=Date.now();stage='STOPPING';reason='OPERATOR_STOP';
            }else if(ownRestartStop){stage='STOPPING';
            }else if(healthDecision({status:workerState,workerPid:child.pid,startedAt})==='REQUEST_GRACEFUL_STOP'){
                // A hung child may own an open MySQL data directory. Never force-kill it.
                await writeFile(controlFile,JSON.stringify({requested:'stop',supervisorPid:process.pid,restartAfterShutdown:true}));stopRequestedAt??=Date.now();stage='STOPPING';reason='WORKER_HEARTBEAT_EXPIRED';
            }else if(healthDecision({status:workerState,workerPid:child.pid,startedAt})==='STARTING')stage='STARTING';
            else{
                // External HTTPS/web-server availability belongs to IT's web
                // supervisor. Never stop the collector for a listener it does
                // not own. Worker heartbeat and owned SQL remain supervised.
                if(workerState.stage==='RUNNING'&&(!plan.deployment||plan.deployment.httpMode==='managed-local'))failedHealthChecks=(await responds())?0:failedHealthChecks+1;
                if(failedHealthChecks>=3){await writeFile(controlFile,JSON.stringify({requested:'stop',supervisorPid:process.pid,restartAfterShutdown:true}));stopRequestedAt??=Date.now();stage='STOPPING';reason='OWN_HTTP_NOT_RESPONDING';}
                else stage='RUNNING';
            }
            await status();
            if(stopRequestedAt&&Date.now()-stopRequestedAt>90000){stage='MANUAL_REVIEW_REQUIRED';reason='GRACEFUL_STOP_NOT_CONFIRMED_NO_FORCE_KILL';break;}
            await pause(2000);
        }
        if(stage==='MANUAL_REVIEW_REQUIRED')break;
        const finalCommand=await read(controlFile),workerState=await read(path.join(plan.directory,'service-status.json'));
        const watchdogRestart=finalCommand?.requested==='stop'&&finalCommand?.supervisorPid===process.pid&&finalCommand?.restartAfterShutdown===true;
        const decision=restartDecision({requested:watchdogRestart?'run':finalCommand?.requested,watchdogRestart,exitCode:child.exitCode,workerPid:child.pid,status:workerState,failures});
        reason=launchError?'WORKER_LAUNCH_FAILED':decision.reason;
        if(launchError||decision.action==='review'){stage='MANUAL_REVIEW_REQUIRED';break;}
        if(decision.action==='stop'){stage='STOPPED';break;}
        if(watchdogRestart)await writeFile(controlFile,JSON.stringify({requested:'run'}));
        failures.push(Date.now());restarts++;stage='RESTART_DELAY';await status();
        // The persisted database dispatch reservation still enforces >= 60 seconds.
        await pause(decision.delayMs);
    }
}catch(error){stage='MANUAL_REVIEW_REQUIRED';reason=error.code==='EADDRINUSE'?'SUPERVISOR_ALREADY_RUNNING':error.code==='EACCES'?'SUPERVISOR_PORT_UNAVAILABLE':'SUPERVISOR_START_FAILED';}
finally{if(lock.listening){await status();await new Promise(resolve=>lock.close(resolve));}else process.stdout.write(JSON.stringify({result:'FAIL',category:reason,eaiCalls:0})+'\n');}
