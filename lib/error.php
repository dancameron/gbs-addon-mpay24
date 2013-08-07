<?php
	/**
	 * @author              support@mpay24.com
	 * @version             $Id: error.php 5217 2012-10-16 05:27:43Z anna $
	 * @filesource          error.php
	 * @license             http://ec.europa.eu/idabc/eupl.html EUPL, Version 1.1
	 */

echo "<!DOCTYPE html PUBLIC \"HTML\">
<html>
<head>
<meta http-equiv=\"content-type\" content=\"text/html; charset=UTF-8\">
</head>
<body>";

foreach($_REQUEST as $key => $value)
  echo "$key = " . utf8_encode(urldecode($value)) . "<br/>";

echo "
<a href='index.html'>Order again!</a>
</body>
</html>";
?>
