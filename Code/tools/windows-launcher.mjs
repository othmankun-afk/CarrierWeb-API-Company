// Operator entry point. Long extraction paths are read-only distribution
// locations; legacy runtimes run from a verified per-user managed package.
import {readFile,writeFile,mkdir,lstat,realpath,copyFile} from 'node:fs/promises';
import {constants} from 'node:fs';
import {spawn} from 'node:child_process';
import {createHash} from 'node:crypto';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';

const ownRoot=path.dirname(path.dirname(path.dirname(fileURLToPath(import.meta.url))));
const digest=b=>createHash('sha256').update(b).digest('hex');
const actions=new Set(['install','start','status','add-client','test','stop','recover','access','acceptance']);
const exists=async p=>{try{await lstat(p);return true;}catch(e){if(e.code==='ENOENT')return false;throw e;}};
const inside=(parent,child)=>{const r=path.relative(parent,child);return r!==''&&!path.isAbsolute(r)&&r!=='..'&&!r.startsWith('..'+path.sep);};

export async function verifiedManifest(root){
 const bytes=await readFile(path.join(root,'MANIFEST.json')),manifest=JSON.parse(bytes);
 assert.equal(manifest.profile,'CW_VEHICLES_PORTABLE_WINDOWS_RECETTE_V3','PORTABLE_PACKAGE_REQUIRED');
 assert.equal(manifest.containsCredentials,false);assert.equal(manifest.containsDatabases,false);
 assert.ok(Array.isArray(manifest.files)&&manifest.files.length>0,'PACKAGE_INVENTORY_REQUIRED');
 const names=new Set();
 for(const f of manifest.files){
  assert.ok(typeof f.path==='string'&&f.path.length&&!/[\x00-\x1f:]/.test(f.path)&&!path.isAbsolute(f.path)&&!f.path.split(/[\\/]/).some(p=>!p||p==='.'||p==='..'),'PACKAGE_PATH_INVALID');
  assert.ok(!names.has(f.path.toLowerCase()),'DUPLICATE_PACKAGE_PATH');names.add(f.path.toLowerCase());
  assert.ok(!/^(?:Etat-local|Essais-locaux|Code[\\/]test-results)(?:[\\/]|$)/i.test(f.path),'PRIVATE_STATE_NOT_DISTRIBUTABLE');
  assert.match(f.sha256,/^[a-f0-9]{64}$/,'PACKAGE_HASH_REQUIRED');
  const filename=path.join(root,f.path),stat=await lstat(filename);
  assert.ok(stat.isFile()&&!stat.isSymbolicLink(),'REGULAR_PACKAGE_FILE_REQUIRED');
  assert.ok(inside(root,await realpath(filename)),'PACKAGE_LINK_OUTSIDE_ROOT');
  assert.equal(digest(await readFile(filename)),f.sha256,'PACKAGE_FILE_HASH_MISMATCH');
 }
 return {bytes,manifest,sha256:digest(bytes)};
}

