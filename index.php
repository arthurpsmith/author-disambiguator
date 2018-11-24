<?PHP

// error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|
error_reporting(E_ALL);
ini_set('display_errors', 'On');
ini_set('memory_limit','1500M');
set_time_limit ( 60 * 10 ) ; // Seconds

require_once ( __DIR__ . '/magnustools/common.php' ) ;
require_once ( __DIR__ . '/magnustools/wikidata.php' ) ;

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
$sparql = "SELECT ?q { VALUES ?name { $names_strings } . ?q wdt:P2093 ?name }" ;
$items_papers = getSPARQLitems ( $sparql ) ;

// Potential authors
$no_middle = preg_replace('/\s*[A-Z]\. /',' ',$name) ;
//print "<pre>$no_middle</pre>" ;
$url = "https://www.wikidata.org/w/api.php?action=wbsearchentities&language=en&format=json&limit=50&type=item&search=" . urlencode($no_middle) ;
#print "<pre>$url</pre>" ;
$j = json_decode ( file_get_contents ( $url ) ) ;
#print "<pre>" ; print_r ( $j ) ; print "</pre>" ;
$items_authors = array() ;
foreach ( $j->search AS $a ) $items_authors[] = "wd:" . $a->id ;
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
	$orcid_author = trim ( get_request ( 'orcid_author' , '' ) ) ;
	$author_match = trim ( get_request ( 'author_match' , '' ) ) ;
	$author_q = trim ( get_request ( 'q_author' , '' ) ) ;
	if ( $author_q == '' ) $author_q = $author_match ;
//print "<pre>" ; print_r ( $_REQUEST ) ; print "</pre>" ;

	if ( $author_match == 'new' ) {
		print "Quickstatements V1 commands for creating new author item:" ;
		$commands = array() ;
		$commands[] = "CREATE" ;
		$commands[] = "LAST\tLen\t\"$name\""  ;
		$commands[] = "LAST\tP31\tQ5"  ;
		if ( $orcid_author != '' ) $commands[] = "LAST\tP496\t\"$orcid_author\"" ;
		print "<pre>" . implode("\n",$commands) . "</pre>" ;
		print "Run these and then use the resulting author item ID (Qxx) in further work." ;
		exit ( 0 ) ;
	}
	if ( $author_q == '' ) {
		print "Sorry, can't find author" ;
		exit ( 0 ) ;
	}

	$commands = array() ;
	$papers = get_request ( 'papers' , array() ) ;
	foreach ( $papers AS $paperq ) {
		$i = $wil->getItem ( $paperq ) ;
		if ( !isset($i) ) continue ;
		$authors = $i->getClaims ( 'P2093' ) ;
		foreach ( $authors AS $a ) {
			if ( !isset($a->mainsnak) or !isset($a->mainsnak->datavalue) ) continue ;
			$author_name = $a->mainsnak->datavalue->value ;
			if ( !in_array ( $author_name , $names ) ) continue ;
			$num = '' ;
			if ( isset($a->qualifiers) and isset($a->qualifiers->P1545) ) {
				$tmp = $a->qualifiers->P1545 ;
				$num = $tmp[0]->datavalue->value ;
			}
			$add = "$paperq\tP50\t$author_q" ;
			if ( $num != "" ) $add .= "\tP1545\t\"$num\"" ;
			
			$add .= "\tP1932\t\"$author_name\"" ;
			
#			$commands[] = "-STATEMENT\t" . $a->id ; # Deactivated as per https://www.wikidata.org/wiki/Wikidata_talk:WikiProject_Source_MetaData#Author_names
			$commands[] = $add ;
		}
	}

	print "Quickstatements V1 commands for replacing author name strings with author item:" ;
	print "<pre>" . implode("\n",$commands) . "</pre>" ;
	
	exit ( 0 ) ;
}


print "<form method='post' class='form' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='fuzzy' value='$fuzzy' />
<input type='hidden' name='name' value='" . escape_attribute($name) . "' />" ;

// Clustering
$min_score = 30 ;
$min_authors_for_cluster = 4 ;
$score_cache = array() ;
function compareAuthorLists ( $q1 , $q2 ) {
	global $wil , $score_cache , $min_authors_for_cluster ;

	if ( $q1 > $q2 ) return compareAuthorLists ( $q2 , $q1 ) ; // Enforce $q1 <= $q2
	$key = "$q1|$q2" ;
	if ( isset($score_cache[$key]) ) return $score_cache[$key] ;

	$i1 = $wil->getItem ( $q1 ) ;
	$i2 = $wil->getItem ( $q2 ) ;
	if ( !isset($i1) or !isset($i2) ) return 0 ;
	$authors1 = $i1->getStrings ( 'P2093' ) ;
	$authors2 = $i2->getStrings ( 'P2093' ) ;
	
	foreach ( $i1->getClaims('P50') AS $claim ) $authors1[] = $i1->getTarget ( $claim ) ;
	foreach ( $i2->getClaims('P50') AS $claim ) $authors2[] = $i2->getTarget ( $claim ) ;
	
	$score = 0 ;
	if ( count($authors1) < $min_authors_for_cluster or count($authors2) < $min_authors_for_cluster ) {
		// Return 0
	} else {
	
		foreach ( $authors1 AS $a ) {
			if ( in_array ( $a , $authors2 ) ) {
				if ( preg_match ( '/^Q\d+$/' , $a ) ) {
					$score += 5 ;
				} else {
					$score += 2 ;
				}
			}
		}
	}
	
	$score_cache[$key] = $score ;
	return $score ;
}

