// Initialize an empty Windows test installation in a new directory.
import {readFile,writeFile,mkdir,lstat,realpath} from 'node:fs/promises';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {randomBytes} from 'node:crypto';
import {createServer} from 'node:net';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import {apiRoot,serviceRoot,execute,sha,sourcePins,spawnDatabase,phpArgs} from '../service/runtime.mjs';
import {suiteDatabase,collectSuite} from '../service/suite-runtime.mjs';
import {deploymentConfiguration,requestConcurrencyConfiguration} from '../service/deployment-config.mjs';

export const APPROVED_ARCHIVE='99b07b3b0cb4b18c03ee0ae9d79ad0b14f7f179ad262e8b3ff0de19fb6174065';
const FEEDS=['vehicles','divisions','positions','canbushistory','telemetry','wiredtelemetry'];
export function sixFeedExtra(xml){
    const tags=(name)=>[...xml.matchAll(new RegExp('<'+name+'\\s+part="([A-Za-z0-9_]+)"','g'))].map(m=>m[1]);
    const stores=[...new Set(tags('storeFormat'))],loads=[...new Set(tags('loadFormat'))];
    assert.ok(FEEDS.every(f=>stores.includes(f)),'OFFICIAL_FORMAT_SIX_FEEDS_REQUIRED');
    const disabled=stores.filter(f=>!FEEDS.includes(f)).map(f=>'  <storeFormat part="'+f+'"><noStore type="db,null"/></storeFormat>');
    const blocked=loads.map(f=>'  <loadFormat part="'+f+'"><noLoad type="db,null"/></loadFormat>');
    return '<?xml version="1.0"?>\n<cwEAI><cwEAIFormat>\n'+disabled.concat(blocked).join('\n')+'\n</cwEAIFormat></cwEAI>\n';
}
async function mustBeNewDirectory(directory){
    assert.ok(path.isAbsolute(directory),'ABSOLUTE_NEW_INSTALLATION_REQUIRED');
    assert.match(path.basename(directory),/^cwvehicles-[a-f0-9-]{36}$/,'NEW_SERVICE_FOLDER_REQUIRED');
    try{await lstat(directory);throw Error('INSTALLATION_TARGET_EXISTS');}catch(e){if(e.code!=='ENOENT')throw e;}
    const parent=path.dirname(directory),parentStat=await lstat(parent);assert.ok(parentStat.isDirectory()&&!parentStat.isSymbolicLink(),'REAL_PARENT_DIRECTORY_REQUIRED');
    assert.equal(path.normalize(await realpath(parent)).toLowerCase(),path.normalize(parent).toLowerCase(),'REAL_PARENT_DIRECTORY_REQUIRED');
}
async function regularAbsolute(filename){assert.ok(path.isAbsolute(filename),'ABSOLUTE_RUNTIME_REQUIRED');const s=await lstat(filename);assert.ok(s.isFile()&&!s.isSymbolicLink(),'REGULAR_RUNTIME_REQUIRED');}
async function bind(port){const server=createServer();await new Promise((resolve,reject)=>{server.once('error',()=>reject(Error('INSTALLATION_PORT_OCCUPIED')));server.listen(port,'127.0.0.1',resolve);});return server;}
async function stopListener(server){if(server?.listening)await new Promise(resolve=>server.close(resolve));}
async function requireLocalTransport(php){
    const challenge=randomBytes(32).toString('hex'),listener=createServer(socket=>socket.end(challenge));
    try{await new Promise((resolve,reject)=>{listener.once('error',reject);listener.listen(0,'127.0.0.1',resolve);});
        await execute(php,phpArgs(php,path.join(serviceRoot,'local-loopback-preflight.php')),JSON.stringify({port:listener.address().port,challenge}),5000);
    }finally{await stopListener(listener);}
}
async function initializeData(plan){
    const child=spawn(plan.mysqld,['--no-defaults','--initialize-insecure','--log_syslog=0',`--basedir=${path.dirname(path.dirname(plan.mysqld))}`,`--datadir=${path.join(plan.directory,'data')}`,`--log-error=${path.join(plan.directory,'initialize.log')}`],{windowsHide:true,stdio:'ignore'});
    const timer=setTimeout(()=>child.kill(),45000);try{const [code]=await once(child,'exit');assert.equal(code,0,'NEW_SQL_INITIALIZATION_REQUIRED');}finally{clearTimeout(timer);}
}

