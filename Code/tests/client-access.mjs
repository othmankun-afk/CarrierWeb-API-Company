// Synthetic files only. The Explorer boundary is injected; no UI, SQL or EAI.
import {mkdtemp,mkdir,readFile,writeFile,readdir,lstat,symlink} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {clientAccess,listClientAccess} from '../tools/client-access.mjs';

const parent=process.argv[2];assert.ok(path.isAbsolute(parent||''),'ABSOLUTE_TEST_PARENT_REQUIRED');
await mkdir(parent,{recursive:true});const fixture=await mkdtemp(path.join(parent,'access-'));
const root=path.join(fixture,'Private instance with spaces'),planFile=path.join(root,'service-plan.json');
await mkdir(root);let checks=0;
const check=value=>{assert.ok(value);checks++;};
const reject=async (operation,pattern)=>{await assert.rejects(operation,pattern);checks++;};
const clients=[];
for(const label of ['customer-one-aaaaaaaaaaaa','customer-two-bbbbbbbbbbbb']){
 const directory=path.join(root,'client-onboarding',label);await mkdir(directory,{recursive:true});
 const file=path.join(directory,'ACCES-CLIENT-PRIVE.json');
 // Deliberately not JSON: locating a sheet must not parse its credentials.
 await writeFile(file,'private fixture marker: never print or parse');
 clients.push({tenant:label,directory,username:'unused-upstream-identity'});
}
const plan={profile:'LOCAL_EAI_SUITE_V1',directory:root,clients};
const save=async value=>writeFile(planFile,JSON.stringify(value));await save(plan);
await writeFile(path.join(root,'service-status.json'),JSON.stringify({state:'RUNNING'}));
const ignored=path.join(root,'Essais-locaux','fictitious-customer');await mkdir(ignored,{recursive:true});
await writeFile(path.join(ignored,'ACCES-CLIENT-PRIVE.json'),'unregistered test access must be ignored');
const snapshot=async directory=>{
 const files=[];
 async function visit(folder){for(const entry of await readdir(folder,{withFileTypes:true})){const file=path.join(folder,entry.name);if(entry.isDirectory())await visit(file);else if(entry.isFile())files.push([path.relative(directory,file),createHash('sha256').update(await readFile(file)).digest('hex')]);}}
 await visit(directory);return JSON.stringify(files.sort((a,b)=>a[0].localeCompare(b[0])));
};
const before=await snapshot(fixture),output={text:'',write(value){this.text+=value;}};
let opened=[];const openFile=async file=>opened.push(file);
let listed=await listClientAccess(planFile);
check(listed.length===2);check(listed.every(client=>client.available));check(listed[0].label==='CUSTOMER ONE');
check(listed.every(client=>!client.file.includes('Essais-locaux')));
check(!JSON.stringify(listed).includes('unused-upstream-identity'));
for(const number of ['1','2']){
 const result=await clientAccess(planFile,{ask:async ()=>' '+number+' ',openFile,output});
 check(result.result==='PASS'&&result.opened);check(result.eaiCalls===0&&result.filesWritten===0);
 check(opened.at(-1)===listed[Number(number)-1].file);
}
check(opened.length===2);check(output.text.includes('urls section'));
check(!output.text.includes('private fixture marker'));check(!output.text.includes('unregistered test access'));
const count=opened.length;
check(!(await clientAccess(planFile,{ask:async ()=>'0',openFile,output})).opened);
check(opened.length===count);
check(!(await clientAccess(planFile,{listOnly:true,ask:async ()=>{throw Error('PROMPT_MUST_NOT_OPEN');},openFile,output})).opened);
check(opened.length===count);
for(const answer of ['','-1','3','01','1.0','1e0','1\n2','999999999999999999999'])await reject(clientAccess(planFile,{ask:async ()=>answer,openFile,output}),/CLIENT_SELECTION_INVALID/);
check(opened.length===count);check(await snapshot(fixture)===before);
await reject(listClientAccess('relative-plan.json'),/ABSOLUTE_PLAN_REQUIRED/);
await save({...plan,clients:[]});
check((await clientAccess(planFile,{ask:async ()=>{throw Error('NO_CLIENT_PROMPT');},openFile,output})).clients===0);
await save({...plan,testFixtureOnly:true});await reject(listClientAccess(planFile),/ACCEPTANCE_TEST_ACCESS_IS_NOT_CUSTOMER_ACCESS/);
await save({...plan,profile:'INVALID'});await reject(listClientAccess(planFile),/SUITE_PLAN_REQUIRED/);
await save({...plan,directory:fixture});await reject(listClientAccess(planFile),/PLAN_DIRECTORY_MISMATCH/);
await save({...plan,clients:[{...clients[0],tenant:'invalid\nclient'}]});await reject(listClientAccess(planFile),/CLIENT_TENANT_INVALID/);
await save({...plan,clients:[clients[0],clients[0]]});await reject(listClientAccess(planFile),/DUPLICATE_CLIENT_TENANT/);
await save({...plan,clients:[clients[0],{...clients[1],directory:clients[0].directory}]});await reject(listClientAccess(planFile),/DUPLICATE_CLIENT_DIRECTORY/);
await save({...plan,clients:[{...clients[0],directory:fixture}]});await reject(listClientAccess(planFile),/ACCESS_PATH_OUTSIDE_INSTALLATION/);
const missing={tenant:'missing-customer-cccccccccccc',directory:path.join(root,'client-onboarding','missing')};
await save({...plan,clients:[clients[0],missing]});listed=await listClientAccess(planFile);
check(listed[0].available&&!listed[1].available);
await reject(clientAccess(planFile,{ask:async ()=>'2',openFile,output}),/ACCESS_FILE_NOT_FOUND_REVIEW_REQUIRED/);
check(opened.length===count);
await save(plan);
await reject(clientAccess(planFile,{ask:async ()=>{await save({...plan,clients:[clients[1]]});return '1';},openFile,output}),/CLIENT_REGISTRY_CHANGED_REOPEN_ACCESS/);
check(opened.length===count);
await save(plan);
await reject(clientAccess(planFile,{ask:async ()=>'1',openFile:async ()=>{throw Error('EXPLORER_OPEN_NOT_CONFIRMED');},output}),/EXPLORER_OPEN_NOT_CONFIRMED/);
check(!output.text.includes('unused-upstream-identity'));
const linked=path.join(root,'linked-customer');let junctionTested=false;
try{await symlink(clients[0].directory,linked,process.platform==='win32'?'junction':'dir');junctionTested=true;}catch(error){if(!['EPERM','EACCES'].includes(error.code))throw error;}
if(junctionTested){await save({...plan,clients:[{...clients[0],directory:linked}]});await reject(listClientAccess(planFile),/ACCESS_PATH_MUST_BE_REGULAR|ACCESS_PATH_LINK_REFUSED/);}
await save(plan);check((await lstat(listed[0].file)).isFile());
console.log(JSON.stringify({result:'PASS',suite:'client-access-read-only',checks,fixture,explorerRequestsSimulated:2,realExplorerOpened:false,junctionTested,...(!junctionTested?{junctionNotTestedReason:'Test environment refused creation; no permission bypass attempted'}:{}),eaiCalls:0,servicesStarted:0,filesDeleted:0,productionReady:false}));
