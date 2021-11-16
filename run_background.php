<?PHP

# Manage batches for a user (until none left in queue)

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$pid = getmypid();

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

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file, $db_conn, $token_key, $token_secret);
if ($oauth->isAuthOK()) {
	print ("$pid - Authorized for " .  $oauth->userinfo->name . "\n") ;
} else {
	print ("$pid - Authorization not working - exiting!\n");
	$db_conn->close();
	exit(1);
}

$batch_mgr = new BatchManager($oauth->userinfo->name);
$batch_mgr->load($db_conn);

if ($batch_mgr->is_running()) {
	# Already running - quit this process!
	$old_pid = $batch_mgr->pid;
	print ("Already running batch manager for " . $oauth->userinfo->name . " - pid $old_pid\n");
	exit(0);
}

$batch_mgr->pid = $pid;
$batch_mgr->save($db_conn);

$db_conn->close();

while (1) {
	$db_conn = $dbtools->openToolDB('authors');
	$batch_id = $batch_mgr->next_batch_id_in_queue($db_conn);
	if ($batch_id == NULL) {
		break;
	}
	$batch = new Batch($batch_id);
	$batch->load($db_conn);
	$batch->remove_from_queue($db_conn);
	$db_conn->close();

	$batch->wait_for_batch($oauth);
}

exit ( 0 ) ;

?>
