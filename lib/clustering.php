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

function article_matches_cluster( $cluster, $article_item ) {
	// First double-check on author qids:
	foreach ( $article_item->authors as $author ) {
		if (isset($cluster->authors[$author])) return true;
	}
	$name_matches = 0 ;
	$journal_matches = 0 ;
	$topic_matches = 0 ;
	foreach ( $article_item->author_names as $name) {
		if (isset($cluster->author_names[$name])) $name_matches ++ ;
	}
	foreach ( $article_item->published_in as $journal) {
		if (isset($cluster->journal_qids[$journal])) $journal_matches ++ ;
	}
	foreach ( $article_item->topics as $topic) {
		if (isset($cluster->topic_qids[$topic])) $topic_matches ++ ;
	}
	$match = (($name_matches >= 2) || (($name_matches == 1) && ($journal_matches + $topic_matches > 0))) ;
	return $match;
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
		foreach ( $clusters AS $cluster ) {
			if (article_matches_cluster( $cluster, $article )) {
				$is_in_cluster[$q1] = 1 ;
				$cluster->addArticleItem($article) ;
				break(1);
			}
		}
	}

	foreach ( $article_items AS $article ) {
		$q1 = $article->q ;
		if ( isset($is_in_cluster[$q1]) ) continue ;

		$cluster = new Cluster([], []);
		$cluster->addArticleItem($article) ;
		foreach ( $article_items AS $article2 ) {
			$q2 = $article2->q ;
			if ( $q1 == $q2 ) continue ;
			if ( isset($is_in_cluster[$q2]) ) continue ;
			if (article_matches_cluster( $cluster, $article2 )) {
				$cluster->addArticleItem($article2) ;
			}
		}
	
		if ( count($cluster->articles) == 1 ) continue ;
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

	return map_qids_to_articles($clusters, $article_items);
}

?>
