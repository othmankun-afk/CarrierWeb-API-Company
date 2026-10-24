<?php
namespace FleetApi;

class SafeAudit
{
    public static function category(array $response)
    {
        if ($response['status'] !== 200) { return 'unavailable'; }
        if ($response['body'] === CarrierwebResponse::DATE_ERROR) { return 'date_error'; }
        if ($response['body'] === CarrierwebResponse::CADENCE_ERROR) { return 'rate_limited'; }
        if ($response['headers']['Content-Type'] === 'text/html') { return 'documentation'; }
        return 'data';
    }

    public static function write($path, array $response, $durationMs)
    {
        // Deliberately no request URI, account, IP, credentials or response values.
        $path = Bootstrap::localPath($path);
        if (!is_file($path)) { throw new ApiException(503, 'audit_unavailable'); }
        $entry = array('utc'=>gmdate('Y-m-d\TH:i:s\Z'), 'category'=>self::category($response), 'httpStatus'=>$response['status'], 'durationMs'=>round(max(0,(float)$durationMs),3));
        $line = json_encode($entry)."\n";
        $file = @fopen($path,'ab');
        if (!$file) { throw new ApiException(503, 'audit_unavailable'); }
        if (!flock($file,LOCK_EX | LOCK_NB)) { fclose($file); throw new ApiException(503,'audit_unavailable'); }
        $ok = fwrite($file,$line) === strlen($line) && fflush($file);
        flock($file,LOCK_UN);fclose($file);
        if (!$ok) { throw new ApiException(503,'audit_unavailable'); }
    }
}
