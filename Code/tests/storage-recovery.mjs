import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createReadStream} from 'node:fs';
import {mkdir,mkdtemp,readFile,writeFile,stat} from 'node:fs/promises';
import {createServer,createConnection} from 'node:net';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {randomBytes,createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {execute,phpArgs,spawnDatabase} from '../service/runtime.mjs';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const [php,mysqld,mysqldump,suffix]=process.argv.slice(2);
if(!php||!mysqld||!mysqldump||!suffix||!/^[a-z0-9-]+$/i.test(suffix))throw Error('USAGE_PHP_MYSQLD_MYSQLDUMP_SUFFIX');
const mysql=path.join(path.dirname(mysqldump),'mysql.exe');
for(const program of [php,mysqld,mysqldump,mysql])assert.ok(path.isAbsolute(program)&&(await stat(program)).isFile(),'ABSOLUTE_FIXTURE_BINARY_REQUIRED');
const directory=await mkdtemp(path.join(root,'test-results/storage-recovery-'));
const schema='recovery_'+randomBytes(8).toString('hex');
const fixtures=[];let checks=0,failure,report={},hold;
const check=(ok,label)=>{assert.ok(ok,label);checks++;};
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const sha=bytes=>createHash('sha256').update(bytes).digest('hex');
async function freePort(){const s=createServer();s.listen(0,'127.0.0.1');await once(s,'listening');const port=s.address().port;await new Promise(r=>s.close(r));return port;}
async function native(program,args,{inputFile=null,timeout=60000}={}){
    const child=spawn(program,args,{cwd:directory,windowsHide:true,stdio:[inputFile?'pipe':'ignore','ignore','pipe']});
    let errorBytes=0;child.stderr.on('data',bytes=>errorBytes+=bytes.length);
    if(inputFile){child.stdin.on('error',()=>{});createReadStream(inputFile).pipe(child.stdin);}
    const timer=setTimeout(()=>child.kill(),timeout);
    try{const [code]=await once(child,'exit');if(code!==0)throw Error('FIXTURE_NATIVE_COMMAND_FAILED');return {errorBytes};}finally{clearTimeout(timer);}
}
const invoke=(f,action)=>execute(php,phpArgs(php,path.join(root,'tests/storage-recovery.php')),JSON.stringify({directory:f.directory,port:f.port,rootPassword:f.rootPassword,schema,bootstrapped:f.bootstrapped,action})+'\n',30000);
async function ready(f){let lastFailure;for(let attempt=0;attempt<80;attempt++){if(f.child.exitCode!==null)throw Error('FIXTURE_SQL_EARLY_EXIT');try{await invoke(f,'ready');return;}catch(e){lastFailure=e.message;await pause(150);}}throw Error(lastFailure==='FIXTURE_SQL_CONNECT_FAILED'?'FIXTURE_SQL_NOT_READY_OR_LOOPBACK_BLOCKED':'FIXTURE_SQL_NOT_READY');}
async function makeFixture(name){
    const f={directory:path.join(directory,name),port:await freePort(),rootPassword:randomBytes(32).toString('hex'),bootstrapped:false};fixtures.push(f);
    await mkdir(path.join(f.directory,'data'),{recursive:true});
    await native(mysqld,['--no-defaults','--initialize-insecure','--log_syslog=0',`--basedir=${path.dirname(path.dirname(mysqld))}`,`--datadir=${path.join(f.directory,'data')}`,`--log-error=${path.join(f.directory,'initialize.log')}`]);
    f.child=spawnDatabase({mysqld,directory:f.directory,sqlPort:f.port});await ready(f);await invoke(f,'bootstrap');f.bootstrapped=true;
    f.defaults=path.join(f.directory,'own-fixture-client.cnf');
    await writeFile(f.defaults,`[client]\nhost=127.0.0.1\nport=${f.port}\nuser=root\npassword=${f.rootPassword}\nprotocol=TCP\ndefault-character-set=utf8mb4\n`,{flag:'wx'});
    return f;
}
async function closed(port){return new Promise(resolve=>{const c=createConnection({host:'127.0.0.1',port});c.setTimeout(1000);c.on('connect',()=>{c.destroy();resolve(false);});c.on('error',e=>{c.destroy();resolve(e.code==='ECONNREFUSED');});c.on('timeout',()=>{c.destroy();resolve(false);});});}

try{
    // Restrict inherited ACLs BEFORE generating any fixture credentials.
    const ps=path.join(process.env.SystemRoot||'C:\\Windows','System32/WindowsPowerShell/v1.0/powershell.exe');
    const aclScript=`$ErrorActionPreference="Stop";$ProgressPreference="SilentlyContinue";$d=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("${Buffer.from(directory).toString('base64')}"));$u=[Security.Principal.WindowsIdentity]::GetCurrent().User;$a=New-Object Security.AccessControl.DirectorySecurity;$a.SetAccessRuleProtection($true,$false);$a.SetOwner($u);foreach($s in @($u,(New-Object Security.Principal.SecurityIdentifier("S-1-5-18")))){$a.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($s,"FullControl","ContainerInherit,ObjectInherit","None","Allow")))};Set-Acl -LiteralPath $d -AclObject $a;[Console]::Out.Write('{"result":"PASS"}')`;
    await execute(ps,['-NoProfile','-NonInteractive','-EncodedCommand',Buffer.from(aclScript,'utf16le').toString('base64')]);checks++;
    const source=await makeFixture('source'),target=await makeFixture('restored');
    const populated=await invoke(source,'populate');check(populated.snapshot.state.generation===1,'INITIAL_PUBLICATION');
    check(populated.snapshot.tables.api_suite_history.rows===10002,'INITIAL_HISTORY');
    for(const endpoint of ['vehicles','vehiclesBase','positions','canbus','telemetry'])check(populated.snapshot.repositoryResponses[endpoint].rows===(['vehicles','vehiclesBase'].includes(endpoint)?1:3334),'INITIAL_REPOSITORY_RESPONSE');
    const dump=path.join(directory,'synthetic-single-transaction.sql');
    const backupStarted=performance.now();
    await native(mysqldump,[`--defaults-file=${source.defaults}`,'--single-transaction','--quick','--skip-lock-tables','--hex-blob','--set-gtid-purged=OFF','--no-tablespaces','--skip-add-drop-table','--skip-comments','--skip-dump-date',`--result-file=${dump}`,schema]);
    const backupMs=performance.now()-backupStarted,dumpBytes=await readFile(dump);
    check(dumpBytes.length>0,'DUMP_NONEMPTY');
    const restoreStarted=performance.now();
    await native(mysql,[`--defaults-file=${target.defaults}`,schema],{inputFile:dump});
    const restoreMs=performance.now()-restoreStarted,restored=await invoke(target,'snapshot');
    check(restored.snapshot.allRowsSha256===populated.snapshot.allRowsSha256,'RESTORE_EXACT_ROWS');
    check(restored.snapshot.allTypesSha256===populated.snapshot.allTypesSha256,'RESTORE_EXACT_TYPES');
    check(JSON.stringify(restored.snapshot.state)===JSON.stringify(populated.snapshot.state),'RESTORE_STATE_CURSORS');
    check(JSON.stringify(restored.snapshot.repositoryResponses)===JSON.stringify(populated.snapshot.repositoryResponses),'RESTORE_ALL_FIVE_TYPED_REPOSITORY_RESPONSES');
    for(const name of Object.keys(populated.snapshot.tables))check(JSON.stringify(populated.snapshot.tables[name])===JSON.stringify(restored.snapshot.tables[name]),'RESTORE_EACH_TABLE');
    const prepared=await invoke(source,'prepare-crash');check(prepared.snapshot.state.inFlight===true,'PERSISTED_REQUEST_RESERVATION');
    hold=spawn(php,phpArgs(php,path.join(root,'tests/storage-recovery.php')),{cwd:root,windowsHide:true,stdio:['pipe','pipe','pipe']});
    hold.stdin.on('error',()=>{});let holdOutput='',holdErrors=0;hold.stderr.on('data',b=>holdErrors+=b.length);
    const held=new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('UNCOMMITTED_TRANSACTION_NOT_READY')),10000);hold.stdout.on('data',b=>{holdOutput+=b;if(holdOutput.includes('\n')){clearTimeout(timer);try{resolve(JSON.parse(holdOutput.trim()));}catch{reject(Error('FIXTURE_HOLD_INVALID'));}}});hold.once('exit',()=>{clearTimeout(timer);reject(Error('FIXTURE_HOLD_EARLY_EXIT'));});});
    hold.stdin.write(JSON.stringify({directory:source.directory,port:source.port,rootPassword:source.rootPassword,schema,bootstrapped:true,action:'hold-uncommitted'})+'\n');
    const heldState=await held;check(heldState.transactionOpen&&heldState.ownDatadirVerified&&!holdErrors,'UNCOMMITTED_TRANSACTION_OPEN');
    check((await invoke(source,'identity')).ownDatadirVerified&&source.child.exitCode===null,'EXACT_OWN_DATADIR_BEFORE_KILL');
    // Only this harness's exact fresh child is terminated, never a PID supplied
    // by a plan or discovered on the user's machine. No filesystem is removed.
    const crashStarted=performance.now(),crashedChild=source.child;
    check(crashedChild.kill('SIGKILL'),'OWN_CHILD_TERMINATED');await once(crashedChild,'exit');
    hold.stdin.end('\n');await Promise.race([once(hold,'exit'),pause(5000)]);if(hold.exitCode===null)hold.kill();
    source.child=spawnDatabase({mysqld,directory:source.directory,sqlPort:source.port});await ready(source);
    const restartMs=performance.now()-crashStarted,recovered=await invoke(source,'snapshot');
    check(recovered.snapshot.allRowsSha256===prepared.snapshot.allRowsSha256,'COMMITTED_ROWS_SURVIVE_UNCOMMITTED_ROLL_BACK');
    check(recovered.snapshot.allTypesSha256===prepared.snapshot.allTypesSha256,'CRASH_PRESERVES_TYPES');
    check(JSON.stringify(recovered.snapshot.repositoryResponses)===JSON.stringify(prepared.snapshot.repositoryResponses),'CRASH_PRESERVES_ALL_FIVE_TYPED_REPOSITORY_RESPONSES');
    check(recovered.snapshot.tables.fixture_recovery_probe.rows===1,'UNCOMMITTED_INSERT_ABSENT');
    check(recovered.snapshot.state.generation===1,'UNCOMMITTED_GENERATION_ROLLED_BACK');
    const explicit=await invoke(source,'verify-recovery');checks+=explicit.checks;
    report={backup:{method:'mysqldump --single-transaction --quick --hex-blob',bytes:dumpBytes.length,sha256:sha(dumpBytes),durationMs:Number(backupMs.toFixed(2)),fixtureTables:6,fixtureHistoryEvents:10002,rowsAndColumnTypesIdentical:true,stateCursorsAndHashesIdentical:true,allFiveTypedRepositoryResponsesIdentical:true,restoredIntoSecondFreshMysqlInstance:true,restoreDurationMs:Number(restoreMs.toFixed(2)),timingsAreLocalSyntheticSampleNotProductionSizing:true,pointInTimeRecoveryTested:false},crashRecovery:{method:'Terminate only exact owned identity-verified MySQL child with open uncommitted fixture transaction',restartAndReadyMs:Number(restartMs.toFixed(2)),committedRowsPreserved:true,uncommittedRowsAndStateRolledBack:true,persistedInFlightGuardPreserved:true,allFiveTypedRepositoryResponsesPreserved:true,automaticEaiRetryBlocked:explicit.automaticRetryBlocked,recentReviewBlocked:explicit.recentReviewBlocked,explicitReviewPreservedCursorsAndPublication:explicit.reviewPreservedCursorsAndPublication,syntheticClockAgedOnlyInFixture:true},tableChecks:populated.snapshot.tables,repositoryResponseChecks:populated.snapshot.repositoryResponses};
}catch(e){failure=/^[A-Z0-9_]+$/.test(e.message)?e.message:'STORAGE_RECOVERY_TEST_FAILED';}
finally{
    if(hold&&hold.exitCode===null){hold.stdin.end('\n');await Promise.race([once(hold,'exit'),pause(2000)]);if(hold.exitCode===null)hold.kill();}
    for(const f of fixtures){if(f.child&&f.child.exitCode===null){try{await invoke(f,'shutdown');await Promise.race([once(f.child,'exit'),pause(5000)]);if(f.child.exitCode===null){f.child.unref();failure='OWN_SQL_SHUTDOWN_NOT_CONFIRMED';}}catch{f.child.unref();failure='OWN_SQL_SHUTDOWN_NOT_CONFIRMED';}}}
}
const portsClosed=[];for(const f of fixtures)portsClosed.push(await closed(f.port));
report={...report,result:failure||portsClosed.includes(false)?'FAIL':'PASS',suite:'synthetic-isolated-logical-backup-restore-and-innodb-crash-recovery',checks,fixtureDirectory:directory,fixturePortsClosedAfterShutdown:portsClosed.every(Boolean),externalCalls:0,eaiCalls:0,companyWrites:0,realCustomerDataUsed:false,filesDeleted:0,productionBackupValidated:false,wholeMachinePowerLossTested:false,utc:new Date().toISOString()};
if(failure)report.failureCategory=failure;
await writeFile(path.join(root,'test-results/storage-recovery-'+suffix+'.json'),JSON.stringify(report,null,2)+'\n',{flag:'wx'});
console.log(JSON.stringify(report));if(report.result!=='PASS')process.exitCode=1;
