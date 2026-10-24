<?php
namespace FleetApi;

// API-owned access policy, not a claim about Carrierweb retention. No deletion,
// upstream transport or cursor changes are performed by this class.
class SuiteHistoryPolicy
{
    // No business or upstream history limit has been established. A deployment
    // must explicitly supply its owner-approved policy, or keep it unconfigured.
    public static function defaults(){return null;}
    public static function normalize(array $policy)
    {
        if(count($policy)!==2||!isset($policy['lookbackDays'],$policy['maxQuerySpanDays']))throw new ApiException(503,'history_policy_invalid');
        // This arithmetic guard is only for 32-bit PHP interoperability, not an
        // EAI retention limit. It never selects or activates a deployment limit.
        foreach(array('lookbackDays','maxQuerySpanDays') as $key){if(!is_int($policy[$key])||$policy[$key]<1||$policy[$key]>24855)throw new ApiException(503,'history_policy_invalid');}
        if($policy['maxQuerySpanDays']>$policy['lookbackDays'])throw new ApiException(503,'history_policy_invalid');
        return array('lookbackDays'=>$policy['lookbackDays'],'maxQuerySpanDays'=>$policy['maxQuerySpanDays']);
    }
    public static function describe($policy,$now=null)
    {
        if($now===null)$now=time();if(!is_int($now)||$now<0)throw new ApiException(503,'history_clock_invalid');
        $p=$policy===null?null:self::normalize($policy);
        return array('configured'=>$p!==null,'basis'=>'API_LOCAL_COLLECTED_DATA_ONLY','lookbackDays'=>$p===null?null:$p['lookbackDays'],'maxQuerySpanDays'=>$p===null?null:$p['maxQuerySpanDays'],'earliestPermittedUtc'=>$p===null?null:gmdate('Y-m-d H:i:s',$now-$p['lookbackDays']*86400),'evaluatedUtc'=>gmdate('Y-m-d H:i:s',$now),'deletesStoredData'=>false,'automaticEaiBackfill'=>false,'upstreamRetentionVerified'=>false);
    }
    public static function window($policy,$from,$to,$now=null)
    {
        $d=self::describe($policy,$now);$now=strtotime($d['evaluatedUtc'].' UTC');
        if($from===null&&$to===null)return array('provided'=>false,'fromUtc'=>null,'toUtc'=>null,'inPolicy'=>null,'rejectionCode'=>null);
        if($from===null||$to===null)throw new ApiException(400,'missing_parameter');
        $from=Contract::inputDate($from);$to=Contract::inputDate($to);$code=null;
        if($d['configured']){
            if(strcmp($from,$to)>0)$code='history_reversed_window';
            elseif(strcmp($from,$d['earliestPermittedUtc'])<0)$code='history_lookback_exceeded';
            elseif(strtotime($to.' UTC')-strtotime($from.' UTC')>$d['maxQuerySpanDays']*86400)$code='history_query_span_exceeded';
            elseif(strtotime($from.' UTC')>$now)$code='history_future_query';
        }
        // Ending later today is allowed: only already-collected rows are read.
        return array('provided'=>true,'fromUtc'=>$from,'toUtc'=>$to,'inPolicy'=>$code===null,'rejectionCode'=>$code);
    }
    public static function enforce($policy,$from,$to,$now=null)
    {
        $w=self::window($policy,$from,$to,$now);if($w['rejectionCode']!==null)throw new ApiException(422,$w['rejectionCode']);return $w;
    }
}
