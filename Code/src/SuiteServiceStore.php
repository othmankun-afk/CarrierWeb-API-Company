<?php
namespace FleetApi;
require_once __DIR__.'/EaiSuiteMapping.php';
require_once __DIR__.'/EaiVehicleState.php';
require_once __DIR__.'/CompactStateJson.php';
require_once __DIR__.'/SuiteCapacityPolicy.php';
require_once __DIR__.'/SnapshotReadPolicy.php';
require_once __DIR__.'/EaiCursorState.php';
class SuiteServiceStore
{
    const MAX_HISTORY_ROWS=100000;
    private static function execute($db,$sql,array $values){$s=$db->prepare($sql);if(!$s){throw new ApiException(503,'suite_storage_failed');}$args=array(str_repeat('s',count($values)));foreach($values as $k=>$v)$args[]=&$values[$k];if($values&&!call_user_func_array(array($s,'bind_param'),$args)){throw new ApiException(503,'suite_storage_failed');}if(!$s->execute()){throw new ApiException(503,'suite_storage_failed');}return $s;}
    public static function row($db,$tenant,$lock=false){$s=self::execute($db,'SELECT * FROM api_suite_state WHERE tenant_key=?'.($lock?' FOR UPDATE':''),array($tenant));$r=$s->get_result()->fetch_assoc();$s->close();if(!$r)throw new ApiException(503,'suite_state_missing');return $r;}
    public static function initialize($db,$tenant){if(!is_string($tenant)||!preg_match('/^[a-z0-9_-]{1,64}$/D',$tenant))throw new ApiException(503,'suite_tenant_invalid');$s=self::execute($db,"INSERT INTO api_suite_state(tenant_key,cursors_json,status) VALUES(?,'{}','WAITING_FIRST_SYNC')",array($tenant));$s->close();}
    public static function state($db,$tenant){$r=self::row($db,$tenant);return array('generation'=>(int)$r['generation'],'capturedUtc'=>$r['captured_utc'],'cursors'=>StrictJson::decode($r['cursors_json'],true,16),'inFlight'=>(bool)$r['in_flight'],'status'=>$r['status'],'lastAttemptUtc'=>$r['last_attempt_utc']);}
    private static function capacityUsage($db,$tenant){$q=self::execute($db,'SELECT COUNT(*) AS n,COALESCE(SUM(OCTET_LENGTH(payload_json)),0) AS payload_bytes FROM api_suite_history WHERE tenant_key=?',array($tenant));$r=$q->get_result()->fetch_assoc();$q->close();if(!$r||!preg_match('/^[0-9]+$/D',(string)$r['n'])||!preg_match('/^[0-9]+$/D',(string)$r['payload_bytes']))throw new ApiException(503,'suite_capacity_usage_invalid');return array((float)$r['n']<=PHP_INT_MAX?(int)$r['n']:(float)$r['n'],(float)$r['payload_bytes']<=PHP_INT_MAX?(int)$r['payload_bytes']:(float)$r['payload_bytes']);}
    public static function capacity($db,$tenant,array $capacityPolicy=null){self::row($db,$tenant);list($count,$bytes)=self::capacityUsage($db,$tenant);return SuiteCapacityPolicy::describe($capacityPolicy,$count,$bytes);}
    public static function preflightCapacity($db,$tenant,array $capacityPolicy=null){$usage=self::capacity($db,$tenant,$capacityPolicy);return SuiteCapacityPolicy::preflight($capacityPolicy,$usage['storedTenantEvents'],$usage['storedTenantPayloadBytes']);}
    public static function ensureBackfillSchema($db){
        // Called only by an own-schema maintenance connection whose datadir and
        // port have already been verified by the CLI boundary. Publishers/readers
        // have no CREATE privilege and must not perform installation implicitly.
        $r=$db->query('SELECT CURRENT_USER() AS account')->fetch_assoc();if(!isset($r['account'])||strpos($r['account'],'root@')!==0)throw new ApiException(503,'suite_root_maintenance_required');
        $sql="CREATE TABLE IF NOT EXISTS api_suite_backfill_state (tenant_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,cursors_json TEXT NOT NULL,pages_accepted BIGINT UNSIGNED NOT NULL DEFAULT 0,first_capture_utc DATETIME NULL,last_capture_utc DATETIME NULL,last_attempt_utc DATETIME NULL,last_page_counts_json TEXT NOT NULL,complete_history_verified TINYINT NOT NULL DEFAULT 0,status VARCHAR(64) CHARACTER SET ascii NOT NULL) ENGINE=InnoDB";
        if(!$db->query($sql))throw new ApiException(503,'suite_storage_failed');return array('result'=>'PASS','ownBackfillTableEnsured'=>true,'rowsDeleted'=>0,'externalCalls'=>0);
    }
    private static function backfillRow($db,$tenant,$lock=false){$q=self::execute($db,'SELECT * FROM api_suite_backfill_state WHERE tenant_key=?'.($lock?' FOR UPDATE':''),array($tenant));$row=$q->get_result()->fetch_assoc();$q->close();return $row;}
    public static function backfillState($db,$tenant){
        self::row($db,$tenant);$r=self::backfillRow($db,$tenant);return array('exists'=>$r?true:false,'pagesAccepted'=>$r?(int)$r['pages_accepted']:0,'cursors'=>$r?StrictJson::decode($r['cursors_json'],true,16):array('positions'=>array('0'=>'0'),'canbushistory'=>'0','telemetry'=>array('0'=>'0'),'wiredtelemetry'=>array('0'=>'0')),'firstCaptureUtc'=>$r?$r['first_capture_utc']:null,'lastCaptureUtc'=>$r?$r['last_capture_utc']:null,'lastAttemptUtc'=>$r?$r['last_attempt_utc']:null,'lastPageCounts'=>$r?StrictJson::decode($r['last_page_counts_json'],true,8):array(),'status'=>$r?$r['status']:'NOT_REQUESTED','completeHistoryVerified'=>false,'upstreamRetentionVerified'=>false);
    }
    public static function dispatchBackfill($db,$tenant,$interval){
        if(!is_int($interval)||$interval<60||$interval>86400)throw new ApiException(503,'suite_interval_invalid');
        if(!$db->begin_transaction())throw new ApiException(503,'suite_storage_failed');try{
            $r=self::row($db,$tenant,true);if((int)$r['generation']<1)throw new ApiException(503,'publication_unavailable');if($r['in_flight']||strpos($r['status'],'FAULT_')===0)throw new ApiException(503,'suite_manual_review_required');
            if($r['last_attempt_utc']!==null){$age=time()-strtotime($r['last_attempt_utc'].' UTC');if($age<0||$age<=$interval)throw new ApiException(503,'suite_cadence_wait');}
            $initial=json_encode(array('positions'=>array('0'=>'0'),'canbushistory'=>'0','telemetry'=>array('0'=>'0'),'wiredtelemetry'=>array('0'=>'0')));
            $q=self::execute($db,"INSERT INTO api_suite_backfill_state(tenant_key,cursors_json,last_page_counts_json,status) VALUES(?,?,'{}','WAITING_FIRST_PAGE') ON DUPLICATE KEY UPDATE tenant_key=VALUES(tenant_key)",array($tenant,$initial));$q->close();self::backfillRow($db,$tenant,true);
            $q=self::execute($db,"UPDATE api_suite_backfill_state SET last_attempt_utc=UTC_TIMESTAMP(),status='COLLECTING' WHERE tenant_key=?",array($tenant));$q->close();
            // Both live and historical reads reserve the SAME persisted clock.
            $q=self::execute($db,"UPDATE api_suite_state SET in_flight=1,status='BACKFILL_COLLECTING',last_attempt_utc=UTC_TIMESTAMP() WHERE tenant_key=?",array($tenant));$q->close();if(!$db->commit())throw new ApiException(503,'suite_storage_failed');return self::backfillState($db,$tenant);
        }catch(\Exception $e){$db->rollback();throw $e;}
    }
    public static function acceptBackfill($db,$tenant,array $capture,array $capacityPolicy=null){
        if($capacityPolicy!==null)$capacityPolicy=SuiteCapacityPolicy::normalize($capacityPolicy);
        if(!isset($capture['purpose'])||$capture['purpose']!=='HISTORY_BACKFILL_SINGLE_PAGE'||!array_key_exists('requestedSkipBeforeUtc',$capture)||$capture['requestedSkipBeforeUtc']!==null)throw new ApiException(503,'suite_backfill_purpose_required');
        if(!isset($capture['result'],$capture['tenant'],$capture['bundle'],$capture['cursors'],$capture['transportAttempts'],$capture['serverWrites'],$capture['nativeSha256'])||$capture['result']!=='PASS'||$capture['tenant']!==$tenant||!isset($capture['bundle']['tenant'])||$capture['bundle']['tenant']!==$tenant||$capture['transportAttempts']!==1||$capture['serverWrites']!==0||!is_string($capture['nativeSha256'])||!preg_match('/^[a-f0-9]{64}$/D',$capture['nativeSha256'])||!is_array($capture['cursors']))throw new ApiException(503,'suite_capture_invalid');
        $page=$capture['bundle'];PublishedVehiclesRepository::checkTime($page['capturedUtc'],3600);if(!$db->begin_transaction())throw new ApiException(503,'suite_storage_failed');try{
            $live=self::row($db,$tenant,true);$backfill=self::backfillRow($db,$tenant,true);if(!$live['in_flight']||$live['status']!=='BACKFILL_COLLECTING'||!$backfill||$backfill['status']!=='COLLECTING')throw new ApiException(503,'suite_backfill_dispatch_required');
            self::cursors($capture['cursors'],StrictJson::decode($backfill['cursors_json'],true,16));if(!hash_equals($live['scope_sha256'],hash('sha256',$live['scope_json'])))throw new ApiException(503,'suite_integrity_invalid');$scope=PublishedVehiclesRepository::scope(StrictJson::decode($live['scope_json'],true,8));$allowed=array_fill_keys($scope,true);
            $mappedPageEvents=0;$counts=array();$skipped=array();foreach(array('positions'=>'positions','canbus'=>'canbushistory','telemetry'=>'telemetry') as $endpoint=>$part){
                if(!isset($page['feeds'])||!array_key_exists($part,$page['feeds']))throw new ApiException(503,'suite_backfill_feed_absent');$rows=EaiSuiteMapping::history($page,$endpoint);$counts[$endpoint]=0;$skipped[$endpoint]=0;
                $mappedPageEvents+=count($rows);SuiteCapacityPolicy::enforcePage($capacityPolicy,$mappedPageEvents);
                foreach($rows as $row){if(!isset($allowed[(string)$row['CWVehicleID']])){$skipped[$endpoint]++;continue;}$time=Contract::eventTime($endpoint,$row['MeasuredTime']);$q=self::execute($db,'SELECT vehicle_id,measured_at FROM api_suite_history WHERE tenant_key=? AND endpoint=? AND record_id=? FOR UPDATE',array($tenant,$endpoint,$row['ID']));$old=$q->get_result()->fetch_assoc();$q->close();if($old&&((string)$old['vehicle_id']!==(string)$row['CWVehicleID']||$old['measured_at']!==$time))throw new ApiException(503,'suite_history_identity_changed');
                    $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$q=self::execute($db,'INSERT INTO api_suite_history(tenant_key,endpoint,record_id,vehicle_id,measured_at,payload_json,payload_sha256) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),payload_sha256=VALUES(payload_sha256)',array($tenant,$endpoint,$row['ID'],(string)$row['CWVehicleID'],$time,$json,hash('sha256',$json)));$q->close();$counts[$endpoint]++;
                }
            }
            list($historyCount,$historyBytes)=self::capacityUsage($db,$tenant);$capacity=SuiteCapacityPolicy::enforce($capacityPolicy,$historyCount,$historyBytes);
            $q=self::execute($db,"UPDATE api_suite_backfill_state SET cursors_json=?,pages_accepted=pages_accepted+1,first_capture_utc=COALESCE(first_capture_utc,?),last_capture_utc=?,last_page_counts_json=?,complete_history_verified=0,status='PAGE_ACCEPTED_NOT_COMPLETE' WHERE tenant_key=?",array(json_encode($capture['cursors']),$page['capturedUtc'],$page['capturedUtc'],json_encode($counts),$tenant));$q->close();
            // Backfill must not pretend the live feeds were refreshed or replace
            // the current snapshots, fleet authorization, bundle or live cursors.
            $q=self::execute($db,"UPDATE api_suite_state SET in_flight=0,status='PUBLISHED' WHERE tenant_key=?",array($tenant));$q->close();if(!$db->commit())throw new ApiException(503,'suite_storage_failed');
            return array('result'=>'PASS','historyPageRows'=>$counts,'rowsOutsideCurrentLiveScope'=>$skipped,'pagesAccepted'=>(int)$backfill['pages_accepted']+1,'liveGenerationPreserved'=>(int)$live['generation'],'liveSnapshotPreserved'=>true,'liveCursorsPreserved'=>true,'sharedMinuteCadencePreserved'=>true,'completeHistoryVerified'=>false,'upstreamRetentionVerified'=>false,'serverWrites'=>0,'filesDeleted'=>0,'capacity'=>$capacity);
        }catch(\Exception $e){$db->rollback();throw $e;}
    }
    public static function dispatch($db,$tenant,$interval){
        if(!is_int($interval)||$interval<60||$interval>86400)throw new ApiException(503,'suite_interval_invalid');
        if(!$db->begin_transaction())throw new ApiException(503,'suite_storage_failed');try{$r=self::row($db,$tenant,true);if($r['in_flight']||strpos($r['status'],'FAULT_')===0)throw new ApiException(503,'suite_manual_review_required');
            // DATETIME has whole-second precision in existing installations.
            // One rounding guard prevents two physical sends <60s apart.
            if($r['last_attempt_utc']!==null){$age=time()-strtotime($r['last_attempt_utc'].' UTC');if($age<0||$age<=$interval)throw new ApiException(503,'suite_cadence_wait');}
            $s=self::execute($db,"UPDATE api_suite_state SET in_flight=1,status='COLLECTING',last_attempt_utc=UTC_TIMESTAMP() WHERE tenant_key=?",array($tenant));$s->close();if(!$db->commit())throw new ApiException(503,'suite_storage_failed');return self::state($db,$tenant);
        }catch(\Exception $e){$db->rollback();throw $e;}
    }
    public static function cursors(array $next,array $previous){
        foreach($next as $part=>$value){if(!in_array($part,array('positions','canbushistory','telemetry','wiredtelemetry'),true))throw new ApiException(503,'suite_cursor_invalid');$values=is_array($value)?$value:array('scalar'=>$value);if(count($values)>100)throw new ApiException(503,'suite_cursor_invalid');
            foreach($values as $key=>$id){if(is_array($value)&&!preg_match('/^(?:0|[1-9][0-9]{0,7})$/D',(string)$key))throw new ApiException(503,'suite_cursor_invalid');if(!is_string($id)||!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$id))throw new ApiException(503,'suite_cursor_invalid');
                $old=isset($previous[$part])?(is_array($previous[$part])?(isset($previous[$part][$key])?$previous[$part][$key]:null):$previous[$part]):null;
                if($old!==null&&(strlen($id)<strlen($old)||(strlen($id)===strlen($old)&&strcmp($id,$old)<0)))throw new ApiException(503,'suite_cursor_regressed');
            }
        }
        foreach($previous as $part=>$old){if(!array_key_exists($part,$next)||(is_array($old)&&is_array($next[$part])&&array_diff_key($old,$next[$part])))throw new ApiException(503,'suite_cursor_lost');}
    }
    public static function recover($db,$tenant,array $review){
        if(!isset($review['expectedGeneration'],$review['expectedStatus'],$review['expectedLastAttemptUtc'],$review['reason'])||count($review)!==4||!is_int($review['expectedGeneration'])||$review['reason']!=='MANUAL_REVIEW_AFTER_LOCAL_FAILURE')throw new ApiException(503,'suite_recovery_review_invalid');
        Contract::eventTime('vehicles',$review['expectedLastAttemptUtc']);if(!$db->begin_transaction())throw new ApiException(503,'suite_storage_failed');try{$r=self::row($db,$tenant,true);
            if((int)$r['generation']!==$review['expectedGeneration']||$r['status']!==$review['expectedStatus']||$r['last_attempt_utc']!==$review['expectedLastAttemptUtc'])throw new ApiException(503,'suite_recovery_state_changed');
            if(!$r['in_flight']&&strpos($r['status'],'FAULT_')!==0)throw new ApiException(503,'suite_recovery_not_required');$age=time()-strtotime($r['last_attempt_utc'].' UTC');if($age<60)throw new ApiException(503,'suite_recovery_attempt_recent');
            $q=self::execute($db,"UPDATE api_suite_state SET in_flight=0,status='RECOVERY_REVIEWED' WHERE tenant_key=?",array($tenant));$q->close();if(!$db->commit())throw new ApiException(503,'suite_storage_failed');return array('result'=>'PASS','generation'=>(int)$r['generation'],'cursorsPreserved'=>true,'publicationPreserved'=>true,'externalCalls'=>0);
        }catch(\Exception $e){$db->rollback();throw $e;}
    }
    public static function accept($db,$tenant,array $capture,array $profile,array $capacityPolicy=null,array $localReplay=null){
        if($capacityPolicy!==null)$capacityPolicy=SuiteCapacityPolicy::normalize($capacityPolicy);
        if(isset($capture['purpose'])&&$capture['purpose']!=='INCREMENTAL')throw new ApiException(503,'suite_incremental_purpose_required');
        if(!isset($capture['result'],$capture['tenant'],$capture['bundle'],$capture['cursors'],$capture['transportAttempts'],$capture['serverWrites'],$capture['nativeSha256'])||$capture['result']!=='PASS'||$capture['tenant']!==$tenant||$capture['bundle']['tenant']!==$tenant||$capture['transportAttempts']!==1||$capture['serverWrites']!==0||!is_string($capture['nativeSha256'])||!preg_match('/^[a-f0-9]{64}$/D',$capture['nativeSha256'])||!is_array($capture['cursors']))throw new ApiException(503,'suite_capture_invalid');
        $page=$capture['bundle'];
        if($localReplay===null){PublishedVehiclesRepository::checkTime($page['capturedUtc'],3600);}
        else{
            if(count($localReplay)!==4||!isset($localReplay['expectedGeneration'],$localReplay['expectedStatus'],$localReplay['expectedLastAttemptUtc'],$localReplay['reason'])||!is_int($localReplay['expectedGeneration'])||$localReplay['reason']!=='LOCAL_PENDING_CAPTURE_REPLAY')throw new ApiException(503,'suite_local_replay_review_invalid');
            Contract::eventTime('vehicles',$localReplay['expectedLastAttemptUtc']);
            // Reprocessing an old received page never changes its real time.
            // It may remain STALE; future timestamps are still forbidden.
            SnapshotReadPolicy::evaluate($page['capturedUtc'],300,'last-known-with-warning');
        }
        if(!$db->begin_transaction())throw new ApiException(503,'suite_storage_failed');try{
            $old=self::row($db,$tenant,true);if(!$old['in_flight'])throw new ApiException(503,'suite_dispatch_required');$previousCursors=StrictJson::decode($old['cursors_json'],true,16);$cursorMetadataReviewApplied=false;
            if($localReplay!==null){
                if((int)$old['generation']!==$localReplay['expectedGeneration']||$old['status']!==$localReplay['expectedStatus']||$old['last_attempt_utc']!==$localReplay['expectedLastAttemptUtc'])throw new ApiException(503,'suite_local_replay_state_changed');
                if($old['status']!=='COLLECTING'&&strpos($old['status'],'FAULT_')!==0)throw new ApiException(503,'suite_local_replay_not_required');
                if(strcmp($page['capturedUtc'],$old['last_attempt_utc'])<0||($old['captured_utc']!==null&&strcmp($page['capturedUtc'],$old['captured_utc'])<=0))throw new ApiException(503,'suite_local_replay_wrong_attempt');
                // Only explicit replay of this exact cursor fault may apply
                // official SDK high-water semantics to the received metadata.
                // Raw events, capture time and every existing cursor survive.
                if($old['status']==='FAULT_SUITE_CURSOR_REGRESSED'){$receivedCursors=$capture['cursors'];$capture['cursors']=EaiCursorState::reviewedPage($receivedCursors,$previousCursors);$cursorMetadataReviewApplied=$receivedCursors!==$capture['cursors'];}
            }
            self::cursors($capture['cursors'],$previousCursors);
            if($old['captured_utc']!==null&&strcmp($old['captured_utc'],$page['capturedUtc'])>0)throw new ApiException(503,'suite_capture_out_of_order');
            if($old['bundle_json']!==null&&!hash_equals($old['bundle_sha256'],hash('sha256',$old['bundle_json'])))throw new ApiException(503,'suite_integrity_invalid');
            $previous=$old['bundle_json']===null?null:StrictJson::decode(CompactStateJson::decode($old['bundle_json']),true,64);$current=EaiVehicleState::merge($page,$previous);
            $document=EaiSuiteMapping::compile($current,$profile,$capture['nativeSha256']);if(!isset($document['endpoints']['vehicles']))throw new ApiException(503,'suite_vehicle_mapping_incomplete');
            // Compile append-only history from the RAW page, never from the
            // last-per-physical-source snapshot used for /vehicles sensors.
            $mappedPageEvents=0;$newRows=array();$present=array();foreach(array('positions'=>'positions','canbus'=>'canbushistory','telemetry'=>'telemetry') as $endpoint=>$part){
                if(!array_key_exists($part,$page['feeds']))continue;$newRows[$endpoint]=EaiSuiteMapping::history($page,$endpoint);$present[]=$endpoint;
                $mappedPageEvents+=count($newRows[$endpoint]);SuiteCapacityPolicy::enforcePage($capacityPolicy,$mappedPageEvents);
                foreach($newRows[$endpoint] as $row){$time=Contract::eventTime($endpoint,$row['MeasuredTime']);$q=self::execute($db,'SELECT vehicle_id,measured_at FROM api_suite_history WHERE tenant_key=? AND endpoint=? AND record_id=? FOR UPDATE',array($tenant,$endpoint,$row['ID']));$prior=$q->get_result()->fetch_assoc();$q->close();
                    if($prior&&((string)$prior['vehicle_id']!==(string)$row['CWVehicleID']||$prior['measured_at']!==$time))throw new ApiException(503,'suite_history_identity_changed');
                    $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$q=self::execute($db,'INSERT INTO api_suite_history(tenant_key,endpoint,record_id,vehicle_id,measured_at,payload_json,payload_sha256) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),payload_sha256=VALUES(payload_sha256)',array($tenant,$endpoint,$row['ID'],(string)$row['CWVehicleID'],$time,$json,hash('sha256',$json)));$q->close();
                }
                $q=self::execute($db,'INSERT INTO api_suite_feeds(tenant_key,endpoint,first_present_utc,last_present_utc) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_present_utc=VALUES(last_present_utc)',array($tenant,$endpoint,$page['capturedUtc'],$page['capturedUtc']));$q->close();
            }
            foreach(array('vehicles','vehiclesBase') as $endpoint){$json=json_encode($document['endpoints'][$endpoint],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$q=self::execute($db,'INSERT INTO api_suite_snapshots(tenant_key,endpoint,payload_json,payload_sha256) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),payload_sha256=VALUES(payload_sha256)',array($tenant,$endpoint,$json,hash('sha256',$json)));$q->close();}
            // An explicit deployment approval may replace the legacy recette
            // guard. Neither path deletes history or activates a retention.
            list($historyCount,$historyBytes)=self::capacityUsage($db,$tenant);$capacity=SuiteCapacityPolicy::enforce($capacityPolicy,$historyCount,$historyBytes);
            $json=json_encode($current,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false||strlen($json)>16777216)throw new ApiException(503,'suite_snapshot_bound');$json=CompactStateJson::encode($json);
            $scopeJson=json_encode($document['vehicleIds']);$q=self::execute($db,"UPDATE api_suite_state SET generation=generation+1,captured_utc=?,bundle_json=?,bundle_sha256=?,scope_json=?,scope_sha256=?,cursors_json=?,in_flight=0,status='PUBLISHED' WHERE tenant_key=?",array($page['capturedUtc'],$json,hash('sha256',$json),$scopeJson,hash('sha256',$scopeJson),json_encode($capture['cursors']),$tenant));$q->close();if(!$db->commit())throw new ApiException(503,'suite_storage_failed');
            $counts=array();foreach($newRows as $endpoint=>$rows)$counts[$endpoint]=count($rows);return array('result'=>'PASS','generation'=>(int)$old['generation']+1,'vehicleRows'=>count($document['vehicleIds']),'capturedUtc'=>$page['capturedUtc'],'historyPageRows'=>$counts,'presentFeeds'=>$present,'serverWrites'=>0,'completeHistoryVerified'=>false,'productionReady'=>false,'capacity'=>$capacity,'cursorMetadataReviewApplied'=>$cursorMetadataReviewApplied);
        }catch(\Exception $e){$db->rollback();throw $e;}
    }
}
