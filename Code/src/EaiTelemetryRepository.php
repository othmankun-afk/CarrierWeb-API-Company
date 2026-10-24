<?php
namespace FleetApi;

// Explicit opt-in LOCAL profile; legacy comparison evidence is not bundled.
// Seven common-event values matched; complete feed coverage and timezone are NOT proved.
// Other EAI endpoints are deliberately unavailable, not guessed transformations.
class EaiTelemetryRepository implements Repository
{
    private $db;
    private $tenant;
    public function __construct($db, $tenant) { $this->db = $db; $this->tenant = $tenant; }

    public static function integer($value)
    {
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value)) { throw new ApiException(503, 'source_invalid'); }
        $digits = $value[0] === '-' ? substr($value, 1) : $value;
        if (strlen($digits) > 16 || (strlen($digits) === 16 && strcmp($digits, '9007199254740991') > 0)) { throw new ApiException(503, 'source_invalid'); }
        // Integers within the safe JSON range remain exact on 32-bit PHP as doubles.
        $number = (float)$value;
        return $number >= (-PHP_INT_MAX - 1) && $number <= PHP_INT_MAX ? (int)$value : $number;
    }

    public function read($tenant, $endpoint, $vehicle, $from, $to, $limit)
    {
        if ($tenant !== $this->tenant || $endpoint !== 'telemetry') { throw new ApiException(503, 'mapping_unverified'); }
        if (!Contract::vehicleId($vehicle) || Contract::inputDate($from) !== $from || Contract::inputDate($to) !== $to || !is_int($limit) || $limit < 1 || $limit > 100000) { throw new ApiException(503, 'source_invalid'); }
        $columns = array();
        foreach (Contract::fields('telemetry') as $name => $type) {
            $columns[] = ($name === 'ID' || $type === 'n') ? 'CAST(`'.$name.'` AS CHAR) AS `'.$name.'`' : '`'.$name.'`';
        }
        // ID tie ordering is a local deterministic choice, not a supplier guarantee.
        $query = $this->db->prepare('SELECT '.implode(',', $columns).' FROM `cwReturnTelemetry` WHERE `CWVehicleID` = ? AND `MeasuredTime` >= ? AND `MeasuredTime` <= ? ORDER BY `MeasuredTime`, `ID` LIMIT '.($limit + 1));
        $query->execute(array($vehicle, $from, $to));
        $rows = array();
        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            if (count($rows) === $limit) { throw new ApiException(413, 'result_too_large'); }
            foreach (Contract::fields('telemetry') as $name => $type) {
                if (!array_key_exists($name, $row)) { throw new ApiException(503, 'source_invalid'); }
                if ($type === 'n') { $row[$name] = self::integer($row[$name]); }
            }
            $row = Contract::row('telemetry', $row);
            if ((string)$row['CWVehicleID'] !== $vehicle || strcmp($row['MeasuredTime'], $from) < 0 || strcmp($row['MeasuredTime'], $to) > 0) { throw new ApiException(503, 'source_invalid'); }
            $rows[] = $row;
        }
        return Contract::rows('telemetry', $rows);
    }
}