export async function prepareLaunch({root=ownRoot,action,installParent=process.env.CW_API_INSTALL_PARENT}={}){
 assert.ok(actions.has(action),'KNOWN_LAUNCH_ACTION_REQUIRED');
 assert.ok(path.isAbsolute(root),'ABSOLUTE_PACKAGE_ROOT_REQUIRED');root=path.resolve(root);
 const updateReference=path.join(root,'Etat-local/managed-update.json');
 if(await exists(updateReference)){
  const source=await verifiedManifest(root),ref=JSON.parse(await readFile(updateReference));
  assert.equal(ref.profile,'CW_API_MANAGED_UPDATE_REFERENCE_V1');assert.equal(ref.sourceManifestSha256,source.sha256);
  assert.ok(path.isAbsolute(ref.installedRoot)&&ref.installedRoot.length<=80&&!ref.installedRoot.startsWith('\\\\'),'LOCAL_MANAGED_UPDATE_REQUIRED');
  const installed=await verifiedManifest(ref.installedRoot);assert.equal(installed.sha256,ref.installedManifestSha256);assert.equal(installed.sha256,source.sha256);
  const receipt=JSON.parse(await readFile(path.join(ref.installedRoot,'MANAGED-PACKAGE.json')));assert.equal(receipt.sourceRoot,root);assert.equal(receipt.manifestSha256,source.sha256);
  return {root:ref.installedRoot,managed:true,created:false,updated:true};
 }
 // Preserve existing short-path installations and their private state.
 if(root.length<=80)return {root,managed:false,created:false};
 assert.equal(process.platform,'win32','WINDOWS_ONLY');
 const source=await verifiedManifest(root);
 if(await exists(path.join(root,'Etat-local','installation.json')))throw Error('INITIALIZED_PACKAGE_MOVED_REQUIRES_REVIEW');
 const local=process.env.LOCALAPPDATA;
 const parent=installParent?path.resolve(installParent):path.join(local||'', 'CWAPI');
 assert.ok((installParent?path.isAbsolute(installParent):local&&path.isAbsolute(local))&&!parent.startsWith('\\\\'),'LOCAL_INSTALL_PARENT_REQUIRED');
 assert.ok(!inside(root,parent)&&parent!==root,'MANAGED_PARENT_MUST_BE_OUTSIDE_EXTRACTION');
 const key=digest(root.toLowerCase()+'\n'+source.sha256).slice(0,16),target=path.join(parent,'p-'+key);
 assert.ok(target.length<=80,'MANAGED_INSTALL_PARENT_TOO_LONG');
 const receiptFile=path.join(target,'MANAGED-PACKAGE.json');
 if(await exists(target)){
  const s=await lstat(target);assert.ok(s.isDirectory()&&!s.isSymbolicLink(),'REGULAR_MANAGED_DIRECTORY_REQUIRED');
  assert.equal(path.normalize(await realpath(target)).toLowerCase(),target.toLowerCase(),'REGULAR_MANAGED_DIRECTORY_REQUIRED');
  assert.ok(await exists(receiptFile),'INCOMPLETE_MANAGED_PACKAGE_REQUIRES_REVIEW');
  const receipt=JSON.parse(await readFile(receiptFile));
  assert.equal(receipt.profile,'CW_API_MANAGED_PACKAGE_V1');assert.equal(receipt.sourceRoot,root);assert.equal(receipt.manifestSha256,source.sha256);
  assert.equal(digest(await readFile(path.join(target,'MANIFEST.json'))),source.sha256,'MANAGED_PACKAGE_VERSION_MISMATCH');
  await verifiedManifest(target);
  return {root:target,managed:true,created:false};
 }
 assert.ok(action==='install'||action==='acceptance','INSTALL_FIRST_FROM_THIS_PACKAGE');
 await mkdir(parent,{recursive:true});
 assert.equal(path.normalize(await realpath(parent)).toLowerCase(),parent.toLowerCase(),'REAL_LOCAL_INSTALL_PARENT_REQUIRED');
 await mkdir(target); // Exclusive creation; never overwrite or resume a partial copy.
 for(const f of source.manifest.files){
  const to=path.join(target,f.path);await mkdir(path.dirname(to),{recursive:true});
  await copyFile(path.join(root,f.path),to,constants.COPYFILE_EXCL);
  assert.equal(digest(await readFile(to)),f.sha256,'PACKAGE_CHANGED_DURING_PREPARATION');
 }
 await writeFile(path.join(target,'MANIFEST.json'),source.bytes,{flag:'wx'});
 await verifiedManifest(target);
 await writeFile(receiptFile,JSON.stringify({profile:'CW_API_MANAGED_PACKAGE_V1',sourceRoot:root,manifestSha256:source.sha256,utc:new Date().toISOString(),privateStateCopied:false,eaiCalls:0,filesDeleted:0},null,2)+'\n',{flag:'wx'});
 return {root:target,managed:true,created:true};
}

export async function launch(action,{root=ownRoot,installParent,prepareOnly=false,listOnly=false}={}){
 if(root.length>80)console.log('Checking installation files. Long extraction paths are handled automatically; no EAI request is made during preparation.');
 const location=await prepareLaunch({root,action,installParent});
 if(location.managed)console.log('Managed local package: '+location.root+'\nKeep using the launchers in the extracted folder. No existing installation was moved.');
 if(prepareOnly)return {...location,result:'PASS',servicesStarted:0,eaiCalls:0};
 const entry=action==='acceptance'?'Tests/recette-portable.mjs':'Code/tools/portable.mjs';
 const args=action==='acceptance'?[]:[action];
 if(action==='access'&&listOnly)args.push('--list');
 return await new Promise((resolve,reject)=>{
  const child=spawn(path.join(location.root,'Runtime/node/node.exe'),[path.join(location.root,entry),...args],{cwd:location.root,stdio:'inherit',windowsHide:true,shell:false});
  child.once('error',()=>reject(Error('BUNDLED_LAUNCH_NOT_CONFIRMED')));
  child.once('exit',(code,signal)=>resolve({...location,exitCode:signal?1:code??1}));
 });
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 try{const result=await launch(process.argv[2],{listOnly:process.argv.includes('--list')});process.exitCode=result.exitCode;}
 catch(e){console.log('NOT CONFIRMED: '+(/^[A-Z0-9_]{1,100}$/.test(e.message)?e.message:'LAUNCH_PREPARATION_REQUIRES_REVIEW'));console.log('No files were deleted. Keep any partial package for review; no automatic retry was made.');process.exitCode=1;}
}
