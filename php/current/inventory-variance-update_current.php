<?php

// This example shows updating stock levels in Finale 
//   - starts with supplier product id values and corresponding quantity on hand values (an inventory feed)
//   - updates are at a specified sublocation since Finale tracks inventory at sublocation level
//   - uses the facility and product resource to lookup facilityUrl/productUrl from user data
//   - uses the inventory item resource to lookup current quantity on hand to calculate difference between new and old
//   - ignore packing and lot identifiers to keep it simple (if you need an example with packing and lot let the Finale team know)
//   - updates inventory using the inventory variance resource
//
// Replace these variables with appropriate values for your company and user accounts
$host = "https://app.finaleinventory.com";
$authPath = "/demo/api/auth";
$username = "test";
$password = "finale";

// Inventory variance always applies to a single sublocation (demo data - replace with sublocation in your account)
#$sublocationName = "M0";

// Map from supplier product id to new stock level for each product id (demo data, should match supplier product id values in your account)
#$stockLevelUpdate = array("71352" => 19, "2x" => 12);
    
require('./auth.php');

function finale_fetch_resource($auth, $resource) {
  curl_setopt($auth['curl_handle'], CURLOPT_URL, $auth['host'] . $auth['auth_response']->{$resource});
  curl_setopt($auth['curl_handle'], CURLOPT_HTTPGET, true);
  
  $result = curl_exec($auth['curl_handle']);
  
  $status_code = curl_getinfo($auth['curl_handle'], CURLINFO_HTTP_CODE);
  if ($status_code != 200) exit("FAIL: fetch $resource statusCode=$status_code\n");
  return json_decode($result);
}

$auth = finale_auth($host, $authPath, $username, $password);
echo "Authenticated successfully username=".$auth["auth_response"]->name."\n";

// Fetch facility resource and create index from name to facility url
$facilityResp = finale_fetch_resource($auth, 'resourceFacility');
foreach($facilityResp->facilityUrl as $idx => $facilityUrl) {
    $facilityNameLookup[$facilityResp->facilityName[$idx]] = $facilityUrl;
}
echo "Fetch facility resource length=".count($facilityResp->facilityUrl)."\n";
// Fetch product response and create index from supplier id to product url
$productResp = finale_fetch_resource($auth, 'resourceProduct');
foreach($productResp->productUrl as $idx => $productUrl) {
    foreach($productResp->supplierList[$idx] as $supplier) {
        if ($supplier->supplierProductId) {
            $supplierProductIdLookup[$supplier->supplierProductId] = $productUrl;
        }
    }
}
echo "Fetch product resource length=".count($productResp->productUrl)."\n";
// Fetch inventory response and create index from facilityUrl and productUrl to quantity on hand
//   - filter out any items with lots and packing to make things simpler
//   - it is possible for facilityUrl and productUrl pairs to appear multiple times so need to add them together
$iiResp = finale_fetch_resource($auth, 'resourceInventoryItem');
foreach($iiResp->facilityUrl as $idx => $facilityUrl) {
    if ($iiResp->quantityOnHand[$idx] and (!$iiResp->normalizedPackingString[$idx] and (!$iiResp->lotId[$idx]))) {
        $quantityLookup[$facilityUrl][$iiResp->productUrl[$idx]] += $iiResp->quantityOnHand[$idx];
    }
}
echo "Fetch inventory item resource length=".count($iiResp->facilityUrl)."\n";


/* Begin of BSD - Feed from simple http CSV file */

// The inventory variance needs the facilityUrl which we lookup based on the sublocation name
#$facilityUrl = $facilityNameLookup[$sublocationName];
$facilityUrl = $facilityNameLookup["BSD"];

// The body of the inventory variance has a few required fields:
// - facilityUrl to be updated
// - type which is "FACILITY" for a batch stock change and "FACILITY_COUNT" for a stock take
// - statusId which is "PHSCL_INV_COMMITTED" the committed status and "PHSCL_INV_INPUT" for the editable/draft state
// - sessionSecret for CSRF handling (not actually relevant for API calls from server but required since API is shared with browser)
$inventory_variance_body = array(
    "facilityUrl" => $facilityUrl,
    "physicalInventoryTypeId" => "FACILITY",
    "statusId" => "PHSCL_INV_COMMITTED",
    "sessionSecret" => $auth['session_secret']
);

