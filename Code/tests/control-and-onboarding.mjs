import {readFile,writeFile,mkdir,mkdtemp} from 'node:fs/promises';
import {PassThrough,Writable} from 'node:stream';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import {stateAge,validateControlPlan,statusText} from '../service/control-suite.mjs';
import {readMaskedLine,onboardInteractive} from '../tools/onboard-interactive.mjs';

if(process.argv.includes('--terminal-fixture')){
 const value=await readMaskedLine('Fictitious test password: ');
 assert.equal(value,'fixture-secret-9x');
 console.log(JSON.stringify({result:'PASS',terminalMasking:true,passwordPrinted:false}));
}else{
 let checks=0;const check=value=>{assert.ok(value);checks++;};
 const root=path.dirname(path.dirname(fileURLToPath(import.meta.url)));
 const directory=path.join(root,'test-results');await mkdir(directory,{recursive:true});
 const fixture=await mkdtemp(path.join(directory,'control-unit-')),file=path.join(fixture,'service-plan.json');
 const plan={profile:'LOCAL_EAI_SUITE_V1',directory:fixture,node:process.execPath,clients:[{tenant:'synthetic'}],intervalSeconds:60,sqlPort:12001,apiPort:12002,lockPort:12003,supervisorPort:12004};
 check(validateControlPlan(file,plan)===plan);
 for(const altered of [{profile:'wrong'},{directory:path.dirname(fixture)},{intervalSeconds:59},{intervalSeconds:60.5},{node:'node.exe'},{sqlPort:0},{apiPort:65536},{supervisorPort:plan.lockPort},{clients:null}]){assert.throws(()=>validateControlPlan(file,{...plan,...altered}));checks++;}
 assert.throws(()=>validateControlPlan('relative.json',plan));checks++;
 const now=Date.parse('2026-10-21T12:00:00Z');check(stateAge('2026-10-21T11:59:55Z',now)===5);check(stateAge('2026-10-21T12:00:10Z',now)===-10);
 for(const stamp of [null,'not-a-date',{},false])check(stateAge(stamp,now)===Infinity);
 const text=statusText({state:'STOPPED',intervalSeconds:60,ports:{http:false,sql:false},worker:null,supervisor:null});check(text.includes('STOPPED')&&text.includes('60 seconds'));
 function terminal(){const input=new PassThrough();input.isTTY=true;input.isRaw=false;input.setRawMode=value=>{input.isRaw=value;return input;};let output='';const sink=new Writable({write(chunk,_encoding,callback){output+=chunk.toString();callback();}});return {input,sink,output:()=>output};}
 {
  const t=terminal(),pending=readMaskedLine('Password: ',{input:t.input,output:t.sink});
  for(const character of 'secretX')t.input.emit('keypress',character,{name:character});
  t.input.emit('keypress',undefined,{name:'backspace'});t.input.emit('keypress','9',{name:'9'});t.input.emit('keypress','\r',{name:'return'});
  check(await pending==='secret9');check(!t.output().includes('secret')&&!t.output().includes('9'));check(t.output().includes('*******'));check(t.input.isRaw===false);
 }
 {
  const t=terminal(),pending=readMaskedLine('Password: ',{input:t.input,output:t.sink});
  t.input.emit('keypress','x',{name:'x'});t.input.emit('keypress','\u0003',{name:'c',ctrl:true});
  await assert.rejects(pending,/OPERATOR_CANCELLED/);checks++;check(t.input.isRaw===false);check(!t.output().includes('x'));
 }
 assert.throws(()=>readMaskedLine('Password: ',{input:new PassThrough(),output:process.stdout}),/INTERACTIVE_TERMINAL_REQUIRED/);checks++;
 await writeFile(file,JSON.stringify(plan),{flag:'wx'});
 let registrations=0,secretReads=0,log='';const output={write:value=>{log+=value;}};
 await assert.rejects(onboardInteractive(file,{ask:async()=> 'NO',secret:async()=>{secretReads++;return 'never-used';},register:async()=>{registrations++;},output}),/EAI_QUIET_MINUTE_CONFIRMATION_REQUIRED/);checks++;
 check(registrations===0&&secretReads===0);
 const answers=['YES','Synthetic client','synthetic.eai'];let captured;
 const result=await onboardInteractive(file,{ask:async()=>answers.shift(),secret:async()=> 'fictitious-secret',register:async(_file,request)=>{registrations++;captured={...request};return {result:'PASS',eaiCalls:0};},output,dependencies:{}});
 check(result.result==='PASS'&&registrations===1);check(captured.name==='Synthetic client'&&captured.eaiUsername==='synthetic.eai');check(captured.eaiPassword==='fictitious-secret');check(captured.noKnownEaiCallInLastMinute===true);check(captured.approvedUnknownPolicy==='legacy-markers-v1');check(!log.includes('fictitious-secret'));
 console.log(JSON.stringify({result:'PASS',suite:'node-control-and-masked-onboarding',checks,eaiCalls:0,servicesStarted:0,windowsPolicyChanged:false,filesDeleted:0}));
}
