<?php
function instock($qty) 
{
	if($qty >= 1)
	{
		return 1;
	}else{
		return 0;
	}
}
$client = new SoapClient('http://www.adultthemepartystore.com/index.php/api/soap/?wsdl');
$session = $client->login('AdminHermy', 'Chadscoffee.1');

$list = $client->call($session, 'catalog_product.list');

foreach ($list as $mageproduct)
{
	$mageproducts[$mageproduct["sku"]] = $mageproduct["product_id"];
}
$client->endSession($session);

$con = mysqli_connect("localhost","adultthe_jertheb","veilep.Com1!","adultthe_mag");
if (mysqli_connect_errno())
{
	echo "Failed to connect to MySQL: " . mysqli_connect_error();
}

$file = fopen("finale-exp/fininv.csv","r");
fgetcsv($file);
$i = 0;
$u = 0;
while(!feof($file))
{
	$line = fgetcsv($file);
	$id = $mageproducts[$line[0]];
	if ($id)
	{
		$i++;
		if(mysqli_query($con,"UPDATE cataloginventory_stock_item SET qty=".$line[10].", is_in_stock=".instock($line[10])." WHERE product_id=".$id) == false)
		{
			echo 'query failed';
		}
		else
		{
			$u++;
		}
	}
	unset($id);
}
echo $i.' recognized ';
echo $u.' successful querys';
fclose($file);
mysqli_close($con);
?>