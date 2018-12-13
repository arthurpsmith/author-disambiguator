<?PHP

// Class to hold lists of ID's of authors and articles that we
// consider to be grouped together in a cluster
// I.e. linked together by co-authorship graph
class Cluster {
	public $authors = array() ;
	public $articles = array() ;
	public $author_names = array() ;
	public $journal_qids = array() ;
	public $topic_qids = array() ;

	public function __construct ( $author_list, $article_list ) {
		$this->addAuthorList( $author_list );
		$this->addArticleList( $article_list );
	}

	public function addAuthor( $author_qid ) {
		$this->authors[$author_qid] = 1 ;
	}

	public function addArticle( $article_qid ) {
		$this->articles[$article_qid] = 1 ;
	}

	public function addAuthorList( $author_list ) {
		foreach ( $author_list AS $author_qid ) {
			$this->authors[$author_qid] = 1 ;
		}
	}

	public function addArticleList( $article_list ) {
		foreach ( $article_list AS $article_qid ) {
			$this->articles[$article_qid] = 1 ;
		}
	}

	public function hasAuthor( $author_qid ) {
		return isset($this->authors[$author_qid]) ;
	}

	public function hasArticle( $article_qid ) {
		return isset($this->articles[$article_qid]) ;
	}

	public function addArticleItem( $article ) {
		$this->articles[$article->q] = 1 ;
		foreach ( $article->author_names AS $name ) {
			$this->author_names[$name] = 1 ;
		}
		foreach ( $article->authors AS $author_q ) {
			if (isset($article->authors_stated_as[$author_q])) {
				$stated_name = $article->authors_stated_as[$author_q] ;
				if (! empty($stated_name) ) $this->author_names[$stated_name] = 1 ;
			}
		}
		foreach ( $article->published_in AS $journal_qid ) {
			$this->journal_qids[$journal_qid] = 1 ;
		}
		foreach ( $article->topics AS $topic_qid ) {
			$this->topic_qids[$topic_qid] = 1 ;
		}
	}
}
?>
