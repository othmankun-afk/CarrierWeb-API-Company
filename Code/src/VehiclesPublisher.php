<?php
namespace FleetApi;
require_once __DIR__.'/PublishedVehiclesRepository.php';
require_once __DIR__.'/VehiclesLegacyCompatibility.php';

class VehiclesPublisher
{
    public static function accountScope(array $bundle){
        if(!isset($bundle['feeds']['vehicles'])||!is_array($bundle['feeds']['vehicles'])||array_values($bundle['feeds']['vehicles'])!==$bundle['feeds']['vehicles']){throw new ApiException(503,'publication_scope_invalid');}
        $ids=array();foreach($bundle['feeds']['vehicles'] as $vehicle){
            if(!is_array($vehicle)||!isset($vehicle['CWVehicleID'])||(!is_string($vehicle['CWVehicleID'])&&!is_int($vehicle['CWVehicleID']))){throw new ApiException(503,'publication_scope_invalid');}
            $ids[]=(string)$vehicle['CWVehicleID'];
        }
        return PublishedVehiclesRepository::scope($ids);
    }
    public static function prepare(array $bundle,array $rules,array $scope,$maxAge,$scopeMode='explicit-list'){
        $scope=PublishedVehiclesRepository::scope($scope);
        if(!in_array($scopeMode,array('explicit-list','eai-account'),true)){throw new ApiException(503,'publication_scope_invalid');}
        if(isset($bundle['wireContractMode'])){
            if($bundle['wireContractMode']!==VehiclesLegacyCompatibility::MODE){throw new ApiException(503,'wire_contract_mode_invalid');}
            VehiclesLegacyCompatibility::assertRules($rules);
        }
        if($scopeMode==='eai-account'&&self::accountScope($bundle)!==$scope){throw new ApiException(503,'publication_scope_invalid');}
        if(!isset($bundle['capturedUtc'])){throw new ApiException(503,'bundle_invalid');}
        PublishedVehiclesRepository::checkTime($bundle['capturedUtc'],$maxAge);
        $mapped=EaiVehicleMapping::evaluate($bundle,$rules,$scope);
        if(!$mapped['candidateComplete']||count($mapped['rows'])!==count($scope)){throw new ApiException(503,'mapping_incomplete');}
        $payload=json_encode($mapped['rows'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $scopeJson=json_encode($scope);
        if($payload===false||strlen($payload)>16777216){throw new ApiException(503,'publication_size_invalid');}
        return array('tenant'=>$bundle['tenant'],'capturedUtc'=>$bundle['capturedUtc'],'payload'=>$payload,'payloadSha256'=>hash('sha256',$payload),
            'scopeJson'=>$scopeJson,'scopeSha256'=>PublishedVehiclesRepository::digest($scope),'scopeMode'=>$scopeMode,'rowCount'=>count($scope),
            'sourceSha256'=>PublishedVehiclesRepository::digest($bundle),'rulesSha256'=>PublishedVehiclesRepository::digest($rules));
    }
    public static function publish($connection,array $bundle,array $rules,array $scope,$maxAge,$scopeMode='explicit-list'){
        // All parsing/mapping/validation precedes the first database mutation.
        $p=self::prepare($bundle,$rules,$scope,$maxAge,$scopeMode);
        if(!$connection->begin_transaction()){throw new ApiException(503,'publication_write_failed');}
        $statement=null;
        try{
            $lock=$connection->prepare('SELECT captured_utc,payload_sha256,scope_mode FROM api_vehicle_publications WHERE tenant_key = ? FOR UPDATE');
            if(!$lock){throw new ApiException(503,'publication_write_failed');}
            $lock->bind_param('s',$p['tenant']);if(!$lock->execute()){throw new ApiException(503,'publication_write_failed');}
            $result=$lock->get_result();if(!$result){throw new ApiException(503,'publication_write_failed');}$previous=$result->fetch_assoc();$result->free();$lock->close();
            if($previous&&strcmp($previous['captured_utc'],$p['capturedUtc'])>0){throw new ApiException(503,'publication_out_of_order');}
            if($previous&&$previous['captured_utc']===$p['capturedUtc']&&(!hash_equals($previous['payload_sha256'],$p['payloadSha256'])||$previous['scope_mode']!==$p['scopeMode'])){throw new ApiException(503,'publication_conflict');}
            $statement=$connection->prepare('INSERT INTO api_vehicle_publications (tenant_key,captured_utc,payload_json,payload_sha256,scope_json,scope_sha256,scope_mode,row_count,source_sha256,rules_sha256) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE captured_utc=VALUES(captured_utc),payload_json=VALUES(payload_json),payload_sha256=VALUES(payload_sha256),scope_json=VALUES(scope_json),scope_sha256=VALUES(scope_sha256),scope_mode=VALUES(scope_mode),row_count=VALUES(row_count),source_sha256=VALUES(source_sha256),rules_sha256=VALUES(rules_sha256)');
            if(!$statement){throw new ApiException(503,'publication_write_failed');}
            $statement->bind_param('sssssssiss',$p['tenant'],$p['capturedUtc'],$p['payload'],$p['payloadSha256'],$p['scopeJson'],$p['scopeSha256'],$p['scopeMode'],$p['rowCount'],$p['sourceSha256'],$p['rulesSha256']);
            if(!$statement->execute()||!$connection->commit()){throw new ApiException(503,'publication_write_failed');}
            $statement->close();return array('result'=>'PASS','tenant'=>$p['tenant'],'rowCount'=>$p['rowCount'],'payloadSha256'=>$p['payloadSha256'],'sourceSha256'=>$p['sourceSha256'],'rulesSha256'=>$p['rulesSha256'],'capturedUtc'=>$p['capturedUtc'],'customerMappingVerified'=>false);
        }catch(\Exception $e){$connection->rollback();if($statement){$statement->close();}if($e instanceof ApiException){throw $e;}throw new ApiException(503,'publication_write_failed');}
    }
}
