<?PHP

require_once ( __DIR__ . '/lib/initialize.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_oauth.php' );

$oauth = new WD_OAuth('author-disambiguator', '/var/www/html/oauth.ini');

$oauth->logout();
header( "Location: index.php" );

?>