export async function initializeEmptySuite(options){
    assert.equal(process.platform,'win32','WINDOWS_RECETTE_ONLY');
    assert.ok(options&&typeof options==='object','INSTALL_OPTIONS_REQUIRED');
    const directory=options.directory;await mustBeNewDirectory(directory);
    for(const binary of ['php','mysqld','node'])await regularAbsolute(options[binary]);
    await requireLocalTransport(options.php);
    assert.ok(path.isAbsolute(options.sdkDirectory),'ABSOLUTE_OFFICIAL_SDK_REQUIRED');
    const sdkStat=await lstat(options.sdkDirectory);assert.ok(sdkStat.isDirectory()&&!sdkStat.isSymbolicLink(),'REGULAR_OFFICIAL_SDK_REQUIRED');
    const pinFile=path.join(apiRoot,'configuration','sdk-runtime-pins.json'),pins=JSON.parse(await readFile(pinFile));
    assert.equal(pins.profile,'OFFICIAL_EAI_S1366_RUNTIME_PINS_V1','OFFICIAL_SDK_PINS_REQUIRED');assert.equal(pins.archiveSha256,APPROVED_ARCHIVE,'OFFICIAL_SDK_VERSION_REQUIRED');
    assert.ok(Object.keys(pins.runtimeFiles).length>=20,'OFFICIAL_SDK_PIN_SET_REQUIRED');
    for(const f of Object.keys(pins.runtimeFiles)){assert.ok(!path.isAbsolute(f)&&!f.split(/[\\/]/).includes('..'),'OFFICIAL_SDK_PIN_PATH_INVALID');assert.match(pins.runtimeFiles[f],/^[a-f0-9]{64}$/);}
    await sourcePins({sourceDirectory:options.sdkDirectory,sourceSha256:pins.runtimeFiles});
    const portNames=['sqlPort','apiPort','lockPort','supervisorPort'];for(const key of portNames)assert.ok(Number.isInteger(options[key])&&options[key]>=1024&&options[key]<=65535,'EXPLICIT_LOCAL_PORT_REQUIRED');
    assert.equal(new Set(portNames.map(k=>options[k])).size,4,'DISTINCT_LOCAL_PORTS_REQUIRED');
    const deployment=deploymentConfiguration(options.deployment,options.apiPort);
    const concurrency=requestConcurrencyConfiguration(options.requestConcurrency,path.join(directory,'request-locks'),deployment.mode==='server-recette');
    const windowsOwner=await execute('powershell.exe',['-NoProfile','-Command',"$identity=[Security.Principal.WindowsIdentity]::GetCurrent();@{result='PASS';sid=$identity.User.Value}|ConvertTo-Json -Compress"]);
    assert.match(windowsOwner.sid,/^S-1-[0-9-]+$/,'WINDOWS_OWNER_IDENTITY_REQUIRED');
    const reservations=[];let sql,plan,root,created=false,shutdownConfirmed=true;
    try{
        for(const name of portNames)reservations.push(await bind(options[name]));
        const phpInfo=await execute(options.php,phpArgs(options.php,'-r').concat('echo json_encode(array("result"=>extension_loaded("mysqli")?"PASS":"FAIL","category"=>"MYSQLI_REQUIRED","version"=>PHP_VERSION));'));
        assert.ok(/^5\.6\.|^8\.2\./.test(phpInfo.version),'TESTED_PHP_FAMILY_REQUIRED');
        await mkdir(directory);created=true;
        await execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(serviceRoot,'private-folder.ps1'),'-Directory',directory]);
        await mkdir(path.join(directory,'data'));await mkdir(path.join(directory,'sdk-template','scratch'),{recursive:true});await mkdir(path.join(directory,'rate-limit'));await mkdir(path.join(directory,'request-locks'));
        const manifest={customer:'bootstrap-template',eaiUsername:'bootstrap-template',sourceDirectory:options.sdkDirectory,archiveSha256:APPROVED_ARCHIVE,sourceSha256:pins.runtimeFiles,bridgeRootSha256:Object.fromEntries(Object.entries(pins.runtimeFiles).filter(([f])=>!/[\\/]/.test(f)))};
        const manifestPath=path.join(directory,'sdk-template','source-manifest.json'),extraPath=path.join(directory,'sdk-template','extra.xml');
        const manifestBytes=JSON.stringify(manifest);await writeFile(manifestPath,manifestBytes,{flag:'wx',mode:0o600});await writeFile(extraPath,sixFeedExtra(await readFile(path.join(options.sdkDirectory,'cwEAIFormats.xml'),'utf8')),{flag:'wx',mode:0o600});
        plan={profile:'LOCAL_EAI_SUITE_V1',directory,node:options.node,php:options.php,mysqld:options.mysqld,sqlPort:options.sqlPort,apiPort:options.apiPort,lockPort:options.lockPort,supervisorPort:options.supervisorPort,intervalSeconds:60,sdkManifestPath:manifestPath,sdkExtraPath:extraPath,clients:[],deployment,deploymentMode:deployment.mode==='local-recette'?'WINDOWS_LOCAL_RECETTE':'WINDOWS_SERVER_RECETTE',productionReady:false};
        plan.windowsOwnerSid=windowsOwner.sid;
        const templateClient={tenant:'bootstrap-template',username:'bootstrap-template',directory:path.dirname(manifestPath),manifestPath,manifestSha256:sha(manifestBytes),initialCursors:{positions:{0:'0'},canbushistory:'0',telemetry:{0:'0'},wiredtelemetry:{0:'0'}}};
        const preflight=await collectSuite(plan,templateClient,'PREFLIGHT_NO_TRANSPORT',templateClient.initialCursors,true);assert.equal(preflight.transportAttempts,0,'NO_EAI_TRANSPORT_DURING_BOOTSTRAP');
        await initializeData(plan);await stopListener(reservations[0]);sql=spawnDatabase(plan);shutdownConfirmed=false;
        root={host:'127.0.0.1',port:plan.sqlPort,username:'root',password:''};let ready=false;
        for(let i=0;i<60;i++){if(sql.exitCode!==null)throw Error('NEW_SQL_STARTUP_EXIT');try{await suiteDatabase(plan,root,'ready');ready=true;break;}catch{await new Promise(r=>setTimeout(r,200));}}
        assert.ok(ready,'NEW_SQL_READY_REQUIRED');const secrets={rootPassword:randomBytes(32).toString('hex'),clients:{}};
        await suiteDatabase(plan,root,'bootstrap',{rootPassword:secrets.rootPassword});root.password=secrets.rootPassword;
        await execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(serviceRoot,'secrets.ps1'),'-Operation','Write','-Path',path.join(directory,'secrets.dpapi')],JSON.stringify(secrets));
        const config={accounts:{},options:{maxRows:10000,maxResponseBytes:16777216,minIntervalSeconds:60,enforceMinuteRule:true,requestConcurrency:concurrency},rateLimitDirectory:path.join(directory,'rate-limit'),source:{driver:'eai-suite-live-mysqli',profile:'eai-suite-live-v1',historyDiagnosticEnabled:true,tenants:{}}};
        await writeFile(path.join(directory,'server-profile.json'),JSON.stringify(deployment,null,2),{flag:'wx',mode:0o600});
        await writeFile(path.join(directory,'http-config.json'),JSON.stringify(config),{flag:'wx',mode:0o600});await writeFile(path.join(directory,'service-plan.json'),JSON.stringify(plan,null,2),{flag:'wx',mode:0o600});await writeFile(path.join(directory,'control.json'),'{"requested":"stop"}',{flag:'wx',mode:0o600});
        await writeFile(path.join(directory,'INITIALISATION.json'),JSON.stringify({profile:'EMPTY_LOCAL_SUITE_INITIALIZATION_V1',customerCount:0,sdkPreflight:true,eaiCalls:0,serviceStarted:false,taskInstalled:false,productionReady:false,utc:new Date().toISOString()},null,2),{flag:'wx',mode:0o600});
    }finally{
        if(sql&&sql.exitCode===null&&root){try{await suiteDatabase(plan,root,'shutdown');await Promise.race([once(sql,'exit'),new Promise(r=>setTimeout(r,7000))]);shutdownConfirmed=sql.exitCode!==null;}catch{shutdownConfirmed=false;}}
        else if(sql)shutdownConfirmed=sql.exitCode!==null;
        for(const reservation of reservations)await stopListener(reservation);
        if(created&&!shutdownConfirmed)throw Error('NEW_SQL_SHUTDOWN_REVIEW_REQUIRED');
    }
    return {result:'PASS',directory,clients:0,sdkPreflightVerified:true,eaiCalls:0,existingDatabasesOpened:0,serverWrites:0,serviceStarted:false,taskInstalled:false,shutdownConfirmed,filesDeleted:0,productionReady:false};
}

if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
    try{const optionsFile=process.argv[2];assert.ok(path.isAbsolute(optionsFile||''),'ABSOLUTE_OPTIONS_REQUIRED');console.log(JSON.stringify(await initializeEmptySuite(JSON.parse(await readFile(optionsFile)))));}
    catch(e){console.log(JSON.stringify({result:'FAIL',category:/^[A-Z0-9_]{1,80}$/.test(e.message)?e.message:'EMPTY_BOOTSTRAP_NOT_CONFIRMED',eaiCalls:0,serviceStarted:false,filesDeleted:0,productionReady:false}));process.exitCode=1;}
}
