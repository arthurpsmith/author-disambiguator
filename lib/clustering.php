<?PHP

class ClusteringContext {
	public $author_articles = array() ;
	public $article_authors = array() ;
	public $already_clustered = array() ;

	public function __construct ( $article_items ) {
		foreach ( $article_items AS $article ) {
			$this->article_authors[$article->q] = $article->authors ;
			foreach ( $article->authors as $author ) {
				if (! isset($this->author_articles[$author]) ) {
					$this->author_articles[$author] = array() ;
				}
				$this->author_articles[$author][] = $article->q ;
			}
		}
		uasort ( $this->article_authors, function ($a, $b) {
				return count($b) - count($a) ;
			}
		);
		uasort ( $this->author_articles, function ($a, $b) {
				return count($b) - count($a) ;
			}
		);
	}

	public function add_articles_to_cluster($cluster, $articles) {
		foreach ($articles as $article_qid) {
			if (isset($this->already_clustered[$article_qid])) continue;
			$cluster->addArticle($article_qid) ;
			$this->already_clustered[$article_qid] = 1 ;
			$this->add_authors_to_cluster($cluster, $this->article_authors[$article_qid]);
		}
	}

	public function add_authors_to_cluster($cluster, $authors) {
		foreach ($authors as $author_qid) {
			if (isset($this->already_clustered[$author_qid])) continue;
			$cluster->addAuthor($author_qid) ;
			$this->already_clustered[$author_qid] = 1 ;
			$this->add_articles_to_cluster($cluster, $this->author_articles[$author_qid]);
		}
	}
}

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

function map_qids_to_articles( $clusters, $article_items ) {
	$articles_by_qid = array() ;
	foreach ( $article_items AS $article ) {
		$articles_by_qid[$article->q] = $article ;
	}
	$full_clusters = array() ;
	foreach ( $clusters AS $label => $cluster ) {
		$full_cluster = array() ;
		foreach ( array_keys($cluster->articles) AS $article_qid ) {
			$full_cluster[] = $articles_by_qid[$article_qid] ;
		}
		usort( $full_cluster, 'WikidataArticleEntry::dateCompare' ) ;
		$full_clusters[$label] = $full_cluster ;
	}
	return $full_clusters ;
}

function cluster_articles ( $article_items, $names_to_ignore ) {
	$clusters = array() ;
	$min_score = 30 ;

	$articles_by_qid = array() ;
	foreach ( $article_items AS $article ) {
		$articles_by_qid[$article->q] = $article ;
	}

	$clustering_context = new ClusteringContext( $article_items ) ;
	foreach ( array_keys($clustering_context->author_articles) AS $author_qid ) {
		if ( isset($clustering_context->already_clustered[$author_qid]) ) continue ;
		$cluster = new Cluster([], []) ;
		$clustering_context->add_authors_to_cluster($cluster, [$author_qid] ) ;

		if ( count(array_keys($cluster->articles)) > 1) {
			$clusters['Group #'.(count($clusters)+1)] = $cluster ;
		}
	}
	print_r($clusters) ;

	$is_in_cluster = array() ;
	foreach ( $clusters AS $cluster ) {
		foreach (array_keys($cluster->articles) AS $article_qid ) {
			$is_in_cluster[$article_qid] = 1 ;
			$cluster->addArticleItem($articles_by_qid[$article_qid]) ;
		}
	}

	foreach ( $article_items AS $article ) {
		$q1 = $article->q ;
		if ( isset($is_in_cluster[$q1]) ) continue ;
		$base_score = compareArticles ( $article , $article, $names_to_ignore ) ;
		if ( $base_score == 0 ) continue ;
		$cluster = new Cluster([], []);
		foreach ( $article_items AS $article2 ) {
			$q2 = $article2->q ;
			if ( $q1 == $q2 ) continue ;
			if ( isset($is_in_cluster[$q2]) ) continue ;
			$score = compareArticles ( $article , $article2, $names_to_ignore ) ;
			$score = 100 * $score / $base_score ;
			if ( $score >= $min_score ) {
				if ( count($cluster) == 0 ) $cluster->addArticleItem($article) ;
				$cluster->addArticleItem($article2) ;
			}
		}
	
		if ( count($cluster->articles) == 0 ) continue ;
		foreach ( array_keys($cluster->articles) AS $c ) $is_in_cluster[$c] = 1 ;
		$clusters['Group #'.(count($clusters)+1)] = $cluster ;
	}
	$cluster = new Cluster([],[]) ;
	foreach ( $article_items AS $article ) {
		if ( isset($is_in_cluster[$article->q]) ) continue ;
		$cluster->addArticleItem($article) ;
	}
	if ( count($cluster->articles) > 0 ) {
		$clusters['Misc'] = $cluster ;
	}
	print("<br/>") ;
	print_r($clusters) ;

	return map_qids_to_articles($clusters, $article_items);
}

?>
