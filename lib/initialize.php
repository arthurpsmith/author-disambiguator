<?PHP

# Block specific user agents...

if (isset($_SERVER['HTTP_USER_AGENT'])) {
	$ua = $_SERVER['HTTP_USER_AGENT'];
	$blocklist = ['Mb2345Browser', 'LieBaoFast', 'MicroMessenger', 'UCBrowser'];
	foreach ($blocklist AS $blocked_ua) {
		if (strpos($ua, $blocked_ua) !== false) {
			http_response_code(403);
			print "Your user-agent is blocked from this service due to abuse. File a github issue if you think this was in error.";
			exit(0);
		}
	}
}

error_reporting(E_ALL);
ini_set('display_errors', 'On');
ini_set('memory_limit','1500M');
set_time_limit ( 60 * 10 ) ; // Seconds

setlocale(LC_CTYPE, 'en_US.UTF-8'); // Required for iconv to work

require_once ( __DIR__ . '/../magnustools/common.php' ) ;
require_once ( __DIR__ . '/../magnustools/wikidata.php' ) ;
require_once ( __DIR__ . '/../lib/wikidata_claims.php' ) ;
require_once ( __DIR__ . '/../lib/edit_claims.php' ) ;
require_once ( __DIR__ . '/../lib/article_model.php' ) ;
require_once ( __DIR__ . '/../lib/article_model2.php' ) ;
require_once ( __DIR__ . '/../lib/cluster.php' ) ;
require_once ( __DIR__ . '/../lib/clustering.php' ) ;
require_once ( __DIR__ . '/../lib/clustering2.php' ) ;
require_once ( __DIR__ . '/../lib/qs_commands.php' ) ;
require_once ( __DIR__ . '/../lib/author_data.php' ) ;
require_once ( __DIR__ . '/../lib/compare_lists.php' ) ;
require_once ( __DIR__ . '/../lib/name_model.php' ) ;
require_once ( __DIR__ . '/../lib/display_code.php' ) ;
require_once ( __DIR__ . '/../lib/database_tools.php' ) ;
require_once ( __DIR__ . '/../lib/author_list.php' );
require_once ( __DIR__ . '/../lib/batch.php' ) ;
require_once ( __DIR__ . '/../lib/batch_manager.php' ) ;
require_once ( __DIR__ . '/../lib/rate_limit.php' );
require_once ( __DIR__ . '/../local_config.php' ) ;

?>
