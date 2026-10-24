// Explicit code allowlist, not an archive of a working installation.
import {readFile,writeFile,mkdir,lstat,copyFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import {apiRoot,execute,sha} from '../service/runtime.mjs';

export const CODE_ALLOWLIST=[
    'src/Api.php','src/Bootstrap.php','src/CarrierwebResponse.php','src/Contract.php','src/CompactStateJson.php','src/SnapshotReadPolicy.php',
    'src/EaiCurrentVehicleSignals.php','src/EaiCursorState.php','src/EaiSnapshotVehiclesRepository.php','src/EaiSuiteMapping.php','src/EaiSuiteRepository.php',
    'src/EaiTelemetryRepository.php','src/EaiVehicleMapping.php','src/EaiVehicleSignals.php','src/EaiVehicleState.php',
    'src/LegacyVehicleCounterPolicy.php','src/MysqliDatabase.php','src/PublishedVehiclesRepository.php','src/Repository.php',
    'src/SafeAudit.php','src/StrictJson.php','src/SuiteHistoryPolicy.php','src/SuiteLiveRepository.php','src/SuiteServiceStore.php',
    'src/VehiclesLegacyCompatibility.php','src/VehiclesPublisher.php','src/VehiclesServiceDiagnostic.php',
    'public/index.php','public/router.php','compat/auth-missing.json','compat/documentation.json','demo/fixtures.php',
    'database/suite_live.sql','configuration/sdk-runtime-pins.json','configuration/capacity-input.template.json',
    'service/runtime.mjs','service/suite-runtime.mjs','service/suite-database.php','service/collect-suite-eai.php',
    'service/suite-eai-policy.php','service/sdk-date-normalization.php','service/suite-worker.mjs','service/suite-supervisor.mjs',
    'service/supervisor-policy.mjs','service/status-writer.mjs','service/collection-health.mjs','service/Control-Suite.ps1','service/Start-Suite-Supervisor.ps1','service/control-suite.mjs',
    'service/Install-Suite-Autostart.ps1','service/private-folder.ps1','service/secrets.ps1','service/local-loopback-preflight.php',
    'tools/add-suite-client.mjs','tools/add-suite-client-core.mjs','tools/recovery-policy.mjs','tools/recover-local-sync.mjs','tools/Add-Suite-Client.ps1','tools/onboard-interactive.mjs','tools/client-access.mjs','tools/suite-registry.mjs',
    'tests/client-access.mjs','tests/onboarding-resume.mjs','tests/vehicle-state-ties.php','tests/sync-replay-fixture.php','tests/sync-replay-integration.mjs',
    'tests/patricia-compatibility.php','tests/cursor-replay-sql.php',
    'tools/initialize-empty-suite.mjs','tools/build-replication-package.mjs','tools/package-archive.ps1','tools/capacity-plan.mjs','tools/test-local-delivery.mjs',
    'tests/replication-package.mjs','tests/replication-onboarding-source.php','tests/replication-sql-isolation.php','tests/capacity-plan.mjs',
    'docs/REPLICATION_WINDOWS_RECETTE.md','docs/CAPACITY_INPUTS.md','docs/EXPLOITATION_DECISION.md',
    'Installation/Lancer.ps1','Installation/01-Installer.cmd','Installation/02-Demarrer.cmd','Installation/03-Statut.cmd',
    'Installation/04-Ajouter-client.cmd','Installation/05-Tester.cmd','Installation/06-Arreter.cmd',
    'tests/delivery-launchers.mjs','docs/QUICKSTART_COLLEGUE.md','docs/API_CONTRACT.md',
    'service/deployment-config.mjs','src/DeploymentGate.php','tests/deployment-config.mjs','tests/deployment-gate.php','docs/SERVER_CONFIGURATION.md',
    'service/Install-Server-Startup.ps1','docs/SERVER_STARTUP_WINDOWS.md','tests/server-startup-validation.ps1','tests/server-startup-static.mjs',
    'src/RequestProtection.php','src/SuiteCapacityPolicy.php','tests/request-protection.php','tests/request-protection-worker.php',
    'tests/suite-capacity-policy.php','tests/deployment-review.php','tests/deployment-review.mjs',
    'tools/configure-suite-capacity.mjs','tools/validate-suite-capacity.php','tools/capacity-owner.ps1','docs/CAPACITY_POLICY.md','tests/configure-suite-capacity.mjs'
];

export async function assertNewTarget(target){assert.ok(path.isAbsolute(target),'ABSOLUTE_NEW_PACKAGE_TARGET_REQUIRED');try{await lstat(target);throw Error('PACKAGE_TARGET_EXISTS');}catch(e){if(e.code!=='ENOENT')throw e;}}
export function portableBytes(filename,original){
    // Normalize the optional source-reference comment for distribution.
    // The manifest retains both original and packaged content hashes.
    if(filename==='src/EaiTelemetryRepository.php')return Buffer.from(original.toString('utf8').replace(/^\/\/ Explicit opt-in LOCAL profile\. Evidence: [^\r\n]+\r?\n/m,'// Explicit opt-in LOCAL profile; legacy comparison evidence is not bundled.\n'));
    return original;
}
export function assertNoPrivateContent(filename,bytes){
    assert.ok(!/(?:^|\/)(?:data|var|private-runs|outputs|test-results|client-onboarding)(?:\/|$)/i.test(filename),'PRIVATE_PACKAGE_PATH_REFUSED');
    assert.ok(!/\.(?:dpapi|log|csv|zip|exe|dll|pem)$/i.test(filename),'PRIVATE_OR_EXTERNAL_BINARY_REFUSED');
    const text=bytes.toString('utf8');
    assert.ok(!/[A-Z]:[\\/]+Users[\\/]+/i.test(text),'PERSONAL_PATH_IN_PACKAGE');
    const privateClientNames=['soma'+'gec','fre'+'terium'];
    assert.ok(!new RegExp('\\b(?:'+privateClientNames.join('|')+')(?:[._-]|\\b)','i').test(text),'CLIENT_IDENTITY_IN_PACKAGE');
    assert.ok(!/https?:\/\/[^\s"'<>]+[?&]password=[a-f0-9]{8,}/i.test(text),'CREDENTIAL_URL_IN_PACKAGE');
    assert.ok(!/"(?:eaiPassword|rootPassword|apiPassword)"\s*:\s*"[^"\s]{4,}"/.test(text),'LITERAL_CREDENTIAL_IN_PACKAGE');
}
export async function buildReplicationPackage(directory,{zip=true}={}){
    await assertNewTarget(directory);const zipFile=directory+'.zip';if(zip)await assertNewTarget(zipFile);
    assert.equal(new Set(CODE_ALLOWLIST).size,CODE_ALLOWLIST.length,'ALLOWLIST_DUPLICATE');
    const files=[];await mkdir(directory);
    for(const relative of CODE_ALLOWLIST){
        assert.ok(!path.isAbsolute(relative)&&!relative.split('/').includes('..'),'ALLOWLIST_INVALID');
        const source=path.join(apiRoot,relative),s=await lstat(source);assert.ok(s.isFile()&&!s.isSymbolicLink(),'REGULAR_CODE_FILE_REQUIRED');
        const original=await readFile(source),bytes=portableBytes(relative,original);assertNoPrivateContent(relative,bytes);
        const target=path.join(directory,relative);await mkdir(path.dirname(target),{recursive:true});await writeFile(target,bytes,{flag:'wx'});
        files.push({path:relative,size:bytes.length,sha256:sha(bytes),sourceSha256:sha(original),packagingCommentNormalized:!bytes.equals(original)});
    }
    const manifest={profile:'EAI_SUITE_CODE_ONLY_WINDOWS_RECETTE_V1',createdUtc:new Date().toISOString(),files,externalPrerequisites:['Windows PowerShell with CurrentUser DPAPI','Node.js runtime','PHP CLI with mysqli','MySQL Server 5.7 tested family','Official approved EAI SDK S.1366 and its PHP 5.6 runtime'],containsCustomerAccounts:false,containsCredentials:false,containsCapturedData:false,containsExternalBinaries:false,productionReady:false};
    await writeFile(path.join(directory,'PACKAGE-MANIFEST.json'),JSON.stringify(manifest,null,2)+'\n',{flag:'wx'});
    if(zip)await execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(apiRoot,'tools/package-archive.ps1'),'-Action','Create','-Source',directory,'-Target',zipFile]);
    return {result:'PASS',directory,...(zip?{archive:zipFile,archiveSha256:sha(await readFile(zipFile))}:{}),codeFiles:files.length,customerAccounts:0,externalBinaries:0,credentials:0,capturedDataFiles:0,productionReady:false};
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
    try{console.log(JSON.stringify(await buildReplicationPackage(process.argv[2])));}catch(e){console.log(JSON.stringify({result:'FAIL',category:/^[A-Z0-9_]{1,80}$/.test(e.message)?e.message:'CODE_PACKAGE_NOT_CONFIRMED',filesDeleted:0,productionReady:false}));process.exitCode=1;}
}
