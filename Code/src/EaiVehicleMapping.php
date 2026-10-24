<?php
namespace FleetApi;
require_once __DIR__.'/Contract.php';
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/EaiTelemetryRepository.php';
require_once __DIR__.'/EaiVehicleSignals.php';
require_once __DIR__.'/LegacyVehicleCounterPolicy.php';
require_once __DIR__.'/EaiCurrentVehicleSignals.php';

// Local candidate evaluation only. No database/network access and no HTTP activation.
// A rule is an explicit hypothesis until paired customer evidence validates it.
class EaiVehicleMapping
{
    public static function derivedFields()
    {
        return array('DivisionDesc','Location','Plugged','PluggedType','Door','Temperature','Engine','Battery','EngineRunningTime','EngineRunningTimeHHMM');
    }

    public static function draftRules()
    {
        $rules = array();
        foreach (Contract::fields('vehicles') as $field => $type) {
            if (in_array($field, self::derivedFields(), true)) { $rules[$field] = null; continue; }
            $column = $field === 'CurrentSpeed' ? 'Speed' : ($field === 'Zipcode' ? 'ZipCode' : $field);
            $rules[$field] = array('source'=>'vehicles','column'=>$column,'operation'=>$type === 's' ? 'text' : 'number');
            if ($field === 'Latitude' || $field === 'Longitude') {
                $rules[$field]['operation'] = 'divide'; $rules[$field]['divisor'] = 100000;
            }
        }
        return $rules;
    }

    private static function reject($code) { throw new ApiException(503, $code); }

