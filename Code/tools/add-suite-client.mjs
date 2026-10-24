// Secret-bearing input is DPAPI CurrentUser, never a password command argument.
// This is a local administrator tool, not a public onboarding endpoint.
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createServer} from 'node:net';
import http from 'node:http';
import path from 'node:path';
import {randomBytes} from 'node:crypto';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {apiRoot,serviceRoot,execute,sourcePins,spawnDatabase} from '../service/runtime.mjs';
import {collectSuite,suiteDatabase} from '../service/suite-runtime.mjs';
import {addLiveClient,SUITE_ENDPOINTS} from './add-suite-client-core.mjs';

export async function reservePort(port){
    const server=createServer();await new Promise((resolve,reject)=>{server.once('error',()=>reject(Error('SERVICE_MUST_BE_STOPPED_PORT_OCCUPIED')));server.listen(port,'127.0.0.1',resolve);});
    return server;
}
async function freePort(){const s=await reservePort(0),port=s.address().port;await new Promise(r=>s.close(r));return port;}
async function get(port,target){return new Promise((resolve,reject)=>{const q=http.get({hostname:'127.0.0.1',port,path:target},r=>{let body='';r.on('data',b=>{body+=b;if(body.length>16777216)q.destroy(Error('ONBOARDING_HTTP_BOUND'));});r.on('end',()=>resolve({status:r.statusCode,body}));});q.on('error',()=>reject(Error('ONBOARDING_HTTP_CONNECTION')));q.setTimeout(4000,()=>q.destroy());});}
export async function verifyFirstHttp(plan,stage,config,access){
    const filename=path.join(stage,'first-http-config.json');await writeFile(filename,JSON.stringify(config),{flag:'wx',mode:0o600});const port=await freePort();
    const server=spawn(plan.php,['-n','-d',`extension_dir="${path.join(path.dirname(plan.php),'ext').replaceAll('\\','/')}"`,'-d','extension=php_mysqli.dll','-d','display_errors=0','-d','log_errors=0','-S',`127.0.0.1:${port}`,'-t',path.join(apiRoot,'public'),path.join(apiRoot,'public/router.php')],{cwd:apiRoot,windowsHide:true,stdio:'ignore',env:{...process.env,FLEET_API_LOCAL_TEST:'1',FLEET_API_CONFIG:filename,FLEET_API_DEMO:'',FLEET_API_AUDIT_LOG:''}});
    try{
        // Probe a fixed uncredentialed unsupported route; do not consume any
        // endpoint's first authenticated 60-second slot during readiness.
        let ready=false;for(let i=0;i<40;i++){if(server.exitCode!==null)throw Error('ONBOARDING_HTTP_START');try{await get(port,'/');ready=true;break;}catch{await new Promise(r=>setTimeout(r,100));}}assert.ok(ready,'ONBOARDING_HTTP_START');
        const id=String(access.capture.bundle.feeds.vehicles[0].CWVehicleID),stamp=access.capture.bundle.capturedUtc.slice(0,19).replace('T',' '),day=stamp.slice(0,10);
        for(const endpoint of SUITE_ENDPOINTS){const history=['positions','canbus','telemetry'].includes(endpoint);const query='password='+encodeURIComponent(access.apiPassword)+(history?'&vehicleid='+encodeURIComponent(id)+'&ymdfrom='+day+'&ymdto='+encodeURIComponent(stamp):'');
            const r=await get(port,'/cweai/'+access.account+'/'+endpoint+'?'+query);assert.equal(r.status,200,'FIRST_HTTP_PUBLICATION_REQUIRED');let rows;try{rows=JSON.parse(r.body);}catch{throw Error('FIRST_HTTP_JSON_REQUIRED');}assert.ok(Array.isArray(rows),'FIRST_HTTP_ROWS_REQUIRED');if(!history)assert.equal(rows.length,access.capture.bundle.feeds.vehicles.length,'FIRST_HTTP_SCOPE_REQUIRED');
        }
        // Cadence is exercised for real, on the same actual endpoint/token.
        const repeat=await get(port,'/cweai/'+access.account+'/vehicles?password='+encodeURIComponent(access.apiPassword));assert.notEqual(repeat.status,200,'FIRST_HTTP_MINUTE_RULE_REQUIRED');
        return {result:'PASS',endpointsVerified:5,rateLimitVerified:true};
    }finally{if(server.exitCode===null&&server.signalCode===null){server.kill();await Promise.race([once(server,'exit'),new Promise(r=>setTimeout(r,3000))]);}if(server.exitCode===null&&server.signalCode===null)throw Error('ONBOARDING_HTTP_STOP_UNCONFIRMED');}
}
export function localDependencies(){
    let sql,activePlan,root;
    return {
        assertStopped:async plan=>{let control;try{control=JSON.parse(await readFile(path.join(plan.directory,'control.json')));}catch{throw Error('SERVICE_STOP_CONFIRMATION_REQUIRED');}assert.equal(control.requested,'stop','SERVICE_STOP_CONFIRMATION_REQUIRED');for(const port of [plan.lockPort,plan.apiPort,plan.sqlPort,plan.supervisorPort].filter(Number.isInteger)){const s=await reservePort(port);await new Promise(r=>s.close(r));}},
        verifySources:sourcePins,
        sdkExtra:async(plan,input)=>{const filename=input.sdkExtraPath||plan.sdkExtraPath||path.join(plan.clients[0]?.directory||'','extra.xml');assert.ok(path.isAbsolute(filename),'SDK_EXTRA_REQUIRED');return readFile(filename,'utf8');},
        readSecrets:(directory,filename='secrets.dpapi')=>execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(serviceRoot,'secrets.ps1'),'-Operation','Read','-Path',path.join(directory,filename)]),
        writeSecrets:(filename,value)=>execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(serviceRoot,'secrets.ps1'),'-Operation','Write','-Path',filename],JSON.stringify(value)),
        startSql:async(plan,secrets)=>{activePlan=plan;root={host:'127.0.0.1',port:plan.sqlPort,username:'root',password:secrets.rootPassword};sql=spawnDatabase(plan);let ready=false;for(let i=0;i<50;i++){if(sql.exitCode!==null)throw Error('OWN_SQL_EXITED');try{await suiteDatabase(plan,root,'ready');ready=true;break;}catch{await new Promise(r=>setTimeout(r,200));}}assert.ok(ready,'OWN_SQL_NOT_READY');},
        provision:(plan,_secrets,client)=>suiteDatabase(plan,root,'provision',{tenant:client.tenant,schema:'suite_'+randomBytes(8).toString('hex'),readerPassword:randomBytes(32).toString('hex'),writerPassword:randomBytes(32).toString('hex'),...(client.capacityPolicy?{capacityPolicy:client.capacityPolicy}:{})}),
        database:suiteDatabase,collect:collectSuite,verifyHttp:verifyFirstHttp,
        stopSql:async()=>{if(sql&&sql.exitCode===null){await suiteDatabase(activePlan,root,'shutdown');await Promise.race([once(sql,'exit'),new Promise(r=>setTimeout(r,7000))]);if(sql.exitCode===null)throw Error('OWN_SQL_SHUTDOWN_UNCONFIRMED');}}
    };
}
async function main(){
    const [planArg,inputArg]=process.argv.slice(2);assert.ok(path.isAbsolute(planArg||'')&&path.isAbsolute(inputArg||''),'ABSOLUTE_PRIVATE_PATHS_REQUIRED');
    assert.ok(path.dirname(inputArg)===path.dirname(planArg),'INPUT_MUST_BE_IN_PRIVATE_INSTALLATION');
    let input;try{input=await execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(serviceRoot,'secrets.ps1'),'-Operation','Read','-Path',inputArg]);const result=await addLiveClient(planArg,input,localDependencies());console.log(JSON.stringify(result));}
    finally{input=null;}
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){try{await main();}catch(e){console.log(JSON.stringify({result:'FAIL',category:/^[A-Z0-9_]{1,80}$/.test(e.message)?e.message:'ONBOARDING_NOT_CONFIRMED',credentialsExposed:false,automaticRetry:false,productionReady:false}));process.exitCode=1;}}