$bsdFile = fopen("https://www.buyseasonsdirect.com/resources/inventoryfeed.csv","r");
$i = 0;
while(!feof($bsdFile)){
	$line = fgetcsv($bsdFile);
	$productUrl = $supplierProductIdLookup[$line[0]];
  $quantityOnHandVar = $line[1] - $quantityLookup[$facilityUrl][$productUrl];
  if ($quantityOnHandVar and ($i < 2000) and ($productUrl !== null)) { // Limiting query to 2000 per location per run
    $inventory_variance_body["inventoryItemVarianceList"][$i++] = array( 
      "quantityOnHandVar" => $quantityOnHandVar,
      "productUrl" => $productUrl, 
      "facilityUrl" => $facilityUrl 
    );
  }
  //if ($productUrl !== null){
  //var_dump($line[0]);
  //}
	unset($productUrl);
	unset($quantityOnHandVar);
}
fclose($bsdFile);

if (count($inventory_variance_body["inventoryItemVarianceList"])) {
  // Post to the top level resource URL to create a new entity
  curl_setopt($auth['curl_handle'], CURLOPT_URL, $auth['host'] . $auth['auth_response']->resourceInventoryVariance);
  curl_setopt($auth['curl_handle'], CURLOPT_POST, true);
  curl_setopt($auth['curl_handle'], CURLOPT_POSTFIELDS, json_encode($inventory_variance_body));
  $result = curl_exec($auth['curl_handle']);
    
  $status_code = curl_getinfo($auth['curl_handle'], CURLINFO_HTTP_CODE);
  if ($status_code != 200) exit("FAIL: inventory variance create error statusCode=$status_code result=$result\n");
  $inventory_variance_create_response = json_decode($result);
  echo "Create inventory variance success count=".count($inventory_variance_create_response->inventoryItemVarianceList)."\n";
	#var_dump($inventory_variance_create_response->inventoryItemVarianceList);
} else {
  echo "Not Creating Inventory Variance for BSD since there are no quantity changes\n";
}


/* Begin of EM - Feed from HTTPS CSV File with Authentication */
unset($inventory_variance_body);
$facilityUrl = $facilityNameLookup["EM"];
$inventory_variance_body = array(
    "facilityUrl" => $facilityUrl, 
    "physicalInventoryTypeId" => "FACILITY", 
    "statusId" => "PHSCL_INV_COMMITTED",
    "sessionSecret" => $auth['session_secret']
);

$emFile = fopen("https://beta:BetaTest@www.elegantmomentslingerie.com/feeds/v1/liveinventory.csv","r");
fgetcsv($emFile);
$i = 0;
while(!feof($emFile)){
	$line = fgetcsv($emFile);
	$productUrl = $supplierProductIdLookup[$line[0]];
  $quantityOnHandVar = $line[6] - $quantityLookup[$facilityUrl][$productUrl];
  if ($quantityOnHandVar and ($i < 2000) and ($productUrl !== null)) {
    $inventory_variance_body["inventoryItemVarianceList"][$i++] = array( 
      "quantityOnHandVar" => $quantityOnHandVar,
      "productUrl" => $productUrl, 
      "facilityUrl" => $facilityUrl 
    );
  }
	unset($productUrl);
	unset($quantityOnHandVar);
}
fclose($emFile);

if (count($inventory_variance_body["inventoryItemVarianceList"])) {
    // Post to the top level resource URL to create a new entity
    curl_setopt($auth['curl_handle'], CURLOPT_URL, $auth['host'] . $auth['auth_response']->resourceInventoryVariance);
    curl_setopt($auth['curl_handle'], CURLOPT_POST, true);
    curl_setopt($auth['curl_handle'], CURLOPT_POSTFIELDS, json_encode($inventory_variance_body));
    $result = curl_exec($auth['curl_handle']);
    $status_code = curl_getinfo($auth['curl_handle'], CURLINFO_HTTP_CODE);
    if ($status_code != 200) exit("FAIL: inventory variance create error statusCode=$status_code result=$result\n");
    $inventory_variance_create_response = json_decode($result);
    echo "EM inventory variance success count=".count($inventory_variance_create_response->inventoryItemVarianceList)."\n";
} else {
    echo "Not Creating Inventory Variance for EM since there are no quantity changes\n";
}


