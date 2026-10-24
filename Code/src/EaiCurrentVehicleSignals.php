<?php
namespace FleetApi;
require_once __DIR__.'/Contract.php';
require_once __DIR__.'/EaiVehicleSignals.php';

// Six current-vehicle signals follow SDK Appendix P and retain the API field types.
// Missing or ambiguous measurements use the configured unknown markers;
// invalid source identity, time, scope or policy causes an error.
class EaiCurrentVehicleSignals
{
    const POLICY = 'documented-eai-v1';
    const UNKNOWN_POLICY = 'legacy-markers-v1';
    const MAX_ROWS = 50000;

    // One evaluation owns this context. There is deliberately no static cache:
    // another tenant, request or changed bundle must build its own index.
    private $preparedBundle;
    private $preparedScope = null;
    private $preparedScopeError = null;
    private $preparedSources = null;
    private $preparedSourceErrors = array();
    private $preparedGlobalError = null;

    private function __construct(array $bundle) { $this->preparedBundle = $bundle; }

    public static function prepare(array $bundle) { return new self($bundle); }

    private static function fail($reason) { throw new ApiException(503, $reason); }

    private static function fields()
    {
        return array('Door','Temperature','Battery','Engine','Plugged','PluggedType');
    }

    public static function selected(array $rule)
    {
        return (isset($rule['source']) && $rule['source'] === 'eai_signals') ||
            (isset($rule['operation']) && $rule['operation'] === 'eai_current_signal');
    }

