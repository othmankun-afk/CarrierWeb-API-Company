<?php
require_once dirname(__DIR__).'/src/Bootstrap.php';
require_once dirname(__DIR__).'/src/DeploymentGate.php';
\FleetApi\Bootstrap::configureRuntime();
$started = microtime(true);

try {
    $demo = getenv('FLEET_API_DEMO') === '1';
    $local = isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], array('127.0.0.1','::1'), true);
    // External HTTPS recette is opt-in through a private deployment profile.
    // A local test flag alone never enables a non-loopback client.
    $deploymentMode=\FleetApi\DeploymentGate::authorize($_SERVER,$demo,getenv('FLEET_API_LOCAL_TEST')==='1',\FleetApi\DeploymentGate::profileFromEnvironment());
    if($deploymentMode==='server-recette'){
        \FleetApi\DeploymentGate::requireServerLimits();
    }
    if ($demo) {
        require_once dirname(__DIR__).'/demo/fixtures.php';
        $api = new \FleetApi\Api(fleet_demo_accounts(), new \FleetApi\ArrayRepository(array('demo_tenant'=>fleet_demo_rows())), new \FleetApi\FileRateLimiter(dirname(__DIR__).'/var'), array('maxRows'=>1000,'maxResponseBytes'=>1048576,'minIntervalSeconds'=>0));
    } else { $api = \FleetApi\Bootstrap::configuredApi(); }
    $headers = array();
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) { $headers['authorization'] = $_SERVER['HTTP_AUTHORIZATION']; }
    elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) { $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; }
    $response = $api->handle(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '', isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', $headers);
} catch (\FleetApi\ApiException $e) {
    $response = \FleetApi\CarrierwebResponse::unavailable();
} catch (\Exception $e) {
    $response = \FleetApi\CarrierwebResponse::unavailable();
} catch (\Throwable $e) {
    // PHP 7+ runtime errors; PHP 5.6 still parses this unused catch class.
    $response = \FleetApi\CarrierwebResponse::unavailable();
}
$auditPath = getenv('FLEET_API_AUDIT_LOG');
if (is_string($auditPath) && $auditPath !== '') {
    try { \FleetApi\SafeAudit::write($auditPath,$response,(microtime(true)-$started)*1000); }
    catch (\Exception $e) { $response = \FleetApi\CarrierwebResponse::unavailable(); }
}
http_response_code($response['status']);
// expose_php cannot reliably be changed with ini_set on every deployment.
header_remove('X-Powered-By');
foreach ($response['headers'] as $name => $value) { header($name.': '.$value); }
header('Content-Length: '.strlen($response['body']));
echo $response['body'];
