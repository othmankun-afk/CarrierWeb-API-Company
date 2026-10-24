// Process supervision never resets EAI cursors, reservations, faults or credentials.
export function restartDecision({requested, exitCode, workerPid, status, watchdogRestart=false, now=Date.now(), failures=[], maxRestarts=3, windowMs=900000}) {
    if(!status||status.pid!==workerPid||status.shutdownConfirmed!==true)return {action:'review',reason:'WORKER_EXIT_WITHOUT_CONFIRMED_SQL_SHUTDOWN'};
    if(requested==='stop')return {action:'stop',reason:'OPERATOR_STOP'};
    if(status.stage==='STOPPED'&&!watchdogRestart)return {action:'stop',reason:'WORKER_STOPPED'};
    if((status.stage!=='FAULTED'&&!(watchdogRestart&&status.stage==='STOPPED'))||(exitCode===0&&!watchdogRestart))return {action:'review',reason:'WORKER_EXIT_STATE_AMBIGUOUS'};
    const recent=failures.filter(time=>now-time<windowMs);
    if(recent.length>=maxRestarts)return {action:'review',reason:'LOCAL_RESTART_BUDGET_EXHAUSTED'};
    return {action:'restart',reason:'CLEAN_LOCAL_PROCESS_FAILURE',delayMs:Math.min(30000,5000*2**recent.length)};
}

export function healthDecision({status, workerPid, startedAt, now=Date.now(), startupTimeoutMs=45000, heartbeatTimeoutMs=120000}) {
    if(!status||status.pid!==workerPid)return now-startedAt>startupTimeoutMs?'REQUEST_GRACEFUL_STOP':'STARTING';
    const age=now-Date.parse(status.utc);
    if(!Number.isFinite(age)||age>heartbeatTimeoutMs)return 'REQUEST_GRACEFUL_STOP';
    return 'HEALTHY';
}
