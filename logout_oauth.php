<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$dbtools = new DatabaseTools($db_passwd_file);
$db_conn = $dbtools->openToolDB('authors');
$oauth = new WD_OAuth('author-disambiguator', $oauth_ini_file, $db_conn);

$oauth->logout($db_conn);
$db_conn->close();
header( "Location: index.php" );

?>
