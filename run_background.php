<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$pid = getmypid();

$batch_id = getenv('BATCH_ID');
if (! $batch_id) {
	print("$pid - BATCH_ID environment variable missing - exiting...\n");
	exit(1);
}

$token_key = getenv('TOKEN_KEY');
if (! $token_key) {
	print("$pid - TOKEN_KEY environment variable missing - exiting...\n");
	exit(1);
}

$token_secret = getenv('TOKEN_SECRET');
if (! $token_key) {
	print("$pid - TOKEN_SECRET environment variable missing - exiting...\n");
	exit(1);
}

$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file, $token_key, $token_secret);
if ($oauth->isAuthOK()) {
	print ("$pid - Authorized for " .  $oauth->userinfo->name . "\n") ;
} else {
	print ("$pid - Authorization not working - exiting!\n");
	exit(1);
}

$eg_string = edit_groups_string($batch_id) ;

$dbtools = new DatabaseTools();
$db_conn = $dbtools->openToolDB('authors');
$dbquery = "UPDATE batches SET process_id = $pid WHERE batch_id = '$batch_id'";
$db_conn->query($dbquery);

$cmd_query = "SELECT ordinal, action, data FROM commands WHERE batch_id = '$batch_id' AND (status = 'READY' OR status = 'RUNNING') ORDER BY ordinal";
$results = $db_conn->query($cmd_query);

$actions_to_run = array();
while ($row = $results->fetch_row()) {
	$action_data = array();
	$action_data['ordinal'] = $row[0];
	$action_data['action'] = $row[1];
	$action_data['data'] = $row[2];
	$actions_to_run[] = $action_data;
}
$results->close();

$edit_claims = new EditClaims($oauth);
foreach ($actions_to_run AS $action_data) {
	$ordinal = $action_data['ordinal'];
	$action = $action_data['action'];
	$data = $action_data['data'];
	$running_cmd = "UPDATE commands SET status = 'RUNNING', run = NOW() WHERE batch_id = '$batch_id' and ordinal = $ordinal";
	$db_conn->query($running_cmd);
	$db_conn->close();

	$error = NULL;
	if ($action == 'replace_name') {
		$cmd_parts = array();
		if (preg_match('/^(Q\d+):(Q\d+):(\d+)/', $data, $cmd_parts)) {
			$author_q = $cmd_parts[1];
			$work_qid = $cmd_parts[2];
			$author_num = $cmd_parts[3];
			print ("$pid - Replacing name for $work_qid - $author_num with $author_q\n");
			$result = $edit_claims->replace_name_with_author($work_qid, $author_num, $author_q, "Author Disambiguator set author [[$author_q]] $eg_string");
			if (! $result) {
				$error = $edit_claims->error;
			}
		} else {
			$error = 'Bad data';
		}
	}
	$db_conn = $dbtools->openToolDB('authors');
	$finished_cmd = "UPDATE commands SET status = 'DONE' WHERE batch_id = '$batch_id' and ordinal = '$ordinal'";
	if ($error != NULL) {
		$finished_cmd = "UPDATE commands SET status = 'ERROR', message = '$error' WHERE batch_id = '$batch_id' and ordinal = '$ordinal'";
	}
	$db_conn->query($finished_cmd);
}
$db_conn->close();


exit ( 0 ) ;

?>
