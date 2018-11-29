<?PHP

// error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|
error_reporting(E_ALL);
ini_set('display_errors', 'On');
ini_set('memory_limit','1500M');
set_time_limit ( 60 * 10 ) ; // Seconds

require_once ( __DIR__ . '/magnustools/common.php' ) ;
require_once ( __DIR__ . '/magnustools/wikidata.php' ) ;
require_once ( __DIR__ . '/lib/clustering.php' ) ;
require_once ( __DIR__ . '/lib/qs_commands.php' ) ;
require_once ( __DIR__ . '/lib/article_model.php' ) ;

function getORCIDurl ( $s ) {
	return "https://orcid.org/orcid-search/quick-search?searchQuery=%22" . urlencode($s) . "%22" ;
}

$action = get_request ( 'action' , '' ) ;
$name = trim ( str_replace ( '_' , ' ' , get_request ( 'name' , '' ) ) ) ;
$fuzzy = get_request ( 'fuzzy' , 0 ) * 1 ;
$fuzzy_checked = $fuzzy ? 'checked' : '' ;

print get_common_header ( '' , 'Author Disambiguator' ) ;

print "<form method='get' class='form form-inline'>
Author name: 
<input name='name' value='" . escape_attribute($name) . "' type='text' placeholder='First Last' />
<label><input type='checkbox' name='fuzzy' value='1' $fuzzy_checked /> Fuzzy match</label>
<input type='submit' class='btn btn-primary' name='doit' value='Look for author' />
</form>" ;

if ( $name == '' ) {
	print get_common_footer() ;
	exit ( 0 ) ;
}


// Publications
$names = array ( $name ) ;
if ( $fuzzy ) {
	$names[] = preg_replace ( '/^([A-Z])\S+.*\s(\S+)$/' , '$1 $2' , $name ) ;
	$names[] = preg_replace ( '/^([A-Z][a-z]+).*\s(\S+)$/' , '$1 $2' , $name ) ;
}
$names_strings = '"' . implode ( '" "' , $names ) . '"' ;
#print "$names_strings" ;
$sparql = "SELECT ?q { VALUES ?name { $names_strings } . ?q wdt:P2093 ?name }" ;
$items_papers = getSPARQLitems ( $sparql ) ;

// Potential authors
$no_middle = preg_replace('/\s*[A-Z]\. /',' ',$name) ;
#print "<pre>$no_middle</pre>" ;
$url = "https://www.wikidata.org/w/api.php?action=query&list=search&format=json&srsearch=" . urlencode($no_middle) ;
#print "<pre>$url</pre>" ;
$j = json_decode ( file_get_contents ( $url ) ) ;
#print "<pre>" ; print_r ( $j ) ; print "</pre>" ;
$items_authors = array() ;
foreach ( $j->query->search AS $a ) $items_authors[] = "wd:" . $a->title ;
$sparql = "SELECT ?q { VALUES ?q { " . implode ( ' ' , $items_authors ) . " } . ?q wdt:P31 wd:Q5 }" ;
$items_authors = getSPARQLitems ( $sparql ) ;



// Load items
$wil = new WikidataItemList ;
$to_load = array() ;
foreach ( $items_papers AS $q ) $to_load[] = $q ;
foreach ( $items_authors AS $q ) $to_load[] = $q ;
$wil->loadItems ( $to_load ) ;