$clusters = array() ;
$is_in_cluster = array() ;
foreach ( $items_papers AS $q1 ) {
	if ( isset($is_in_cluster[$q1]) ) continue ;
	$base_score = compareAuthorLists ( $q1 , $q1 ) ;
        if ( $base_score == 0 ) continue ;
	$cluster = array() ;
	foreach ( $items_papers AS $q2 ) {
		if ( $q1 == $q2 ) continue ;
		if ( isset($is_in_cluster[$q2]) ) continue ;
		$score = compareAuthorLists ( $q1 , $q2 ) ;
		$score = 100 * $score / $base_score ;
		
		if ( $score >= $min_score ) {
			if ( count($cluster) == 0 ) $cluster[] = $q1 ;
			$cluster[] = $q2 ;
		}
//		print "<pre>$q1 / $q2 : $score</pre>" ;
	}
	
	if ( count($cluster) == 0 ) continue ;
	foreach ( $cluster AS $q ) $is_in_cluster[$q] = $q ;
	$clusters['Group #'.(count($clusters)+1)] = $cluster ;
}
$cluster = array() ;
foreach ( $items_papers AS $q1 ) {
	if ( isset($is_in_cluster[$q1]) ) continue ;
	$cluster[] = $q1 ;
}
if ( count($cluster) > 0 ) $clusters['Misc'] = $cluster ;
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
	print "<th>Published In</th><th>DOI/PubMed ID</th>" ;
	print "<th>Topic</th><th>Published Date</th></tr>" ;
	foreach ( $cluster AS $q ) {
		$q = "Q$q" ;
		$i = $wil->getItem ( $q ) ;
		if ( !isset($i) ) continue ;

		$title = $i->getStrings ( 'P1476' ) ;
		if ( count($title) == 0 ) $title = $i->getLabel() ;
		else $title = $title[0] ;

		$authors = $i->getStrings ( 'P2093' ) ;
		$out = array() ;
		foreach ( $authors AS $a ) {
			if ( in_array ( $a , $names ) ) $out[] = "<b>$a</b>" ;
			else {
				$out[] = "<a href='?fuzzy=$fuzzy&name=" . urlencode($a) . "'>$a</a>" ;
				$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
			}
		}
		$author_string_list = implode ( ', ' , $out ) ;
		
		$q_authors = array() ;
		$claims = $i->getClaims ( 'P50' ) ;
		foreach ( $claims AS $c ) {
			$i2 = $wil->getItem ( $i->getTarget($c) ) ;
			$q_authors[] = "<a href='https://www.wikidata.org/wiki/" . $i2->getQ() . "' target='_blank' style='color:green'>" . $i2->getLabel() . "</a>" ;
		}
		$author_entity_list = implode ( ', ' , $q_authors ) ;

		$published_in = array() ;
		$claims = $i->getClaims ( 'P1433' ) ;
		foreach ($claims AS $c ) {
			$i2 = $wil->getItem ( $i->getTarget($c) ) ;
			if ( isset($i2) ) $published_in[] = $i2->getLabel() ;
		}
		$published_in_list = implode ( ', ', $published_in ) ;
	
		$orcid_url = '' ;
		$doi = '' ;
		$pmid = '' ;
		$x = $i->getStrings ( 'P356' ) ;
		if ( count($x) > 0 ) {
                        $doi = $x[0] ;
			$orcid_url = getORCIDurl ( $doi ) ;
		}
		else {
			$x = $i->getStrings ( 'P698' ) ;
			if ( count($x) > 0 ) {
				$pmid = $x[0] ;
				$orcid_url = getORCIDurl ( $pmid ) ;
			}
		}

		print "<tr>" ;
		print "<td><input type='checkbox' name='papers[$q]' value='$q' " . ($is_first_group?'checked':'') . " /></td>" ;
		print "<td style='width:20%;font-size:10pt'><a href='//www.wikidata.org/wiki/$q' target='_blank'>" . $title . "</a></td>" ;
		print "<td style='width:30%;font-size:9pt'>$author_string_list</td>" ;
		print "<td style='width:30%;font-size:9pt'>$author_entity_list</td>" ;
		print "<td style='font-size:9pt'>$published_in_list</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( $doi != '' ) print "DOI: $doi" ;
		if ( $pmid != '' ) print "PubMed: $pmid" ;
		if ( $orcid_url != '' ) print "&nbsp;[<a href='$orcid_url' target='_blank'>ORCID</a>]" ;
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( $i->hasClaims('P921') ) {
			$claims = $i->getClaims('P921') ;
			$p921 = array() ;
			foreach ( $claims AS $c ) {
				$qt = $i->getTarget ( $c ) ;
				$wil->loadItem ( $qt ) ;
				$i2 = $wil->getItem($qt) ;
				if ( !isset($i2) ) continue ;
				$p921[] = "<a href='https://www.wikidata.org/wiki/" . $i2->getQ() . "' target='_blank' style='color:green'>" . $i2->getLabel() . "</a>" ;
			}
			print implode ( '; ' , $p921 ) ;
		}
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;

		if ( $i->hasClaims('P577') ) { // publication date
			$claims = $i->getClaims('P577') ;
			$p577 = array() ;
			foreach ( $claims AS $c ) {
				$p577[] = $c->mainsnak->datavalue->value->time ;
			}
			print implode( '; ', $p577 ) ;
		}
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

print "<div style='margin:20px'><input type='submit' name='doit' value='Do it!' class='btn btn-primary' /></div>" ;
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
