<?php
namespace FleetApi;
require_once __DIR__.'/StrictJson.php';

// API field names and types are documented in Documentation/API_CONTRACT.md.
// This serializer accepts already represented API values. It does not map EAI units.
class ApiException extends \RuntimeException
{
    public $status;
    public function __construct($status, $code) { parent::__construct($code); $this->status = $status; }
}

class Contract
{
    public static function schemas()
    {
        return array(
            'vehiclesBase' => 'CWVehicleID:n VehicleType:n VehicleDescription:s License:s LastLocationTime:s LastReportTime:s Latitude:n Longitude:n Country:s Location:s Zipcode:s CurrentSpeed:n GPSOdometer:n Ignition:n',
            'vehicles' => 'VehicleNumber:s CWVehicleID:n VehicleType:n VehicleDescription:s License:s Owner:s SubcontractorCode:s DivisionID:n DivisionDesc:s LastReportTime:s LastLocationTime:s Latitude:n Longitude:n Country:s Location:s Zipcode:s CurrentSpeed:n GPSOdometer:n TachoOdometer:n LandmarkID:n LandmarkDesc:s CWDriver0ID:n Driver0Name:s Driver0Number:s CWDriver1ID:n Driver1Name:s Driver1Number:s HOSActivityId:n HOSActivityTime:s LastCanbusTime:s CWTrailerID:n Plugged:s PluggedType:s FuelLevel:n FuelLevelTime:s VIN:s Fuel:n Ignition:n Door:s Temperature:n Engine:s Battery:n EngineRunningTime:n EngineRunningTimeHHMM:s',
            'positions' => 'ID:s CWVehicleID:n MeasuredTime:s GpsValid:n Latitude:n Longitude:n Country:s Location:s Zipcode:s Ignition:n CurrentSpeed:n GPSOdometer:n PositionType:n LandmarkID:n',
            'canbus' => 'ID:s CWVehicleID:n MeasuredTime:s CWDriverID:n DriverNumber:s DriverName:s Odometer:n Fuel:n CO2:n FuelLevel:n HighRPM:n HighTorque:n CruiseControl:n Accelerations:n OverSpeed:n BrakeApplications:n HardBrakeApplications:n EngineRollout:n EngineRunningTime:n IdleTime:n IdleFuel:n IdleCO2:n PTOTime:n PTOFuel:n PTOCO2:n GreenZoneRPMFuel:n GreenZoneRPMDistance:n GreenZoneRPMTime:n RetarderDistance:n RetarderTime:n CatalystLevel:n LongIdleTime:n UnprocessedOdometer:n',
            'telemetry' => 'ID:s CWVehicleID:n MeasuredTime:s TransponderID:n TransponderDescription:s TelemetryType:n Value0:n'
        );
    }

    public static function fields($endpoint)
    {
        $schemas = self::schemas();
        if (!isset($schemas[$endpoint])) { throw new ApiException(404, 'not_found'); }
        $fields = array();
        foreach (explode(' ', $schemas[$endpoint]) as $entry) {
            list($name, $type) = explode(':', $entry); $fields[$name] = $type;
        }
        return $fields;
    }

    public static function history($endpoint) { return in_array($endpoint, array('positions','canbus','telemetry'), true); }

    public static function vehicleId($value)
    {
        // Application input domain, not a claim about the supplier's complete range.
        return is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) &&
            (strlen($value) < 10 || strcmp($value, '2147483647') <= 0);
    }

    public static function inputDate($value)
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?: [0-9]{2}:[0-9]{2}(?::[0-9]{2})?)?$/D', $value)) {
            throw new ApiException(400, 'invalid_date');
        }
        if (strlen($value) === 10) { $value .= ' 00:00:00'; }
        elseif (strlen($value) === 16) { $value .= ':00'; }
        $date = \DateTime::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value || substr($value, 0, 4) === '0000') { throw new ApiException(400, 'invalid_date'); }
        return $value;
    }

    public static function eventTime($endpoint, $value)
    {
        if (!is_string($value)) { throw new ApiException(503, 'source_invalid'); }
        if ($endpoint === 'canbus') {
            if (!preg_match('/^([0-9]{2})\.([0-9]{2})\.([0-9]{4}) ([0-9]{1,2}):([0-9]{2}):([0-9]{2})$/D', $value, $m)) { throw new ApiException(503, 'source_invalid'); }
            $canonical = $m[3].'-'.$m[2].'-'.$m[1].' '.str_pad($m[4], 2, '0', STR_PAD_LEFT).':'.$m[5].':'.$m[6];
        } else {
            if (strlen($value) !== 19) { throw new ApiException(503, 'source_invalid'); }
            $canonical = $value;
        }
        try { return self::inputDate($canonical); }
        catch (ApiException $e) { throw new ApiException(503, 'source_invalid'); }
    }

    public static function row($endpoint, $row)
    {
        if (!is_array($row)) { throw new ApiException(503, 'source_invalid'); }
        $result = array();
        foreach (self::fields($endpoint) as $name => $type) {
            if (!array_key_exists($name, $row)) { throw new ApiException(503, 'source_invalid'); }
            $value = $row[$name];
            if ($type === 's') {
                if (!is_string($value) || preg_match('//u', $value) !== 1) { throw new ApiException(503, 'source_invalid'); }
            } elseif ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || abs($value) > 9007199254740991) {
                throw new ApiException(503, 'source_invalid');
            }
            // Do not trim, scale, clamp, decode codes/sentinels or coerce nulls.
            $result[$name] = $value;
        }
        if (!self::vehicleId((string)$result['CWVehicleID']) || floor($result['CWVehicleID']) != $result['CWVehicleID']) { throw new ApiException(503, 'source_invalid'); }
        if ($endpoint === 'vehicles' || $endpoint === 'vehiclesBase') {
            // Validate current-record dates for every repository, including
            // already represented JSON/SQL rows. Preserve valid text unchanged.
            $dates = array('LastReportTime','LastLocationTime');
            if ($endpoint === 'vehicles') {
                $dates = array_merge($dates, array('HOSActivityTime','LastCanbusTime','FuelLevelTime'));
            }
            foreach ($dates as $field) {
                self::eventTime($endpoint, $result[$field]);
            }
        }
        if (self::history($endpoint)) {
            if (!preg_match('/^[0-9]{1,40}$/D', $result['ID'])) { throw new ApiException(503, 'source_invalid'); }
            self::eventTime($endpoint, $result['MeasuredTime']);
        }
        return $result;
    }

    public static function rows($endpoint, $rows)
    {
        if (!is_array($rows) || array_values($rows) !== $rows) { throw new ApiException(503, 'source_invalid'); }
        $result = array(); $seen = array();
        foreach ($rows as $row) {
            $row = self::row($endpoint, $row);
            $key = 'k:'.(self::history($endpoint) ? $row['ID'] : (string)$row['CWVehicleID']);
            if (isset($seen[$key])) { throw new ApiException(503, 'source_invalid'); }
            $seen[$key] = true; $result[] = $row;
        }
        return $result;
    }

    public static function decodeArray($json)
    {
        // Numeric fields are subsequently limited to the exact JSON safe range;
        // IDs must already be quoted strings. Floats support safe integers on x86 PHP.
        if (!is_string($json) || substr(ltrim($json), 0, 1) !== '[') { throw new ApiException(503, 'source_invalid'); }
        try{$value=StrictJson::decode($json,true,64);}catch(JsonDecodeException $e){throw new ApiException(503,'source_invalid');}
        if (!is_array($value)) { throw new ApiException(503, 'source_invalid'); }
        return $value;
    }
}
