import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {readFile,writeFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
export const serviceRoot=path.dirname(fileURLToPath(import.meta.url)),apiRoot=path.dirname(serviceRoot);
export const sha=value=>createHash('sha256').update(value).digest('hex');
export async function execute(program,args,input='',timeout=60000,cwd=apiRoot){
    const environment={...process.env,FLEET_API_LOCAL_TEST:'1',FLEET_API_CONFIG:'',FLEET_API_DEMO:'',PHPRC:'',PHP_INI_SCAN_DIR:''};
    // PowerShell 7's module path cannot be inherited by Windows PowerShell 5.1.
    // Let the latter resolve its own built-in Security/DPAPI modules.
    if(path.basename(program).toLowerCase()==='powershell.exe'){for(const key of Object.keys(environment)){if(key.toLowerCase()==='psmodulepath')delete environment[key];}}
    const child=spawn(program,args,{cwd,windowsHide:true,stdio:['pipe','pipe','pipe'],env:environment});let out='',errors=0;
    child.stdout.on('data',b=>{out+=b;if(out.length>20971520)child.kill();});child.stderr.on('data',b=>errors+=b.length);child.stdin.on('error',()=>{});child.stdin.end(input);const timer=setTimeout(()=>child.kill(),timeout);
    try{const [code]=await once(child,'exit');let result;try{result=JSON.parse(out);}catch{}if(code!==0||errors||result?.result==='FAIL'){throw Error(result?.category||'SERVICE_CHILD_FAILED');}if(!result)throw Error('SERVICE_CHILD_RESPONSE_INVALID');return result;}finally{clearTimeout(timer);out='';}
}
export function phpArgs(php,script){return ['-n','-d',`extension_dir="${path.join(path.dirname(php),'ext').replaceAll('\\','/')}"`,'-d','extension=php_mysqli.dll',script];}
export const database=(plan,connection,action,extra={})=>execute(plan.php,phpArgs(plan.php,path.join(serviceRoot,'database.php')),JSON.stringify({directory:plan.directory,connection,action,...extra}),20000);
export async function sourcePins(manifest){for(const [file,hash] of Object.entries(manifest.sourceSha256)){assert.ok(!path.isAbsolute(file)&&!file.split(/[\\/]/).includes('..'));if(sha(await readFile(path.join(manifest.sourceDirectory,file)))!==hash)throw Error('OFFICIAL_SOURCE_CHANGED');}}
export function eaiConfig(client,manifest,password){
    const scratch=path.join(client.directory,'scratch').replaceAll('\\','/'),source=manifest.sourceDirectory;
    assert.ok(!/[\r\n\0]/.test(password)&&!/[\r\n\0]/.test(client.username));
    return ['backEnd: null','username: '+client.username,'password: '+password,'password_encrypted: 0','actions: storeData',`format: ${path.join(source,'cwEAIFormats.xml').replaceAll('\\','/')}`,`formatExtra: ${path.join(client.directory,'extra.xml').replaceAll('\\','/')}`,'sync_url_base: https://eaiaws1.carrierweb.com/website/eai','sync_url_version: 1.1.232','transport: native','http_use_local_cert_file: 1','forceUseMaxidTable: 2','time_format: gmt','characterset: UTF-8','delay: 60','send_no_delay: 0','allow_server_log_request: 0','allow_server_cfg_request: 0','log_file: NUL','debug_level: 0',...['file_ids_directory','file_in_directory','file_out_directory'].map(k=>`${k}: ${scratch}/`)].join('\n')+'\n';
}
export async function collect(plan,client,password,cursors,preflight=false){
    const manifest=JSON.parse(await readFile(client.manifestPath,'utf8'));if(sha(await readFile(client.manifestPath))!==client.manifestSha256||manifest.eaiUsername!==client.username||manifest.customer.toLowerCase()!==client.tenant)throw Error('ACCOUNT_SOURCE_MISMATCH');
    await sourcePins(manifest);const cyclePlan=path.join(client.directory,'cycle-plan.json');await writeFile(cyclePlan,JSON.stringify({manifestPath:client.manifestPath,tenant:client.tenant,username:client.username,cursors}));
    const php=path.join(manifest.sourceDirectory,'php.exe'),disabled='exec,shell_exec,system,passthru,proc_open,popen,fsockopen,pfsockopen,stream_socket_client,unlink,rename'+(preflight?',curl_exec':'');
    const args=['-n','-d',`extension_dir="${path.join(manifest.sourceDirectory,'ext').replaceAll('\\','/')}"`,'-d','extension=php_curl.dll','-d','extension=php_openssl.dll','-d','memory_limit=256M','-d','display_errors=0','-d','log_errors=0','-d','allow_url_fopen=0','-d',`disable_functions=${disabled}`,path.join(serviceRoot,'collect-eai.php'),preflight?'--preflight':'--collect',cyclePlan];
    const result=await execute(php,args,eaiConfig(client,manifest,password),60000,manifest.sourceDirectory);await sourcePins(manifest);return result;
}
export async function waitForDatabase(plan,rootConnection,child){for(let i=0;i<50;i++){if(child&&child.exitCode!==null)throw Error('OWN_SQL_EXITED_BEFORE_READY');try{return await database(plan,rootConnection,'ready');}catch{await new Promise(r=>setTimeout(r,200));}}throw Error('OWN_SQL_STARTUP_FAILED');}
export function spawnDatabase(plan){return spawn(plan.mysqld,['--no-defaults',`--basedir=${path.dirname(path.dirname(plan.mysqld))}`,`--datadir=${path.join(plan.directory,'data')}`,`--port=${plan.sqlPort}`,'--bind-address=127.0.0.1','--skip-log-bin','--skip-slave-start','--log_syslog=0','--event-scheduler=OFF','--local-infile=0','--secure-file-priv=NULL','--max-connections=12','--innodb-buffer-pool-size=64M',`--log-error=${path.join(plan.directory,'mysql-service.log')}`],{windowsHide:true,stdio:'ignore'});}
