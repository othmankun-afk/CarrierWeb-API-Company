// Local archived-recette onboarding only. A live EAI transport validation is
// deliberately NOT inferred from importing an existing publication.
import {readFile,writeFile,rename,open} from 'node:fs/promises';
import {randomBytes,createHash,randomUUID} from 'node:crypto';
import path from 'node:path';
import assert from 'node:assert/strict';
export const digest=x=>createHash('sha256').update(x).digest('hex');
export function apiAccount(name){assert.equal(typeof name,'string');const value=name.normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,48);assert.match(value,/^[a-z0-9][a-z0-9-]{0,47}$/);return value;}
export function planRegistration(config,document,name){
    assert.equal(document.profile,'EAI_SUITE_ARCHIVED_V1');assert.match(document.tenant,/^[A-Za-z0-9_-]{1,64}$/);assert.ok(Array.isArray(document.vehicleIds)&&document.vehicleIds.length>0);assert.ok(Array.isArray(document.endpoints?.vehicles)&&document.endpoints.vehicles.length===document.vehicleIds.length,'FIRST_VEHICLES_PUBLICATION_REQUIRED');
    assert.equal(config.source.driver,'eai-suite-replay');assert.ok(!config.source.tenants[document.tenant],'TENANT_ALREADY_REGISTERED');assert.ok(Object.keys(config.accounts).length<100,'LOCAL_CLIENT_BOUND');
    const account=apiAccount(name);assert.ok(!config.accounts[account],'API_USERNAME_ALREADY_REGISTERED');
    const password=randomBytes(32).toString('hex');const ids=new Set(document.vehicleIds);assert.equal(ids.size,document.vehicleIds.length);for(const id of ids)assert.match(id,/^[1-9][0-9]{0,9}$/);
    for(const row of document.endpoints.vehicles)assert.ok(ids.has(String(row.CWVehicleID)));
    return {account,tenant:document.tenant,password,tokenSha256:digest(password),endpoints:['vehiclesBase','vehicles','positions','canbus','telemetry'].filter(e=>Array.isArray(document.endpoints[e]))};
}
export async function addArchivedClient(directory,document,name,validate){
    assert.ok(path.isAbsolute(directory));const lockPath=path.join(directory,'registration.lock');let lock;
    try{lock=await open(lockPath,'wx');}catch{throw Error('REGISTRATION_LOCK_EXISTS_REVIEW_REQUIRED');}
    // A process crash leaves the lock for manual review. Ordinary validation
    // rejection releases it; it must not block every subsequent client add.
    let completed=false;
    try{
        const configPath=path.join(directory,'http-config.json');const before=await readFile(configPath);const config=JSON.parse(before);
        const registration=planRegistration(config,document,name);await validate(document);
        const filename=registration.tenant+'-'+randomUUID()+'.suite.json',documentPath=path.join(directory,filename);const text=JSON.stringify(document);
        await writeFile(documentPath,text,{flag:'wx',mode:0o600});config.accounts[registration.account]={tenant:registration.tenant,tokenSha256:registration.tokenSha256,vehicleIds:[],scopeMode:'eai-account',endpoints:registration.endpoints};
        config.source.tenants[registration.tenant]={documentPath,documentSha256:digest(text)};
        const temporary=path.join(directory,'config-'+randomUUID()+'.pending.json');await writeFile(temporary,JSON.stringify(config),{flag:'wx',mode:0o600});
        // Existing configuration remains the only visible registry until all
        // source checks finish; rename replaces it atomically on this volume.
        assert.equal(digest(await readFile(configPath)),digest(before),'REGISTRY_CHANGED_DURING_ADD');await rename(temporary,configPath);completed=true;
        return {...registration,sourceMode:'ARCHIVED_EAI_REPLAY',eaiConnectionVerified:false,productionReady:false};
    }finally{
        await lock.close();
        // Remove only our own single technical lock, never data/backups.
        const {unlink}=await import('node:fs/promises');await unlink(lockPath);
    }
}
