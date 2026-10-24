// Locate client access sheets from the active registry without reading their contents.
import {readFile,lstat,realpath} from 'node:fs/promises';
import {spawn} from 'node:child_process';
import {createInterface} from 'node:readline/promises';
import path from 'node:path';
import assert from 'node:assert/strict';

const sheetName='ACCES-CLIENT-PRIVE.json';
const samePath=(a,b)=>process.platform==='win32'?a.toLowerCase()===b.toLowerCase():a===b;
function inside(parent,child){const relative=path.relative(parent,child);return relative!==''&&!path.isAbsolute(relative)&&relative!=='..'&&!relative.startsWith('..'+path.sep);}
async function regularPath(filename,directory){
 const stat=await lstat(filename);
 assert.ok(!stat.isSymbolicLink()&&(directory?stat.isDirectory():stat.isFile()),'ACCESS_PATH_MUST_BE_REGULAR');
 assert.ok(samePath(path.resolve(filename),await realpath(filename)),'ACCESS_PATH_LINK_REFUSED');
}

export async function listClientAccess(planFile){
 assert.ok(typeof planFile==='string'&&path.isAbsolute(planFile),'ABSOLUTE_PLAN_REQUIRED');
 planFile=path.resolve(planFile);await regularPath(planFile,false);
 const plan=JSON.parse(await readFile(planFile,'utf8')),directory=path.dirname(planFile);
 assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1','SUITE_PLAN_REQUIRED');
 assert.ok(typeof plan.directory==='string'&&path.isAbsolute(plan.directory)&&samePath(path.resolve(plan.directory),directory),'PLAN_DIRECTORY_MISMATCH');
 assert.notEqual(plan.testFixtureOnly,true,'ACCEPTANCE_TEST_ACCESS_IS_NOT_CUSTOMER_ACCESS');
 assert.ok(Array.isArray(plan.clients)&&plan.clients.length<=100,'CLIENT_REGISTRY_INVALID');
 await regularPath(directory,true);
 const tenants=new Set(),folders=new Set(),clients=[];
 for(const client of plan.clients){
  assert.match(client.tenant||'',/^[a-z0-9_-]{1,64}$/,'CLIENT_TENANT_INVALID');
  assert.ok(!tenants.has(client.tenant),'DUPLICATE_CLIENT_TENANT');tenants.add(client.tenant);
  assert.ok(typeof client.directory==='string'&&path.isAbsolute(client.directory)&&!/[\x00-\x1f]/.test(client.directory),'CLIENT_DIRECTORY_INVALID');
  const folder=path.resolve(client.directory),key=process.platform==='win32'?folder.toLowerCase():folder;
  assert.ok(inside(directory,folder),'ACCESS_PATH_OUTSIDE_INSTALLATION');
  assert.ok(!folders.has(key),'DUPLICATE_CLIENT_DIRECTORY');folders.add(key);
  const file=path.join(folder,sheetName);let available=true;
  try{await regularPath(folder,true);await regularPath(file,false);}catch(error){if(error.code==='ENOENT')available=false;else throw error;}
  const label=client.tenant.replace(/-[a-f0-9]{12}$/,'').replace(/[_-]+/g,' ').toUpperCase();
  clients.push({tenant:client.tenant,label,file,available});
 }
 return clients;
}

export async function selectAccessFile(file){
 assert.equal(process.platform,'win32','WINDOWS_ONLY');
 assert.ok(path.isAbsolute(file)&&path.basename(file)===sheetName,'PRIVATE_ACCESS_FILE_REQUIRED');
 const windows=process.env.WINDIR||process.env.SystemRoot;
 assert.ok(windows&&path.isAbsolute(windows),'WINDOWS_SYSTEM_REQUIRED');
 const executable=path.join(windows,'explorer.exe');
 await new Promise((resolve,reject)=>{
  const child=spawn(executable,['/select,'+file],{shell:false,windowsHide:true,stdio:'ignore'});
  child.once('error',()=>reject(Error('EXPLORER_OPEN_NOT_CONFIRMED')));
  child.once('spawn',()=>{child.unref();resolve();});
 });
}

export async function clientAccess(planFile,{ask,openFile=selectAccessFile,listOnly=false,input=process.stdin,output=process.stdout}={}){
 const clients=await listClientAccess(planFile);
 if(!clients.length){output.write('No registered clients in this installation. Use Add client for an authorized account.\nAcceptance-test accounts are separate and are not customer access.\n');return {result:'PASS',clients:0,opened:false,eaiCalls:0,filesWritten:0};}
 output.write('Registered clients in this installation:\n');
 clients.forEach((client,index)=>output.write(`${index+1}. ${client.label} (${client.tenant})${client.available?'':' - access file unavailable'}\n`));
 if(listOnly)return {result:'PASS',clients:clients.length,opened:false,eaiCalls:0,filesWritten:0};
 let reader;
 if(!ask){assert.ok(input.isTTY,'INTERACTIVE_TERMINAL_REQUIRED');reader=createInterface({input,output,terminal:true});ask=prompt=>reader.question(prompt);}
 try{
  const answer=(await ask('Choose a client number, or 0 to cancel: ')).trim();
  if(answer==='0')return {result:'PASS',clients:clients.length,opened:false,eaiCalls:0,filesWritten:0};
  assert.match(answer,/^[1-9][0-9]*$/,'CLIENT_SELECTION_INVALID');
  const selected=clients[Number(answer)-1];assert.ok(selected,'CLIENT_SELECTION_INVALID');
  // Recheck the registry and paths after the operator's selection. Never
  // recover an obsolete sheet by searching test folders or guessing its UUID.
  const current=(await listClientAccess(planFile)).find(client=>client.tenant===selected.tenant);
  assert.ok(current&&samePath(current.file,selected.file),'CLIENT_REGISTRY_CHANGED_REOPEN_ACCESS');
  assert.ok(current.available,'ACCESS_FILE_NOT_FOUND_REVIEW_REQUIRED');
  output.write('Private access file: '+current.file+'\n');
  await openFile(current.file);
  output.write('Folder opening requested with ACCES-CLIENT-PRIVE.json selected.\nOpen that file in a text editor; its urls section contains the five API links.\nKeep this file private. No service was stopped or started.\n');
  return {result:'PASS',clients:clients.length,opened:true,eaiCalls:0,filesWritten:0};
 }finally{reader?.close();}
}
