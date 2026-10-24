import {readFile} from 'node:fs/promises';
import {createInterface} from 'node:readline/promises';
import {emitKeypressEvents} from 'node:readline';
import path from 'node:path';
import assert from 'node:assert/strict';
import {addLiveClient,pendingClientReview} from './add-suite-client-core.mjs';
import {localDependencies} from './add-suite-client.mjs';

export function readMaskedLine(prompt,{input=process.stdin,output=process.stdout}={}){
 assert.ok(input.isTTY&&typeof input.setRawMode==='function','INTERACTIVE_TERMINAL_REQUIRED');
 output.write(prompt);emitKeypressEvents(input);const previousRaw=input.isRaw===true,characters=[];
 return new Promise((resolve,reject)=>{
  const finish=(error)=>{input.removeListener('keypress',onKey);input.removeListener('end',onEnd);input.setRawMode(previousRaw);input.pause();output.write('\n');const value=characters.join('');characters.fill('');if(error)reject(Error(error));else resolve(value);};
  const onEnd=()=>finish('OPERATOR_INPUT_CLOSED');
  const onKey=(text,key={})=>{
   if(key.ctrl&&(key.name==='c'||key.name==='d'))return finish('OPERATOR_CANCELLED');
   if(key.name==='return'||key.name==='enter')return finish();
   if(key.name==='backspace'){if(characters.length){characters.pop();output.write('\b \b');}return;}
   if(key.ctrl||key.meta||!text||/[\x00-\x1f\x7f]/.test(text))return;
   if(characters.length+Array.from(text).length>4096)return;
   for(const character of text){characters.push(character);output.write('*');}
  };
  input.on('keypress',onKey);input.once('end',onEnd);input.setRawMode(true);input.resume();
 });
}
export async function onboardInteractive(planFile,{ask,secret,register=addLiveClient,review=pendingClientReview,dependencies,output=process.stdout,input=process.stdin}={}){
 assert.ok(path.isAbsolute(planFile||''),'ABSOLUTE_PLAN_REQUIRED');
 const plan=JSON.parse(await readFile(planFile,'utf8'));
 assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1','SUITE_PLAN_REQUIRED');assert.equal(plan.directory,path.dirname(planFile),'PLAN_DIRECTORY_MISMATCH');
 let reader,password,request;
 if(!ask){assert.ok(input.isTTY,'INTERACTIVE_TERMINAL_REQUIRED');reader=createInterface({input,output,terminal:true});ask=prompt=>reader.question(prompt);}
 secret??=prompt=>readMaskedLine(prompt,{input,output});
 try{
  output.write('Stop the service before adding a client. At most one EAI read; no automatic retry.\n');
  const quiet=await ask('No known call for THIS EAI account during the last minute? Answer YES: ');
  assert.equal(quiet,'YES','EAI_QUIET_MINUTE_CONFIRMATION_REQUIRED');
  const name=await ask('New client name: '),eaiUsername=await ask('EAI username: ');
  const pending=await review(planFile,eaiUsername);let resume={};
  if(pending){
   output.write('An unfinished registration exists for this account. Last step: '+pending.phase+'; result: '+pending.category+'.\n');
   assert.ok(!pending.registryReviewRequired&&pending.phase!=='REGISTRY_COMMIT','ONBOARDING_REGISTRY_REVIEW_REQUIRED');
   output.write('Resume keeps the same client and database. A saved valid response is reused; otherwise one new EAI read needs your approval.\n');
   const approval=await ask('Resume this registration? Type RESUME, or press Enter to cancel: ');
   assert.equal(approval,'RESUME','OPERATOR_CANCELLED');
   resume={resumeApproval:'RESUME_STAGED_CLIENT',resumeTenant:pending.tenant};
  }
  reader?.close();reader=null;
  password=await secret('EAI password (masked; do not use the API password): ');
  assert.ok(typeof password==='string'&&password.length>0,'EAI_PASSWORD_REQUIRED');
  request={name,eaiUsername,eaiPassword:password,noKnownEaiCallInLastMinute:true,approvedUnknownPolicy:'legacy-markers-v1',approvedCounterPolicy:'approved-compatibility-zero-empty-v1',...resume};
  const result=await register(planFile,request,dependencies||localDependencies());
  output.write('The private API access sheet is indicated in the result. Then use Start and check Status.\n');
  return result;
 }finally{reader?.close();if(request)request.eaiPassword='';request=null;password=null;}
}
