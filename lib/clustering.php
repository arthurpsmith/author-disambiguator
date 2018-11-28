<?PHP

// Heuristic clustering algorithm
$min_authors_for_cluster = 4 ;
$score_cache = array() ;
function compareArticles( $wil, $q1 , $q2 ) {
	global $score_cache , $min_authors_for_cluster ;

	if ( $q1 > $q2 ) return compareArticles ( $wil, $q2 , $q1 ) ; // Enforce $q1 <= $q2
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

function cluster_articles ( $wil, $items_papers ) {
	$clusters = array() ;
	$min_score = 30 ;
	$is_in_cluster = array() ;
	foreach ( $items_papers AS $q1 ) {
		if ( isset($is_in_cluster[$q1]) ) continue ;
		$base_score = compareArticles ( $wil, $q1 , $q1 ) ;
		if ( $base_score == 0 ) continue ;
		$cluster = array() ;
		foreach ( $items_papers AS $q2 ) {
			if ( $q1 == $q2 ) continue ;
			if ( isset($is_in_cluster[$q2]) ) continue ;
			$score = compareArticles ( $wil, $q1 , $q2 ) ;
			$score = 100 * $score / $base_score ;
			if ( $score >= $min_score ) {
				if ( count($cluster) == 0 ) $cluster[] = $q1 ;
				$cluster[] = $q2 ;
			}
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
	return $clusters;
}

?>
