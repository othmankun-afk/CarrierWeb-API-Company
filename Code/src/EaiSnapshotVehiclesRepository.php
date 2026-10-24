<?php
namespace FleetApi;
require_once __DIR__.'/EaiVehicleMapping.php';

// Explicitly experimental LOCAL reader. A complete candidate is not proof of
// Carrierweb equivalence. No credentials, SQL writes, network calls or defaults.
class EaiSnapshotVehiclesRepository implements Repository
{
    private $config;
    public function __construct(array $config)
    {
        self::validate($config); $this->config=$config;
    }

    public static function validate(array $config)
    {
        if (getenv('FLEET_API_LOCAL_TEST')!=='1' || !isset($config['profile'],$config['tenants'],$config['maxFileBytes']) ||
            $config['profile']!=='candidate-vehicles-local-v1' || !is_array($config['tenants']) ||
            !is_int($config['maxFileBytes']) || $config['maxFileBytes']<1 || $config['maxFileBytes']>67108864) { throw new ApiException(503,'configuration_invalid'); }
        foreach($config['tenants'] as $tenant=>$source) {
            if(!is_string($tenant)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$tenant)||!is_array($source)||
                !isset($source['path'],$source['sha256'],$source['authorizedVehicleIds'],$source['maxSnapshotAgeSeconds'])||
                !is_string($source['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$source['sha256'])||
                !is_array($source['authorizedVehicleIds'])||count($source['authorizedVehicleIds'])<1||count($source['authorizedVehicleIds'])>10000||
                !is_int($source['maxSnapshotAgeSeconds'])||$source['maxSnapshotAgeSeconds']<1||$source['maxSnapshotAgeSeconds']>86400) { throw new ApiException(503,'configuration_invalid'); }
            Bootstrap::localPath($source['path']);
            $ids=array();foreach($source['authorizedVehicleIds'] as $id){if(!Contract::vehicleId($id)||isset($ids['id:'.$id])){throw new ApiException(503,'configuration_invalid');}$ids['id:'.$id]=true;}
        }
    }

    public function read($tenant,$endpoint,$vehicle,$from,$to,$limit)
    {
        if($endpoint!=='vehicles'||!isset($this->config['tenants'][$tenant])){throw new ApiException(503,'source_unavailable');}
        $source=$this->config['tenants'][$tenant];
        // Check the resolved path again in case a local file was replaced.
        $path=Bootstrap::localPath($source['path']);$stream=@fopen($path,'rb');
        if(!$stream){throw new ApiException(503,'source_unavailable');}
        $text=stream_get_contents($stream,$this->config['maxFileBytes']+1);fclose($stream);
        if(!is_string($text)||strlen($text)>$this->config['maxFileBytes']||!hash_equals($source['sha256'],hash('sha256',$text))){throw new ApiException(503,'source_integrity_invalid');}
        try{$doc=StrictJson::decode($text,true,64);}catch(JsonDecodeException $e){throw new ApiException(503,'source_invalid');}
        if(!is_array($doc)||!isset($doc['profile'],$doc['bundle'],$doc['rules'],$doc['sourceKind'])||
            $doc['profile']!=='candidate-vehicles-local-v1'||!is_array($doc['bundle'])||!is_array($doc['rules'])||
            !in_array($doc['sourceKind'],array('FICTITIOUS_TEST','CUSTOMER_CANDIDATE_UNVERIFIED'),true)||
            !isset($doc['bundle']['tenant'],$doc['bundle']['timeBasis'],$doc['bundle']['capturedUtc'])||
            $doc['bundle']['tenant']!==$tenant||$doc['bundle']['timeBasis']!=='UTC'){throw new ApiException(503,'source_invalid');}
        $capture=Contract::eventTime('vehicles',$doc['bundle']['capturedUtc']);$now=gmdate('Y-m-d H:i:s');
        if(strcmp($capture,$now)>0){throw new ApiException(503,'source_from_future');}
        $a=new \DateTime($capture,new \DateTimeZone('UTC'));$b=new \DateTime($now,new \DateTimeZone('UTC'));
        $delta=$a->diff($b);$age=$delta->days*86400+$delta->h*3600+$delta->i*60+$delta->s;
        if($age>$source['maxSnapshotAgeSeconds']){throw new ApiException(503,'source_stale');}
        $result=EaiVehicleMapping::evaluate($doc['bundle'],$doc['rules'],$source['authorizedVehicleIds']);
        if(!$result['candidateComplete']){throw new ApiException(503,'mapping_incomplete');}
        if(count($result['rows'])>$limit){throw new ApiException(413,'result_too_large');}
        return $result['rows'];
    }
}
