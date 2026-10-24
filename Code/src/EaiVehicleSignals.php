<?php
namespace FleetApi;
require_once __DIR__.'/Contract.php';
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/EaiTelemetryRepository.php';

// Documented EAI quantities. This does not silently equate physical volts,
// an ignition contact or a reefer operating mode with the legacy API fields.
class EaiVehicleSignals
{
    public static function integer($raw){
        if(is_int($raw)){$raw=(string)$raw;}if(!is_string($raw)){throw new ApiException(503,'signal_value_invalid');}
        try{return EaiTelemetryRepository::integer($raw);}catch(ApiException $e){throw new ApiException(503,'signal_value_invalid');}
    }
    public static function hhmm($seconds){
        $n=self::integer($seconds);if($n<0){throw new ApiException(503,'signal_duration_invalid');}
        return str_pad(sprintf('%.0F',floor($n/3600)),2,'0',STR_PAD_LEFT).':'.str_pad((string)floor(fmod($n,3600)/60),2,'0',STR_PAD_LEFT);
    }
    public static function decode($type,$raw){
        $type=self::integer($type);$value=self::integer($raw);
        if(in_array($type,array(50,51,174,180),true)){return array('quantity'=>'temperature','value'=>$value/100,'unit'=>'degC');}
        if(in_array($type,array(60,61,73,74,71,72,76,77,63,93),true)){
            if($value!==0&&$value!==1){throw new ApiException(503,'signal_enum_unmapped');}
            $door=in_array($type,array(60,61,73,74),true);$hitch=in_array($type,array(71,72,76,77),true);
            $on=in_array($type,array(73,74,71),true)?$value===0:$value===1;
            return array('quantity'=>$door?'doorOpen':($hitch?'hitchAttached':'ignitionOn'),'value'=>$on,'unit'=>'boolean');
        }
        if(in_array($type,array(153,157),true)){
            if($value<0){throw new ApiException(503,'signal_voltage_invalid');}
            return array('quantity'=>'batteryVoltage','value'=>$value/80,'unit'=>'V','rawEaiValue'=>$value);
        }
        if($type===155){if($value<0){throw new ApiException(503,'signal_value_invalid');}return array('quantity'=>'boxBatteryLevel','value'=>fmod($value,1000),'unit'=>'percent','rawEaiValue'=>$value);}
        if($type===151){
            $modes=array('ENGINE_START_STOP','STANDBY_START_STOP','ENGINE_CONTINUOUS','STANDBY_CONTINUOUS');if($value<0||$value>3){throw new ApiException(503,'signal_enum_unmapped');}
            return array('quantity'=>'reeferEngineMode','value'=>$modes[$value],'unit'=>'mode');
        }
        if($type===183){
            if($value===4294967295.0||$value===4294967295){throw new ApiException(503,'signal_measurement_unknown');}
            if($value===4294967294.0||$value===4294967294){throw new ApiException(503,'signal_sensor_error');}
            if($value<0){throw new ApiException(503,'signal_value_invalid');}return array('quantity'=>'engineRpm','value'=>$value,'unit'=>'rpm');
        }
        throw new ApiException(503,'signal_type_unsupported');
    }
    private static function field($type){
        if(in_array($type,array(50,51,174,180),true)){return 'Temperature';}
        if(in_array($type,array(60,61,73,74),true)){return 'Door';}
        if(in_array($type,array(153,157,155),true)){return 'Battery';}
        if(in_array($type,array(63,93,151,183),true)){return 'Engine';}
        if(in_array($type,array(71,72,76,77),true)){return 'Plugged';}return null;
    }
    private static function candidate($part,array $row,$type,$raw,$channel){
        $v=self::decode($type,$raw);$measured=Contract::eventTime('vehicles',$row['MeasuredTime']);return array('source'=>$part,'id'=>(string)$row['ID'],'partition'=>isset($row['CWMergeTableID'])?(string)$row['CWMergeTableID']:null,'measuredUtc'=>$measured,'telemetryType'=>$type,'transponderId'=>isset($row['TransponderID'])?(string)$row['TransponderID']:null,'telemetrySource'=>isset($row['TelemetrySource'])?(string)$row['TelemetrySource']:null,'channel'=>$channel,'measurement'=>$v);
    }
    public static function evidence(array $bundle,array $authorizedIds){
        $allowed=array_fill_keys($authorizedIds,true);$result=array();$indexed=array();
        foreach(array('telemetry','wiredtelemetry','canbushistory') as $part){$indexed[$part]=array();if(!isset($bundle['feeds'][$part])){continue;}foreach($bundle['feeds'][$part] as $row){$id=(string)$row['CWVehicleID'];if(isset($allowed[$id])){$indexed[$part][$id][]=$row;}}}
        foreach($bundle['feeds']['vehicles'] as $vehicle){$id=(string)$vehicle['CWVehicleID'];if(!isset($allowed[$id])){continue;}$fields=array();foreach(array('Plugged','PluggedType','Door','Temperature','Engine','Battery','EngineRunningTime','EngineRunningTimeHHMM') as $field){$fields[$field]=array('status'=>'NO_APPLICABLE_SOURCE_OBSERVED','candidates'=>array());}
            $fields['PluggedType']['status']='WIRE_TYPE_DEFINITION_REQUIRED';$fields['Plugged']['trailerRelationId']=(string)$vehicle['CWTrailerID'];$errors=array();
            foreach(array('telemetry','wiredtelemetry') as $part){if(!isset($indexed[$part][$id])){continue;}
                foreach($indexed[$part][$id] as $row){$channels=array();
                    if($part==='telemetry'){$channels[]=array($row['TelemetryType'],$row['Value0'],null);}
                    else{for($n=0;$n<100;$n++){if(isset($row['TelemetryType'.$n])&&array_key_exists('Value'.$n,$row)){$channels[]=array($row['TelemetryType'.$n],$row['Value'.$n],$n);}}}
                    foreach($channels as $channel){try{$type=self::integer($channel[0]);$field=self::field($type);if($field===null){continue;}$fields[$field]['candidates'][]=self::candidate($part,$row,$type,$channel[1],$channel[2]);}catch(ApiException $e){$errors[$e->getMessage()]=true;}}
                }
            }
            if(isset($indexed['canbushistory'][$id])){foreach($indexed['canbushistory'][$id] as $row){
                try{$seconds=self::integer($row['EngineRunningTime']);if($seconds<0){throw new ApiException(503,'signal_duration_invalid');}$base=array('source'=>'canbushistory','id'=>(string)$row['ID'],'measuredUtc'=>$row['MeasuredTime'],'alignsVehicleCanbusTime'=>$row['MeasuredTime']===$vehicle['LastCanbusTime']);
                    $fields['EngineRunningTime']['candidates'][]=$base+array('measurement'=>array('quantity'=>'engineRunningDuration','value'=>$seconds,'unit'=>'s'));
                    $fields['EngineRunningTimeHHMM']['candidates'][]=$base+array('measurement'=>array('quantity'=>'engineRunningDurationFormatted','value'=>self::hhmm($row['EngineRunningTime']),'unit'=>'HH:MM','legacyNonemptyFormatVerified'=>false));
                }catch(ApiException $e){$errors[$e->getMessage()]=true;}
            }}
            foreach($fields as $field=>&$info){$count=count($info['candidates']);$info['candidateCount']=$count;if($count){$info['status']=$count===1?'DOCUMENTED_EAI_VALUE_WIRE_RULE_REQUIRED':'MULTIPLE_SOURCES_SELECTION_REQUIRED';$info['candidates']=array_slice($info['candidates'],0,16);$info['candidatesTruncated']=$count>16;
                foreach($info['candidates'] as &$candidate){$capture=new \DateTime($bundle['capturedUtc'],new \DateTimeZone('UTC'));$measured=new \DateTime($candidate['measuredUtc'],new \DateTimeZone('UTC'));$delta=$measured->diff($capture);$candidate['ageSecondsAtCapture']=$delta->invert?null:$delta->days*86400+$delta->h*3600+$delta->i*60+$delta->s;$candidate['afterCapture']=(bool)$delta->invert;}
                unset($candidate);
            }}
            unset($info);$result['id:'.$id]=array('fields'=>$fields,'sourceIssues'=>array_keys($errors),'wireMappingAccepted'=>false);
        }
        return $result;
    }
}
