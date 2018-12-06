<?PHP

// Heuristic clustering algorithm
$min_authors_for_cluster = 3 ;
$score_cache = array() ;
function compareArticles( $article1 , $article2, $names_to_ignore ) {
	global $score_cache , $min_authors_for_cluster ;

	$q1 = $article1->q ;
	$q2 = $article2->q ;
	$key = "$q1|$q2" ;
	if ( $q1 > $q2 ) $key = "$q2|$q1" ;
	if ( isset($score_cache[$key]) ) return $score_cache[$key] ;

	$authors1 = array_merge( $article1->author_names, $article1->authors ) ;
	$authors2 = array_merge( $article2->author_names, $article2->authors ) ;

	$other_qids1 = array_merge( $article1->published_in, $article1->topics ) ;
	$other_qids2 = array_merge( $article2->published_in, $article2->topics ) ;
	$count1 = count($authors1) + count($other_qids1) ;
	$count2 = count($authors2) + count($other_qids2) ;

	$score = 0 ;
	if ( $count1 < $min_authors_for_cluster or $count2 < $min_authors_for_cluster ) {
		// Return 0
	} else {
		foreach ( $authors1 AS $a ) {
			if ( in_array ( $a, $names_to_ignore ) ) continue ;
			if ( in_array ( $a , $authors2 ) ) {
				if ( preg_match ( '/^Q\d+$/' , $a ) ) {
					$score += 5 ;
				} else {
					$score += 2 ;
				}
			}
		}
		foreach ( $other_qids1 AS $qid1 ) {
			if ( in_array ( $qid1, $other_qids2 ) ) {
				$score += 2 ;
			}
		}
	}
	
	$score_cache[$key] = $score ;
	return $score ;
}

function cluster_articles ( $article_items, $names_to_ignore ) {
	$clusters = array() ;
	$min_score = 30 ;
	$is_in_cluster = array() ;
	foreach ( $article_items AS $article ) {
		$q1 = $article->q ;
		if ( isset($is_in_cluster[$q1]) ) continue ;
		$base_score = compareArticles ( $article , $article, $names_to_ignore ) ;
		if ( $base_score == 0 ) continue ;
		$cluster = array() ;
		foreach ( $article_items AS $article2 ) {
			$q2 = $article2->q ;
			if ( $q1 == $q2 ) continue ;
			if ( isset($is_in_cluster[$q2]) ) continue ;
			$score = compareArticles ( $article , $article2, $names_to_ignore ) ;
			$score = 100 * $score / $base_score ;
			if ( $score >= $min_score ) {
				if ( count($cluster) == 0 ) $cluster[] = $article ;
				$cluster[] = $article2 ;
			}
		}
	
		if ( count($cluster) == 0 ) continue ;
		usort( $cluster, 'WikidataArticleEntry::dateCompare' );
		foreach ( $cluster AS $c ) $is_in_cluster[$c->q] = 1 ;
		$clusters['Group #'.(count($clusters)+1)] = $cluster ;
	}
	$cluster = array() ;
	foreach ( $article_items AS $article ) {
		if ( isset($is_in_cluster[$article->q]) ) continue ;
		$cluster[] = $article ;
	}
	if ( count($cluster) > 0 ) {
		usort( $cluster, 'WikidataArticleEntry::dateCompare' );
		$clusters['Misc'] = $cluster ;
	}

	return $clusters;
}

?>
