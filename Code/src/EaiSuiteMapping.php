<?php
namespace FleetApi;
require_once __DIR__.'/EaiVehicleMapping.php';
require_once __DIR__.'/VehiclesPublisher.php';

// Pure, bounded EAI -> represented API conversion. No transport or storage.
// History pages remain explicitly PARTIAL: a successful export is not proof
// of provider history completeness. Missing feeds are unavailable, not [].
class EaiSuiteMapping
{
    const PROFILE='EAI_SUITE_ARCHIVED_V1';
    public static function integer($value){
        if(is_int($value)){$value=(string)$value;}
        return EaiTelemetryRepository::integer($value);
    }
    public static function text($value){
        if(!is_string($value)||preg_match('//u',$value)!==1){throw new ApiException(503,'suite_text_invalid');}return $value;
    }
    public static function sdkGramInteger($value){
        // cwEAIFormats.xml declares these three CO2 fields bigint; the
        // original cwEAIClientTables::fixType stores round($v). Reproduce
        // that documented storage step without floating-point decimal loss.
        if(!is_string($value)||strpos($value,'.')===false)return self::integer($value);
        if(!preg_match('/^(-?)(0|[1-9][0-9]*)\.([0-9]{1,12})$/D',$value,$m)){throw new ApiException(503,'suite_co2_invalid');}
        $whole=self::integer($m[2]);$rounded=$whole+($m[3][0]>='5'?1:0);if($m[1]==='-')$rounded=-$rounded;
        return self::integer(sprintf('%.0F',$rounded));
    }
    public static function history(array $bundle,$endpoint){
        $parts=array('positions'=>'positions','canbus'=>'canbushistory','telemetry'=>'telemetry');
        if(!isset($parts[$endpoint])||!isset($bundle['timeBasis'],$bundle['tenant'],$bundle['capturedUtc'])||$bundle['timeBasis']!=='UTC'){throw new ApiException(503,'suite_policy_invalid');}
        $captured=Contract::eventTime('vehicles',$bundle['capturedUtc']);$scope=array_fill_keys(VehiclesPublisher::accountScope($bundle),true);$part=$parts[$endpoint];
        if(!array_key_exists($part,$bundle['feeds'])||!is_array($bundle['feeds'][$part])||array_values($bundle['feeds'][$part])!==$bundle['feeds'][$part]){throw new ApiException(503,'suite_feed_unavailable');}
        if(count($bundle['feeds'][$part])>50000){throw new ApiException(503,'suite_page_bound');}$rows=array();
        foreach($bundle['feeds'][$part] as $source){
            if(!is_array($source)||!isset($source['CWVehicleID'])){throw new ApiException(503,'suite_identity_invalid');}
            $id=is_int($source['CWVehicleID'])?(string)$source['CWVehicleID']:$source['CWVehicleID'];
            if(!Contract::vehicleId($id)){throw new ApiException(503,'suite_identity_invalid');}if(!isset($scope[$id]))continue;
            $row=array();foreach(Contract::fields($endpoint) as $field=>$type){
                if(!array_key_exists($field,$source)){throw new ApiException(503,'suite_missing_'.$endpoint.'_'.$field);}$v=$source[$field];
                if($v===null){
                    // Original SDK storage: explicit null in CHAR/VARCHAR is
                    // fixType(null, char/varchar) -> one ASCII space. This is
                    // unavailable text, NEVER a current vehicle location or
                    // a fabricated sensor/numeric value. Missing keys, IDs,
                    // dates and numeric nulls remain hard failures.
                    if($type==='s'&&!in_array($field,array('ID','MeasuredTime'),true)){$row[$field]=' ';continue;}
                    throw new ApiException(503,'suite_missing_'.$endpoint.'_'.$field);
                }
                if($field==='ID'){
                    // Never permit a floating point ID or round it through JS/PHP.
                    if(is_int($v))$v=(string)$v;if(!is_string($v)||!preg_match('/^[0-9]{1,40}$/D',$v)){throw new ApiException(503,'suite_event_id_invalid');}$row[$field]=$v;
                }elseif($field==='MeasuredTime'){
                    $time=Contract::eventTime('vehicles',$v);if(strcmp($time,$captured)>0){throw new ApiException(503,'suite_event_future');}
                    $row[$field]=$endpoint==='canbus'?(new \DateTime($time,new \DateTimeZone('UTC')))->format('d.m.Y G:i:s'):$time;
                }elseif($type==='s'){$row[$field]=self::text($v);}
                else{$n=$endpoint==='canbus'&&in_array($field,array('CO2','IdleCO2','PTOCO2'),true)?self::sdkGramInteger($v):self::integer($v);$row[$field]=$endpoint==='positions'&&in_array($field,array('Latitude','Longitude'),true)?$n/100000:$n;}
            }
            $rows[]=Contract::row($endpoint,$row);
        }
        $rows=Contract::rows($endpoint,$rows);
        usort($rows,function($a,$b)use($endpoint){$c=strcmp(Contract::eventTime($endpoint,$a['MeasuredTime']),Contract::eventTime($endpoint,$b['MeasuredTime']));return $c!==0?$c:strcmp($a['ID'],$b['ID']);});return $rows;
    }
    public static function compile(array $bundle,array $profile,$sourceSha256){
        if(!isset($bundle['tenant'],$bundle['timeBasis'],$bundle['capturedUtc'])||!is_string($bundle['tenant'])||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$bundle['tenant'])||$bundle['timeBasis']!=='UTC'||!is_string($sourceSha256)||!preg_match('/^[a-f0-9]{64}$/D',$sourceSha256)){throw new ApiException(503,'suite_policy_invalid');}
        if(!isset($profile['customer'],$profile['endpoint'],$profile['wireContractMode'],$profile['ruleOverrides'])||strtolower($profile['customer'])!==$bundle['tenant']||$profile['endpoint']!=='vehicles'||$profile['wireContractMode']!==VehiclesLegacyCompatibility::MODE){throw new ApiException(503,'suite_rule_tenant_invalid');}
        Contract::eventTime('vehicles',$bundle['capturedUtc']);$ids=VehiclesPublisher::accountScope($bundle);$bundle['wireContractMode']=VehiclesLegacyCompatibility::MODE;
        $rules=array_replace(EaiVehicleMapping::draftRules(),$profile['ruleOverrides']);VehiclesLegacyCompatibility::assertRules($rules);
        $mapped=EaiVehicleMapping::evaluate($bundle,$rules,$ids);$endpoints=array();$unavailable=array();
        if($mapped['candidateComplete']){$endpoints['vehicles']=$mapped['rows'];}
        else{$unavailable['vehicles']='mapping_incomplete';}
        // Base fleet has no dependency on telemetry, divisions or derived fields.
        $baseRules=EaiVehicleMapping::draftRules();$baseRules['Location']=array('source'=>'vehicles','column'=>'LastLocation','operation'=>'text');$base=array();
        foreach($bundle['feeds']['vehicles'] as $source){$row=array();foreach(Contract::fields('vehiclesBase') as $field=>$type){
                $rule=$baseRules[$field];$column=$rule['column'];if(!array_key_exists($column,$source)||$source[$column]===null){throw new ApiException(503,'suite_base_field_missing');}
                $v=$source[$column];$row[$field]=$type==='s'?self::text($v):self::integer($v);if($field==='Latitude'||$field==='Longitude')$row[$field]/=100000;
            }Contract::eventTime('vehicles',$row['LastLocationTime']);Contract::eventTime('vehicles',$row['LastReportTime']);$base[]=Contract::row('vehiclesBase',$row);}
        $endpoints['vehiclesBase']=Contract::rows('vehiclesBase',$base);$coverage=array();
        foreach(array('positions','canbus','telemetry') as $endpoint){
            try{$endpoints[$endpoint]=self::history($bundle,$endpoint);$coverage[$endpoint]=array('status'=>'ARCHIVED_PARTIAL','completeHistoryVerified'=>false);}
            catch(ApiException $e){$unavailable[$endpoint]=$e->getMessage();}
        }
        return array('profile'=>self::PROFILE,'tenant'=>$bundle['tenant'],'sourceCapturedUtc'=>$bundle['capturedUtc'],'sourceSha256'=>$sourceSha256,'scopeMode'=>'eai-account','vehicleIds'=>$ids,
            'endpoints'=>$endpoints,'unavailable'=>$unavailable,'coverage'=>$coverage,'legacyProviderParity'=>false,'currentFreshnessAsserted'=>false,'productionReady'=>false);
    }
}
