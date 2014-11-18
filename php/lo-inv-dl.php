<?php

//C/K LO FTP Download && File Set UP 
$ftp_host = "50.243.9.241";
$ftp_user_name = "veil";
$ftp_user_pass = "mprf$9sz";

// path to remote file
$remote_file = 'veil.txt';
$local_file = 'veil-imp/lo_stock.txt';

// open some file to write to
$handle = fopen($local_file, 'w');

// set up basic connection
$lo_ftp = ftp_connect($ftp_host) or die("Couldn't connect to $ftp_host");

// login with username and password
$login_result = ftp_login($lo_ftp, $ftp_user_name, $ftp_user_pass);

// try to download $remote_file and save it to $handle
if (ftp_fget($lo_ftp, $handle, $remote_file, FTP_ASCII, 0)) {

"Successfully downloaded $remote_file to $local_file\n";

} else {
 echo "There was a problem while downloading $remote_file to $local_file\n";
}

// close the connection and the file handler
ftp_close($lo_ftp);
fclose($handle);


//C/K


?>