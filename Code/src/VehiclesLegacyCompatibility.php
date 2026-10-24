<?php
namespace FleetApi;
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/EaiCurrentVehicleSignals.php';

// Check the supported legacy response representations. These checks do not
// establish sensor selection or source lineage.
class VehiclesLegacyCompatibility
{
    const MODE = 'carrierweb-legacy';
    public static function assertRules(array $rules){
        foreach(array('Plugged','PluggedType','Door','Temperature','Engine','Battery') as $field){
            if(!isset($rules[$field])||!is_array($rules[$field])||!EaiCurrentVehicleSignals::selected($rules[$field])){continue;}
            $r=$rules[$field];$tenant=isset($r['tenant'])?$r['tenant']:null;
            EaiCurrentVehicleSignals::validateRule($field,array('tenant'=>$tenant,'timeBasis'=>'UTC','capturedUtc'=>gmdate('Y-m-d H:i:s')),$r);
            if(in_array($field,array('Plugged','PluggedType'),true)&&(!isset($rules['Plugged'],$rules['PluggedType'])||$rules['Plugged']!==$rules['PluggedType'])){throw new ApiException(503,'eai_hitch_pair_invalid');}
        }
        foreach(array('Battery','Temperature','Door','Engine') as $field){
            if(!isset($rules[$field])){continue;}$r=$rules[$field];
            if(!is_array($r)||!isset($r['operation'])||!is_string($r['operation'])){throw new ApiException(503,'legacy_representation_rule_invalid');}
            // The fixed documented-EAI policy preserves these four wire
            // representations; this says nothing about old-provider semantics.
            if(EaiCurrentVehicleSignals::selected($r)){continue;}
            $op=$r['operation'];
            if($field==='Battery'){
                $raw=$op==='number'||($op==='eai_signal'&&isset($r['quantity'],$r['representation'])&&$r['quantity']==='batteryVoltage'&&$r['representation']==='raw_eai');
                if(!$raw){throw new ApiException(503,'legacy_battery_representation_changed');}
            }elseif($field==='Temperature'){
                $celsius=($op==='divide'&&isset($r['divisor'])&&$r['divisor']===100)||($op==='eai_signal'&&isset($r['quantity'],$r['representation'])&&$r['quantity']==='temperature'&&$r['representation']==='physical');
                if(!$celsius){throw new ApiException(503,'legacy_temperature_representation_changed');}
            }else{
                if($op==='enum'){$labels=isset($r['values'])?$r['values']:null;}
                elseif($op==='eai_signal'&&isset($r['representation'])&&$r['representation']==='boolean_labels'){$labels=isset($r['labels'])?$r['labels']:null;}
                else{throw new ApiException(503,'legacy_label_representation_changed');}
                $allowed=$field==='Door'?array('OPENED','CLOSED','-'):array('ON','OFF','-');
                if(!is_array($labels)||!$labels){throw new ApiException(503,'legacy_label_representation_changed');}
                foreach($labels as $label){if(!is_string($label)||!in_array($label,$allowed,true)){throw new ApiException(503,'legacy_label_representation_changed');}}
            }
        }
    }
}
