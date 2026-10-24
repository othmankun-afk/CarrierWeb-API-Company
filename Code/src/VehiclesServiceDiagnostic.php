<?php
namespace FleetApi;
require_once __DIR__.'/EaiVehicleSignals.php';
class VehiclesServiceDiagnostic
{
    private static function resolutions(array $bundle,array $rules,array $ids){
        $allowed=array_fill_keys($ids,true);$result=array();
        foreach($bundle['feeds']['vehicles'] as $vehicle){$id=(string)$vehicle['CWVehicleID'];if(!isset($allowed[$id])){continue;}$fields=array();
            foreach(array('Door','Temperature','Battery','Engine','Plugged','PluggedType') as $field){
                if(!isset($rules[$field])||!is_array($rules[$field])||!EaiCurrentVehicleSignals::selected($rules[$field])){continue;}
                try{
                    if(in_array($field,array('Plugged','PluggedType'),true)&&(!isset($rules['Plugged'],$rules['PluggedType'])||$rules['Plugged']!==$rules['PluggedType'])){throw new ApiException(503,'eai_hitch_pair_invalid');}
                    $fields[$field]=EaiCurrentVehicleSignals::resolve($field,$vehicle,$bundle,$rules[$field]);
                }
                catch(ApiException $e){$fields[$field]=array('status'=>'ERROR','reason'=>$e->getMessage(),'isLegacyProviderParity'=>false);}
            }
            if($fields){$result['id:'.$id]=$fields;}
        }
        return $result;
    }
    public static function read($db,$tenant,array $connection,array $allowedIds,$all){
        $db->requireInnoDb('api_vehicle_service');$s=$db->prepare('SELECT generation,captured_utc,snapshot_json,snapshot_sha256,status,last_attempt_utc,in_flight FROM api_vehicle_service WHERE tenant_key=?');$s->execute(array($tenant));$r=$s->fetch();
        if(!$r||$r['snapshot_json']===null){throw new ApiException(503,'diagnostic_snapshot_unavailable');}
        if(strlen($r['snapshot_json'])>16777216||!hash_equals($r['snapshot_sha256'],hash('sha256',$r['snapshot_json']))){throw new ApiException(503,'diagnostic_integrity_invalid');}
        $bundle=StrictJson::decode($r['snapshot_json'],true,64);if($bundle['tenant']!==$tenant||$bundle['capturedUtc']!==$r['captured_utc']){throw new ApiException(503,'diagnostic_tenant_invalid');}
        $path=Bootstrap::localPath($connection['diagnosticRuleProfile']);$bytes=file_get_contents($path);if(hash('sha256',$bytes)!==$connection['diagnosticRuleProfileSha256']){throw new ApiException(503,'diagnostic_rules_changed');}$profile=StrictJson::decode($bytes,true,32);
        if(strtolower($profile['customer'])!==$tenant){throw new ApiException(503,'diagnostic_rule_tenant_invalid');}
        $rules=array_replace(EaiVehicleMapping::draftRules(),$profile['ruleOverrides']);$ids=$all?VehiclesPublisher::accountScope($bundle):$allowedIds;$e=EaiVehicleMapping::evaluate($bundle,$rules,$ids);
        $fresh=true;try{PublishedVehiclesRepository::checkTime($bundle['capturedUtc'],$connection['maxSnapshotAgeSeconds']);}catch(ApiException $ex){$fresh=false;}
        return array('mode'=>'LOCAL_EAI_DIAGNOSTIC_NOT_CARRIERWEB_CONTRACT','tenant'=>$tenant,'generation'=>(int)$r['generation'],'capturedUtc'=>$bundle['capturedUtc'],'fresh'=>$fresh,'serviceStatus'=>$r['status'],'inFlight'=>(bool)$r['in_flight'],'lastAttemptUtc'=>$r['last_attempt_utc'],'vehicleCount'=>count($e['partial']),'candidateComplete'=>$e['candidateComplete'],'vehicles'=>array_values($e['partial']),'unresolvedByVehicle'=>$e['issues'],'sourceState'=>isset($bundle['sourceState'])?$bundle['sourceState']:null,'eaiSourceEvidence'=>EaiVehicleSignals::evidence($bundle,$ids),'eaiFieldResolution'=>self::resolutions($bundle,$rules,$ids),'wireFieldPolicies'=>LegacyVehicleCounterPolicy::metadata($bundle,$rules),'productionReady'=>false);
    }
}
