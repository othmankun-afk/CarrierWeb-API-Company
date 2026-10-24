<?php
namespace FleetApi;
require_once __DIR__.'/EaiVehicleMapping.php';

// The serving connection has SELECT only. Publication happens in a separate
// transaction under a different SQL account, after all 44 fields validate.
class PublishedVehiclesRepository implements PublishedVehicleScopeRepository
{
    private $db;
    private $tenant;
    private $maxAge;
    private $verifiedScope=null;
    private $verifiedMode=null;
    public function __construct($db,$tenant,$maxAge){$this->db=$db;$this->tenant=$tenant;$this->maxAge=$maxAge;}
    public function supportsVehicleAccountScope($tenant){return $tenant===$this->tenant;}
    public function verifiedVehicleScope($tenant){if($tenant!==$this->tenant||$this->verifiedScope===null||$this->verifiedMode!=='eai-account'){throw new ApiException(503,'publication_scope_invalid');}return $this->verifiedScope;}
    public static function scope(array $ids){
        if(!$ids||count($ids)>10000){throw new ApiException(503,'publication_scope_invalid');}
        $seen=array();foreach($ids as $id){if(!Contract::vehicleId($id)||isset($seen['id:'.$id])){throw new ApiException(503,'publication_scope_invalid');}$seen['id:'.$id]=true;}
        sort($ids,SORT_STRING);return $ids;
    }
    public static function digest($value){$json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false){throw new ApiException(503,'source_invalid');}return hash('sha256',$json);}
    public static function checkTime($captured,$maxAge){
        if(!is_int($maxAge)||$maxAge<1||$maxAge>86400){throw new ApiException(503,'configuration_invalid');}
        $captured=Contract::eventTime('vehicles',$captured);$now=gmdate('Y-m-d H:i:s');
        if(strcmp($captured,$now)>0){throw new ApiException(503,'source_from_future');}
        $a=new \DateTime($captured,new \DateTimeZone('UTC'));$b=new \DateTime($now,new \DateTimeZone('UTC'));$d=$a->diff($b);
        if($d->days*86400+$d->h*3600+$d->i*60+$d->s>$maxAge){throw new ApiException(503,'source_stale');}
    }
    public function read($tenant,$endpoint,$vehicle,$from,$to,$limit){
        $this->verifiedScope=null;$this->verifiedMode=null;
        if($tenant!==$this->tenant||$endpoint!=='vehicles'){throw new ApiException(503,'source_unavailable');}
        $this->db->requireInnoDb('api_vehicle_publications');
        $stmt=$this->db->prepare('SELECT captured_utc,payload_json,payload_sha256,scope_json,scope_sha256,scope_mode,row_count,source_sha256,rules_sha256 FROM api_vehicle_publications WHERE tenant_key = ?');
        $stmt->execute(array($tenant));$record=$stmt->fetch();if(!$record){throw new ApiException(503,'publication_unavailable');}
        self::checkTime($record['captured_utc'],$this->maxAge);
        if(!in_array($record['scope_mode'],array('explicit-list','eai-account'),true)){throw new ApiException(503,'publication_scope_invalid');}
        if(strlen($record['payload_json'])>16777216||strlen($record['scope_json'])>262144||!hash_equals($record['payload_sha256'],hash('sha256',$record['payload_json']))){throw new ApiException(503,'publication_integrity_invalid');}
        try{$scope=StrictJson::decode($record['scope_json'],true,8);}catch(JsonDecodeException $e){throw new ApiException(503,'publication_integrity_invalid');}
        if(!is_array($scope)||self::digest(self::scope($scope))!==$record['scope_sha256']){throw new ApiException(503,'publication_integrity_invalid');}
        foreach(array('source_sha256','rules_sha256') as $field){if(!preg_match('/^[a-f0-9]{64}$/D',$record[$field])){throw new ApiException(503,'publication_integrity_invalid');}}
        $rows=Contract::rows('vehicles',Contract::decodeArray($record['payload_json']));
        $actual=array();foreach($rows as $row){$actual[]=(string)$row['CWVehicleID'];}
        if(self::scope($actual)!==self::scope($scope)||count($rows)!==(int)$record['row_count']){throw new ApiException(503,'publication_scope_invalid');}
        if(count($rows)>$limit){throw new ApiException(413,'result_too_large');}$this->verifiedScope=self::scope($scope);$this->verifiedMode=$record['scope_mode'];return $rows;
    }
}
