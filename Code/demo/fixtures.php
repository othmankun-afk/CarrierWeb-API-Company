<?php
// Entirely fictitious local data. This is not copied from a customer response.
function fleet_demo_rows()
{
    $data = array();
    foreach (\FleetApi\Contract::schemas() as $endpoint => $unused) {
        $row = array();
        foreach (\FleetApi\Contract::fields($endpoint) as $name => $type) { $row[$name] = $type === 'n' ? 0 : ''; }
        $row['CWVehicleID'] = 7;
        foreach (array('LastLocationTime','LastReportTime','HOSActivityTime','LastCanbusTime','FuelLevelTime') as $field) {
            if (array_key_exists($field, $row)) { $row[$field] = '2030-01-02 10:00:00'; }
        }
        foreach (array('Country'=>'ZZ','Location'=>'FICTITIOUS LOCATION','VehicleDescription'=>'LOCAL DEMO VEHICLE','License'=>'DEMO-ONLY','VehicleNumber'=>'DEMO-7','Zipcode'=>' ') as $field => $value) { if (array_key_exists($field, $row)) { $row[$field] = $value; } }
        foreach (array('Latitude'=>12.34567,'Longitude'=>23.45678,'GPSOdometer'=>12345,'Fuel'=>1234,'FuelLevel'=>456,'Battery'=>2345,'Temperature'=>7.25,'CatalystLevel'=>321,'EngineRunningTime'=>6543) as $field => $value) { if (array_key_exists($field, $row)) { $row[$field] = $value; } }
        if (\FleetApi\Contract::history($endpoint)) {
            $data[$endpoint] = array();
            for ($i = 0; $i < 3; $i++) {
                $copy = $row; $copy['ID'] = '9007199254740993'.(string)$i;
                $copy['MeasuredTime'] = $endpoint === 'canbus' ? '02.01.2030 10:0'.$i.':00' : '2030-01-02 10:0'.$i.':00';
                if ($endpoint === 'telemetry') {
                    $codes = array(153,171,9999); $copy['TelemetryType'] = $codes[$i];
                    $copy['TransponderID'] = 17; $copy['TransponderDescription'] = 'SYNTHETIC SENSOR  ';
                    $copy['Value0'] = 1234 + $i;
                }
                if ($endpoint === 'canbus') { $copy['DriverName'] = '  '; $copy['DriverNumber'] = ''; $copy['Odometer'] = 2147483649.0; }
                $data[$endpoint][] = $copy;
            }
        } else { $data[$endpoint] = array($row); }
    }
    return $data;
}

function fleet_demo_accounts()
{
    return array('demo'=>array('tenant'=>'demo_tenant','tokenSha256'=>hash('sha256','demo-local-token-2026'),'vehicleIds'=>array('7'),'endpoints'=>array_keys(\FleetApi\Contract::schemas())));
}
