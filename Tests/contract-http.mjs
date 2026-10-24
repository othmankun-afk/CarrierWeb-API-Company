// Public-wire regression with generated demonstration rows only. No SQL/EAI.
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createServer,createConnection} from 'node:net';
import http from 'node:http';
import path from 'node:path';
import assert from 'node:assert/strict';
import {apiRoot} from '../Code/service/runtime.mjs';
import {runtime,restrictEnvironment} from '../Code/tools/portable.mjs';
export async function contractHttp(){
 restrictEnvironment();const listener=createServer();listener.listen(0,'127.0.0.1');await once(listener,'listening');const port=listener.address().port;await new Promise(r=>listener.close(r));
 const server=spawn(runtime.php,['-n','-d','expose_php=0','-S','127.0.0.1:'+port,'-t',path.join(apiRoot,'public'),path.join(apiRoot,'public/router.php')],{cwd:apiRoot,windowsHide:true,stdio:'ignore',env:{...process.env,FLEET_API_DEMO:'1',FLEET_API_CONFIG:'',FLEET_API_AUDIT_LOG:'',FLEET_API_LOCAL_TEST:'1'}});
 let checks=0;const verify=(v,label)=>{assert.ok(v,label);checks++;};
 const get=(url,method='GET')=>new Promise((resolve,reject)=>{const q=http.request({host:'127.0.0.1',port,path:url,method,agent:false},r=>{let body='';r.on('data',b=>body+=b);r.on('end',()=>resolve({status:r.statusCode,headers:r.headers,body}));});q.on('error',reject);q.setTimeout(4000,()=>q.destroy(Error('CONTRACT_HTTP_TIMEOUT')));q.end();});
 const uri=(ep,changes={})=>{const q=new URLSearchParams({password:'demo-local-token-2026',...(['positions','canbus','telemetry'].includes(ep)?{vehicleid:'7',ymdfrom:'2030-01-02 10:00:00',ymdto:'2030-01-02 10:02:00'}:{}),...changes});return '/cweai/demo/'+ep+'?'+q;};
 try{
  let ready=false;for(let i=0;i<40;i++){try{await get('/');ready=true;break;}catch{await new Promise(r=>setTimeout(r,100));}}verify(ready,'HTTP_READY');
  for(const [ep,fields] of Object.entries({vehicles:44,vehiclesBase:14,positions:14,canbus:33,telemetry:7})){
   const r=await get(uri(ep)),rows=JSON.parse(r.body);verify(r.status===200&&Array.isArray(rows)&&rows.length>0,'FIVE_ROUTES');verify(rows.every(x=>Object.keys(x).length===fields&&x.CWVehicleID===7),'WIRE_SHAPE_SCOPE');verify((r.headers['content-type']||'').startsWith('application/json'),'JSON_MIME');verify(!r.headers['x-powered-by'],'NO_RUNTIME_HEADER');
   const denied=await get(uri(ep,{password:'fictitious-wrong-token'}));verify(denied.status===503&&denied.body==='','WRONG_SECRET_DENIED');
   verify((await get(uri(ep),'POST')).status===503,'READ_ONLY_METHOD');
   if(!['positions','canbus','telemetry'].includes(ep))continue;
   const exact=await get(uri(ep,{ymdfrom:'2030-01-02 10:01:00',ymdto:'2030-01-02 10:01:00'}));verify(JSON.parse(exact.body).length===1,'INCLUSIVE_BOTH_BOUNDARIES');
   verify((await get(uri(ep,{ymdfrom:'2030-01-03',ymdto:'2030-01-02'}))).body==='[]','REVERSED_RANGE');
   verify((await get(uri(ep,{ymdfrom:'2030-01-02',ymdto:'2030-01-02'}))).body==='[]','DATE_ONLY_IS_MIDNIGHT_NOT_WHOLE_DAY');
   verify(JSON.parse((await get(uri(ep,{ymdfrom:'2030-01-02',ymdto:'2030-01-02 23:59:59'}))).body).length===3,'EXPLICIT_FULL_DAY');
   verify(JSON.parse((await get(uri(ep,{ymdfrom:'2030-01-02 10:00',ymdto:'2030-01-02 10:02'}))).body).length===3,'MINUTE_INPUT_FORMAT');
   verify((await get(uri(ep,{ymdfrom:'2030-01-02 09:00:00',ymdto:'2030-01-02 09:01:00'}))).body==='[]','EMPTY_LOCAL_WINDOW');
   verify((await get(uri(ep,{vehicleid:'8'}))).status===503,'FOREIGN_VEHICLE');
   for(const bad of ['2030-02-30','2030-01-02T10:00:00Z','2030-01-02 24:00:00',"2030-01-02' OR 1=1 --"]){const invalid=await get(uri(ep,{ymdfrom:bad}));verify(ep==='positions'?invalid.status===200&&invalid.body.startsWith('ERR: '):invalid.status===503&&invalid.body==='','INVALID_DATE_WIRE');}
   verify((await get(uri(ep)+'&vehicleid=8')).status===503,'DUPLICATE_PARAMETER_DENIED');
   const first=rows[0];verify(typeof first.ID==='string'&&first.ID==='90071992547409930','LARGE_ID_TEXT_UNCHANGED');verify(ep==='canbus'?first.MeasuredTime==='02.01.2030 10:00:00':first.MeasuredTime==='2030-01-02 10:00:00','DATE_OUTPUT_FORMAT');
  }
  const missing=await get('/cweai/demo/positions?password=demo-local-token-2026');verify(missing.status===200&&missing.body.length>0,'POSITIONS_MISSING_PARAMETERS_COMPATIBILITY');
  verify((await get(uri('positions',{ymdfrom:''}))).body==='[]','POSITIONS_EMPTY_FROM_COMPATIBILITY');
  verify((await get('/src/Bootstrap.php')).status===503,'SOURCE_NOT_EXPOSED');
  return {result:'PASS',suite:'five-endpoint-public-http-dates-and-errors',checks,eaiCalls:0,oldApiCalls:0,companyWrites:0,fixtureOnly:true,validatesImplementationNotOldProviderParity:true};
 }finally{if(server.exitCode===null){server.kill();await Promise.race([once(server,'exit'),new Promise(r=>setTimeout(r,3000))]);}assert.ok(server.exitCode!==null||server.signalCode!==null,'OWN_HTTP_STOP_REQUIRED');}
}
