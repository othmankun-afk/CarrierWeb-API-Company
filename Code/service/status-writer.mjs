import {writeFile,rename as nativeRename} from 'node:fs/promises';
import {randomUUID} from 'node:crypto';
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));

// Windows readers (including PowerShell Get-Content) may temporarily deny
// replacement of an open file. Retry only these LOCAL sharing failures. This
// never retries a transport/SQL/EAI operation and never deletes the old status.
export function statusWriter(target,{renameFile=nativeRename,maxAttempts=6,wait=pause,onFallback=()=>{}}={}){
    let queue=Promise.resolve();
    return value=>{
        const bytes=JSON.stringify(value,null,2);
        // A failed status write must not poison every later/final heartbeat.
        const attempt=queue.catch(()=>{}).then(async()=>{
            const temporary=target+'.'+process.pid+'.'+randomUUID()+'.tmp';
            await writeFile(temporary,bytes,{flag:'wx'});
            for(let index=0;;index++){
                try{await renameFile(temporary,target);return;}
                catch(error){
                    if(!['EACCES','EPERM','EBUSY'].includes(error.code))throw error;
                    if(index+1>=maxAttempts){
                        // Some Windows viewers keep a handle indefinitely with
                        // ReadWrite but without Delete sharing. An in-place
                        // status refresh avoids stopping healthy SQL/EAI merely
                        // because an informational file cannot be replaced.
                        // Readers must treat transient invalid JSON as unknown;
                        // it never authorizes transport, SQL writes or a retry.
                        await writeFile(target,bytes);onFallback();return;
                    }
                    await wait(Math.min(200,20*(index+1)));
                }
            }
        });
        queue=attempt;return attempt;
    };
}
