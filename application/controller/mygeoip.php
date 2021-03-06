<?php
header("Access-Control-Allow-Origin: *");
include('../config.php');
include('../model/IXmapsMaxMind.php');

$myIp = $_SERVER['REMOTE_ADDR'];
//$myIp = "186.108.108.134"; // Buenos Aires: TEST
//$myIp = "200.3.149.136"; // Bogota
//$myIp = "128.100.72.189"; // Toronto: TEST
//$myIp = "66.163.72.177"; // Toronto
//$myIp = "4.15.136.14"; // Wichita
//$myIp = "183.89.98.35"; // Wichita

// yikes. If myIp is null the returned JSON is malformed. Adding some error handling, but defaulting to random IP in Toronto still isn't great - open to suggestions
if(ip2long($myIp) === false) {
  $myIp = "66.163.72.177";
}


$mm = new IXmapsMaxMind();
$geoIp = $mm->getGeoIp($myIp);
//print_r($geoIp);
//$geoIpByRadidu = $mm->getGeoDataByPopulationRadius($geoIp);
//print_r($geoIpByRadidu);


$mm->closeDatFiles();

$myCountry = "";
$myCountryName = "";
$myCity = "";
$myAsn = "";
$myIsp = "";
$myLat = "";
$myLong = "";

//print_r($geoIp);

if(isset($geoIp['geoip']['country_code'])){
    $myCountry = $geoIp['geoip']['country_code'];
}
if(isset($geoIp['geoip']['country_name'])){
    $myCountryName = $geoIp['geoip']['country_name'];
}
if(isset($geoIp['geoip']['city'])){
    $myCity = $geoIp['geoip']['city'];
}
if(isset($geoIp['asn'])){
    $myAsn = $geoIp['asn'];
}
if(isset($geoIp['isp'])){
    $myIsp = $geoIp['isp'];
}
if(isset($geoIp['geoip']['latitude'])){
    $myLat = $geoIp['geoip']['latitude'];
}
if(isset($geoIp['geoip']['longitude'])){
    $myLong = $geoIp['geoip']['longitude'];
}

/* Testing find most populated city in a radius*/
if($myCity==""){

}

$result = array(
	"myIp" => $myIp,
	"myCountry" => $myCountry,
	"myCountryName" => $myCountryName,
	"myCity" => $myCity,
	"myAsn" => $myAsn,
	"myIsp" => $myIsp,
	"myLat" => $myLat,
	"myLong" => $myLong
);

echo json_encode($result);
?>