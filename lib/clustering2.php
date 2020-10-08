<?PHP

# Using article_model2 instead of article_model
#
class ClusteringContext2 {
	public $author_articles = array() ;
	public $article_authors = array() ;
	public $already_clustered = array() ;

	public function __construct ( $article_items ) {
		foreach ( $article_items AS $article ) {
			$authors_for_this_article = [];
			foreach ( $article->authors as $author_list ) {
				foreach ( $author_list AS $author ) {
					$authors_for_this_article[] = $author;
					if (! isset($this->author_articles[$author]) ) {
						$this->author_articles[$author] = array() ;
					}
					$this->author_articles[$author][] = $article->q ;
				}
			}
			$this->article_authors[$article->q] = $authors_for_this_article ;
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
		$authors_to_add = array() ;
		foreach ($articles as $article_qid) {
			if (isset($this->already_clustered[$article_qid])) continue;
			$cluster->addArticle($article_qid) ;
			$this->already_clustered[$article_qid] = 1 ;
			foreach ($this->article_authors[$article_qid] as $author) {
				if (isset($this->already_clustered[$author])) continue;
				$authors_to_add[$author] = 1;
			}
		}
		if (count(array_keys($authors_to_add)) > 0) {
			$this->add_authors_to_cluster($cluster, array_keys($authors_to_add));
		}
	}

	public function add_authors_to_cluster($cluster, $authors) {
		$articles_to_add = array();
		foreach ($authors as $author_qid) {
			if (isset($this->already_clustered[$author_qid])) continue;
			$cluster->addAuthor($author_qid) ;
			$this->already_clustered[$author_qid] = 1 ;
			foreach ($this->author_articles[$author_qid] as $article) {
				if (isset($this->already_clustered[$article])) continue;
				$articles_to_add[$article] = 1;
			}
		}
		if (count(array_keys($articles_to_add)) > 0) {
			$this->add_articles_to_cluster($cluster, array_keys($articles_to_add));
		}
	}
}

function map_qids_to_articles2( $clusters, $article_items ) {
	$articles_by_qid = array() ;
	foreach ( $article_items AS $article ) {
		$articles_by_qid[$article->q] = $article ;
	}
	foreach ( $clusters AS $label => $cluster ) {
		$article_list = array() ;
		foreach ( array_keys($cluster->articles) AS $article_qid ) {
			$article_list[] = $articles_by_qid[$article_qid] ;
		}
		usort( $article_list, 'WikidataArticleEntry::dateCompare' ) ;
		$cluster->article_list = $article_list ;
	}
	return $clusters ;
}

function author_matches_article2( $article, $author_data, $names_to_ignore ) {
	foreach ( $article->authors AS $author_list ) {
		if ( in_array($author_data->qid, $author_list) ) return true;
		foreach ( $author_data->coauthors as $coauthor ) {
			if ( in_array($coauthor, $author_list) ) return true;
		}
	}
	$name_matches = 0 ;
	$journal_matches = 0 ;
	$topic_matches = 0 ;
	foreach ( $article->author_names AS $name_list ) {
		foreach ( $author_data->coauthor_names as $name) {
			if (in_array($name, $names_to_ignore)) continue ;
			if (in_array($name, $name_list)) $name_matches ++ ;
		}
	}
	foreach ( $author_data->journal_qids as $journal) {
		if (in_array($journal, $article->published_in) ) $journal_matches ++ ;
	}
	foreach ( $author_data->topic_qids as $topic) {
		if (in_array($topic, $article->topics)) $topic_matches ++ ;
	}
	$match = (($name_matches >= 2) || (($name_matches == 1) && ($journal_matches + $topic_matches > 0))) ;
	return $match;
}

function author_matches_cluster2( $cluster, $author_data, $names_to_ignore ) {
	if (isset($cluster->authors[$author_data->qid])) return true;
	foreach ( $author_data->coauthors as $coauthor ) {
		if (isset($cluster->authors[$coauthor])) return true;
	}
	$name_matches = 0 ;
	$journal_matches = 0 ;
	$topic_matches = 0 ;
	foreach ( $author_data->coauthor_names as $name) {
		if (in_array($name, $names_to_ignore)) continue ;
		if (isset($cluster->author_names[$name])) $name_matches ++ ;
	}
	foreach ( $author_data->journal_qids as $journal) {
		if (isset($cluster->journal_qids[$journal])) $journal_matches ++ ;
	}
	foreach ( $author_data->topic_qids as $topic) {
		if (isset($cluster->topic_qids[$topic])) $topic_matches ++ ;
	}
	$match = (($name_matches >= 2) || (($name_matches == 1) && ($journal_matches + $topic_matches > 0))) ;
	return $match;
}

function article_matches_cluster2( $cluster, $article_item, $names_to_ignore ) {
	// First double-check on author qids:
	foreach ( $article_item->authors as $author_list ) {
		foreach ( $author_list AS $author ) {
			if (isset($cluster->authors[$author])) return true;
		}
	}
	$name_matches = 0 ;
	$journal_matches = 0 ;
	$topic_matches = 0 ;
	foreach ( $article_item->author_names as $name_list) {
		foreach ( $name_list AS $name ) {
			if (in_array($name, $names_to_ignore)) continue ;
			if (isset($cluster->author_names[$name])) $name_matches ++ ;
		}
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

function cluster_articles2 ( $article_items, $names_to_ignore, $precise ) {
	if ( $precise) {
		$clusters = precise_cluster2( $article_items, $names_to_ignore );
	} else {
		$clusters = rough_cluster2( $article_items, $names_to_ignore ) ;
        }
	return map_qids_to_articles2($clusters, $article_items);
}

function precise_cluster2 ( $article_items, $names ) {
	$clusters = array();

	$articles_by_qid = array() ;
	foreach ( $article_items AS $article ) {
		$articles_by_qid[$article->q] = $article ;
	}

	foreach ($article_items as $article) {
		foreach ( $article->author_names AS $num => $name_list ) {
			foreach ( $name_list AS $c => $a ) {
				if ( in_array ( $a , $names ) ) {
					$neighbor_names = neighboring_author_strings2($article, $num);
					$article_num = $article->q . ':' . $c ;
					if (! isset($clusters[$neighbor_names])) {
						$clusters[$neighbor_names] = new Cluster([], []) ;
					}
					$cluster = $clusters[$neighbor_names] ;
					$cluster->addArticleItem2($article) ;
					$cluster->article_authnums[] = $article_num ;
				}
			}
		}
	}

	uasort ( $clusters, function ($a, $b) {
			return count($b->article_authnums) - count($a->article_authnums) ;
		}
	);

	foreach ($clusters as $key => $cluster) {
		$article_list = array_keys($cluster->articles);
		if (count($article_list) == 1) {
			if (! isset($clusters['Misc']) ) {
				$clusters['Misc'] = new Cluster([], []);
			}
			$clusters['Misc']->addArticleItem2($articles_by_qid[$article_list[0]]);
			$clusters['Misc']->article_authnums[] = $cluster->article_authnums[0];
			unset($clusters[$key]);
		}
	}
	return $clusters;
}

function rough_cluster2 ( $article_items, $names_to_ignore ) {
	$clusters = array();
	$min_score = 30 ;

	$articles_by_qid = array() ;
	foreach ( $article_items AS $article ) {
		$articles_by_qid[$article->q] = $article ;
	}

	$clustering_context = new ClusteringContext2( $article_items ) ;
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
			$cluster->addArticleItem2($articles_by_qid[$article_qid]) ;
		}
	}

	foreach ( $article_items AS $article ) {
		$q1 = $article->q ;
		if ( isset($is_in_cluster[$q1]) ) continue ;
		foreach ( $clusters AS $cluster ) {
			if (article_matches_cluster2( $cluster, $article, $names_to_ignore )) {
				$is_in_cluster[$q1] = 1 ;
				$cluster->addArticleItem2($article) ;
				break(1);
			}
		}
	}

	foreach ( $article_items AS $article ) {
		$q1 = $article->q ;
		if ( isset($is_in_cluster[$q1]) ) continue ;

		$cluster = new Cluster([], []);
		$cluster->addArticleItem2($article) ;
		foreach ( $article_items AS $article2 ) {
			$q2 = $article2->q ;
			if ( $q1 == $q2 ) continue ;
			if ( isset($is_in_cluster[$q2]) ) continue ;
			if (article_matches_cluster2( $cluster, $article2, $names_to_ignore )) {
				$cluster->addArticleItem2($article2) ;
			}
		}
	
		if ( count($cluster->articles) == 1 ) continue ;
		foreach ( array_keys($cluster->articles) AS $c ) $is_in_cluster[$c] = 1 ;
		$clusters['Group #'.(count($clusters)+1)] = $cluster ;
	}
	$cluster = new Cluster([],[]) ;
	foreach ( $article_items AS $article ) {
		if ( isset($is_in_cluster[$article->q]) ) continue ;
		$cluster->addArticleItem2($article) ;
	}
	if ( count($cluster->articles) > 0 ) {
		$clusters['Misc'] = $cluster ;
	}

	return $clusters;
}

function neighboring_author_strings2 ( $article, $num, $preceding = 1, $following = 1 ) {
	$author_name_strings = array();
	for ($i = $num - $preceding; $i <= $num + $following; $i++) {
		if (isset($article->author_names[$i]) ) {
			$name = reset($article->author_names[$i]) ;
			$author_name_strings[] = $name ;
		} else if (isset($article->authors[$i])) {
			$author_q = reset($article->authors[$i]) ;
			if (isset($article->authors_stated_as[$author_q])) {
				$author_name_strings[] = $article->authors_stated_as[$author_q] ;
			}
		}
	}
	return implode('|', $author_name_strings);
}

?>
