<?php
/*
* Bitstorm SQL - A small and fast Bittorrent tracker
* Copyright 2015 Peter Caprioli
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

 /*************************
 ** Configuration start **
 *************************/

//MySQL details
define('__DB_SERVER', '127.0.0.1');
define('__DB_USERNAME', 'bitstorm');
define('__DB_PASSWORD', 'bitstorm');
define('__DB_DATABASE', 'bitstorm');
define('__DB_TABLE', 'peers');

//Peer announce interval (Seconds)
define('__INTERVAL', 1800);

//Time out if peer is this late to re-announce (Seconds)
define('__TIMEOUT', 60);

//Minimum announce interval (Seconds)
//Most clients honor this, but not all
//This is not enforced server side
define('__INTERVAL_MIN', 600);

//Never encode more than this number of peers in a single request
define('__MAX_PPR', 25);

//Check for expired peers every 1/N request (garbage collection)
define('__CHECK_EXPIRED', 100);

 /***********************
 ** Configuration end **
 ***********************/

//Use the correct content-type
header("Content-type: Text/Plain");
header("Connection: Close");

//No parameters, assume using a browser
if (empty($_GET)) { header("Location: ui.php"); die(); }

//Make sure we have something to use as a key
if (!isset($_GET['key'])) {
	$_GET['key'] = '';
}

/*
$r = array();
for($i=0; $i!=50; $i++) {
	$r[] = array('', '1337', sha1(rand().rand().rand(), true));
}
die(track($r, 0, 0));
*/

//Inputs that are needed, do not continue without these
valdata('peer_id', true);
valdata('port');
valdata('info_hash', true);
valdata('key');

//Connect to the MySQL server
$db = new PDO('mysql:host='.__DB_SERVER.';dbname='.__DB_DATABASE.';charset=utf8', __DB_USERNAME, __DB_PASSWORD);
//$db -> setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );

//Do we have a valid client port?
if (!ctype_digit($_GET['port']) || $_GET['port'] < 1 || $_GET['port'] > 65535) {
	die(track('Invalid client port'));
}

//Clean up expired peers
if (rand(0, __CHECK_EXPIRED) == 0) {
	try {
		$db -> query("DELETE FROM ".__DB_TABLE." WHERE expire<".time());
	} catch (PDOException $e) {
		die(track('Unable to remove old entries:'.$e->getMessage()));
	}
}

//Check if peer has announced before
try {
	$q = $db -> prepare("SELECT * FROM ".__DB_TABLE." WHERE peerId=? AND infoHash=? LIMIT 1");
	$q -> execute(array(bin2hex($_GET['peer_id']), bin2hex($_GET['info_hash'])));
	$client = $q -> fetch();
} catch (PDOException $e) {
	die(track('Unable to check peer status:'.$e->getMessage()));
}

//User agent is required
if (!isset($_SERVER['HTTP_USER_AGENT'])) {
	$_SERVER['HTTP_USER_AGENT'] = "N/A";
}

if ($client === false) { //Peer has not announced before
	//The client wants to unregister, but is not registered
	//Do nothing
	if (isset($_GET['event']) && $_GET['event'] === 'stopped') {
		die(track(array(), 0, 0));
	}

	try {
		//Query to insert a new peer
		$q = $db -> prepare("INSERT INTO ".__DB_TABLE." VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?)");

		//Execute the query
		$q -> execute(array(
			$_SERVER['REMOTE_ADDR'],
			$_GET['port'],
			bin2hex($_GET['peer_id']),
			bin2hex($_GET['info_hash']),
			sha1($_GET['key']),
			substr($_SERVER['HTTP_USER_AGENT'], 0, 80),
			time()+__INTERVAL+__TIMEOUT,
			is_seed()
		));
	} catch (PDOException $e) {
		die(track('Unable to insert new peer:'.$e->getMessage()));
	}

} else { //This peer exists in the database
	if ($client[5] !== sha1($_GET['key'])) { //The key stored in the database does not match the key we received
		die(track('Access denied, your client failed to supply valid credentials'));
	}

	//Did the client stop the torrent?
	if (isset($_GET['event']) && $_GET['event'] === 'stopped') {
		try {
			$q = $db -> prepare("DELETE FROM ".__DB_TABLE." WHERE id=?");
			$q -> execute(array($client[0]));
		} catch (PDOException $e) {
			die(track('Unable to unregister:'.$e->getMessage()));
		}
		die(track(array(), 0, 0)); //Some clients whine if we do not return a bencoded string when unregistering
	}

	//Update peer row

	try {
		$q = $db -> prepare("UPDATE ".__DB_TABLE." SET ipAddress=?, port=?, expire=?, isSeed=? WHERE id=?");
		$q -> execute(array(
			$_SERVER['REMOTE_ADDR'],
			$_GET['port'],
			time()+__INTERVAL+60,
			is_seed(),
			$client[0]
		));
	} catch (PDOException $e) {
		die(track('Unable to unregister:'.$e->getMessage()));
	}
}

