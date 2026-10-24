// Isolated path policy tests. No application runtime or EAI transport is run.
import {mkdtemp,mkdir,writeFile,readFile,lstat} from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {prepareLaunch} from '../Code/tools/windows-launcher.mjs';
const parent=process.argv[2];assert.ok(path.isAbsolute(parent||'')&&parent.length<55,'SHORT_QA_PARENT_REQUIRED');
await mkdir(parent,{recursive:true});const fixture=await mkdtemp(path.join(parent,'q-'));let checks=0;
const check=v=>{assert.ok(v);checks++;};
const hash=b=>createHash('sha256').update(b).digest('hex');
const source=path.join(fixture,'Extracted package with spaces and a deliberately long directory name to reproduce the reported Windows installation problem');await mkdir(source);
const files=[];
for(const name of ['Code/tools/portable.mjs','Runtime/node/node.exe','Documentation/example.md']){const bytes=Buffer.from('fictitious path fixture; never executable: '+name);await mkdir(path.dirname(path.join(source,name)),{recursive:true});await writeFile(path.join(source,name),bytes,{flag:'wx'});files.push({path:name,sha256:hash(bytes),bytes:bytes.length});}
const manifest={profile:'CW_VEHICLES_PORTABLE_WINDOWS_RECETTE_V3',containsCredentials:false,containsDatabases:false,files};
await writeFile(path.join(source,'MANIFEST.json'),JSON.stringify(manifest),{flag:'wx'});
const managedParent=path.join(fixture,'managed');
const first=await prepareLaunch({root:source,action:'install',installParent:managedParent});check(first.managed&&first.created&&first.root.length<=80);check(!first.root.startsWith(source));
for(const f of files)check(hash(await readFile(path.join(first.root,f.path)))===f.sha256);
await mkdir(path.join(first.root,'Etat-local'));await writeFile(path.join(first.root,'Etat-local','private-fixture.txt'),'retained fictitious state');
for(const action of ['install','start','status','add-client','test','stop','recover','access','acceptance']){const again=await prepareLaunch({root:source,action,installParent:managedParent});check(again.root===first.root&&!again.created);}
check(await readFile(path.join(first.root,'Etat-local','private-fixture.txt'),'utf8')==='retained fictitious state');
check(!(await lstat(path.join(source,'Etat-local')).catch(()=>null)));
// Exercise default-parent resolution with a temporary synthetic AppData path.
const savedLocal=process.env.LOCALAPPDATA,savedParent=process.env.CW_API_INSTALL_PARENT;
try{delete process.env.CW_API_INSTALL_PARENT;process.env.LOCALAPPDATA=path.join(fixture,'d');const automatic=await prepareLaunch({root:source,action:'install'});check(automatic.managed&&automatic.created);check(automatic.root.startsWith(path.join(fixture,'d','CWAPI')+path.sep));}
finally{if(savedLocal===undefined)delete process.env.LOCALAPPDATA;else process.env.LOCALAPPDATA=savedLocal;if(savedParent===undefined)delete process.env.CW_API_INSTALL_PARENT;else process.env.CW_API_INSTALL_PARENT=savedParent;}
const short=await prepareLaunch({root:fixture,action:'status'});check(short.root===fixture&&!short.managed);
await assert.rejects(prepareLaunch({root:source,action:'unknown',installParent:managedParent}),/KNOWN_LAUNCH_ACTION_REQUIRED/);checks++;
await assert.rejects(prepareLaunch({root:source,action:'status',installParent:path.join(fixture,'unused')}),/INSTALL_FIRST_FROM_THIS_PACKAGE/);checks++;
await assert.rejects(prepareLaunch({root:source,action:'install',installParent:path.join(source,'managed')}),/MANAGED_PARENT_MUST_BE_OUTSIDE_EXTRACTION/);checks++;
await assert.rejects(prepareLaunch({root:source,action:'install',installParent:path.join(fixture,'overlong-parent-'.repeat(6))}),/MANAGED_INSTALL_PARENT_TOO_LONG/);checks++;
const partialParent=path.join(fixture,'partial');await mkdir(path.join(partialParent,path.basename(first.root)),{recursive:true});
await assert.rejects(prepareLaunch({root:source,action:'install',installParent:partialParent}),/INCOMPLETE_MANAGED_PACKAGE_REQUIRES_REVIEW/);checks++;
await writeFile(path.join(first.root,files[0].path),'tampered');
await assert.rejects(prepareLaunch({root:source,action:'start',installParent:managedParent}),/PACKAGE_FILE_HASH_MISMATCH/);checks++;
await writeFile(path.join(source,'Documentation/example.md'),'tampered source');
await assert.rejects(prepareLaunch({root:source,action:'install',installParent:managedParent}),/PACKAGE_FILE_HASH_MISMATCH/);checks++;
await writeFile(path.join(source,'Documentation/example.md'),'fictitious path fixture; never executable: Documentation/example.md');
await mkdir(path.join(source,'Etat-local'));await writeFile(path.join(source,'Etat-local','installation.json'),'{}');
await assert.rejects(prepareLaunch({root:source,action:'install',installParent:managedParent}),/INITIALIZED_PACKAGE_MOVED_REQUIRES_REVIEW/);checks++;
check((await lstat(path.join(first.root,'Etat-local','private-fixture.txt'))).isFile());
console.log(JSON.stringify({result:'PASS',suite:'managed-launcher-location',checks,fixture,eaiCalls:0,servicesStarted:0,filesDeleted:0,productionReady:false}));
