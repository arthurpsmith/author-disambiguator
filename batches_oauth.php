<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file);

$action = get_request ( 'action' , '' ) ;

$batch_id = get_request ( 'id' , '' ) ;

if ($action == 'authorize') {
	$oauth->doAuthorizationRedirect($oauth_url_prefix . 'batches_oauth.php');
	exit(0);
}

print disambig_header( True );

if ($oauth->isAuthOK()) {
	print "Wikimedia user account: " . $oauth->userinfo->name ;
	print " <span style='font-size:small'>(<a href='logout_oauth.php'>log out</a>)</a>";
} else {
	print "You haven't authorized this application yet: click <a href='?action=authorize'>here</a> to do that, then reload this page.";
}
print "<hr>";

$owner = $oauth->userinfo->name;
$dbtools = new DatabaseTools();
$db_conn = $dbtools->openToolDB('authors');

// Test exec:
// $pid = exec("ls -lR > /dev/null 2>&1 & echo $!");

if ( $batch_id  == '') {
	$dbquery = "SELECT batch_id, start from batches where owner = '$owner' order by start desc";

	$batch_list = array();
	$results = $db_conn->query($dbquery);
	while ($row = $results->fetch_row()) {
		$batch_data = array();
		$batch_data['id'] = $row[0];
		$batch_data['date'] = $row[1];
		$batch_list[] = $batch_data;
	}
	$results->close();

	print "<table><tr><th>Batch ID</th><th>Start time</th></tr>";
	foreach ($batch_list AS $batch_data) {
		$id = $batch_data['id'];
		print "<tr><td><a href='?id=$id'>$id</a></td>";
		print "<td>" . $batch_data['date'] . "</td></tr>";
	}
	print "</table>";
} else {
	$dbquery = "SELECT ordinal, action, status, message, run from commands where batch_id = '$batch_id' order by ordinal";
	$results = $db_conn->query($dbquery);

	print "<table><tr><th>#</th><th>Action</th><th>Status</th><th>Message</th><th>Timestamp</th></tr>";
	while ($row = $results->fetch_row()) {
		$ordinal = $row[0];
		$action = $row[1];
		$status = $row[2];
		$message = $row[3];
		$run_timestamp = $row[4];
		print "<tr><td>$ordinal</td>";
		print "<td>$action</td>";
		print "<td>$status</td>";
		print "<td>$message</td>";
		print "<td>$run_timestamp</td></tr>\n";
	}
	print "</table>";
	$results->close();
}
$db_conn->close();

print_footer() ;

?>
