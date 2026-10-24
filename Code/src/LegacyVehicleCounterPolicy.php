<?php
namespace FleetApi;
require_once __DIR__.'/Contract.php';

// Legacy response constants are separate from measured CANbus counters.
// The source diagnostic exposes the EAI counters.
class LegacyVehicleCounterPolicy
{
    const POLICY='observed-vehicles-zero-empty-v1';
    const APPROVED_POLICY='approved-compatibility-zero-empty-v1';
    public static function selected(array $rule){return isset($rule['operation'])&&$rule['operation']==='legacy_vehicle_counter';}
    public static function value($field,array $bundle,array $rules){
        if(!in_array($field,array('EngineRunningTime','EngineRunningTimeHHMM'),true)||!isset($bundle['tenant'],$bundle['wireContractMode'])||!is_string($bundle['tenant'])||$bundle['wireContractMode']!=='carrierweb-legacy'){
            throw new ApiException(503,'legacy_counter_policy_invalid');
        }
        $pair=array();foreach(array('EngineRunningTime','EngineRunningTimeHHMM') as $name){
            if(!isset($rules[$name])||!is_array($rules[$name])){throw new ApiException(503,'legacy_counter_pair_invalid');}$r=$rules[$name];
            // New tenants use the configured constants without an old-provider
            // observation hash. Observed values require their own reference hash.
            $approved=isset($r['policy'])&&$r['policy']===self::APPROVED_POLICY;
            $keys=array_keys($r);sort($keys);$expected=$approved?array('operation','policy','source','tenant'):array('operation','policy','referenceSha256','source','tenant');sort($expected);
            $sourceValid=isset($r['source'],$r['policy'])&&($approved?$r['source']==='contract_compatibility':($r['source']==='legacy_vehicles'&&$r['policy']===self::POLICY&&isset($r['referenceSha256'])&&is_string($r['referenceSha256'])&&preg_match('/^[a-f0-9]{64}$/D',$r['referenceSha256'])));
            if($keys!==$expected||!self::selected($r)||!$sourceValid||!is_string($r['tenant'])||$r['tenant']!==$bundle['tenant']){
                throw new ApiException(503,'legacy_counter_policy_invalid');
            }
            ksort($r);$pair[]=$r;
        }
        if($pair[0]!==$pair[1]){throw new ApiException(503,'legacy_counter_pair_invalid');}
        return $field==='EngineRunningTime'?0:'';
    }
    public static function metadata(array $bundle,array $rules){
        if(!isset($rules['EngineRunningTime'])||!is_array($rules['EngineRunningTime'])||!self::selected($rules['EngineRunningTime'])){return array();}
        self::value('EngineRunningTime',$bundle,$rules);
        $policy=$rules['EngineRunningTime']['policy'];
        return array('EngineRunningTime'=>array('policy'=>$policy,'isRealEaiMeasurement'=>false,'sourceCounterRetainedIn'=>'eaiSourceEvidence'),
            'EngineRunningTimeHHMM'=>array('policy'=>$policy,'isRealEaiMeasurement'=>false,'sourceCounterRetainedIn'=>'eaiSourceEvidence'));
    }
}
