<?php
namespace FleetApi;
require_once __DIR__.'/EaiSuiteMapping.php';

// Explicit archived-recette profile. Immutable, hash-pinned publication per
// tenant. Never activates live source freshness or complete-history claims.
class EaiSuiteRepository implements PublishedVehicleScopeRepository
{
    private $config;private $scope=null;private $tenant=null;
    public function __construct(array $config){$this->config=$config;}
    public function supportsVehicleAccountScope($tenant){return isset($this->config['tenants'][$tenant]);}
    public function verifiedVehicleScope($tenant){if($tenant!==$this->tenant||$this->scope===null){throw new ApiException(503,'publication_scope_invalid');}return $this->scope;}
    public function read($tenant,$endpoint,$vehicle,$from,$to,$limit){
        $this->scope=null;$this->tenant=null;if(!isset($this->config['tenants'][$tenant])){throw new ApiException(503,'suite_tenant_unavailable');}
        $c=$this->config['tenants'][$tenant];$stream=@fopen($c['documentPath'],'rb');if(!$stream){throw new ApiException(503,'source_unavailable');}
        $text=stream_get_contents($stream,33554433);fclose($stream);
        if(!is_string($text)||strlen($text)>33554432||!hash_equals($c['documentSha256'],hash('sha256',$text))){throw new ApiException(503,'suite_integrity_invalid');}
        $document=StrictJson::decode($text,true,64);return $this->readDocument($tenant,$endpoint,$vehicle,$from,$to,$limit,$document);
    }
    public function readDocument($tenant,$endpoint,$vehicle,$from,$to,$limit,array $d){
        $this->scope=null;$this->tenant=null;
        if(!isset($d['profile'],$d['tenant'],$d['scopeMode'],$d['vehicleIds'],$d['endpoints'],$d['sourceSha256'],$d['sourceCapturedUtc'])||$d['profile']!==EaiSuiteMapping::PROFILE||$d['tenant']!==$tenant||$d['scopeMode']!=='eai-account'||!is_string($d['sourceSha256'])||!preg_match('/^[a-f0-9]{64}$/D',$d['sourceSha256'])){throw new ApiException(503,'suite_document_invalid');}
        Contract::eventTime('vehicles',$d['sourceCapturedUtc']);$ids=PublishedVehiclesRepository::scope($d['vehicleIds']);$scope=array_fill_keys($ids,true);
        if(!array_key_exists($endpoint,$d['endpoints'])){throw new ApiException(503,'suite_feed_unavailable');}
        if(Contract::history($endpoint)&&(!Contract::vehicleId($vehicle)||!isset($scope[$vehicle]))){throw new ApiException(403,'forbidden');}
        $rows=Contract::rows($endpoint,$d['endpoints'][$endpoint]);$actual=array();foreach($rows as $row){$id=(string)$row['CWVehicleID'];if(!isset($scope[$id])){throw new ApiException(503,'suite_scope_invalid');}$actual[$id]=true;}
        if(!Contract::history($endpoint)&&count($actual)!==count($scope)){throw new ApiException(503,'suite_scope_invalid');}
        $repository=new ArrayRepository(array($tenant=>array($endpoint=>$rows)));$selected=$repository->read($tenant,$endpoint,$vehicle,$from,$to,$limit);
        $this->scope=$ids;$this->tenant=$tenant;return $selected;
    }
}