    public static function rules($tenant)
    {
        if (!is_string($tenant) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tenant)) { self::fail('eai_signal_rule_invalid'); }
        $rule = array('source'=>'eai_signals','operation'=>'eai_current_signal','policy'=>self::POLICY,
            'tenant'=>$tenant,'selection'=>'unique-physical-source','anchor'=>'capturedUtc',
            'maxAgeSeconds'=>null,'unknownPolicy'=>self::UNKNOWN_POLICY);
        $rules = array();
        foreach (self::fields() as $field) { $rules[$field] = $rule; }
        return $rules;
    }

    private static function time($raw, $reason)
    {
        try { return Contract::eventTime('vehicles', $raw); }
        catch (ApiException $e) { self::fail($reason); }
    }

    public static function validateRule($field, array $bundle, array $rule)
    {
        if (!in_array($field, self::fields(), true)) { self::fail('eai_signal_rule_invalid'); }
        $keys = array_keys($rule); sort($keys);
        $expected = array('source','operation','policy','tenant','selection','anchor','maxAgeSeconds','unknownPolicy'); sort($expected);
        if ($keys !== $expected || $rule['source'] !== 'eai_signals' || $rule['operation'] !== 'eai_current_signal' ||
            $rule['policy'] !== self::POLICY || !is_string($rule['tenant']) ||
            !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $rule['tenant']) ||
            $rule['selection'] !== 'unique-physical-source' || $rule['anchor'] !== 'capturedUtc' ||
            $rule['maxAgeSeconds'] !== null || $rule['unknownPolicy'] !== self::UNKNOWN_POLICY) {
            self::fail('eai_signal_rule_invalid');
        }
        if (!isset($bundle['tenant'],$bundle['timeBasis'],$bundle['capturedUtc']) ||
            !is_string($bundle['tenant']) || $bundle['tenant'] !== $rule['tenant'] || $bundle['timeBasis'] !== 'UTC') {
            self::fail('eai_signal_bundle_invalid');
        }
        self::time($bundle['capturedUtc'], 'eai_signal_bundle_invalid');
    }

    private static function id($raw)
    {
        if (is_int($raw)) { $raw = (string)$raw; }
        if (!is_string($raw) || !preg_match('/^(?:0|[1-9][0-9]{0,39})$/D', $raw)) { self::fail('eai_signal_source_id_invalid'); }
        return $raw;
    }

    private static function vehicleId($raw)
    {
        $id = self::id($raw);
        if (!Contract::vehicleId($id)) { self::fail('eai_signal_source_id_invalid'); }
        return $id;
    }

    private static function rows($rows, $limit)
    {
        if (!is_array($rows) || array_values($rows) !== $rows || count($rows) > $limit) { self::fail('eai_signal_feed_invalid'); }
        return $rows;
    }

    private static function types($field)
    {
        if ($field === 'Door') { return array('60','61','73','74'); }
        if ($field === 'Temperature') { return array('50','51','174','180'); }
        if ($field === 'Battery') { return array('153','157'); }
        if ($field === 'Engine') { return array('183'); }
        return array('70','71','72','75','76','77');
    }

    private static function marker($field)
    {
        if ($field === 'Temperature' || $field === 'Battery') { return -1000; }
        return $field === 'PluggedType' ? ' ' : '-';
    }

    private static function result($field, $value, $status, $reason, $source, $age)
    {
        return array('value'=>$value,'status'=>$status,'reason'=>$reason,'source'=>$source,
            'measurementAgeSeconds'=>$age,'isLegacyProviderParity'=>false);
    }

    private static function unknown($field, $reason, $source = null, $age = null)
    {
        return self::result($field, self::marker($field), 'UNKNOWN', $reason, $source, $age);
    }

    private static function age($measured, $capture)
    {
        $a = new \DateTime($measured, new \DateTimeZone('UTC'));
        $b = new \DateTime($capture, new \DateTimeZone('UTC'));
        $delta = $a->diff($b);
        return $delta->days * 86400 + $delta->h * 3600 + $delta->i * 60 + $delta->s;
    }

    private static function add(array &$groups, $key, array $candidate)
    {
        if (!isset($groups[$key]) || strcmp($candidate['source']['measuredUtc'], $groups[$key]['time']) > 0) {
            $groups[$key] = array('time'=>$candidate['source']['measuredUtc'],'candidates'=>array($candidate));
        } elseif ($candidate['source']['measuredUtc'] === $groups[$key]['time']) {
            // Preserve latest ties even when values agree; input order and IDs
            // never establish which simultaneous event is authoritative.
            $groups[$key]['candidates'][] = $candidate;
        }
    }

    private function indexedScope(array $vehicle)
    {
        // Keep the old error order: requested identity precedes fleet validity.
        $id = self::vehicleId(isset($vehicle['CWVehicleID']) ? $vehicle['CWVehicleID'] : null);
        if ($this->preparedScope === null) {
            $this->preparedScope = array();
            try {
                $bundle = $this->preparedBundle;
                if (!isset($bundle['feeds']) || !is_array($bundle['feeds']) || !array_key_exists('vehicles', $bundle['feeds'])) { self::fail('eai_signal_bundle_invalid'); }
                foreach (self::rows($bundle['feeds']['vehicles'], 10000) as $row) {
                    if (!is_array($row)) { self::fail('eai_signal_feed_invalid'); }
                    $rowId = self::vehicleId(isset($row['CWVehicleID']) ? $row['CWVehicleID'] : null);
                    if (isset($this->preparedScope['id:'.$rowId])) { self::fail('eai_signal_vehicle_duplicate'); }
                    $this->preparedScope['id:'.$rowId] = true;
                }
            } catch (ApiException $e) { $this->preparedScopeError = $e->getMessage(); }
        }
        if ($this->preparedScopeError !== null) { self::fail($this->preparedScopeError); }
        if (!isset($this->preparedScope['id:'.$id])) { self::fail('eai_signal_vehicle_outside_scope'); }
        return $id;
    }

    private function indexSources()
    {
        $this->preparedSources = array(); $position = 0; $bundle = $this->preparedBundle;
        foreach (array('telemetry','wiredtelemetry') as $part) {
            if (!array_key_exists($part, $bundle['feeds'])) { continue; }
            $position++;
            try { $rows = self::rows($bundle['feeds'][$part], self::MAX_ROWS); }
            catch (ApiException $e) { $this->preparedGlobalError = array('position'=>$position,'reason'=>$e->getMessage()); return; }
            foreach ($rows as $row) {
                $position++;
                // Row shape and vehicle identity were checked even for foreign
                // vehicles. Their event/time/channel data were NOT checked.
                try {
                    if (!is_array($row)) { self::fail('eai_signal_feed_invalid'); }
                    $vehicleId = self::vehicleId(isset($row['CWVehicleID']) ? $row['CWVehicleID'] : null);
                } catch (ApiException $e) { $this->preparedGlobalError = array('position'=>$position,'reason'=>$e->getMessage()); return; }
                if (!isset($this->preparedScope['id:'.$vehicleId]) || isset($this->preparedSourceErrors['id:'.$vehicleId])) { continue; }
                try {
                    $event = self::id(isset($row['ID']) ? $row['ID'] : null);
                    $partition = array_key_exists('CWMergeTableID', $row) ? self::id($row['CWMergeTableID']) : null;
                    $measured = self::time(isset($row['MeasuredTime']) ? $row['MeasuredTime'] : null, 'eai_signal_event_time_invalid');
                    if (strcmp($measured, $bundle['capturedUtc']) > 0) { self::fail('eai_signal_event_after_capture'); }
                    $base = array('source'=>$part,'vehicleId'=>$vehicleId,'id'=>$event,'partition'=>$partition,
                        'measuredUtc'=>$measured,'transponderId'=>null,'telemetrySource'=>null,'channel'=>null);
                    $entries = array();
                    if ($part === 'telemetry') {
                        $transponder = self::id(isset($row['TransponderID']) ? $row['TransponderID'] : null);
                        $type = self::id(isset($row['TelemetryType']) ? $row['TelemetryType'] : null);
                        $source = $base; $source['transponderId'] = $transponder; $source['telemetryType'] = $type;
                        $entries[] = array('key'=>'telemetry|'.$transponder.'|'.$type,'source'=>$source,'row'=>$row,'column'=>'Value0','type'=>$type);
                    } else {
                        $telemetrySource = self::id(isset($row['TelemetrySource']) ? $row['TelemetrySource'] : null);
                        for ($channel = 0; $channel < 100; $channel++) {
                            $column = 'TelemetryType'.$channel;
                            if (!array_key_exists($column, $row)) { continue; }
                            $type = self::id($row[$column]);
                            $source = $base; $source['telemetrySource'] = $telemetrySource; $source['channel'] = $channel; $source['telemetryType'] = $type;
                            $entries[] = array('key'=>'wiredtelemetry|'.$telemetrySource.'|'.$channel.'|'.$type,'source'=>$source,'row'=>$row,'column'=>'Value'.$channel,'type'=>$type);
                        }
                    }
                    if (!isset($this->preparedSources['id:'.$vehicleId])) { $this->preparedSources['id:'.$vehicleId] = array(); }
                    foreach ($entries as $entry) {
                        // Retain only the latest ties per physical source, as
                        // the original selector did, not every old source row.
                        // Unsupported types were still validated above.
                        if (!in_array($entry['type'], array('60','61','73','74','50','51','174','180','153','157','183','70','71','72','75','76','77'), true)) { continue; }
                        $key = $entry['key']; unset($entry['key']); self::add($this->preparedSources['id:'.$vehicleId], $key, $entry);
                    }
                } catch (ApiException $e) { $this->preparedSourceErrors['id:'.$vehicleId] = array('position'=>$position,'reason'=>$e->getMessage()); }
            }
        }
    }

    private function indexedCandidates($field, $vehicleId)
    {
        if ($this->preparedSources === null) { $this->indexSources(); }
        $local = isset($this->preparedSourceErrors['id:'.$vehicleId]) ? $this->preparedSourceErrors['id:'.$vehicleId] : null;
        $global = $this->preparedGlobalError;
        // Eagerly discovering a later global fault must not replace an earlier
        // event fault for this vehicle. Errors are deferred until resolution.
        if ($local !== null && ($global === null || $local['position'] < $global['position'])) { self::fail($local['reason']); }
        if ($global !== null) { self::fail($global['reason']); }
        $groups = array(); $types = self::types($field);
        if (isset($this->preparedSources['id:'.$vehicleId])) {
            foreach ($this->preparedSources['id:'.$vehicleId] as $key=>$group) {
                if (in_array($group['candidates'][0]['type'], $types, true)) { $groups[$key] = $group; }
            }
        }
        return $groups;
    }

    public static function resolve($field, array $vehicle, array $bundle, array $rule)
    {
        return self::prepare($bundle)->resolvePrepared($field, $vehicle, $rule);
    }

    public function resolvePrepared($field, array $vehicle, array $rule)
    {
        $bundle = $this->preparedBundle;
        self::validateRule($field, $bundle, $rule);
        $vehicleId = $this->indexedScope($vehicle);
        $groups = $this->indexedCandidates($field, $vehicleId);
        if (!$groups) { return self::unknown($field, 'source_missing'); }
        if (count($groups) !== 1) { return self::unknown($field, 'source_ambiguous'); }
        $group = reset($groups);
        if (count($group['candidates']) !== 1) { return self::unknown($field, 'latest_event_ambiguous'); }
        $candidate = $group['candidates'][0]; $source = $candidate['source']; $row = $candidate['row'];
        $age = self::age($source['measuredUtc'], $bundle['capturedUtc']); $column = $candidate['column']; $type = $candidate['type'];
        if (!array_key_exists($column, $row) || $row[$column] === null ||
            (in_array($type, array('70','75'), true) && (!array_key_exists('MinValue', $row) || $row['MinValue'] === null))) {
            return self::unknown($field, 'measurement_missing', $source, $age);
        }
        try {
            if (in_array($type, array('70','75'), true)) {
                $raw = EaiVehicleSignals::integer($row[$column]);
                $minimum = EaiVehicleSignals::integer($row['MinValue']);
                $value = $raw >= $minimum;
            } else {
                $decoded = EaiVehicleSignals::decode($type, $row[$column]);
                $value = $decoded['value'];
                if ($field === 'Battery') { $value = $decoded['rawEaiValue']; }
                elseif ($field === 'Door') { $value = $value ? 'OPENED' : 'CLOSED'; }
                elseif ($field === 'Engine') { $value = $value > 0 ? 'ON' : 'OFF'; }
            }
            if ($field === 'Plugged' || $field === 'PluggedType') {
                $labels = array('70'=>'SENSITE_RSSI','71'=>'QUANTA','72'=>'MDT','75'=>'SENSOR_NAME','76'=>'REEFER','77'=>'HITCH');
                $value = $field === 'PluggedType' ? $labels[$type] : ($value ? 'HITCHED' : 'UNHITCHED');
            }
        } catch (ApiException $e) {
            $reason = $e->getMessage() === 'signal_measurement_unknown' ? 'measurement_unknown' :
                ($e->getMessage() === 'signal_sensor_error' ? 'sensor_error' : 'measurement_invalid');
            return self::unknown($field, $reason, $source, $age);
        }
        return self::result($field, $value, 'KNOWN_LAST_REPORTED', 'unique_physical_source_latest', $source, $age);
    }

    public static function value($field, array $vehicle, array $bundle, array $rule)
    {
        $result = self::resolve($field, $vehicle, $bundle, $rule);
        return $result['value'];
    }

    public function valuePrepared($field, array $vehicle, array $rule)
    {
        $result = $this->resolvePrepared($field, $vehicle, $rule);
        return $result['value'];
    }
}
