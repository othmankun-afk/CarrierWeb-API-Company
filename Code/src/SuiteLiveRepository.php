<?php
namespace FleetApi;
require_once __DIR__.'/SuiteServiceStore.php';
require_once __DIR__.'/SuiteHistoryPolicy.php';
require_once __DIR__.'/SnapshotReadPolicy.php';
class SuiteLiveRepository implements PublishedVehicleScopeRepository
{
    private $db;private $tenant;private $maxAge;private $scope=null;private $policy;private $metadata=null;private $capacityPolicy;private $readPolicy;private $publicationMetadata=null;
    public function __construct($db,$tenant,$maxAge,array $historyPolicy=null,array $capacityPolicy=null,$readPolicy='fail-closed'){$this->db=$db;$this->tenant=$tenant;$this->maxAge=$maxAge;$this->policy=$historyPolicy===null?null:SuiteHistoryPolicy::normalize($historyPolicy);$this->capacityPolicy=$capacityPolicy===null?null:SuiteCapacityPolicy::normalize($capacityPolicy);$this->readPolicy=SnapshotReadPolicy::normalize($readPolicy);}
    public function publicationMetadata(){return $this->publicationMetadata;}
    public function supportsVehicleAccountScope($tenant){return $tenant===$this->tenant;}
    public function verifiedVehicleScope($tenant){if($tenant!==$this->tenant||$this->scope===null)throw new ApiException(503,'publication_scope_invalid');return $this->scope;}
    public function historyMetadata(){return $this->metadata;}
    private function publication($tenant,$requireFresh){
        $this->scope=null;$this->metadata=null;$this->publicationMetadata=null;if($tenant!==$this->tenant)throw new ApiException(503,'suite_tenant_invalid');foreach(array('api_suite_state','api_suite_snapshots','api_suite_feeds','api_suite_history') as $table)$this->db->requireInnoDb($table);
        $q=$this->db->prepare('SELECT captured_utc,scope_json,scope_sha256,generation,status,in_flight,last_attempt_utc FROM api_suite_state WHERE tenant_key=?');$q->execute(array($tenant));$state=$q->fetch();if(!$state||!$state['captured_utc']||(int)$state['generation']<1)throw new ApiException(503,'publication_unavailable');if(!hash_equals($state['scope_sha256'],hash('sha256',$state['scope_json'])))throw new ApiException(503,'suite_integrity_invalid');
        $scope=PublishedVehiclesRepository::scope(StrictJson::decode($state['scope_json'],true,8));
        if($requireFresh){$this->publicationMetadata=SnapshotReadPolicy::evaluate($state['captured_utc'],$this->maxAge,$this->readPolicy);$pendingAge=isset($state['last_attempt_utc'])?time()-strtotime($state['last_attempt_utc'].' UTC'):PHP_INT_MAX;$this->publicationMetadata['collectionState']=(strpos($state['status'],'FAULT_')===0||($state['in_flight']&&$pendingAge>60))?'REVIEW-REQUIRED':($state['in_flight']?'IN-PROGRESS':$state['status']);}
        return array($state,$scope);
    }
    private function historyInfo($tenant,$endpoint,$vehicle,$from,$to,array $state){
        $q=$this->db->prepare('SELECT first_present_utc,last_present_utc FROM api_suite_feeds WHERE tenant_key=? AND endpoint=?');$q->execute(array($tenant,$endpoint));$feed=$q->fetch();
        $q=$this->db->prepare('SELECT COUNT(*) AS n,MIN(measured_at) AS earliest,MAX(measured_at) AS latest FROM api_suite_history WHERE tenant_key=? AND endpoint=? AND vehicle_id=?');$q->execute(array($tenant,$endpoint,$vehicle));$stored=$q->fetch();
        $q=$this->db->prepare('SELECT COUNT(*) AS n,COALESCE(SUM(OCTET_LENGTH(payload_json)),0) AS payload_bytes FROM api_suite_history WHERE tenant_key=?');$q->execute(array($tenant));$capacity=$q->fetch();if(!$capacity||!preg_match('/^[0-9]+$/D',(string)$capacity['n'])||!preg_match('/^[0-9]+$/D',(string)$capacity['payload_bytes']))throw new ApiException(503,'suite_capacity_usage_invalid');$capacityCount=(float)$capacity['n']<=PHP_INT_MAX?(int)$capacity['n']:(float)$capacity['n'];$capacityBytes=(float)$capacity['payload_bytes']<=PHP_INT_MAX?(int)$capacity['payload_bytes']:(float)$capacity['payload_bytes'];
        $fresh=false;if($feed){try{PublishedVehiclesRepository::checkTime($feed['last_present_utc'],$this->maxAge);$fresh=true;}catch(ApiException $e){}}
        return array('endpoint'=>$endpoint,'vehicleId'=>$vehicle,'timeBasis'=>'UTC','mode'=>'COLLECTED_PAGES_ONLY','generation'=>(int)$state['generation'],'lastPublicationUtc'=>$state['captured_utc'],'completeHistoryVerified'=>false,'continuousCoverageVerified'=>false,'upstreamAvailabilityVerified'=>false,'upstreamOldestAvailableUtc'=>null,'feedPresent'=>$feed?true:false,'feedFresh'=>$fresh,'feedFirstObservedUtc'=>$feed?$feed['first_present_utc']:null,'feedLastObservedUtc'=>$feed?$feed['last_present_utc']:null,'storedEventCount'=>(int)$stored['n'],'earliestStoredMeasuredUtc'=>$stored['earliest'],'latestStoredMeasuredUtc'=>$stored['latest'],'boundsMeaning'=>'OBSERVED_EVENT_EXTREMES_NOT_A_CONTINUOUS_COVERAGE_INTERVAL','emptyResultMeaning'=>'NO_STORED_MATCH_NOT_PROOF_OF_SOURCE_ABSENCE','automaticEaiBackfill'=>false,'gapTrackingAvailable'=>false,'policy'=>SuiteHistoryPolicy::describe($this->policy),'requestedWindow'=>SuiteHistoryPolicy::window($this->policy,$from,$to),'capacity'=>SuiteCapacityPolicy::describe($this->capacityPolicy,$capacityCount,$capacityBytes));
    }
    public function historyDiagnostic($tenant,$endpoint,$vehicle,$from=null,$to=null){
        if(!Contract::history($endpoint))throw new ApiException(400,'invalid_endpoint');list($state,$scope)=$this->publication($tenant,false);if(!Contract::vehicleId($vehicle)||!in_array($vehicle,$scope,true))throw new ApiException(403,'forbidden');$this->metadata=$this->historyInfo($tenant,$endpoint,$vehicle,$from,$to,$state);$this->scope=$scope;return $this->metadata;
    }
    public function read($tenant,$endpoint,$vehicle,$from,$to,$limit){
        list($state,$scope)=$this->publication($tenant,true);$allowed=array_fill_keys($scope,true);$rows=array();
        if(Contract::history($endpoint)){
            if(!Contract::vehicleId($vehicle)||!isset($allowed[$vehicle]))throw new ApiException(403,'forbidden');
            $this->metadata=$this->historyInfo($tenant,$endpoint,$vehicle,$from,$to,$state);SuiteHistoryPolicy::enforce($this->policy,$from,$to);if(!$this->metadata['feedPresent'])throw new ApiException(503,'suite_feed_unavailable');SnapshotReadPolicy::evaluate($this->metadata['feedLastObservedUtc'],$this->maxAge,$this->readPolicy);
            $q=$this->db->prepare('SELECT record_id,vehicle_id,measured_at,payload_json,payload_sha256 FROM api_suite_history WHERE tenant_key=? AND endpoint=? AND vehicle_id=? AND measured_at>=? AND measured_at<=? ORDER BY measured_at,record_id LIMIT '.(int)($limit+1));$q->execute(array($tenant,$endpoint,$vehicle,$from,$to));
            while($record=$q->fetch()){
                if(count($rows)>=$limit)throw new ApiException(413,'result_too_large');if(!hash_equals($record['payload_sha256'],hash('sha256',$record['payload_json'])))throw new ApiException(503,'suite_integrity_invalid');$row=Contract::row($endpoint,StrictJson::decode($record['payload_json'],true,16));
                if($record['record_id']!==$row['ID']||(string)$row['CWVehicleID']!==$vehicle||$record['measured_at']!==Contract::eventTime($endpoint,$row['MeasuredTime']))throw new ApiException(503,'suite_history_identity_changed');$rows[]=$row;
            }$this->metadata['returnedEventCount']=count($rows);
        }else{
            $q=$this->db->prepare('SELECT payload_json,payload_sha256 FROM api_suite_snapshots WHERE tenant_key=? AND endpoint=?');$q->execute(array($tenant,$endpoint));$record=$q->fetch();if(!$record||!hash_equals($record['payload_sha256'],hash('sha256',$record['payload_json'])))throw new ApiException(503,'suite_integrity_invalid');$rows=Contract::rows($endpoint,Contract::decodeArray($record['payload_json']));$actual=array();foreach($rows as $row)$actual[]=(string)$row['CWVehicleID'];if(PublishedVehiclesRepository::scope($actual)!==$scope)throw new ApiException(503,'publication_scope_invalid');if(count($rows)>$limit)throw new ApiException(413,'result_too_large');
        }
        $this->scope=$scope;return Contract::rows($endpoint,$rows);
    }
}
