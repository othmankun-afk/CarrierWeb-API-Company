<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../demo/fixtures.php';
FleetApi\Bootstrap::configureRuntime();

// Deterministic fixture clock: no sleeping and no customer/remote configuration.
class SuiteMinuteFixtureLimiter implements FleetApi\RateLimiter {
    public $clock=1000; public $keys=array(); private $last=array();
    public function acquire($key,$interval){
        $this->keys[]=$key;
        if(isset($this->last[$key])&&$this->clock-$this->last[$key]<$interval){return $interval-($this->clock-$this->last[$key]);}
        $this->last[$key]=$this->clock; return 0;
    }
}
class SuitePolicyFixtureRepository extends FleetApi\ConfiguredRepository {
    private $rows; private $metadata; public $reads=0;
    public function __construct(){parent::__construct(array());$this->rows=fleet_demo_rows();}
    public function supportsExpandedAccountScope($tenant){return true;}
    public function supportsVehicleAccountScope($tenant){return true;}
    public function clearRequestMetadata(){$this->metadata=null;}
    public function historyMetadata(){return $this->metadata;}
    public function verifiedVehicleScope($tenant){return array('7');}
    public function read($tenant,$endpoint,$vehicle,$from,$to,$limit){
        $this->reads++;
        if(FleetApi\Contract::history($endpoint)){
            if($vehicle!=='7')throw new FleetApi\ApiException(403,'forbidden');
            $this->metadata=array('completeHistoryVerified'=>false,'policy'=>array('status'=>'NOT_CONFIGURED'));
            if($from==='1800-01-01 00:00:00')throw new FleetApi\ApiException(422,'history_lookback_exceeded');
        }
        return FleetApi\Contract::history($endpoint)?$this->rows[$endpoint]:array($this->rows[$endpoint][0]);
    }
    public function historyDiagnostic($tenant,$endpoint,$vehicle,$from=null,$to=null){
        if($vehicle!=='7')throw new FleetApi\ApiException(403,'forbidden');
        return array('endpoint'=>$endpoint,'vehicleId'=>$vehicle,'completeHistoryVerified'=>false,'upstreamAvailabilityVerified'=>false,'policy'=>array('status'=>'NOT_CONFIGURED'));
    }
}
$checks=0;
function policyCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function policyUrl($account,$endpoint){return '/cweai/'.$account.'/'.$endpoint.'?password=fixture-token'.(FleetApi\Contract::history($endpoint)?'&vehicleid=7&ymdfrom=2026-09-01&ymdto=2026-09-02':'');}
try{
    $options=array('maxRows'=>100,'maxResponseBytes'=>1048576,'minIntervalSeconds'=>60,'enforceMinuteRule'=>true,'suiteIncremental'=>true);
    $accounts=array();foreach(array('alpha','beta') as $name)$accounts[$name]=array('tenant'=>$name,'tokenSha256'=>hash('sha256','fixture-token'),'vehicleIds'=>array(),'scopeMode'=>'eai-account','endpoints'=>array_keys(FleetApi\Contract::schemas()));
    foreach(array(0,59) as $bad){$o=$options;$o['minIntervalSeconds']=$bad;$rejected=false;try{new FleetApi\Api($accounts,new SuitePolicyFixtureRepository(),new SuiteMinuteFixtureLimiter(),$o);}catch(FleetApi\ApiException $e){$rejected=$e->getMessage()==='configuration_invalid';}policyCheck($rejected,'minute_configuration_fail_closed');}
    foreach(array_keys(FleetApi\Contract::schemas()) as $endpoint){
        $repo=new SuitePolicyFixtureRepository();$limiter=new SuiteMinuteFixtureLimiter();$api=new FleetApi\Api($accounts,$repo,$limiter,$options);$url=policyUrl('alpha',$endpoint);
        policyCheck($api->handle('GET',$url)['status']===200,'first_read_allowed');
        $limiter->clock=1059;$blocked=$api->handle('GET',$url);
        policyCheck($blocked['headers']['Retry-After']==='1','before_sixty_retry_after');
        policyCheck($repo->reads===1,'blocked_read_does_not_query_source');
        policyCheck($blocked['status']===($endpoint==='positions'?200:503),'existing_wire_cadence_preserved');
        policyCheck($blocked['headers']['X-Request-Min-Interval']==='60','minute_header');
        policyCheck($api->handle('GET',policyUrl('beta',$endpoint))['status']===200,'independent_tenant_budget');
        $limiter->clock=1060;policyCheck($api->handle('GET',$url)['status']===200,'exact_sixty_allowed');
    }
    $repo=new SuitePolicyFixtureRepository();$limiter=new SuiteMinuteFixtureLimiter();$api=new FleetApi\Api($accounts,$repo,$limiter,$options);
    $diagnostic='/diagnostic/alpha/history?password=fixture-token&endpoint=telemetry&vehicleid=7';
    $response=$api->handle('GET',$diagnostic);$body=json_decode($response['body'],true);
    policyCheck($response['status']===200&&!$body['completeHistoryVerified']&&!$body['upstreamAvailabilityVerified'],'diagnostic_no_completeness_claim');
    policyCheck(!isset($body['policy']['lookbackDays']),'no_arbitrary_default_lookback');
    policyCheck($limiter->keys[0]==='alpha/telemetry','diagnostic_same_rate_key');
    policyCheck($api->handle('GET',policyUrl('alpha','telemetry'))['status']===503,'diagnostic_cannot_bypass_minute');
    $limiter->clock=1060;policyCheck($api->handle('GET',policyUrl('alpha','telemetry'))['status']===200,'data_after_diagnostic_sixty');
    policyCheck($api->handle('GET',$diagnostic)['status']===429,'data_then_diagnostic_limited');
    policyCheck($api->handle('GET',str_replace('vehicleid=7','vehicleid=8',$diagnostic))['status']===429,'unauthorized_vehicle_budget_not_bypassed');
    $limiter->clock=1120;policyCheck($api->handle('GET',str_replace('vehicleid=7','vehicleid=8',$diagnostic))['status']===403,'diagnostic_foreign_vehicle_denied');
    policyCheck($api->handle('GET',str_replace('fixture-token','wrong-token',$diagnostic))['status']===401,'diagnostic_authentication_required');
    policyCheck($api->handle('GET','/diagnostic/alpha/history?password=fixture-token&endpoint=vehicles&vehicleid=7')['status']===400,'diagnostic_only_history_endpoints');
    policyCheck($api->handle('GET',$diagnostic.'&ymdfrom=2026-09-01')['status']===400,'diagnostic_both_dates_required');
    $limiter->clock=1180;$response=$api->handle('GET',str_replace('2026-09-01','1800-01-01',policyUrl('alpha','canbus')));
    policyCheck($response['status']===503&&$response['headers']['X-History-Policy-Reason']==='history_lookback_exceeded','policy_reason_visible_compatible_body');
    $response=$api->handle('GET',str_replace('fixture-token','bad',policyUrl('alpha','vehicles')));
    policyCheck(!isset($response['headers']['X-History-Completeness']),'metadata_cleared_between_requests');
    $directory=__DIR__.'/../test-results/minute-boundary-'.uniqid();mkdir($directory);$clock=1000.75;$real=new FleetApi\FileRateLimiter($directory,function()use(&$clock){return $clock;});
    policyCheck($real->acquire('fixture',60)===0,'fractional_first_allowed');
    $clock=1060.74;policyCheck($real->acquire('fixture',60)>0,'fractional_gap_less_than_sixty_rejected');
    $clock=1061.0;policyCheck($real->acquire('fixture',60)===0,'rounded_safe_boundary_allowed');
    echo json_encode(array('result'=>'PASS','suite'=>'suite-api-minute-and-history-diagnostic','checks'=>$checks,'externalCalls'=>0,'eaiCalls'=>0,'defaultLookbackDays'=>null,'utc'=>gmdate('c'))).PHP_EOL;
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','category'=>$e->getMessage(),'checks'=>$checks)).PHP_EOL;exit(1);}
