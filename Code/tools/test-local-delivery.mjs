// Test the five local HTTP endpoints; reports contain only verification results.
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {randomUUID} from 'node:crypto';
import path from 'node:path';
import http from 'node:http';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {execute,serviceRoot} from '../service/runtime.mjs';
export const ENDPOINTS=['vehicles','vehiclesBase','positions','canbus','telemetry'];
export function testTarget(account,password,endpoint,vehicleId,now=new Date()){
    assert.match(account,/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/);
    assert.ok(typeof password==='string'&&password.length>0&&password.length<=512);
    assert.ok(ENDPOINTS.includes(endpoint));
    const query=new URLSearchParams({password});
    if(['positions','canbus','telemetry'].includes(endpoint)){
        assert.match(String(vehicleId),/^[1-9][0-9]{0,9}$/);
        const utc=now.toISOString().slice(0,19).replace('T',' ');
        query.set('vehicleid',String(vehicleId));query.set('ymdfrom',utc.slice(0,10));query.set('ymdto',utc);
    }
    return '/cweai/'+account+'/'+endpoint+'?'+query.toString();
}
export function summarize(endpoint,status,body,headers={}){
    if(status===429||(status===503&&Number(headers['retry-after'])>0)||(status===200&&body.startsWith('API ERROR: getPositionsHistory function limit request per 1 minute reached'))){return {endpoint,result:'WAIT',category:'API_MINUTE_RULE',retryAfterSeconds:Math.max(60,Number(headers['retry-after'])||60)};}
    if(status!==200)return {endpoint,result:'FAIL',category:'HTTP_'+status};
    let rows;try{rows=JSON.parse(body);}catch{return {endpoint,result:'FAIL',category:'NON_JSON_RESPONSE'};}
    if(!Array.isArray(rows)||rows.some(r=>!r||typeof r!=='object'||Array.isArray(r)||!Number.isInteger(r.CWVehicleID)))return {endpoint,result:'FAIL',category:'JSON_ROWS_INVALID'};
    if(headers['x-data-freshness']==='STALE')return {endpoint,result:'DEGRADED',category:'LAST_KNOWN_PUBLICATION',rows:rows.length,publicationCapturedUtc:headers['x-data-captured-at'],publicationAgeSeconds:Number(headers['x-data-age-seconds'])};
    return {endpoint,result:'PASS',rows:rows.length};
}
function localGet(port,target){return new Promise(resolve=>{
    const request=http.get({hostname:'127.0.0.1',port,path:target},response=>{
        let size=0,body='';response.on('data',chunk=>{size+=chunk.length;if(size>16777216)request.destroy();else body+=chunk;});
        response.on('end',()=>resolve({status:response.statusCode,body,headers:response.headers}));
        response.on('error',()=>resolve({status:0,body:'',headers:{}}));
    });request.on('error',()=>resolve({status:0,body:'',headers:{}}));request.setTimeout(10000,()=>request.destroy());
});}
export async function testLocalDelivery(planFile){
    assert.ok(path.isAbsolute(planFile),'ABSOLUTE_PLAN_REQUIRED');
    const plan=JSON.parse(await readFile(planFile));assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1','SUITE_PLAN_REQUIRED');
    assert.equal(path.dirname(planFile),plan.directory,'PLAN_DIRECTORY_MISMATCH');assert.ok(Number.isInteger(plan.apiPort)&&plan.apiPort>=1024&&plan.apiPort<=65535,'LOCAL_API_PORT_REQUIRED');
    const config=JSON.parse(await readFile(path.join(plan.directory,'http-config.json'))),results=[];
    let secrets;
    try{
        secrets=await execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(serviceRoot,'secrets.ps1'),'-Operation','Read','-Path',path.join(plan.directory,'secrets.dpapi')]);
        for(const [account,entry] of Object.entries(config.accounts)){
            const password=secrets.clients?.[entry.tenant]?.apiPassword;
            if(!password){results.push({account,result:'FAIL',category:'LOCAL_ACCESS_SECRET_MISSING'});continue;}
            let sample;
            for(const endpoint of ENDPOINTS){
                if(['positions','canbus','telemetry'].includes(endpoint)&&!sample){results.push({account,endpoint,result:'SKIPPED',category:'AUTHORIZED_VEHICLE_NOT_OBTAINED'});continue;}
                const response=await localGet(plan.apiPort,testTarget(account,password,endpoint,sample));
                const summary=summarize(endpoint,response.status,response.body,response.headers);results.push({account,...summary});
                if(endpoint==='vehicles'&&['PASS','DEGRADED'].includes(summary.result)&&summary.rows>0)sample=JSON.parse(response.body)[0].CWVehicleID;
            }
        }
    }finally{secrets=null;}
    const result=results.length===0?'NO_CLIENT':results.every(r=>r.result==='PASS')?'PASS':results.some(r=>r.result==='FAIL')?'FAIL':results.some(r=>r.result==='DEGRADED')?'DEGRADED':'WAIT_OR_SKIPPED';
    const report={result,profile:'LOCAL_HTTP_RECETTE_ONLY_V1',utc:new Date().toISOString(),results,eaiCalls:0,oldApiCalls:0,responseBodiesRecorded:false,credentialsRecorded:false,filesDeleted:0,productionReady:false};
    const folder=path.join(plan.directory,'recette-reports');await mkdir(folder,{recursive:true});const reportPath=path.join(folder,'http-'+randomUUID()+'.json');await writeFile(reportPath,JSON.stringify(report,null,2)+'\n',{flag:'wx',mode:0o600});
    return {result,reportPath,message:results.map(r=>(r.endpoint||'account')+': '+r.result+(r.rows!==undefined?' - '+r.rows+' rows':'')+(r.category?' - '+r.category:'')).join('\n'),accounts:Object.keys(config.accounts).length,eaiCalls:0,credentialsExposed:false,productionReady:false};
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
    try{const result=await testLocalDelivery(process.argv[2]);console.log(JSON.stringify(result));if(result.result!=='PASS')process.exitCode=1;}
    catch(e){console.log(JSON.stringify({result:'FAIL',category:/^[A-Z0-9_]{1,100}$/.test(e.message)?e.message:'LOCAL_HTTP_TEST_NOT_CONFIRMED',eaiCalls:0,credentialsExposed:false,productionReady:false}));process.exitCode=1;}
}
