// Verify source file hashes and distribution contents against the inventory.
import {readFile,lstat} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
const root=path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const sha=b=>createHash('sha256').update(b).digest('hex');
export async function verifySource(){
 const bytes=await readFile(path.join(root,'SOURCE-MANIFEST.json')),m=JSON.parse(bytes);
 assert.equal(m.profile,'CW_API_SOURCE_CHECKOUT_V1');assert.equal(m.containsCredentials,false);assert.equal(m.containsRuntimeBinaries,false);
 const excluded=m.excludedPortableFiles||[];assert.ok(Array.isArray(excluded));assert.equal(new Set(excluded).size,excluded.length);
 for(const name of excluded){assert.match(name,/^Tests\/[A-Z0-9_]+\.json$/);assert.ok(!m.files.some(f=>f.path===name),'EXCLUDED_SOURCE_FILE_PRESENT');}
 const names=new Set();let checks=0;
 for(const f of m.files){
  assert.ok(!path.isAbsolute(f.path)&&!f.path.split(/[\\/]/).includes('..'));assert.ok(!names.has(f.path));names.add(f.path);
  assert.ok(!/^(?:Runtime|Etat-local|Essais-locaux|private-runs|notes)\/|\.(?:exe|dll|dpapi|zip|7z|log)$/i.test(f.path));
  const file=path.join(root,f.path),s=await lstat(file);assert.ok(s.isFile()&&!s.isSymbolicLink());const content=await readFile(file);assert.equal(sha(content),f.sha256,'SOURCE_HASH_MISMATCH: '+f.path);
  const text=content.toString('utf8');assert.ok(!/[A-Z]:[\\/]+Users[\\/]+/i.test(text),'PERSONAL_WORKSTATION_PATH');
  assert.ok(!/https?:\/\/[^\s"'<>]+[?&]password=[a-f0-9]{8,}/i.test(text),'SECRET_BEARING_URL');
  checks++;
 }
 for(const f of ['README.md','Documentation/API_CONTRACT.md','Documentation/INSTALLATION.md','Documentation/GITHUB_HANDOVER.md','Documentation/DEVELOPMENT.md','THIRD_PARTY.md','.gitignore','.gitattributes'])assert.ok(names.has(f),'REQUIRED_GUIDE_OR_GUARD');
 assert.ok(!names.has('MANIFEST.json'),'SOURCE_IS_NOT_PORTABLE_MANIFEST');
 const contract=await readFile(path.join(root,'Code/src/Contract.php'),'utf8'),doc=await readFile(path.join(root,'Documentation/API_CONTRACT.md'),'utf8');
 for(const match of contract.matchAll(/'(vehiclesBase|vehicles|positions|canbus|telemetry)'\s*=>\s*'([^']+)'/g)){assert.ok(doc.includes(match[2]),'DOCUMENTED_EXACT_SCHEMA');checks++;}
 return {result:'PASS',sourceFiles:m.files.length,checks,sourceManifestSha256:sha(bytes),runtimeBinariesIncluded:false,credentialsIncluded:false,eaiCalls:0,databaseConnections:0,filesDeleted:0,productionReady:false};
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 try{console.log(JSON.stringify(await verifySource()));}catch(e){console.log(JSON.stringify({result:'FAIL',category:String(e.message).slice(0,180),eaiCalls:0,filesDeleted:0}));process.exitCode=1;}
}