/*  Begin of LO -  Feed provided on FTP in TXT File and Updated Daily */
//  @TODO Only Update this file once per day. File has roughly 12,300 Skus.
//  Easier way to check all 12K and update without processing every cron?

unset($inventory_variance_body);
$facilityUrl = $facilityNameLookup["LO"];
$inventory_variance_body = array(
    "facilityUrl" => $facilityUrl, 
    "physicalInventoryTypeId" => "FACILITY", 
    "statusId" => "PHSCL_INV_COMMITTED",
    "sessionSecret" => $auth['session_secret']
);

// Supplier FTP Connection Info
$ftp_server = "50.243.9.241";
$ftp_conn = ftp_connect($ftp_server) or die("Could not connect to $ftp_server");
$login = ftp_login($ftp_conn,"veil","mprf$9sz");
ftp_get($ftp_conn,"veil.txt","veil.txt", FTP_ASCII);
ftp_close($ftp_conn);
$loFile = fopen("veil.txt","r");
fgetcsv($loFile,0,"\t");
$products = array();
$duplicates = array();

while(!feof($loFile)){
	$line = fgetcsv($loFile,0,"\t");
	if ($products[$line[0]]){
		if($duplicates[$line[0]]){
			$duplicates[$line[0]]++;
		} else {
			$duplicates[$line[0]] = 1;
		}
	} else {
	  $products[$line[0]] = 1;
	}
}
rewind($loFile);
fgetcsv($loFile,0,"\t");
$i = 0;
while(!feof($loFile)){
	$line = fgetcsv($loFile,0,"\t");
	$productUrl = $supplierProductIdLookup[$line[0]];
  $quantityOnHandVar = $line[1] - $quantityLookup[$facilityUrl][$productUrl];
  if ($quantityOnHandVar and ($i < 2000) and ($productUrl !== null) and (!$duplicates[$line[0]])) {
    $inventory_variance_body["inventoryItemVarianceList"][$i++] = array( 
      "quantityOnHandVar" => $quantityOnHandVar,
      "productUrl" => $productUrl, 
      "facilityUrl" => $facilityUrl 
    );
  }
	unset($productUrl);
	unset($quantityOnHandVar);
}
fclose($loFile);

if (count($inventory_variance_body["inventoryItemVarianceList"])) {
    // Post to the top level resource URL to create a new entity
    curl_setopt($auth['curl_handle'], CURLOPT_URL, $auth['host'] . $auth['auth_response']->resourceInventoryVariance);
    curl_setopt($auth['curl_handle'], CURLOPT_POST, true);
    curl_setopt($auth['curl_handle'], CURLOPT_POSTFIELDS, json_encode($inventory_variance_body));
    $result = curl_exec($auth['curl_handle']);
      
    $status_code = curl_getinfo($auth['curl_handle'], CURLINFO_HTTP_CODE);
    if ($status_code != 200) exit("FAIL: inventory variance create error statusCode=$status_code result=$result\n");
    $inventory_variance_create_response = json_decode($result);

    echo "LO inventory variance success count=".count($inventory_variance_create_response->inventoryItemVarianceList)."\n";
} else {
  echo "Not Creating Inventory Variance for LO since there are no quantity changes\n";
}


