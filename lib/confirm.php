<?php
	/**
	 * @author              support@mpay24.com
	 * @version             $Id: confirm.php 5230 2012-10-25 14:42:49Z anna $
	 * @filesource          confirm.php
	 * @license             http://ec.europa.eu/idabc/eupl.html EUPL, Version 1.1
	 */

include("test.php");            

foreach($_GET as $key => $value){
    if($key !== 'TID')
        $args[$key] = $value;
}
    
$myShop = new MyShop("MerchantID", "Password", TRUE, null, null, TRUE);
$myShop->confirm($_GET['TID'], $args);
?>