import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export const TECHNICAL_HISTORY_ROW_GUARD = 100000;
const ENDPOINTS = ['positions', 'canbus', 'telemetry'];
const MAX = Number.MAX_SAFE_INTEGER;

function localPath(path, label) {
  if (typeof path !== 'string' || !path.trim() || /^[\\/]{2}/.test(path) || /^[a-z][a-z0-9+.-]*:\/\//i.test(path)) {
    throw new TypeError(`NON_LOCAL_FILE_PATH:${label}`);
  }
  return path;
}

function bounded(value, path, { integer = false, positive = false } = {}) {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0 || value > MAX ||
      (integer && !Number.isSafeInteger(value)) || (positive && value <= 0)) {
    throw new TypeError(`INVALID_NUMERIC_INPUT:${path}`);
  }
  return value;
}
function product(values, path) {
  const answer = values.reduce((total, value) => total * value, 1);
  return bounded(answer, path);
}
function sum(values, path) {
  return bounded(values.reduce((total, value) => total + value, 0), path);
}
function bytes(value) {
  if (value === null) return null;
  return { bytes: value, MiB: value / 1048576, GiB: value / 1073741824 };
}

/** Pure dry-run model. Null/missing inputs block their dependent conclusion. */
export function planCapacity(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input) || input.schemaVersion !== 1) {
    throw new TypeError('INVALID_SCHEMA_VERSION');
  }
  const missing = new Set();
  const cautions = [];
  function get(path, options = {}, required = true) {
    const value = path.split('.').reduce((o, key) => o?.[key], input);
    if (value === undefined || value === null) {
      if (required) missing.add(path);
      return null;
    }
    return bounded(value, path, options);
  }
  const pins = input.evidencePins ?? [];
  if (!Array.isArray(pins)) throw new TypeError('INVALID_EVIDENCE_PINS');
  const pinIds = new Set();
  for (const pin of pins) {
    if (!pin || typeof pin.id !== 'string' || !pin.id.trim() || pinIds.has(pin.id) ||
        typeof pin.path !== 'string' || !pin.path.trim() ||
        typeof pin.sha256 !== 'string' || !/^[a-f0-9]{64}$/i.test(pin.sha256)) {
      throw new TypeError('INVALID_EVIDENCE_PIN');
    }
    localPath(pin.path, 'evidence');
    pinIds.add(pin.id);
  }
  function evidence(value, path) {
    if (value === undefined || value === null || value === '') {
      missing.add(path);
      return false;
    }
    if (typeof value !== 'string' || !pinIds.has(value)) throw new TypeError(`UNKNOWN_EVIDENCE_ID:${path}`);
    return true;
  }
  const clientCount = get('workload.clientCount', { integer: true, positive: true });
  const vehicles = get('workload.vehiclesPerClient', { integer: true, positive: true });
  const storageMode = input.workload?.storageMode;
  if (storageMode !== undefined && storageMode !== null && !['KEEP_ALL_NO_DELETION','RETAINED_WINDOW_MODEL_ONLY'].includes(storageMode)) throw new TypeError('INVALID_STORAGE_MODE');
  if (!storageMode) missing.add('workload.storageMode');
  const horizonDays = storageMode === 'KEEP_ALL_NO_DELETION' ? get('workload.planningHorizonDays', { positive: true }) : null;
  const retentionDays = storageMode === 'RETAINED_WINDOW_MODEL_ONLY' ? get('workload.retentionDays', { positive: true }) : null;
  const sizingDays = storageMode === 'KEEP_ALL_NO_DELETION' ? horizonDays : retentionDays;
  if (storageMode === 'RETAINED_WINDOW_MODEL_ONLY') cautions.push('A retained-window scenario does NOT implement or authorize deletion; it cannot size indefinite keep-all operation.');
  let dailyBasisValid = false;
  const basis = input.workload?.basis;
  if (basis === 'STEADY_STATE_OBSERVED') {
    const pinned = evidence(input.workload.basisEvidenceId, 'workload.basisEvidenceId');
    const window = get('workload.observationWindowSeconds', { positive: true });
    if (input.workload.catchupExcluded !== true) missing.add('workload.catchupExcluded=true');
    dailyBasisValid = pinned && window !== null && input.workload.catchupExcluded === true;
    cautions.push('A steady-state observation is a measured scenario, not a future traffic guarantee.');
  } else if (basis === 'OPERATOR_ASSUMPTION') {
    if (typeof input.workload.basisDescription !== 'string' || !input.workload.basisDescription.trim()) {
      missing.add('workload.basisDescription');
    } else {
      dailyBasisValid = true;
    }
    cautions.push('Daily event volumes are an explicit operator assumption, not an observed EAI production rate.');
  } else if (basis === undefined || basis === null) {
    missing.add('workload.basis');
  } else {
    throw new TypeError('UNSUPPORTED_DAILY_BASIS:catch-up ingestion must not be extrapolated into daily event production');
  }

  const fixedLogical = get('current.fixedLogicalBytesPerClient');
  const fixedAllocated = get('current.fixedAllocatedBytesPerClient');
  const currentRows = get('current.currentHistoryRowsPerClient', { integer: true });
  const sharedAllocated = get('current.sharedAllocatedBytes');
  const auxiliaryAllocated = get('current.auxiliaryAllocatedBytes');
  const alreadyAllocated = get('current.alreadyAllocatedBytesForPlannedScope');
  const freeDisk = get('infrastructure.freeDiskBytes');
  const backupCopies = get('infrastructure.backupCopies', { integer: true });
  const backupRatio = get('infrastructure.backupSizeRatio', { positive: true });
  const restoreCopies = get('infrastructure.restoreWorkspaceCopies', { integer: true });
  const headroom = get('infrastructure.reservedHeadroomBytes');
  const requiredRpo = get('infrastructure.requiredRpoSeconds', { positive: true });
  const requiredRto = get('infrastructure.requiredRtoSeconds', { positive: true });
  const measuredBackupInterval = get('infrastructure.verifiedBackupIntervalSeconds', { positive: true });
  const measuredRestore = get('infrastructure.verifiedRestoreSeconds', { positive: true });
  const cost = get('infrastructure.costPerGiBMonth', {}, false);

  const endpoints = {};
  for (const endpoint of ENDPOINTS) {
    const rate = get(`workload.eventsPerDayPerVehicle.${endpoint}`);
    const payload = get(`measurements.endpoints.${endpoint}.payloadBytesPerEvent`);
    const data = get(`measurements.endpoints.${endpoint}.allocatedDataBytesPerEvent`);
    const index = get(`measurements.endpoints.${endpoint}.allocatedIndexBytesPerEvent`);
    const measured = input.measurements?.endpoints?.[endpoint];
    const payloadPinned = evidence(measured?.payloadEvidenceId, `measurements.endpoints.${endpoint}.payloadEvidenceId`);
    const allocationPinned = evidence(measured?.allocationEvidenceId, `measurements.endpoints.${endpoint}.allocationEvidenceId`);
    const currentEndpointRows = storageMode === 'KEEP_ALL_NO_DELETION' ? get(`current.historyRowsByEndpoint.${endpoint}`, { integer: true }) : 0;
    const futureRows = dailyBasisValid && sizingDays !== null && vehicles !== null && rate !== null
      ? Math.ceil(product([rate, vehicles, sizingDays], `${endpoint}.futureRowsPerClient`)) : null;
    const rowsPerClient = futureRows !== null && currentEndpointRows !== null ? sum([futureRows,currentEndpointRows], `${endpoint}.rowsPerClient`) : null;
    if (rowsPerClient !== null) bounded(rowsPerClient, `${endpoint}.rowsPerClient`, { integer: true });
    const logicalBytes = rowsPerClient !== null && payload !== null && payloadPinned
      ? product([rowsPerClient, payload], `${endpoint}.logicalBytesPerClient`) : null;
    const allocatedData = rowsPerClient !== null && data !== null && allocationPinned
      ? product([rowsPerClient, data], `${endpoint}.allocatedDataBytesPerClient`) : null;
    const allocatedIndex = rowsPerClient !== null && index !== null && allocationPinned
      ? product([rowsPerClient, index], `${endpoint}.allocatedIndexBytesPerClient`) : null;
    endpoints[endpoint] = { eventsPerDayPerVehicle: rate, currentRowsPerClient: currentEndpointRows, additionalRowsDuringHorizon: futureRows, retainedRowsPerClient: rowsPerClient,
      logicalPayloadPerClient: bytes(logicalBytes), allocatedDataPerClient: bytes(allocatedData),
      allocatedIndexPerClient: bytes(allocatedIndex) };
  }
  const all = (getter) => ENDPOINTS.map((endpoint) => getter(endpoints[endpoint]));
  const addWhenKnown = (values, label) => values.every((v) => v !== null) ? sum(values, label) : null;
  const rowTotal = addWhenKnown(all((e) => e.retainedRowsPerClient), 'rowsPerClient');
  if (storageMode === 'KEEP_ALL_NO_DELETION' && currentRows !== null && all(e=>e.currentRowsPerClient).every(n=>n!==null) && sum(all(e=>e.currentRowsPerClient),'currentRowsCheck') !== currentRows) throw new TypeError('CURRENT_ENDPOINT_ROW_TOTAL_MISMATCH');
  const logicalPerClient = addWhenKnown([fixedLogical, ...all((e) => e.logicalPayloadPerClient?.bytes ?? null)], 'logicalPerClient');
  const allocatedPerClient = addWhenKnown([fixedAllocated,
    ...all((e) => e.allocatedDataPerClient?.bytes ?? null),
    ...all((e) => e.allocatedIndexPerClient?.bytes ?? null)], 'allocatedPerClient');
  const tenantTotal = allocatedPerClient !== null && clientCount !== null
    ? product([allocatedPerClient, clientCount], 'allTenantBytes') : null;
  const primaryTotal = addWhenKnown([tenantTotal, sharedAllocated, auxiliaryAllocated], 'primaryTotal');
  const backupTotal = primaryTotal !== null && backupRatio !== null && backupCopies !== null
    ? product([primaryTotal, backupRatio, backupCopies], 'backupTotal') : null;
  const restoreTotal = primaryTotal !== null && restoreCopies !== null
    ? product([primaryTotal, restoreCopies], 'restoreTotal') : null;
  const total = addWhenKnown([primaryTotal, backupTotal, restoreTotal, headroom], 'plannedTotal');
  const incremental = total !== null && alreadyAllocated !== null ? Math.max(0, total - alreadyAllocated) : null;
  const fitsDisk = incremental !== null && freeDisk !== null ? incremental <= freeDisk : null;
  const ingestionRate = get('observedIngestion.eventsPerSecond', {}, false);
  const ingestionEvidencePresent = ingestionRate === null ? false
    : evidence(input.observedIngestion?.evidenceId, 'observedIngestion.evidenceId');
  const remainingGuardRows = currentRows === null ? null : Math.max(0, TECHNICAL_HISTORY_ROW_GUARD - currentRows);
  const stopRunwaySeconds = remainingGuardRows !== null && ingestionRate !== null && ingestionRate > 0 && ingestionEvidencePresent
    ? remainingGuardRows / ingestionRate : null;
  if (stopRunwaySeconds !== null) bounded(stopRunwaySeconds, 'stopRunwaySeconds');
  const rowGuardFits = rowTotal === null ? null : rowTotal <= TECHNICAL_HISTORY_ROW_GUARD;
  if (rowGuardFits === false) cautions.push('The planned retained rows exceed the existing technical guard. This model does not raise it or delete data.');
  if (currentRows !== null && currentRows >= TECHNICAL_HISTORY_ROW_GUARD) cautions.push('The current row guard is already reached; free disk alone does not restore publication.');
  cautions.push('No retention duration, quota, daily traffic, overhead ratio, headroom, RPO or RTO is chosen by this tool.');
  cautions.push('Retained rows describe a target dataset, not an implemented purge or a guarantee that all historical data is obtainable.');
  cautions.push('Backups and restore workspaces are charged to the same disk budget in this model; model separate volumes separately.');
  cautions.push('RPO/RTO arithmetic does not certify power-loss recovery, disk loss, scheduler operation, offsite protection or DPAPI secret portability.');
  const timing = {
    requiredRpoSeconds: requiredRpo, verifiedBackupIntervalSeconds: measuredBackupInterval,
    backupIntervalMeetsRequiredRpo: requiredRpo !== null && measuredBackupInterval !== null && backupCopies !== null
      ? backupCopies > 0 && measuredBackupInterval <= requiredRpo : null,
    requiredRtoSeconds: requiredRto, verifiedRestoreSeconds: measuredRestore,
    observedRestoreMeetsRequiredRto: requiredRto !== null && measuredRestore !== null ? measuredRestore <= requiredRto : null,
    productionRecoveryCertified: false,
  };
  const missingInputs = [...missing].sort();
  const failures = [];
  if (fitsDisk === false) failures.push('ADDITIONAL_STORAGE_EXCEEDS_FREE_DISK');
  if (rowGuardFits === false) failures.push('TARGET_ROWS_EXCEED_EXISTING_TECHNICAL_GUARD');
  if (timing.backupIntervalMeetsRequiredRpo === false) failures.push('BACKUP_INTERVAL_OR_COPIES_DO_NOT_MEET_RPO');
  if (timing.observedRestoreMeetsRequiredRto === false) failures.push('OBSERVED_RESTORE_EXCEEDS_REQUIRED_RTO');
  return {
    result: missingInputs.length ? 'BLOCKED_MISSING_INPUTS' : basis === 'OPERATOR_ASSUMPTION'
      ? 'CONDITIONAL_OPERATOR_ASSUMPTION' : 'CONDITIONAL_CAPACITY_MODEL',
    mode: 'DRY_RUN_NO_ACTIVE_CHANGE', schemaVersion: 1, evidencePins: structuredClone(pins),
    assumptions: { cohort: input.cohort ?? null, clientCount, vehiclesPerClient: vehicles,
      storageMode: storageMode ?? null, planningHorizonDays: horizonDays, retentionDays, dailyBasis: basis ?? null, dailyBasisDescription: input.workload?.basisDescription ?? null },
    missingInputs, endpoints,
    feasibility: { result: failures.length ? 'REQUIREMENTS_NOT_MET' : missingInputs.length
      ? 'UNDETERMINED_MISSING_INPUTS' : 'CONDITIONAL_SCENARIO_FIT', failures, productionCertification: false },
    storage: { logicalPerClient: bytes(logicalPerClient), allocatedPerClient: bytes(allocatedPerClient),
      logicalAveragePerVehicle: bytes(logicalPerClient !== null && vehicles !== null ? logicalPerClient / vehicles : null),
      allocatedAveragePerVehicle: bytes(allocatedPerClient !== null && vehicles !== null ? allocatedPerClient / vehicles : null),
      sharedAllocated: bytes(sharedAllocated), auxiliaryAllocated: bytes(auxiliaryAllocated),
      allPlannedClients: bytes(tenantTotal), primary: bytes(primaryTotal), backups: bytes(backupTotal),
      restoreWorkspace: bytes(restoreTotal), reservedHeadroom: bytes(headroom), totalDemand: bytes(total),
      alreadyAllocatedMatchingScope: bytes(alreadyAllocated), additionalDemand: bytes(incremental),
      freeDisk: bytes(freeDisk), additionalDemandFitsFreeDisk: fitsDisk },
    technicalRowGuard: { rowsPerTenant: TECHNICAL_HISTORY_ROW_GUARD, isRetentionPolicy: false,
      isCertifiedProductionCapacity: false, currentRowsPerClient: currentRows,
      plannedRowsPerClient: rowTotal, plannedRowsFitExistingGuard: rowGuardFits,
      remainingRowsPerClient: remainingGuardRows, stopRunwaySeconds,
      stopRunwayBasis: stopRunwaySeconds === null ? null : 'SHORT_OBSERVED_INGESTION_NOT_DAILY_PRODUCTION',
      nextPageFits: 'NOT_ASSESSED_WITHOUT_NEXT_PAGE_SIZE',
      recommendation: 'REVIEW_CAPACITY_OR_APPROVED_RETENTION; NO_AUTOMATIC_PURGE_OR_GUARD_CHANGE' },
    recovery: timing,
    estimatedStorageCostPerMonth: total !== null && cost !== null
      ? bounded(total / 1073741824 * cost, 'storageCost') : null,
    costCurrency: input.infrastructure?.costCurrency ?? null,
    costIncludes: ['STORAGE_ONLY_NOT_CPU_RAM_IOPS_HTTPS_MONITORING_LABOUR'],
    productionReady: false, deletionAuthorized: false, existingHistoryIncluded: storageMode === 'KEEP_ALL_NO_DELETION', activeChanges: 0, sqlCalls: 0, networkCalls: 0, filesDeleted: 0, cautions,
  };
}

