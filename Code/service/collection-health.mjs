// Process liveness, collection progress and data freshness are separate facts.
export function collectionHealth(state,{maxAgeSeconds=300,now=Date.now()}={}){
 const stamp=typeof state?.capturedUtc==='string'?Date.parse(state.capturedUtc.replace(' ','T')+'Z'):NaN;
 const age=Number.isFinite(stamp)?Math.floor((now-stamp)/1000):null;
 const blocked=state?.inFlight===true||String(state?.status||'').startsWith('FAULT_');
 return {...state,persistedStatus:state?.status||'UNKNOWN',status:blocked?'BLOCKED_REVIEW_REQUIRED':state?.status||'UNKNOWN',
  ...(blocked?{attention:'MANUAL_REVIEW_REQUIRED_NO_AUTOMATIC_RETRY'}:{}),
  publicationFreshness:age===null||age<0?'UNAVAILABLE':age>maxAgeSeconds?'STALE':'FRESH',publicationAgeSeconds:age,
  synchronizationHealthy:!blocked&&age!==null&&age>=0&&age<=maxAgeSeconds};
}
