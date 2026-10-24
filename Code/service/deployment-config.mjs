// Deployment addresses are configuration, never inferred from an HTTP Host or
// forwarded header. This module does not expose a listener or approve release.
import assert from 'node:assert/strict';

export function deploymentConfiguration(input, apiPort) {
    assert.ok(Number.isInteger(apiPort)&&apiPort>=1024&&apiPort<=65535,'DEPLOYMENT_PORT_INVALID');
    const value=input??{mode:'local-recette',publicBaseUrl:'http://127.0.0.1:'+apiPort,httpMode:'managed-local',serverAccessEnabled:false,approval:null};
    assert.ok(value&&typeof value==='object'&&!Array.isArray(value),'DEPLOYMENT_CONFIG_REQUIRED');
    const allowed=['mode','publicBaseUrl','httpMode','serverAccessEnabled','approval'];
    assert.ok(Object.keys(value).every(k=>allowed.includes(k)),'DEPLOYMENT_OPTION_INVALID');
    assert.ok(['local-recette','server-recette'].includes(value.mode),'DEPLOYMENT_MODE_INVALID');
    assert.ok(['managed-local','external-http'].includes(value.httpMode),'DEPLOYMENT_HTTP_MODE_INVALID');
    assert.equal(typeof value.serverAccessEnabled,'boolean','DEPLOYMENT_ENABLE_REQUIRED');
    assert.ok(typeof value.publicBaseUrl==='string'&&value.publicBaseUrl.length<=2048&&!/[\s\\]/.test(value.publicBaseUrl),'DEPLOYMENT_BASE_URL_INVALID');
    let url;try{url=new URL(value.publicBaseUrl);}catch{throw Error('DEPLOYMENT_BASE_URL_INVALID');}
    assert.ok(!url.username&&!url.password&&!url.search&&!url.hash&&url.pathname==='/'&&url.hostname,'DEPLOYMENT_ORIGIN_ONLY_REQUIRED');
    if(value.mode==='local-recette') {
        assert.equal(value.httpMode,'managed-local','LOCAL_HTTP_MODE_REQUIRED');
        assert.ok(url.protocol==='http:'&&url.hostname==='127.0.0.1'&&Number(url.port)===apiPort,'LOCAL_BASE_URL_REQUIRED');
        assert.equal(value.serverAccessEnabled,false,'LOCAL_REMOTE_ACCESS_FORBIDDEN');
        assert.ok(value.approval===null||value.approval===undefined,'LOCAL_APPROVAL_INVALID');
    } else {
        assert.equal(value.httpMode,'external-http','SERVER_EXTERNAL_HTTP_REQUIRED');
        assert.equal(url.protocol,'https:','SERVER_HTTPS_REQUIRED');
        assert.ok(!['localhost','127.0.0.1','[::1]'].includes(url.hostname),'SERVER_PUBLIC_ORIGIN_REQUIRED');
        if(value.serverAccessEnabled) {
            const a=value.approval;
            assert.ok(a&&typeof a==='object'&&Object.keys(a).sort().join(',')==='approvedBy,approvedUtc,reference','SERVER_APPROVAL_REQUIRED');
            for(const key of ['approvedBy','reference'])assert.ok(typeof a[key]==='string'&&a[key].length>0&&a[key].length<=256&&!/[\r\n\0]/.test(a[key]),'SERVER_APPROVAL_REQUIRED');
            assert.ok(typeof a.approvedUtc==='string'&&/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(a.approvedUtc)&&Number.isFinite(Date.parse(a.approvedUtc.replace(' ','T')+'Z')),'SERVER_APPROVAL_REQUIRED');
            assert.equal(new Date(a.approvedUtc.replace(' ','T')+'Z').toISOString().slice(0,19).replace('T',' '),a.approvedUtc,'SERVER_APPROVAL_REQUIRED');
        } else assert.ok(value.approval===null||value.approval===undefined,'DISABLED_SERVER_APPROVAL_INVALID');
    }
    return {version:'EAI_API_DEPLOYMENT_V1',mode:value.mode,publicBaseUrl:url.origin,httpMode:value.httpMode,serverAccessEnabled:value.serverAccessEnabled,approval:value.approval??null};
}

export function publicBaseForPlan(plan) {
    if(!plan.deployment)return deploymentConfiguration(undefined,plan.apiPort).publicBaseUrl;
    const {version,...input}=plan.deployment;
    assert.equal(version,'EAI_API_DEPLOYMENT_V1','DEPLOYMENT_VERSION_INVALID');
    return deploymentConfiguration(input,plan.apiPort).publicBaseUrl;
}

export function requestConcurrencyConfiguration(input, directory, serverMode=false) {
    const v=input??(serverMode?null:{globalMaxConcurrent:1,tenantMaxConcurrent:1,requestTimeoutSeconds:30});
    assert.ok(v&&typeof v==='object'&&!Array.isArray(v),'SERVER_REQUEST_LIMITS_REQUIRED');
    assert.equal(Object.keys(v).sort().join(','),'globalMaxConcurrent,requestTimeoutSeconds,tenantMaxConcurrent','REQUEST_LIMITS_INVALID');
    assert.ok(Number.isInteger(v.globalMaxConcurrent)&&v.globalMaxConcurrent>=1&&v.globalMaxConcurrent<=256,'REQUEST_LIMITS_INVALID');
    assert.ok(Number.isInteger(v.tenantMaxConcurrent)&&v.tenantMaxConcurrent>=1&&v.tenantMaxConcurrent<=v.globalMaxConcurrent,'REQUEST_LIMITS_INVALID');
    assert.ok(Number.isInteger(v.requestTimeoutSeconds)&&v.requestTimeoutSeconds>=1&&v.requestTimeoutSeconds<=300,'REQUEST_LIMITS_INVALID');
    return {...v,directory};
}
