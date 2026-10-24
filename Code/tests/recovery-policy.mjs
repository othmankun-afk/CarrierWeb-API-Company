import assert from 'node:assert/strict';import {recoveryReview,resumeOne} from '../tools/recovery-policy.mjs';
let checks=0;const check=v=>{assert.ok(v);checks++;};
const state={generation:70,status:'COLLECTING',inFlight:true,lastAttemptUtc:'2026-10-20 23:21:23'};
const stamp=Date.parse('2026-10-20T23:21:23Z');
check(recoveryReview(state,{now:stamp+61000}).expectedGeneration===70);
for(const age of [0,59000,60000]){assert.throws(()=>recoveryReview(state,{now:stamp+age}));checks++;}
assert.throws(()=>recoveryReview({...state,inFlight:false,status:'PUBLISHED'},{now:stamp+61000}));checks++;
const plan={intervalSeconds:60},client={tenant:'fixture',initialCursors:{},ruleProfilePath:'fictitious',ruleProfileSha256:'fixture'},connection={};
const input={plan,client,connection,state,approval:'ONE_REVIEWED_EAI_ATTEMPT',quietConfirmed:true};
for(const failAt of [null,'recover','dispatch','collect','save','accept']){
 let calls=0,saved=false,actions=[];
 const deps={database:async(_p,_c,action)=>{actions.push(action);if(action===failAt)throw Error('FICTITIOUS_FAILURE');return {result:'PASS',generation:71};},collect:async()=>{calls++;if(failAt==='collect')throw Error('FICTITIOUS_FAILURE');return {result:'PASS'};},saveCapture:async()=>{if(failAt==='save')throw Error('FICTITIOUS_FAILURE');saved=true;}};
 if(failAt){await assert.rejects(resumeOne(input,deps));checks++;}else{const r=await resumeOne(input,deps);check(r.result==='PASS'&&r.transportAttempts===1&&r.generation===71);check(saved);}
 check(calls<=1);check(!actions.includes('delete'));check(!actions.includes('reset-cursors'));
}
let called=false;const noCalls={database:async()=>{called=true;}};
await assert.rejects(resumeOne({...input,approval:null},noCalls));check(!called);
await assert.rejects(resumeOne({...input,quietConfirmed:false},noCalls));check(!called);
console.log(JSON.stringify({result:'PASS',suite:'recovery-policy',checks,eaiCalls:0,filesDeleted:0}));