$delete_statements = array() ;
if ( $action == 'add' ) {
	print "<form method='post' class='form' action='https://tools.wmflabs.org/quickstatements/api.php'>" ;
	print "<input type='hidden' name='action' value='import' />" ;
	print "<input type='hidden' name='temporary' value='1' />" ;
	print "<input type='hidden' name='openpage' value='1' />" ;
	$orcid_author = trim ( get_request ( 'orcid_author' , '' ) ) ;
	$author_match = trim ( get_request ( 'author_match' , '' ) ) ;
	$author_q = trim ( get_request ( 'q_author' , '' ) ) ;
	if ( $author_q == '' ) $author_q = $author_match ;

	if ( $author_match == 'new' ) {
		print "<br/>Quickstatements V1 commands for creating new author item:" ;
		$commands = new_author_qs_commands ( $name, $orcid_author ) ;
		print "<textarea name='data' rows=5>" . implode("\n",$commands) . "</textarea>" ;
		print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' /><br/>" ;
		print "Run these and then use the resulting author item ID (Qxx) in further work." ;
		print "</form>" ;
		exit ( 0 ) ;
	}
	if ( $author_q == '' ) {
		print "Sorry, can't find author" ;
		exit ( 0 ) ;
	}

	$papers = get_request ( 'papers' , array() ) ;
	$commands = replace_authors_qs_commands ( $wil, $papers, $names, $author_q ) ;

	print "Quickstatements V1 commands for replacing author name strings with author item:" ;
	print "<textarea name='data' rows=20>" . implode("\n",$commands) . "</textarea>" ;
	print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' />" ;
	print "</form>" ;
	
	exit ( 0 ) ;
}


print "<form method='post' class='form' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='fuzzy' value='$fuzzy' />
<input type='hidden' name='name' value='" . escape_attribute($name) . "' />" ;

$clusters = cluster_articles ( $wil, $items_papers ) ;
#print "<pre>" ; print_r ( $clusters ) ; print "</pre>" ;

// P50 authors
$to_load = array() ;
foreach ( $items_papers AS $q ) {
	$i = $wil->getItem ( $q ) ;
	if ( !isset($i) ) continue ;
	$claims = $i->getClaims ( 'P50' ) ;
	foreach ( $claims AS $c ) $to_load[] = $i->getTarget ( $c ) ;
	$claims = $i->getClaims ( 'P1433' ) ;
	foreach ( $claims AS $c ) $to_load[] = $i->getTarget ( $c ) ;
}
$wil->loadItems ( $to_load ) ;

// Publications
$name_counter = array() ;
print "<h2>Potential publications</h2>" ;
print "<p>" . count($items_papers) . " publications found</p>" ;


$is_first_group = true ;
foreach ( $clusters AS $cluster_name => $cluster ) {
	print "<div class='group'>" ;
	print "<h3>$cluster_name</h3>" ;
?>
<div>
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",true);return false'>Check all</a> | 
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",false);return false'>Uncheck all</a>
</div>
<?PHP
	print "<table class='table table-striped table-condensed'>" ;
	print "<tbody>" ;
	print "<tr><th></th><th>Title</th>" ;
	print "<th>Author Name Strings</th><th>Identified Authors</th>" ;
	print "<th>Published In</th><th>Identifier(s)</th>" ;
	print "<th>Topic</th><th>Published Date</th></tr>" ;
	foreach ( $cluster AS $q ) {
		$q = "Q$q" ;
		$i = $wil->getItem ( $q ) ;
		if ( !isset($i) ) continue ;
		$article = new WikidataArticleEntry( $i ) ;

		$out = array() ;
		foreach ( $article->author_names AS $a ) {
			if ( in_array ( $a , $names ) ) $out[] = "<b>$a</b>" ;
			else {
				$out[] = "<a href='?fuzzy=$fuzzy&name=" . urlencode($a) . "'>$a</a>" ;
				$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
			}
		}
		$author_string_list = implode ( ', ' , $out ) ;
		
		$q_authors = array() ;
		foreach ( $article->authors AS $qt ) {
			$i2 = $wil->getItem ( $qt ) ;
			$q_authors[] = "<a href='https://www.wikidata.org/wiki/" . $i2->getQ() . "' target='_blank' style='color:green'>" . $i2->getLabel() . "</a>" ;
		}
		$author_entity_list = implode ( ', ' , $q_authors ) ;

		$published_in = array() ;
		foreach ( $article->published_in AS $qt ) {
			$i2 = $wil->getItem ( $qt ) ;
			if ( isset($i2) ) $published_in[] = $i2->getLabel() ;
		}
		$published_in_list = implode ( ', ', $published_in ) ;
	
		print "<tr>" ;
		print "<td><input type='checkbox' name='papers[$q]' value='$q' " . ($is_first_group?'checked':'') . " /></td>" ;
		print "<td style='width:20%;font-size:10pt'><a href='//www.wikidata.org/wiki/$q' target='_blank'>" . $article->title . "</a></td>" ;
		print "<td style='width:30%;font-size:9pt'>$author_string_list</td>" ;
		print "<td style='width:30%;font-size:9pt'>$author_entity_list</td>" ;
		print "<td style='font-size:9pt'>$published_in_list</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( $article->doi != '' ) {
			print "DOI: $article->doi" ;
			print "&nbsp;[<a href='" . getORCIDurl ( $article->doi ) . "'>ORCID</a>]<br/>" ;
		}
		if ( $article->pmid != '' ) {
			print "PubMed: $article->pmid" ;
			print "&nbsp;[<a href='" . getORCIDurl ( $article->pmid ) . "'>ORCID</a>]<br/>" ;
		}
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( count($article->topics) > 0 ) {
			$topics = [] ;
			foreach ( $article->topics AS $qt ) {
				$wil->loadItem ( $qt ) ;
				$i2 = $wil->getItem($qt) ;
				if ( !isset($i2) ) continue ;
				$topics[] = "<a href='https://www.wikidata.org/wiki/" . $i2->getQ() . "' target='_blank' style='color:green'>" . $i2->getLabel() . "</a>" ;
			}
			print implode ( '; ' , $topics ) ;
		}
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;
		print $article->publication_date ;
		print "</td>" ;
		print "</tr>" ;
	}
	print "</tbody></table></div>" ;
	$is_first_group = false ;
}


