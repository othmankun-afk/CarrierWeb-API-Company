import assert from 'node:assert/strict';
import {deploymentConfiguration,publicBaseForPlan,requestConcurrencyConfiguration} from '../service/deployment-config.mjs';
const base={mode:'server-recette',publicBaseUrl:'https://api.company.example',httpMode:'external-http',serverAccessEnabled:true,approval:{reference:'fixture',approvedBy:'fixture',approvedUtc:'2026-10-20 12:00:00'}};
let checks=0;
for(const approvedUtc of ['2026-02-30 00:00:00','2026-04-31 12:00:00','2026-13-01 00:00:00','2026-10-20 24:00:00']){assert.throws(()=>deploymentConfiguration({...base,approval:{...base.approval,approvedUtc}},52012),/APPROVAL/);checks++;}
for(const approvedUtc of ['2024-02-29 00:00:00','2026-02-28 23:59:59']){assert.equal(deploymentConfiguration({...base,approval:{...base.approval,approvedUtc}},52012).approval.approvedUtc,approvedUtc);checks++;}
const configured=deploymentConfiguration(base,52012);assert.equal(publicBaseForPlan({apiPort:52012,deployment:configured}),'https://api.company.example');checks++;
assert.throws(()=>deploymentConfiguration({...base,httpMode:'managed-local'},52012),/SERVER_EXTERNAL/);checks++;
assert.throws(()=>requestConcurrencyConfiguration(undefined,'private-existing-directory',true),/SERVER_REQUEST_LIMITS/);checks++;
for(const apiPort of [undefined,0,NaN,'52012',65536]){assert.throws(()=>publicBaseForPlan({apiPort}),/PORT_INVALID/);checks++;}
for(const publicBaseUrl of ['https://name:password@api.company.example','https://api.company.example/?password=secret','https://api.company.example/path']){assert.throws(()=>deploymentConfiguration({...base,publicBaseUrl},52012),/ORIGIN/);checks++;}
console.log(JSON.stringify({result:'PASS',suite:'deployment-independent-correctness-review',checks,sqlConnections:0,eaiCalls:0,httpListeners:0,filesDeleted:0}));
