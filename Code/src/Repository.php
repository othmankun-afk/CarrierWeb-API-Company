<?php
namespace FleetApi;

interface Repository
{
    public function read($tenant, $endpoint, $vehicle, $from, $to, $limit);
}

// A dynamic account scope must come from the SAME validated publication as
// the returned rows. Generic JSON, array and history repositories cannot grant it.
interface PublishedVehicleScopeRepository extends Repository
{
    public function supportsVehicleAccountScope($tenant);
    public function verifiedVehicleScope($tenant);
}

class ArrayRepository implements Repository
{
    private $data;
    public function __construct(array $data) { $this->data = $data; }
    public function read($tenant, $endpoint, $vehicle, $from, $to, $limit)
    {
        if (!isset($this->data[$tenant]) || !array_key_exists($endpoint, $this->data[$tenant])) { throw new ApiException(503, 'source_unavailable'); }
        $rows = Contract::rows($endpoint, $this->data[$tenant][$endpoint]);
        if (Contract::history($endpoint)) {
            $selected = array();
            foreach ($rows as $row) {
                $time = Contract::eventTime($endpoint, $row['MeasuredTime']);
                if ((string)$row['CWVehicleID'] === $vehicle && strcmp($time, $from) >= 0 && strcmp($time, $to) <= 0) { $selected[] = $row; }
            }
            usort($selected, function ($a, $b) use ($endpoint) {
                $cmp = strcmp(Contract::eventTime($endpoint, $a['MeasuredTime']), Contract::eventTime($endpoint, $b['MeasuredTime']));
                return $cmp !== 0 ? $cmp : strcmp($a['ID'], $b['ID']);
            });
            $rows = $selected;
        }
        if (count($rows) > $limit) { throw new ApiException(413, 'result_too_large'); }
        return $rows;
    }
}

class JsonFileRepository implements Repository
{
    private $files;
    private $maxBytes;
    public function __construct(array $files, $maxBytes) { $this->files = $files; $this->maxBytes = $maxBytes; }
    public function read($tenant, $endpoint, $vehicle, $from, $to, $limit)
    {
        if (!isset($this->files[$tenant])) { throw new ApiException(503, 'source_unavailable'); }
        $path = $this->files[$tenant];
        if (!is_string($path) || strpos($path, '://') !== false || !is_file($path)) { throw new ApiException(503, 'source_unavailable'); }
        $stream = @fopen($path, 'rb');
        if (!$stream) { throw new ApiException(503, 'source_unavailable'); }
        $text = stream_get_contents($stream, $this->maxBytes + 1); fclose($stream);
        if (!is_string($text) || strlen($text) > $this->maxBytes) { throw new ApiException(503, 'source_too_large'); }
        try{$document=StrictJson::decode($text,false,64);}catch(JsonDecodeException $e){throw new ApiException(503,'source_invalid');}
        if (!($document instanceof \stdClass) || !isset($document->tenant) || $document->tenant !== $tenant || !isset($document->endpoints) || !($document->endpoints instanceof \stdClass)) { throw new ApiException(503, 'source_invalid'); }
        if (!property_exists($document->endpoints, $endpoint)) { throw new ApiException(503, 'source_unavailable'); }
        $input = $document->endpoints->$endpoint;
        if (!is_array($input)) { throw new ApiException(503, 'source_invalid'); }
        $rows = array();
        foreach ($input as $row) {
            if (!($row instanceof \stdClass)) { throw new ApiException(503, 'source_invalid'); }
            $rows[] = (array)$row;
        }
        $repository = new ArrayRepository(array($tenant => array($endpoint => $rows)));
        return $repository->read($tenant, $endpoint, $vehicle, $from, $to, $limit);
    }
}

// Reads only the dedicated API representation tables, never guessed EAI columns.
// Supply a SELECT-only PDO connection; the connection is injectable for query tests.
class PdoRepository implements Repository
{
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function read($tenant, $endpoint, $vehicle, $from, $to, $limit)
    {
        try {
            if (!Contract::history($endpoint)) {
                $query = $this->db->prepare('SELECT payload_json FROM api_snapshots WHERE tenant_key = ? AND endpoint = ?');
                $query->execute(array($tenant, $endpoint));
                $record = $query->fetch(\PDO::FETCH_ASSOC);
                if (!$record) { throw new ApiException(503, 'source_unavailable'); }
                $rows = Contract::rows($endpoint, Contract::decodeArray($record['payload_json']));
            } else {
                // The dataset must have been deliberately provisioned; missing != empty.
                $query = $this->db->prepare('SELECT dataset_ready FROM api_history_state WHERE tenant_key = ? AND endpoint = ?');
                $query->execute(array($tenant, $endpoint)); $state = $query->fetch(\PDO::FETCH_ASSOC);
                if (!$state || (string)$state['dataset_ready'] !== '1') { throw new ApiException(503, 'source_unavailable'); }
                $query = $this->db->prepare('SELECT vehicle_id, measured_at, record_id, payload_json FROM api_history WHERE tenant_key = ? AND endpoint = ? AND vehicle_id = ? AND measured_at >= ? AND measured_at <= ? ORDER BY measured_at, record_id LIMIT '.(int)($limit + 1));
                $query->execute(array($tenant, $endpoint, $vehicle, $from, $to));
                $rows = array();
                while ($record = $query->fetch(\PDO::FETCH_ASSOC)) {
                    if (count($rows) === $limit) { throw new ApiException(413, 'result_too_large'); }
                    try{$row=StrictJson::decode($record['payload_json'],true,64);}catch(JsonDecodeException $e){throw new ApiException(503,'source_invalid');}
                    $row = Contract::row($endpoint, $row);
                    if ((string)$record['vehicle_id'] !== $vehicle || (string)$row['CWVehicleID'] !== $vehicle || $record['record_id'] !== $row['ID'] || $record['measured_at'] !== Contract::eventTime($endpoint, $row['MeasuredTime']) || strcmp($record['measured_at'], $from) < 0 || strcmp($record['measured_at'], $to) > 0) { throw new ApiException(503, 'source_invalid'); }
                    $rows[] = $row;
                }
                $rows = Contract::rows($endpoint, $rows);
            }
            if (count($rows) > $limit) { throw new ApiException(413, 'result_too_large'); }
            return $rows;
        } catch (ApiException $e) { throw $e; }
        catch (\Exception $e) { throw new ApiException(503, 'source_unavailable'); }
    }
}
