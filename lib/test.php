<?php
/**
 * @author              support@mpay24.com
 * @version             $Id: test.php 5294 2013-01-17 11:54:45Z anna $
 * @filesource          test.php
 * @license             http://ec.europa.eu/idabc/eupl.html EUPL, Version 1.1
 */

include_once("MPay24Shop.php");

    class MyShop extends MPay24Shop {

    	var $tid = 'My Order';
    	var $price = 0.10;

        function updateTransaction($tid, $args, $shippingConfirmed) {
        	try {
	            $fh = fopen("result.txt", 'w') or die("can't open file");
	
	        	$result = "TID : " . $tid . "\n\n" . sizeof($args) . " transaction arguments:\n\n";
	            foreach($args as $key => $value)
					$result.= $key . " = " . $value . "\n";
	
	            fwrite($fh, $result);
	            fclose($fh);
	            echo "OK:\n the confirmation was successfully recieved";
        	} catch (Exception $e) {
        		echo "ERROR:\n" . $e->getMessage() . "\n" . $e->getTrace();
        	}
        }
        
        function getTransaction($tid) {
        	$transaction = new Transaction($this->tid);
        	$transaction->PRICE = $this->price;
        	return $transaction;
        }
        
        function createProfileOrder($tid) {}
        function createExpressCheckoutOrder($tid) {}
        function createFinishExpressCheckoutOrder($tid, $s, $a, $c) {}
        
        function write_log($operation, $info_to_log) {
          $fh = fopen("log.log", 'a+') or die("can't open file");
          $MessageDate = date("Y-m-d H:i:s");
          $Message= $MessageDate." ".$_SERVER['SERVER_NAME']." mPAY24 : ";
          $result = $Message."$operation : $info_to_log\n";
          fwrite($fh, $result);
          fclose($fh);
        }
        
        function createSecret($tid, $amount, $currency, $timeStamp) {}
        function getSecret($tid) {}

        function createTransaction() {
            $transaction = new Transaction($this->tid);
            $transaction->PRICE = $this->price;
            return $transaction;
        }

        function createMDXI($transaction) {
            $mdxi = new ORDER();

         	$mdxi->Order->setStyle("margin-left: auto; margin-right: auto; width: 600px;");
            
            $mdxi->Order->Tid = $transaction->TID;
            
            $mdxi->Order->Price = $transaction->PRICE;
            
			$mdxi->Order->URL->Success = substr($_SERVER['HTTP_REFERER'], 0, strrpos($_SERVER['HTTP_REFERER'], '/')) . "/success.php";
            $mdxi->Order->URL->Error = substr($_SERVER['HTTP_REFERER'], 0, strrpos($_SERVER['HTTP_REFERER'], '/')) . "/error.php";
            $mdxi->Order->URL->Confirmation = substr($_SERVER['HTTP_REFERER'], 0, strrpos($_SERVER['HTTP_REFERER'], '/')) . "/confirm.php?token=";
           	$mdxi->Order->URL->Cancel = substr($_SERVER['HTTP_REFERER'], 0, strrpos($_SERVER['HTTP_REFERER'], '/')) . "/test.php";
           
           	$myFile = "MDXI.xml";
           	$fh = fopen($myFile, 'w') or die("can't open file");
           	fwrite($fh, $mdxi->toXML());
           	fclose($fh);

           	return $mdxi;
        }
    }


if(isset($_POST["submit"])) {
	$myShop = new MyShop("MerchantID", "Password", TRUE, null, null, TRUE);
	$result = $myShop->pay();

	if($result->getGeneralResponse()->getStatus() == "OK")
		header('Location: ' . $result->getLocation());
	else
		echo "Return Code: " . $result->getGeneralResponse()->getReturnCode();
}
?>