/** Read-only evidence verification, used by the CLI before calculating. */
export async function verifyEvidencePins(input, baseDirectory) {
  for (const pin of input.evidencePins ?? []) {
    if (!pin || typeof pin.path !== 'string' || typeof pin.sha256 !== 'string') throw new TypeError('INVALID_EVIDENCE_PIN');
    localPath(pin.path, 'evidence');
    const actual = createHash('sha256').update(await readFile(resolve(baseDirectory, pin.path))).digest('hex');
    if (actual !== pin.sha256.toLowerCase()) throw new Error(`EVIDENCE_SHA256_MISMATCH:${pin.id}`);
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const args = process.argv.slice(2);
  if (args.length !== 1) {
    process.stderr.write('Usage: node tools/capacity-plan.mjs <input.json>\nRead-only dry run; report is written only to stdout.\n');
    process.exitCode = 2;
  } else {
    try {
      const inputPath = resolve(localPath(args[0], 'input'));
      const input = JSON.parse(await readFile(inputPath, 'utf8'));
      const report = planCapacity(input);
      await verifyEvidencePins(input, resolve(inputPath, '..'));
      process.stdout.write(`${JSON.stringify({ ...report, evidencePinsVerified: true }, null, 2)}\n`);
    } catch (error) {
      process.stderr.write(`${JSON.stringify({ result: 'FAIL', category: String(error.message), activeChanges: 0 })}\n`);
      process.exitCode = 1;
    }
  }
}
