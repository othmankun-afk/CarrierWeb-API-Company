<?php
namespace FleetApi;
require_once __DIR__.'/Contract.php';
require_once __DIR__.'/Repository.php';

// Bounded materialized state, not a history archive. Do not discard the last
// known sensor merely because the next incremental page concerns another one.
class EaiVehicleState
{
    const MAX_ROWS = 50000;
    private static function id($value){
        if(is_int($value)){$value=(string)$value;}
        if(!is_string($value)||!preg_match('/^(?:0|[1-9][0-9]{0,39})$/D',$value)){throw new ApiException(503,'state_source_id_invalid');}
        return $value;
    }
    private static function same(array $a,array $b){ksort($a);ksort($b);return $a===$b;}
    private static function compactEquivalentTies(array $rows){
        // Repeated export IDs can describe the exact same measured payload.
        // Two witnesses retain ambiguity; one would invent a unique sensor.
        // Keep every different payload. Raw history is stored from the page,
        // not from this bounded current-state representation.
        $classes=array();foreach($rows as $event=>$row){
            $payload=$row;unset($payload['ID']);ksort($payload);$key=hash('sha256',serialize($payload));
            if(isset($classes[$key])&&!self::same($classes[$key]['payload'],$payload))throw new ApiException(503,'state_payload_digest_conflict');
            if(!isset($classes[$key]))$classes[$key]=array('payload'=>$payload,'rows'=>array());
            $classes[$key]['rows'][$event]=$row;
        }
        $kept=array();foreach($classes as $class){
            uasort($class['rows'],function($a,$b){$x=self::id($a['ID']);$y=self::id($b['ID']);return strlen($x)!==strlen($y)?(strlen($x)>strlen($y)?-1:1):-strcmp($x,$y);});
            foreach(array_slice($class['rows'],0,2,true) as $event=>$row)$kept[$event]=$row;
        }
        ksort($kept,SORT_STRING);return $kept;
    }
    private static function key($part,array $row){
        $vehicle=self::id(isset($row['CWVehicleID'])?$row['CWVehicleID']:null);
        if($part==='telemetry'){return $vehicle.'|'.self::id(isset($row['TransponderID'])?$row['TransponderID']:null).'|'.self::id(isset($row['TelemetryType'])?$row['TelemetryType']:null);}
        if($part==='wiredtelemetry'){return $vehicle.'|'.self::id(isset($row['TelemetrySource'])?$row['TelemetrySource']:null);}
        return $vehicle;
    }
    public static function merge(array $incoming,$previous=null){
        if(!isset($incoming['tenant'],$incoming['timeBasis'],$incoming['feeds']['vehicles'],$incoming['capturedUtc'])||$incoming['timeBasis']!=='UTC'){throw new ApiException(503,'state_bundle_invalid');}
        if($previous!==null&&(!is_array($previous)||$previous['tenant']!==$incoming['tenant']||$previous['timeBasis']!=='UTC'||strcmp($previous['capturedUtc'],$incoming['capturedUtc'])>0)){throw new ApiException(503,'state_previous_invalid');}
        $scope=array();foreach($incoming['feeds']['vehicles'] as $vehicle){$id=self::id(isset($vehicle['CWVehicleID'])?$vehicle['CWVehicleID']:null);if(isset($scope[$id])){throw new ApiException(503,'state_vehicle_duplicate');}$scope[$id]=true;}
        $result=$incoming;$meta=array('profile'=>'LATEST_PHYSICAL_SOURCE_V1','previousCapturedUtc'=>$previous===null?null:$previous['capturedUtc'],'pageFeeds'=>array(),'retainedRows'=>array(),'tiePolicy'=>'TWO_WITNESSES_PER_EQUAL_PAYLOAD_V1','equivalentTieRowsCompacted'=>array());
        foreach(array('telemetry','wiredtelemetry','canbushistory') as $part){
            $present=array_key_exists($part,$incoming['feeds']);$meta['pageFeeds'][$part]=$present?'PRESENT':'ABSENT';
            if(!$present&&($previous===null||!array_key_exists($part,$previous['feeds']))){continue;}
            $groups=array();$all=array();if($previous!==null&&isset($previous['feeds'][$part])){$all[]=$previous['feeds'][$part];}if($present){$all[]=$incoming['feeds'][$part];}
            foreach($all as $rows){
                if(!is_array($rows)||array_values($rows)!==$rows||count($rows)>self::MAX_ROWS){throw new ApiException(503,'state_feed_bound');}
                foreach($rows as $row){
                    if(!is_array($row)){throw new ApiException(503,'state_feed_invalid');}$id=self::id(isset($row['CWVehicleID'])?$row['CWVehicleID']:null);if(!isset($scope[$id])){continue;}
                    $key=self::key($part,$row);$time=Contract::eventTime('vehicles',isset($row['MeasuredTime'])?$row['MeasuredTime']:null);
                    if(strcmp($time,$incoming['capturedUtc'])>0){throw new ApiException(503,'state_event_after_capture');}
                    $event=self::id(isset($row['CWMergeTableID'])?$row['CWMergeTableID']:'0').'|'.self::id(isset($row['ID'])?$row['ID']:null);
                    if(!isset($groups[$key])||strcmp($time,$groups[$key]['time'])>0){$groups[$key]=array('time'=>$time,'rows'=>array($event=>$row));}
                    elseif($time===$groups[$key]['time']){
                        if(isset($groups[$key]['rows'][$event])&&!self::same($groups[$key]['rows'][$event],$row)){throw new ApiException(503,'state_event_conflict');}
                        $groups[$key]['rows'][$event]=$row;
                        // Validate every incoming identity before compaction.
                        // An arbitrary eight-row limit must not stop a tenant.
                    }
                }
            }
            ksort($groups,SORT_STRING);$kept=array();$compacted=0;foreach($groups as $group){$ties=self::compactEquivalentTies($group['rows']);$compacted+=count($group['rows'])-count($ties);foreach($ties as $row){$kept[]=$row;if(count($kept)>self::MAX_ROWS){throw new ApiException(503,'state_feed_bound');}}}
            $result['feeds'][$part]=$kept;$meta['retainedRows'][$part]=count($kept);$meta['equivalentTieRowsCompacted'][$part]=$compacted;
        }
        $result['sourceState']=$meta;return $result;
    }
}
