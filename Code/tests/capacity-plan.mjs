import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { planCapacity, verifyEvidencePins, TECHNICAL_HISTORY_ROW_GUARD } from '../tools/capacity-plan.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const testPath = fileURLToPath(import.meta.url);
const testSha = createHash('sha256').update(await readFile(testPath)).digest('hex');
let checks = 0;
function check(label, fn) { fn(); checks += 1; }
function fixture() {
  // Deliberately fictitious values, not business choices or client measurements.
  return {
    schemaVersion: 1, cohort: 'SYNTHETIC_ONLY',
    evidencePins: [{ id: 'synthetic', path: testPath, sha256: testSha }],
    current: { fixedLogicalBytesPerClient: 1000, fixedAllocatedBytesPerClient: 2000,
      currentHistoryRowsPerClient: 90000, sharedAllocatedBytes: 300, auxiliaryAllocatedBytes: 400,
      alreadyAllocatedBytesForPlannedScope: 0 },
    measurements: { endpoints: Object.fromEntries(['positions', 'canbus', 'telemetry'].map((e) => [e,
      { payloadBytesPerEvent: 10, payloadEvidenceId: 'synthetic', allocatedDataBytesPerEvent: 20,
        allocatedIndexBytesPerEvent: 5, allocationEvidenceId: 'synthetic' }])) },
    workload: { storageMode: 'RETAINED_WINDOW_MODEL_ONLY', basis: 'OPERATOR_ASSUMPTION', basisDescription: 'Fictitious arithmetic fixture only.',
      retentionDays: 5, eventsPerDayPerVehicle: { positions: 1, canbus: 2, telemetry: 3 },
      vehiclesPerClient: 10, clientCount: 2 },
    observedIngestion: { eventsPerSecond: 10, evidenceId: 'synthetic' },
    infrastructure: { freeDiskBytes: 1000000, backupCopies: 2, backupSizeRatio: 0.5,
      restoreWorkspaceCopies: 1, reservedHeadroomBytes: 1000, requiredRpoSeconds: 3600,
      requiredRtoSeconds: 600, verifiedBackupIntervalSeconds: 1800, verifiedRestoreSeconds: 100,
      costPerGiBMonth: 2, costCurrency: 'TEST_ONLY' },
  };
}
const report = planCapacity(fixture());
check('keep-all includes existing history rather than expiring it',()=>{const x=fixture();x.workload.storageMode='KEEP_ALL_NO_DELETION';x.workload.planningHorizonDays=5;x.current.historyRowsByEndpoint={positions:30000,canbus:30000,telemetry:30000};const r=planCapacity(x);assert.equal(r.technicalRowGuard.plannedRowsPerClient,90300);assert.equal(r.assumptions.retentionDays,null);assert.equal(r.existingHistoryIncluded,true);assert.equal(r.deletionAuthorized,false);});
check('keep-all blocks when horizon or endpoint totals are missing',()=>{const x=fixture();x.workload.storageMode='KEEP_ALL_NO_DELETION';const r=planCapacity(x);assert.equal(r.result,'BLOCKED_MISSING_INPUTS');assert.equal(r.technicalRowGuard.plannedRowsPerClient,null);});
check('keep-all rejects inconsistent current totals',()=>{const x=fixture();x.workload.storageMode='KEEP_ALL_NO_DELETION';x.workload.planningHorizonDays=5;x.current.historyRowsByEndpoint={positions:1,canbus:2,telemetry:3};assert.throws(()=>planCapacity(x),/CURRENT_ENDPOINT_ROW_TOTAL_MISMATCH/);});
check('invalid storage mode cannot imply deletion',()=>{const x=fixture();x.workload.storageMode='AUTO_PURGE';assert.throws(()=>planCapacity(x),/INVALID_STORAGE_MODE/);});
check('explicit assumptions stay conditional', () => assert.equal(report.result, 'CONDITIONAL_OPERATOR_ASSUMPTION'));
check('per endpoint retained rows', () => assert.deepEqual(Object.values(report.endpoints).map((e) => e.retainedRowsPerClient), [50, 100, 150]));
check('logical payload plus fixed only', () => assert.equal(report.storage.logicalPerClient.bytes, 4000));
check('allocation data and index are separate', () => assert.equal(report.storage.allocatedPerClient.bytes, 9500));
check('per vehicle average is explicit scenario average', () => { assert.equal(report.storage.logicalAveragePerVehicle.bytes, 400); assert.equal(report.storage.allocatedAveragePerVehicle.bytes, 950); });
check('shared allocation charged once', () => assert.equal(report.storage.primary.bytes, 19700));
check('explicit backup ratio and count', () => assert.equal(report.storage.backups.bytes, 19700));
check('restore workspace charged separately', () => assert.equal(report.storage.restoreWorkspace.bytes, 19700));
check('all disk demand including headroom', () => assert.equal(report.storage.totalDemand.bytes, 60100));
check('additional demand fits', () => assert.equal(report.storage.additionalDemandFitsFreeDisk, true));
check('technical guard not retention', () => assert.equal(report.technicalRowGuard.isRetentionPolicy, false));
check('technical guard exactly existing value', () => assert.equal(TECHNICAL_HISTORY_ROW_GUARD, 100000));
check('remaining guard runway only observed ingestion', () => assert.equal(report.technicalRowGuard.stopRunwaySeconds, 1000));
check('runway explicitly not daily production', () => assert.match(report.technicalRowGuard.stopRunwayBasis, /NOT_DAILY_PRODUCTION/));
check('RPO interval comparison and RTO observation', () => {
  assert.equal(report.recovery.backupIntervalMeetsRequiredRpo, true);
  assert.equal(report.recovery.observedRestoreMeetsRequiredRto, true);
  assert.equal(report.recovery.productionRecoveryCertified, false);
});
check('no production readiness claim', () => assert.equal(report.productionReady, false));
check('input evidence preserved', () => assert.deepEqual(report.evidencePins, fixture().evidencePins));
check('no input mutation', () => { const x = fixture(); const old = structuredClone(x); planCapacity(x); assert.deepEqual(x, old); });
check('missing retention produces null not a guessed duration', () => {
  const x = fixture(); x.workload.retentionDays = null; const p = planCapacity(x);
  assert.equal(p.result, 'BLOCKED_MISSING_INPUTS'); assert.equal(p.technicalRowGuard.plannedRowsPerClient, null);
  assert.equal(p.storage.totalDemand, null); assert.ok(p.missingInputs.includes('workload.retentionDays'));
});
check('missing physical index calibration blocks physical sizing but not payload sizing', () => {
  const x = fixture(); x.measurements.endpoints.canbus.allocatedIndexBytesPerEvent = null; const p = planCapacity(x);
  assert.equal(p.storage.totalDemand, null); assert.equal(p.storage.logicalPerClient.bytes, 4000);
});
check('missing backup factor not guessed', () => { const x = fixture(); x.infrastructure.backupSizeRatio = null; assert.equal(planCapacity(x).storage.backups, null); });
check('cost optional remains unknown', () => { const x = fixture(); delete x.infrastructure.costPerGiBMonth; assert.equal(planCapacity(x).estimatedStorageCostPerMonth, null); });
check('scope-matching existing allocations deducted once', () => {
  const x = fixture(); x.current.alreadyAllocatedBytesForPlannedScope = 50000; x.infrastructure.freeDiskBytes = 10099;
  const p = planCapacity(x); assert.equal(p.storage.additionalDemand.bytes, 10100); assert.equal(p.storage.additionalDemandFitsFreeDisk, false);
});
check('allocations above target never manufacture negative demand', () => {
  const x = fixture(); x.current.alreadyAllocatedBytesForPlannedScope = 70000; assert.equal(planCapacity(x).storage.additionalDemand.bytes, 0);
});
check('oversized retained dataset rejected by row assessment not purged', () => {
  const x = fixture(); x.workload.retentionDays = 2000; const p = planCapacity(x);
  assert.equal(p.technicalRowGuard.plannedRowsFitExistingGuard, false); assert.equal(p.filesDeleted, 0); assert.equal(p.activeChanges, 0);
  assert.equal(p.feasibility.result, 'REQUIREMENTS_NOT_MET');
});
check('already saturated current guard explicit', () => {
  const x = fixture(); x.current.currentHistoryRowsPerClient = 100001; const p = planCapacity(x);
  assert.equal(p.technicalRowGuard.remainingRowsPerClient, 0); assert.equal(p.technicalRowGuard.stopRunwaySeconds, 0);
});
check('zero ingestion no fake infinite runway', () => { const x = fixture(); x.observedIngestion.eventsPerSecond = 0; assert.equal(planCapacity(x).technicalRowGuard.stopRunwaySeconds, null); });
check('catch-up not accepted as daily basis', () => {
  const x = fixture(); x.workload.basis = 'CATCHUP_OBSERVED'; assert.throws(() => planCapacity(x), /UNSUPPORTED_DAILY_BASIS/);
});
check('daily observation requires catch-up exclusion evidence', () => {
  const x = fixture(); x.workload.basis = 'STEADY_STATE_OBSERVED'; x.workload.basisEvidenceId = 'synthetic';
  x.workload.observationWindowSeconds = 86400; x.workload.catchupExcluded = false;
  const p = planCapacity(x); assert.equal(p.technicalRowGuard.plannedRowsPerClient, null); assert.equal(p.result, 'BLOCKED_MISSING_INPUTS');
});
check('explicit observed steady state remains conditional', () => {
  const x = fixture(); Object.assign(x.workload, { basis: 'STEADY_STATE_OBSERVED', basisEvidenceId: 'synthetic', observationWindowSeconds: 86400, catchupExcluded: true });
  assert.equal(planCapacity(x).result, 'CONDITIONAL_CAPACITY_MODEL');
});
check('invalid enum fails rather than silently assuming', () => { const x = fixture(); x.workload.basis = 'unknown'; assert.throws(() => planCapacity(x)); });
for (const bad of [-1, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1, '10']) {
  check(`invalid numeric input ${String(bad)}`, () => { const x = fixture(); x.infrastructure.freeDiskBytes = bad; assert.throws(() => planCapacity(x), /INVALID_NUMERIC_INPUT/); });
}
check('client count must be a safe integer', () => { const x = fixture(); x.workload.clientCount = 1.5; assert.throws(() => planCapacity(x)); });
check('arithmetic overflow rejected', () => { const x = fixture(); x.workload.vehiclesPerClient = Number.MAX_SAFE_INTEGER; assert.throws(() => planCapacity(x), /INVALID_NUMERIC_INPUT/); });
check('unknown evidence rejects proof claim', () => { const x = fixture(); x.measurements.endpoints.canbus.allocationEvidenceId = 'missing'; assert.throws(() => planCapacity(x), /UNKNOWN_EVIDENCE_ID/); });
check('malformed evidence pin rejected', () => { const x = fixture(); x.evidencePins[0].sha256 = 'bad'; assert.throws(() => planCapacity(x), /INVALID_EVIDENCE_PIN/); });
check('zero backup copies cannot satisfy RPO', () => { const x = fixture(); x.infrastructure.backupCopies = 0; assert.equal(planCapacity(x).recovery.backupIntervalMeetsRequiredRpo, false); });
check('UNC evidence cannot trigger network file access', () => { const x = fixture(); x.evidencePins[0].path = '\\\\example.invalid\\private\\report.json'; assert.throws(() => planCapacity(x), /NON_LOCAL_FILE_PATH/); });
check('URI evidence cannot trigger remote access', () => { const x = fixture(); x.evidencePins[0].path = 'https://example.invalid/report.json'; assert.throws(() => planCapacity(x), /NON_LOCAL_FILE_PATH/); });
const template = JSON.parse(await readFile(resolve(here, '../configuration/capacity-input.template.json'), 'utf8'));
const empty = planCapacity(template);
check('null template deliberately blocked', () => { assert.equal(empty.result, 'BLOCKED_MISSING_INPUTS'); assert.equal(empty.storage.totalDemand, null); assert.equal(empty.assumptions.retentionDays, null); });
await verifyEvidencePins(fixture(), here);
checks += 1;
const tampered = fixture(); tampered.evidencePins[0].sha256 = '0'.repeat(64);
await assert.rejects(verifyEvidencePins(tampered, here), /EVIDENCE_SHA256_MISMATCH/);
checks += 1;
const cli = spawnSync(process.execPath, [resolve(here, '../tools/capacity-plan.mjs'), resolve(here, '../configuration/capacity-input.template.json')], { encoding: 'utf8' });
check('read-only CLI returns blocked report without treating missing choices as failure', () => {
  assert.equal(cli.status, 0, cli.stderr); const p = JSON.parse(cli.stdout);
  assert.equal(p.result, 'BLOCKED_MISSING_INPUTS'); assert.equal(p.evidencePinsVerified, true);
});
process.stdout.write(`${JSON.stringify({ result: 'PASS', checks, suite: 'dry-run-capacity-planner', syntheticInputsOnly: true,
  actualRetentionChosen: false, activeChanges: 0, sqlCalls: 0, networkCalls: 0, filesDeleted: 0, productionReady: false })}\n`);