/* Begin of MO - XML Feed, separated into 1000 lines per file.
// NOTE: Values have been changed to Zero for this vendor temporarily because of shipping problems. Will use again, likely when better ordering is figured out.

// unset $inventory_variance_body and redefine for each new file
for ($x=1; $x<=24; $x++){
	unset($inventory_variance_body);
  $facilityUrl = $facilityNameLookup["MO"];
	$inventory_variance_body = array(
		"facilityUrl" => $facilityUrl, 
		"physicalInventoryTypeId" => "FACILITY", 
		"statusId" => "PHSCL_INV_COMMITTED",
		"sessionSecret" => $auth['session_secret']
	);

	$xml=simplexml_load_file("http://morris.morriscostumes.com/out/available_batchnynyy_".str_pad($x,3,'0',STR_PAD_LEFT).".xml");
	foreach($xml->Available as $available)	{
		$productUrl = $supplierProductIdLookup[(string)$available->Part];
		$quantityOnHandVar = (int)$available->Qty - $quantityLookup[$facilityUrl][$productUrl];
		if ($quantityOnHandVar and ($productUrl !== null)) {
			$inventory_variance_body["inventoryItemVarianceList"][$i++] = array( 
				"quantityOnHandVar" => $quantityOnHandVar,
				"productUrl" => $productUrl, 
				"facilityUrl" => $facilityUrl 
			);
		}
		unset($productUrl);
		unset($quantityOnHandVar);
	}
	
	if (count($inventory_variance_body["inventoryItemVarianceList"])) {
		// Post to the top level resource URL to create a new entity
		curl_setopt($auth['curl_handle'], CURLOPT_URL, $auth['host'] . $auth['auth_response']->resourceInventoryVariance);
		curl_setopt($auth['curl_handle'], CURLOPT_POST, true);
		curl_setopt($auth['curl_handle'], CURLOPT_POSTFIELDS, json_encode($inventory_variance_body));
		$result = curl_exec($auth['curl_handle']);
		  
		$status_code = curl_getinfo($auth['curl_handle'], CURLINFO_HTTP_CODE);
		if ($status_code != 200) exit("FAIL: inventory variance create error statusCode=$status_code result=$result\n");
		$inventory_variance_create_response = json_decode($result);

		echo "MO".$x." inventory variance success count=".count($inventory_variance_create_response->inventoryItemVarianceList)."\n";
	} else {
		echo "Not Creating Inventory Variance for MO since there are no quantity changes\n";
	}
}
// END OF MO */


/* Start of BW - XLS Feed on http, requires PHPEXCEL CLASSES to be included (in separate folder) */
unset($inventory_variance_body);
$facilityUrl = $facilityNameLookup["BW"];
$inventory_variance_body = array(
    "facilityUrl" => $facilityUrl, 
    "physicalInventoryTypeId" => "FACILITY", 
    "statusId" => "PHSCL_INV_COMMITTED",
    "sessionSecret" => $auth['session_secret']
);

file_put_contents("Bewicked_stock.xls",fopen("http://www.bewickedcostumes.com/download/Bewicked_stock.xls","r"));
include 'C:\php\Classes\PHPExcel.php';
$fileType = PHPExcel_IOFactory::identify("Bewicked_stock.xls");
$objReader = PHPExcel_IOFactory::createReader($fileType);
$objReader->setReadDataOnly(true);   
$objPHPExcel = $objReader->load("Bewicked_stock.xls");
$sheetData = $objPHPExcel->getActiveSheet()->toArray(null,true,true,false);

$i = 0;
foreach($sheetData as $row){
	$productUrl = $supplierProductIdLookup[$row[0]];
  $quantityOnHandVar = $row[10] - $quantityLookup[$facilityUrl][$productUrl];
  if ($quantityOnHandVar and ($i < 2000) and ($productUrl !== null)) {
    $inventory_variance_body["inventoryItemVarianceList"][$i++] = array( 
      "quantityOnHandVar" => $quantityOnHandVar,
      "productUrl" => $productUrl, 
      "facilityUrl" => $facilityUrl 
    );
  }
	unset($productUrl);
	unset($quantityOnHandVar);
}

if (count($inventory_variance_body["inventoryItemVarianceList"])) {
    // Post to the top level resource URL to create a new entity
    curl_setopt($auth['curl_handle'], CURLOPT_URL, $auth['host'] . $auth['auth_response']->resourceInventoryVariance);
    curl_setopt($auth['curl_handle'], CURLOPT_POST, true);
    curl_setopt($auth['curl_handle'], CURLOPT_POSTFIELDS, json_encode($inventory_variance_body));
    $result = curl_exec($auth['curl_handle']);    
    $status_code = curl_getinfo($auth['curl_handle'], CURLINFO_HTTP_CODE);
    if ($status_code != 200) exit("FAIL: inventory variance create error statusCode=$status_code result=$result\n");
    $inventory_variance_create_response = json_decode($result);

    echo "BW inventory variance success count=".count($inventory_variance_create_response->inventoryItemVarianceList)."\n";
} else {
    echo "Not Creating Inventory Variance for BW since there are no quantity changes\n";
}
?>