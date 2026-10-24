import assert from 'node:assert/strict';
export function recoveryReview(state,{now=Date.now(),intervalSeconds=60}={}){
 assert.ok(Number.isInteger(state?.generation)&&state.generation>=0,'RECOVERY_STATE_REQUIRED');
 assert.ok(state.inFlight===true||String(state.status).startsWith('FAULT_'),'RECOVERY_NOT_REQUIRED');
 const stamp=Date.parse(String(state.lastAttemptUtc).replace(' ','T')+'Z');
 assert.ok(Number.isFinite(stamp)&&now-stamp>=(Math.max(60,intervalSeconds)+1)*1000,'RECOVERY_QUIET_MINUTE_REQUIRED');
 return {expectedGeneration:state.generation,expectedStatus:state.status,expectedLastAttemptUtc:state.lastAttemptUtc,reason:'MANUAL_REVIEW_AFTER_LOCAL_FAILURE'};
}
export async function resumeOne({plan,client,connection,state,approval,quietConfirmed},deps){
 assert.equal(approval,'ONE_REVIEWED_EAI_ATTEMPT','EXPLICIT_SINGLE_ATTEMPT_APPROVAL_REQUIRED');
 assert.equal(quietConfirmed,true,'EXTERNAL_QUIET_MINUTE_CONFIRMATION_REQUIRED');
 const review=recoveryReview(state,{intervalSeconds:plan.intervalSeconds});let transportAttempts=0;
 await deps.database(plan,connection,'recover',{tenant:client.tenant,review});
 try{
  await deps.database(plan,connection,'dispatch',{tenant:client.tenant,intervalSeconds:plan.intervalSeconds});
  transportAttempts++;
  const capture=await deps.collect(); // Exactly one invocation; never a loop.
  await deps.saveCapture(capture);
  const accepted=await deps.database(plan,connection,'accept',{tenant:client.tenant,capture,ruleProfilePath:client.ruleProfilePath,ruleProfileSha256:client.ruleProfileSha256});
  assert.equal(accepted.result,'PASS','RECOVERY_PUBLICATION_NOT_CONFIRMED');
  return {result:'PASS',generation:accepted.generation,transportAttempts,automaticRetry:false,cursorsReset:false,historyDeleted:false};
 }catch(e){const category=/^[A-Z][A-Z0-9_]{0,47}$/.test(e.message)?e.message:'RECOVERY_NOT_CONFIRMED';try{await deps.database(plan,connection,'fault',{tenant:client.tenant,category});}catch{}throw Error(category);}
}
