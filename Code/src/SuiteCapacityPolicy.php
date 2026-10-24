<?php
namespace FleetApi;

// Capacity is a deployment approval, not an EAI retention rule. No code here
// deletes history, changes a cursor, starts transport or selects a larger quota.
class SuiteCapacityPolicy
{
    const LEGACY_MAX_TENANT_EVENTS=100000;
    private static function invalid(){throw new ApiException(503,'capacity_policy_invalid');}
    private static function integer($value){return is_int($value)&&$value>=1&&$value<=2147483647;}
    private static function text($value,$max){return is_string($value)&&strlen($value)>=1&&strlen($value)<=$max&&trim($value)===$value&&!preg_match('/[\x00-\x1f\x7f]/',$value);}
    public static function normalize(array $policy)
    {
        $keys=array('version','maxTenantEvents','maxTenantPayloadBytes','maxPublicationEvents','warningPercent','evidence');
        if(count($policy)!==count($keys)||array_diff($keys,array_keys($policy))||$policy['version']!=='EAI_SUITE_CAPACITY_V1')self::invalid();
        foreach(array('maxTenantEvents','maxTenantPayloadBytes','maxPublicationEvents') as $key)if(!self::integer($policy[$key]))self::invalid();
        if(!is_int($policy['warningPercent'])||$policy['warningPercent']<1||$policy['warningPercent']>99||$policy['maxPublicationEvents']>$policy['maxTenantEvents']||!is_array($policy['evidence']))self::invalid();
        $e=$policy['evidence'];$keys=array('reference','sha256','approvedBy','approvedUtc','measuredHistoryBytesPerEvent','historyBudgetBytes','safetyMarginPercent');
        if(count($e)!==count($keys)||array_diff($keys,array_keys($e))||!self::text($e['reference'],160)||!self::text($e['approvedBy'],100)||!is_string($e['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$e['sha256']))self::invalid();
        if(!is_string($e['approvedUtc'])||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$e['approvedUtc']))self::invalid();
        $time=strtotime($e['approvedUtc'].' UTC');if($time===false||gmdate('Y-m-d H:i:s',$time)!==$e['approvedUtc'])self::invalid();
        if(!self::integer($e['measuredHistoryBytesPerEvent'])||!self::integer($e['historyBudgetBytes'])||!is_int($e['safetyMarginPercent'])||$e['safetyMarginPercent']<1||$e['safetyMarginPercent']>99)self::invalid();
        // Float arithmetic is intentional: intermediate multiplication may
        // exceed PHP 5.6's 32-bit integer range. Inputs remain exact <=2^31-1.
        $usable=floor(($e['historyBudgetBytes']/100.0)*(100-$e['safetyMarginPercent']));
        if($policy['maxTenantEvents']>floor($usable/$e['measuredHistoryBytesPerEvent'])||$policy['maxTenantPayloadBytes']>$usable)self::invalid();
        return array('version'=>'EAI_SUITE_CAPACITY_V1','maxTenantEvents'=>$policy['maxTenantEvents'],'maxTenantPayloadBytes'=>$policy['maxTenantPayloadBytes'],'maxPublicationEvents'=>$policy['maxPublicationEvents'],'warningPercent'=>$policy['warningPercent'],'evidence'=>array('reference'=>$e['reference'],'sha256'=>$e['sha256'],'approvedBy'=>$e['approvedBy'],'approvedUtc'=>$e['approvedUtc'],'measuredHistoryBytesPerEvent'=>$e['measuredHistoryBytesPerEvent'],'historyBudgetBytes'=>$e['historyBudgetBytes'],'safetyMarginPercent'=>$e['safetyMarginPercent']));
    }
    private static function policy($policy){return $policy===null?null:self::normalize($policy);}
    private static function usageNumber($value){return (is_int($value)||is_float($value))&&is_finite($value)&&$value>=0&&floor($value)==$value&&$value<=9007199254740991.0;}
    private static function usage($count,$payloadBytes){if(!self::usageNumber($count)||!self::usageNumber($payloadBytes))throw new ApiException(503,'suite_capacity_usage_invalid');}
    public static function describe($policy,$count=0,$payloadBytes=0)
    {
        self::usage($count,$payloadBytes);$p=self::policy($policy);$max=$p===null?self::LEGACY_MAX_TENANT_EVENTS:$p['maxTenantEvents'];$byteMax=$p===null?null:$p['maxTenantPayloadBytes'];$warning=$p!==null&&($count>=ceil(($max/100.0)*$p['warningPercent'])||$payloadBytes>=ceil(($byteMax/100.0)*$p['warningPercent']));
        return array('configured'=>$p!==null,'basis'=>$p===null?'LEGACY_RECETTE_SAFETY_GUARD_NOT_PRODUCTION_SIZING':'OPERATOR_APPROVED_CAPACITY_EVIDENCE','storedTenantEvents'=>$count,'maxTenantEvents'=>$max,'remainingEvents'=>max(0,$max-$count),'storedTenantPayloadBytes'=>$payloadBytes,'maxTenantPayloadBytes'=>$byteMax,'remainingPayloadBytes'=>$byteMax===null?null:max(0,$byteMax-$payloadBytes),'maxPublicationEvents'=>$p===null?null:$p['maxPublicationEvents'],'warningPercent'=>$p===null?null:$p['warningPercent'],'warning'=>$warning,'full'=>$count>=$max||($byteMax!==null&&$payloadBytes>=$byteMax),'evidenceReference'=>$p===null?null:$p['evidence']['reference'],'evidenceSha256'=>$p===null?null:$p['evidence']['sha256'],'onLimit'=>'STOP_PUBLICATION_MANUAL_REVIEW_NO_DELETION','deletesStoredData'=>false,'retentionActivated'=>false,'physicalDiskAvailabilityVerified'=>false);
    }
    public static function enforce($policy,$count,$payloadBytes)
    {
        $d=self::describe($policy,$count,$payloadBytes);if($count>$d['maxTenantEvents'])throw new ApiException(503,'suite_history_capacity_review_required');if($d['maxTenantPayloadBytes']!==null&&$payloadBytes>$d['maxTenantPayloadBytes'])throw new ApiException(503,'suite_history_payload_capacity_review_required');return $d;
    }
    public static function preflight($policy,$count,$payloadBytes)
    {
        $d=self::enforce($policy,$count,$payloadBytes);if($d['full'])throw new ApiException(503,'suite_history_capacity_review_required');return $d;
    }
    public static function enforcePage($policy,$mappedPageEvents)
    {
        if(!is_int($mappedPageEvents)||$mappedPageEvents<0)throw new ApiException(503,'suite_capacity_usage_invalid');$p=self::policy($policy);if($p!==null&&$mappedPageEvents>$p['maxPublicationEvents'])throw new ApiException(503,'suite_publication_capacity_review_required');return $mappedPageEvents;
    }
}