// Potential authors
#print "<pre>" ; print_r ( $items_authors ) ; print "</pre>" ;
print "<h2>Potential author items</h2>" ;
print "<table class='table table-striped table-condensed'>" ;
print "<tbody>" ;
foreach ( $items_authors AS $q ) {
	$q = "Q$q" ;
	$i = $wil->getItem ( $q ) ;
	if ( !isset($i) ) continue ;
//		$url = "http://tools.wmflabs.org/autodesc?q=$qlang=en&mode=long&links=text&format=json" ;
//		$j = json_decode ( file_get_contents ( $url ) ) ;
	print "<tr>" ;
	print "<td><input type='radio' name='author_match' value='$q' /></td>" ;
	print "<td><a href='//www.wikidata.org/wiki/$q' target='_blank'>" . $i->getLabel() . "</a></td>" ;
	print "<td>" . $i->getDesc() . "</td>" ;
//		print "<td>" . $j->result . "</td>" ;
	print "</tr>" ;
}
print "<tr><td><input type='radio' name='author_match' value='manual' /></td><td><input type='text' name='q_author' placeholder='Qxxx' /></td><td>Other Q number of this author</td></tr>" ;
print "<tr><td><input type='radio' name='author_match' value='new' checked /></td><td>Create new item</td><td></td></tr>" ;
print "</tbody></table>" ;
print "<div><a href='" . getORCIDurl($name) . "' target='_blank'>Check ORCID for $name</a> | Author has ORCID ID: <input type='text' name='orcid_author' placeholder='xxxx-xxxx-xxxx-xxxx' /></div>" ;

//$sparql = "SELECT ?q { VALUES ?name { $names_strings } . ?q wdt:P31 wd:Q5 ; rdfs:label ?label. filter(str(?label) = ?name ) }" ;
//print "<pre>$sparql</pre>" ;
#$items = getSPARQLitems ( $sparql ) ;
#$wil->loadItems ( $items ) ;


// https://orcid.org/orcid-search/quick-search?searchQuery=%2210.1371/journal.ppat.1002567%22

print "<div style='margin:20px'><input type='submit' name='doit' value='Generate Quickstatements Commands' class='btn btn-primary' /></div>" ;
print "</form>" ;



arsort ( $name_counter , SORT_NUMERIC ) ;
print "<h2>Common names in these papers</h2>" ;
print "<ul>" ;
foreach ( $name_counter AS $a => $cnt ) {
	if ( $cnt == 1 ) break ;
	print "<li><a href='?fuzzy=$fuzzy&name=" . urlencode($a) . "'>$a</a> ($cnt&times;)</li>" ;
}
print "</ul>" ;


print get_common_footer() ;

?>