    private static function value(array $row, $column)
    {
        if (!is_string($column) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $column)) { self::reject('rule_invalid'); }
        if (!array_key_exists($column, $row) || $row[$column] === null) { self::reject('value_missing'); }
        return $row[$column];
    }

    private static function number($value)
    {
        // Preserve exact integers, including on the legacy 32-bit runtime.
        if (is_int($value)) { $value = (string)$value; }
        if (!is_string($value)) { self::reject('value_invalid'); }
        try { return EaiTelemetryRepository::integer($value); }
        catch (ApiException $e) { self::reject('value_invalid'); }
    }

    private static function rowId(array $row, $field)
    {
        $id = self::value($row, $field);
        if (is_int($id)) { $id = (string)$id; }
        if (!is_string($id) || !preg_match('/^(?:0|[1-9][0-9]{0,39})$/D', $id)) { self::reject('source_id_invalid'); }
        return $id;
    }

    private static function timeValue($value)
    {
        try { return Contract::eventTime('vehicles', $value); }
        catch (ApiException $e) { self::reject('event_time_invalid'); }
    }

    private static function numericIdCompare($a, $b)
    {
        if (strlen($a) !== strlen($b)) { return strlen($a) < strlen($b) ? -1 : 1; }
        return strcmp($a, $b);
    }

    private static function transform($raw, array $rule)
    {
        if (!isset($rule['operation'])) { self::reject('rule_invalid'); }
        if ($rule['operation'] === 'text') {
            if (!is_string($raw) || preg_match('//u', $raw) !== 1) { self::reject('value_invalid'); }
            return $raw;
        }
        if($rule['operation']==='eai_signal'){
            if(!isset($rule['telemetryType'],$rule['quantity'],$rule['representation'],$rule['filters'],$rule['source'],$rule['column'])){self::reject('rule_invalid');}
            if((!is_string($rule['telemetryType'])&&!is_int($rule['telemetryType']))||!is_string($rule['quantity'])||!is_string($rule['representation'])){self::reject('rule_invalid');}
            $typeColumn='TelemetryType';
            if($rule['source']==='wiredtelemetry'){
                if(!preg_match('/^Value([0-9]|[1-9][0-9])$/D',$rule['column'],$channel)){self::reject('rule_invalid');}$typeColumn.=$channel[1];
            }elseif($rule['source']!=='telemetry'){self::reject('rule_invalid');}
            if(!isset($rule['filters'][$typeColumn])||(string)$rule['filters'][$typeColumn]!== (string)$rule['telemetryType']){self::reject('signal_type_filter_mismatch');}
            $signal=EaiVehicleSignals::decode($rule['telemetryType'],$raw);
            if($signal['quantity']!==$rule['quantity']){self::reject('signal_quantity_mismatch');}
            if($rule['representation']==='physical'){
                if(!is_int($signal['value'])&&!is_float($signal['value'])){self::reject('signal_representation_invalid');}return $signal['value'];
            }
            if($rule['representation']==='raw_eai'){
                if(!array_key_exists('rawEaiValue',$signal)){self::reject('signal_representation_invalid');}return $signal['rawEaiValue'];
            }
            if($rule['representation']==='boolean_labels'){
                if(!is_bool($signal['value'])||!isset($rule['labels'])||!is_array($rule['labels'])){self::reject('signal_representation_invalid');}
                $key=$signal['value']?'true':'false';if(!array_key_exists($key,$rule['labels'])||!is_string($rule['labels'][$key])){self::reject('signal_label_missing');}return $rule['labels'][$key];
            }
            if($rule['representation']==='source_type_label'){
                $key='type:'.(string)$rule['telemetryType'];if(!isset($rule['labels'])||!is_array($rule['labels'])||!array_key_exists($key,$rule['labels'])||!is_string($rule['labels'][$key])){self::reject('signal_label_missing');}return $rule['labels'][$key];
            }
            self::reject('signal_representation_invalid');
        }
        $number = self::number($raw);
        if ($rule['operation'] === 'number') { return $number; }
        if ($rule['operation'] === 'duration_seconds') {if($number<0){self::reject('signal_duration_invalid');}return $number;}
        if ($rule['operation'] === 'divide') {
            if (!isset($rule['divisor']) || !is_int($rule['divisor']) || $rule['divisor'] < 1 || $rule['divisor'] > 1000000000) { self::reject('rule_invalid'); }
            return $number / $rule['divisor'];
        }
        if ($rule['operation'] === 'enum') {
            // Keys explicitly distinguish code 0 from a missing measurement.
            $key = 'code:'.(string)$number;
            if (!isset($rule['values']) || !is_array($rule['values']) || !array_key_exists($key, $rule['values'])) { self::reject('enum_unmapped'); }
            return $rule['values'][$key];
        }
        if ($rule['operation'] === 'seconds_hhmm') {
            if ($number < 0) { self::reject('value_invalid'); }
            // Explicit candidate operation, never selected just from the field name.
            return EaiVehicleSignals::hhmm($raw);
        }
        self::reject('rule_invalid');
    }

    private static function select(array $vehicle, array $feeds, array $rule, $captureTime)
    {
        if (!isset($rule['source'],$rule['column']) || !is_string($rule['source']) || !is_string($rule['column']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$rule['column'])) { self::reject('rule_invalid'); }
        if ($rule['source'] === 'vehicles') { return self::value($vehicle, $rule['column']); }
        if (!in_array($rule['source'], array('divisions','telemetry','wiredtelemetry','canbushistory'), true)) { self::reject('rule_invalid'); }
        if (!array_key_exists($rule['source'], $feeds)) { self::reject('feed_missing'); }
        $rows = $feeds[$rule['source']];
        if (!is_array($rows) || array_values($rows) !== $rows || count($rows) > 50000) { self::reject('feed_invalid'); }
        if ($rule['source'] === 'divisions') {
            $id = self::rowId($vehicle,'DivisionID'); $found = null;
            foreach ($rows as $row) {
                if (!is_array($row)) { self::reject('feed_invalid'); }
                if (self::rowId($row,'ID') === $id) {
                    if ($found !== null) { self::reject('join_ambiguous'); }
                    $found = $row;
                }
            }
            if ($found === null) {
                // A source-key-specific representation is allowed only when an
                // explicit rule supplies it. It never replaces an absent feed
                // or an ambiguous join, and it never overwrites a real value.
                $key='id:'.$id;
                if(isset($rule['missingKeyValues'])&&is_array($rule['missingKeyValues'])&&array_key_exists($key,$rule['missingKeyValues'])){return $rule['missingKeyValues'][$key];}
                self::reject('related_row_missing');
            }
            return self::value($found, $rule['column']);
        }
        if (!isset($rule['atVehicleTime'],$rule['maxAgeSeconds'],$rule['filters'],$rule['tieBreak']) ||
            !in_array($rule['atVehicleTime'],array('LastLocationTime','LastReportTime','LastCanbusTime'),true) ||
            !is_int($rule['maxAgeSeconds']) || $rule['maxAgeSeconds'] < 0 || $rule['maxAgeSeconds'] > 31536000 ||
            !is_array($rule['filters']) || !in_array($rule['tieBreak'],array('reject','highest_numeric_id'),true)) { self::reject('rule_invalid'); }
        $anchor = self::timeValue(self::value($vehicle, $rule['atVehicleTime']));
        if (strcmp($anchor,$captureTime)>0) { self::reject('anchor_after_capture'); }
        // CWMergeTableID is a server partition, not a physical sensor. Never
        // require one partition as a business filter across midnight rollover.
        if ($rule['source'] === 'telemetry' && (!array_key_exists('TelemetryType',$rule['filters']) || !array_key_exists('TransponderID',$rule['filters']))) { self::reject('sensor_selection_missing'); }
        if ($rule['source'] === 'wiredtelemetry' && (!array_key_exists('TelemetrySource',$rule['filters']) || !preg_match('/^Value([0-9]|[1-9][0-9])$/D',$rule['column'],$channel) || !array_key_exists('TelemetryType'.$channel[1],$rule['filters']))) { self::reject('sensor_selection_missing'); }
        $id = self::rowId($vehicle,'CWVehicleID'); $selected = null; $selectedTime = null; $seen = array(); $ambiguous=false;
        foreach ($rows as $row) {
            if (!is_array($row)) { self::reject('feed_invalid'); }
            if (self::rowId($row,'CWVehicleID') !== $id) { continue; }
            $matches = true;
            foreach ($rule['filters'] as $column=>$expected) {
                $actual = self::value($row,$column);
                if ((!is_string($expected)&&!is_int($expected)) || (!is_string($actual)&&!is_int($actual))) { self::reject('value_invalid'); }
                if ((string)$actual !== (string)$expected) { $matches=false; break; }
            }
            if (!$matches) { continue; }
            $eventId=self::rowId($row,'ID');
            $partition=array_key_exists('CWMergeTableID',$row)?self::rowId($row,'CWMergeTableID'):'none';
            $eventKey='partition:'.$partition.'|id:'.$eventId;
            if (isset($seen[$eventKey])) { self::reject('event_duplicate'); }
            $seen[$eventKey]=true;
            $time=self::timeValue(self::value($row,'MeasuredTime'));
            if (strcmp($time,$anchor)>0) { continue; }
            if ($selected===null || strcmp($time,$selectedTime)>0) { $selected=$row; $selectedTime=$time; $ambiguous=false; }
            elseif ($time===$selectedTime) {
                if ($rule['tieBreak']==='reject') { $ambiguous=true; }
                if ($rule['tieBreak']==='highest_numeric_id'&&self::numericIdCompare($eventId,self::rowId($selected,'ID'))===0) { $ambiguous=true; }
                if (self::numericIdCompare($eventId,self::rowId($selected,'ID'))>0) { $selected=$row; if($rule['tieBreak']==='highest_numeric_id'){$ambiguous=false;} }
            }
        }
        if ($selected===null) { self::reject('event_missing'); }
        if ($ambiguous) { self::reject('event_ambiguous'); }
        // DateTime subtraction remains valid for post-2038 values on 32-bit PHP.
        $a=new \DateTime($anchor,new \DateTimeZone('UTC')); $b=new \DateTime($selectedTime,new \DateTimeZone('UTC'));
        $delta=$a->diff($b); $age=$delta->days*86400+$delta->h*3600+$delta->i*60+$delta->s;
        if ($age>$rule['maxAgeSeconds']) { self::reject('event_stale'); }
        return self::value($selected,$rule['column']);
    }

    public static function evaluate(array $bundle, array $rules, array $authorizedIds)
    {
        if (!isset($bundle['tenant'],$bundle['capturedUtc'],$bundle['timeBasis'],$bundle['feeds']) ||
            !is_string($bundle['tenant']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$bundle['tenant']) ||
            !in_array($bundle['timeBasis'],array('UTC','UNVERIFIED'),true) || !is_array($bundle['feeds']) || !isset($bundle['feeds']['vehicles'])) { self::reject('bundle_invalid'); }
        $capture=self::timeValue($bundle['capturedUtc']); $schema=Contract::fields('vehicles');
        if (array_diff(array_keys($rules),array_keys($schema))) { self::reject('rule_unknown_field'); }
        $scope=array();
        foreach ($authorizedIds as $id) {
            if (!Contract::vehicleId($id) || isset($scope['id:'.$id])) { self::reject('scope_invalid'); }
            $scope['id:'.$id]=true;
        }
        if (count($scope)>10000) { self::reject('scope_too_large'); }
        $vehicles=$bundle['feeds']['vehicles'];
        if (!is_array($vehicles) || array_values($vehicles)!==$vehicles || count($vehicles)>10000) { self::reject('feed_invalid'); }
        $seen=array(); $mapped=array(); $issues=array(); $partial=array(); $outside=0; $preparedSignals=null;
        foreach ($vehicles as $vehicle) {
            if (!is_array($vehicle)) { self::reject('feed_invalid'); }
            $id=self::rowId($vehicle,'CWVehicleID'); $key='id:'.$id;
            if (!Contract::vehicleId($id) || isset($seen[$key])) { self::reject('vehicle_duplicate_or_invalid'); }
            $seen[$key]=true;
            if (!isset($scope[$key])) { $outside++; continue; }
            $values=array(); $rowIssues=array();
            foreach ($schema as $field=>$type) {
                if (!isset($rules[$field]) || !is_array($rules[$field])) { $rowIssues[$field]='rule_missing'; continue; }
                try {
                    if (isset($rules[$field]['source']) && in_array($rules[$field]['source'],array('telemetry','wiredtelemetry','canbushistory'),true) && $bundle['timeBasis']!=='UTC') { self::reject('time_basis_unverified'); }
                    if(LegacyVehicleCounterPolicy::selected($rules[$field])){$value=LegacyVehicleCounterPolicy::value($field,$bundle,$rules);}
                    elseif(EaiCurrentVehicleSignals::selected($rules[$field])){
                        if(in_array($field,array('Plugged','PluggedType'),true)&&(!isset($rules['Plugged'],$rules['PluggedType'])||!is_array($rules['Plugged'])||!is_array($rules['PluggedType'])||$rules['Plugged']!==$rules['PluggedType'])){self::reject('eai_hitch_pair_invalid');}
                        if($preparedSignals===null){$preparedSignals=EaiCurrentVehicleSignals::prepare($bundle);}
                        $value=$preparedSignals->valuePrepared($field,$vehicle,$rules[$field]);
                    }
                    else{$value=self::transform(self::select($vehicle,$bundle['feeds'],$rules[$field],$capture),$rules[$field]);}
                    if (($type==='s' && (!is_string($value)||preg_match('//u',$value)!==1)) || ($type==='n' && ((!is_int($value)&&!is_float($value)) || !is_finite((float)$value) || abs($value)>9007199254740991))) { self::reject('representation_invalid'); }
                    if (in_array($field,array('LastReportTime','LastLocationTime','HOSActivityTime','LastCanbusTime','FuelLevelTime'),true)) { self::timeValue($value); }
                    $values[$field]=$value;
                } catch (ApiException $e) { $rowIssues[$field]=$e->getMessage(); }
            }
            if(isset($values['CWVehicleID']) && (string)$values['CWVehicleID']!==$id){$rowIssues['CWVehicleID']='vehicle_identity_changed';}
            $partial[$key]=$values;
            if ($rowIssues) { $issues[$key]=$rowIssues; }
            else { $mapped[]=Contract::row('vehicles',$values); }
        }
        $missing=count(array_diff_key($scope,$seen));
        return array('tenant'=>$bundle['tenant'],'candidateComplete'=>!$issues&&$missing===0,
            'authorizedCount'=>count($scope),'sourceCount'=>count($vehicles),'outsideScopeCount'=>$outside,'missingAuthorizedCount'=>$missing,
            'rows'=>Contract::rows('vehicles',$mapped),'partial'=>$partial,'issues'=>$issues,
            'mappingVerified'=>false,'productionReady'=>false);
    }
}
