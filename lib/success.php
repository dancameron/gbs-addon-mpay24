<?php
	/**
	 * @author              support@mpay24.com
	 * @version             $Id: success.php 5217 2012-10-16 05:27:43Z anna $
	 * @filesource          success.php
	 * @license             http://ec.europa.eu/idabc/eupl.html EUPL, Version 1.1
	 */

echo "<!DOCTYPE html PUBLIC \"HTML\">
<html>
<head>
<meta http-equiv=\"content-type\" content=\"text/html; charset=UTF-8\">
</head>
<body><textarea rows=\"25\" cols=\"120\">";
$file_handle = fopen("result.txt", "r");
while (!feof($file_handle)) {
   $line = fgets($file_handle);
   echo $line;
}
fclose($file_handle);
echo "</textarea>
<a href='index.html'>Order again!</a>
</body>
</html>";
?>
