import {readFile,writeFile} from 'node:fs/promises';import path from 'node:path';import {serviceRoot,execute,phpArgs,sourcePins,eaiConfig,sha} from './runtime.mjs';
export const suiteDatabase=(plan,connection,action,extra={})=>execute(plan.php,phpArgs(plan.php,path.join(serviceRoot,'suite-database.php')),JSON.stringify({directory:plan.directory,connection,action,...extra}),30000);
export async function collectSuite(plan,client,password,cursors,preflight=false,purpose='INCREMENTAL'){
    if(!['INCREMENTAL','HISTORY_BACKFILL_SINGLE_PAGE'].includes(purpose))throw Error('COLLECTION_PURPOSE_INVALID');
    const bytes=await readFile(client.manifestPath);if(sha(bytes)!==client.manifestSha256)throw Error('ACCOUNT_SOURCE_MISMATCH');const manifest=JSON.parse(bytes);if(manifest.eaiUsername!==client.username||manifest.customer.toLowerCase()!==client.tenant)throw Error('ACCOUNT_SOURCE_MISMATCH');await sourcePins(manifest);
    const cyclePlan=path.join(client.directory,purpose==='INCREMENTAL'?'suite-cycle-plan.json':'backfill-cycle-plan.json');await writeFile(cyclePlan,JSON.stringify({manifestPath:client.manifestPath,tenant:client.tenant,username:client.username,cursors,purpose}));
    const php=path.join(manifest.sourceDirectory,'php.exe'),disabled='exec,shell_exec,system,passthru,proc_open,popen,fsockopen,pfsockopen,stream_socket_client,unlink,rename'+(preflight?',curl_exec':'');
    const args=['-n','-d',`extension_dir="${path.join(manifest.sourceDirectory,'ext').replaceAll('\\','/')}"`,'-d','extension=php_curl.dll','-d','extension=php_openssl.dll','-d','memory_limit=256M','-d','display_errors=0','-d','log_errors=0','-d','allow_url_fopen=0','-d',`disable_functions=${disabled}`,path.join(serviceRoot,'collect-suite-eai.php'),preflight?'--preflight':'--collect',cyclePlan];
    const result=await execute(php,args,eaiConfig(client,manifest,password),60000,manifest.sourceDirectory);await sourcePins(manifest);return result;
}
