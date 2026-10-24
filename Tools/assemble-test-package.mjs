// Assemble an uninitialized test package from a verified runtime base.
import {readFile,writeFile,mkdir,lstat} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {verifySource} from './verify-source.mjs';
const root=path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const sha=b=>createHash('sha256').update(b).digest('hex');
const flags=process.argv.slice(2);assert.ok(flags.length===4&&flags[0]==='--base'&&flags[2]==='--target','USE_BASE_AND_NEW_TARGET');
const base=path.resolve(flags[1]),target=path.resolve(flags[3]);
assert.ok(path.isAbsolute(flags[1])&&path.isAbsolute(flags[3]),'ABSOLUTE_NEW_TARGET_REQUIRED');
function outside(parent,child){const relative=path.relative(parent,child);return relative!==''&&(relative==='..'||relative.startsWith('..'+path.sep)||path.isAbsolute(relative));}
assert.ok(outside(base,target),'TARGET_MUST_NOT_BE_INSIDE_BASE');
assert.ok(outside(root,target),'TARGET_MUST_NOT_BE_INSIDE_SOURCE');
try{await lstat(target);throw Error('TARGET_ALREADY_EXISTS_NO_OVERWRITE');}catch(e){if(e.code!=='ENOENT')throw e;}
const source=await verifySource(),m=JSON.parse(await readFile(path.join(root,'SOURCE-MANIFEST.json'))),asset=JSON.parse(await readFile(path.join(root,'Distribution/portable-asset.json')));
const excluded=new Set(m.excludedPortableFiles||[]);
assert.ok(Array.isArray(m.excludedPortableFiles||[])&&excluded.size===(m.excludedPortableFiles||[]).length,'UNIQUE_PORTABLE_EXCLUSIONS_REQUIRED');
for(const name of excluded){assert.match(name,/^Tests\/[A-Z0-9_]+\.json$/,'ARCHIVED_REPORT_EXCLUSION_ONLY');assert.ok(!m.files.some(f=>f.path===name),'EXCLUDED_SOURCE_FILE_PRESENT');}
const baseManifestBytes=await readFile(path.join(base,'MANIFEST.json'));assert.equal(sha(baseManifestBytes),asset.portableManifestSha256,'PINNED_BASE_MANIFEST_REQUIRED');
const bm=JSON.parse(baseManifestBytes);assert.equal(bm.profile,'CW_VEHICLES_PORTABLE_WINDOWS_RECETTE_V3');
for(const name of ['Etat-local','Essais-locaux','Code/test-results']){try{await lstat(path.join(base,name));throw Error('UNINITIALIZED_BASE_REQUIRED');}catch(e){if(e.code!=='ENOENT')throw e;}}
for(const f of bm.files){assert.ok(!path.isAbsolute(f.path)&&!f.path.split(/[\\/]/).includes('..'));const s=await lstat(path.join(base,f.path));assert.ok(s.isFile()&&!s.isSymbolicLink());assert.equal(sha(await readFile(path.join(base,f.path))),f.sha256,'BASE_PAYLOAD_HASH_REQUIRED');}
const overrides=new Map(m.files.filter(f=>f.portableOverlay).map(f=>[f.path,f]));
// The source README describes GitHub; the package README describes the bundle.
const packageReadme=m.files.find(f=>f.path==='PACKAGE_README.md');
assert.ok(packageReadme&&!packageReadme.portableOverlay,'PACKAGE_README_REQUIRED');
overrides.set('README.md',{...packageReadme,sourcePath:packageReadme.path});
await mkdir(target);const files=[];
for(const f of bm.files){if(excluded.has(f.path))continue;const replacement=overrides.get(f.path),from=path.join(replacement?root:base,replacement?.sourcePath||f.path),bytes=await readFile(from);assert.equal(sha(bytes),replacement?replacement.sha256:f.sha256,'INPUT_CHANGED_DURING_ASSEMBLY');const to=path.join(target,f.path);await mkdir(path.dirname(to),{recursive:true});await writeFile(to,bytes,{flag:'wx'});files.push({...f,bytes:bytes.length,sha256:sha(bytes)});}
// New source entries (for example a launcher helper) must also be assembled.
for(const [relative,f] of overrides){if(files.some(x=>x.path===relative))continue;const bytes=await readFile(path.join(root,f.sourcePath||relative));assert.equal(sha(bytes),f.sha256,'NEW_SOURCE_HASH_REQUIRED');await mkdir(path.dirname(path.join(target,relative)),{recursive:true});await writeFile(path.join(target,relative),bytes,{flag:'wx'});files.push({path:relative,bytes:bytes.length,sha256:sha(bytes),group:'source-added-payload'});}
const receipt={result:'PASS',profile:'SOURCE_ASSEMBLED_PORTABLE_PACKAGE_V1',sourceManifestSha256:source.sourceManifestSha256,baseManifestSha256:sha(baseManifestBytes),sourceFilesOverlaid:overrides.size,eaiCalls:0,servicesStarted:0,databaseConnections:0,filesDeleted:0,productionReady:false};
const receiptBytes=Buffer.from(JSON.stringify(receipt,null,2)+'\n');await writeFile(path.join(target,'ASSEMBLY.json'),receiptBytes,{flag:'wx'});files.push({path:'ASSEMBLY.json',bytes:receiptBytes.length,sha256:sha(receiptBytes),group:'source-assembly-provenance'});
const packageManifest={...bm,files,sourceManifestSha256:source.sourceManifestSha256,productionReady:false};
for(const key of ['createdUtc','release','personalMeetingNotesIncluded','launcherPathFix','testedBaselineArchiveSha256','testedBaselineManifestSha256','documentationAndMetadataRefreshOnly'])delete packageManifest[key];
packageManifest.vendorSdkUse='CarrierWeb EAI SDK; component notices are in Licences/NOTICE.md';
await writeFile(path.join(target,'MANIFEST.json'),JSON.stringify(packageManifest,null,2)+'\n',{flag:'wx'});
console.log(JSON.stringify({...receipt,target,files:files.length}));
