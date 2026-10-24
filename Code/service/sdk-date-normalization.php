<?php
// Same datetime path as original cwEAIStorageDB::storeDataInTable. These are
// calls into the pinned SDK, not new sentinel/unit rules in the API mapper.
function serviceSdkMysqlDate($value){
    global $DB_TYPE;$previous=isset($DB_TYPE)?$DB_TYPE:null;$DB_TYPE='mysql';
    if($value!==null){$value=convertToSelectedTimezone($value,'gmt','%Y-%m-%d %H:%M:%S');if($value==='ERROR'){$value=convertToSelectedTimezone('1970-01-02 00:00:00','gmt','%Y-%m-%d %H:%M:%S');}}
    $value=fixType($value,'datetime');$DB_TYPE=$previous;return $value;
}
function serviceSdkNormalizeDates(array $feeds,$backend){
    foreach($feeds as $part=>&$rows){$format=$backend->getStoreFormat($part);foreach($format['fields'] as $field){if(getColumnType($format['table'],$field['name'])!=='datetime'||isset($field['magic']))continue;$column=fixColumnNameForServerData($field['name']);foreach($rows as &$row){if(array_key_exists($column,$row))$row[$column]=serviceSdkMysqlDate($row[$column]);}unset($row);}}unset($rows);return $feeds;
}