//This is the number of peers we will return to the client
$numwant = __MAX_PPR;

if (isset($_GET['numwant']) && ctype_digit($_GET['numwant']) && $_GET['numwant'] < __MAX_PPR && $_GET['numwant'] >= 0) {
	$numwant = (int)$_GET['numwant'];
}

//Get peers with the same infohash
try {
	$q = $db -> prepare("SELECT * FROM ".__DB_TABLE." WHERE infoHash=? AND peerId!=? ORDER BY Rand() LIMIT ".$numwant);
	$q -> execute(array(
		bin2hex($_GET['info_hash']),
		bin2hex($_GET['peer_id'])
	));

} catch (PDOException $e) {
	die(track('Unable to select:'.$e->getMessage()));
}

$reply = array(); //To be encoded and sent to the client

foreach($q->fetchAll() as $r) { //Runs for every client with the same infohash
	$reply[] = array($r[1], $r[2], $r[3]); //ip, port, peerid
}

//Ugly solution
/*
$q = mysql_query("SELECT count(*) FROM ".__DB_TABLE." WHERE infoHash='".mysql_real_escape_string(bin2hex($_GET['info_hash']))."' AND isSeed=1 AND peerId!='".mysql_real_escape_string(bin2hex($_GET['peer_id']))."'") or die(track('Error selecting: '.mysql_error()));
$seeders = mysql_fetch_array($q);

$q = mysql_query("SELECT count(*) FROM ".__DB_TABLE." WHERE infoHash='".mysql_real_escape_string(bin2hex($_GET['info_hash']))."' AND isSeed=0 AND peerId!='".mysql_real_escape_string(bin2hex($_GET['peer_id']))."'") or die(track('Error selecting: '.mysql_error()));
$leechers = mysql_fetch_array($q);
*/

try {
	$d = array(
		bin2hex($_GET['info_hash']),
		bin2hex($_GET['peer_id'])
	);
	$q = $db -> prepare("SELECT count(*) FROM ".__DB_TABLE." WHERE infoHash=? AND isSeed=1 AND peerId!=?");
	$p = $db -> prepare("SELECT count(*) FROM ".__DB_TABLE." WHERE infoHash=? AND isSeed=0 AND peerId!=?");
	$q -> execute($d);
	$p -> execute($d);
	$seeders = $q -> fetch();
	$leechers = $p -> fetch();

} catch (PDOException $e) {
	die(track('Unable to select:'.$e->getMessage()));
}

die(track($reply, $seeders[0], $leechers[0]));

//Find out if we are seeding or not. Assume not if unknown.
function is_seed() {
	if (!isset($_GET['left'])) {
		return '0';
	}
	if ($_GET['left'] == 0) {
		return '1';
	}
	return '0';
}

//Bencoding function, returns a bencoded dictionary
//You may go ahead and enter custom keys in the dictionary in
//this function if you'd like.
function track($list, $c=0, $i=0) {
	if (is_string($list)) { //Did we get a string? Return an error to the client
		return 'd14:failure reason'.strlen($list).':'.$list.'e';
	}
	$p = ''; //Peer directory
	foreach($list as $d) { //Runs for each client
		$pid = '';
		if (!isset($_GET['no_peer_id'])) { //Send out peer_ids in the reply
			$real_id = __hex2bin($d[2]);
			$pid = '7:peer id'.strlen($real_id).':'.$real_id;
		}
		$p .= 'd2:ip'.strlen($d[0]).':'.$d[0].$pid.'4:porti'.$d[1].'ee';
	}
	//Add some other paramters in the dictionary and merge with peer list
	$r = 'd8:intervali'.__INTERVAL.'e12:min intervali'.__INTERVAL_MIN.'e8:completei'.$c.'e10:incompletei'.$i.'e5:peersl'.$p.'ee';
	return $r;
}

//Do some input validation
function valdata($g, $fixed_size=false) {
	if (!isset($_GET[$g])) {
		die(track('Invalid request, missing data'));
	}
	if (!is_string($_GET[$g])) {
		die(track('Invalid request, unknown data type'));
	}
	if ($fixed_size && strlen($_GET[$g]) != 20) {
		die(track('Invalid request, length on fixed argument not correct'));
	}
	if (strlen($_GET[$g]) > 80) { //128 chars should really be enough
		die(track('Request too long'));
	}
}

function __hex2bin($hex) {
	$r = '';
	for ($i=0; $i < strlen($hex); $i+=2) {
		$r .= chr(hexdec($hex{$i}.$hex{($i+1)}));
	}
	return $r;
}
?>
