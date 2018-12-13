<?PHP

class WikidataArticleEntry {
	public $q = '' ;
	public $title = '' ;
	public $author_names = array() ;
	public $authors = array() ;
	public $authors_stated_as = array() ;
	public $published_in = array() ;
	public $doi = '' ;
	public $pmid = '' ;
	public $topics = array() ;
	public $publication_date = '';

	public function __construct ( $item ) {
		$this->q = $item->getQ() ;

		$title = $item->getStrings ( 'P1476' ) ;
		if ( count($title) == 0 ) $this->title = $item->getLabel() ;
		else $this->title = $title[0] ;

		$claims = $item->getClaims ( 'P2093' ) ; // Author strings
		foreach ( $claims AS $c ) {
			$author_name = $c->mainsnak->datavalue->value ;
			$num = '' ;
			if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
				$tmp = $c->qualifiers->P1545 ;
				$num = $tmp[0]->datavalue->value ;
				$this->author_names[$num] = $author_name ;
			} else {
				$this->author_names[] = $author_name ;
			}
		}
		ksort($this->author_names) ;

		$claims = $item->getClaims ( 'P50' ) ;
		foreach ( $claims AS $c ) {
			$author_q = $item->getTarget($c) ;
			if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
				$tmp = $c->qualifiers->P1545 ;
				$num = $tmp[0]->datavalue->value ;
				$this->authors[$num] = $author_q ;
			} else {
				$this->authors[] = $author_q ;
			}
			if ( isset($c->qualifiers) and isset($c->qualifiers->P1932) ) {
				$tmp = $c->qualifiers->P1932 ;
				$name = $tmp[0]->datavalue->value ;
				$this->authors_stated_as[$author_q] = $name ;
			}
		}
		ksort($this->authors) ;

		$claims = $item->getClaims ( 'P1433' ) ;
		foreach ( $claims AS $c ) {
			$this->published_in[] = $item->getTarget($c) ;
		}
		$x = $item->getStrings ( 'P356' ) ;
		if ( count($x) > 0 ) {
			$this->doi = $x[0] ;
		}
		$x = $item->getStrings ( 'P698' ) ;
		if ( count($x) > 0 ) {
			$this->pmid = $x[0] ;
		}
		if ( $item->hasClaims('P921') ) { // main subject
			$claims = $item->getClaims('P921') ;
			foreach ( $claims AS $c ) {
				$qt = $item->getTarget ( $c ) ;
				$this->topics[] = $qt ;
			}
		}
		if ( $item->hasClaims('P577') ) { // publication date
			$claims = $item->getClaims('P577') ;
			if ( count($claims) > 0 ) $this->publication_date = $claims[0]->mainsnak->datavalue->value->time ;
		}
	}

	public function formattedPublicationDate () {
		$formatted_date = '' ;
		if ( $this->publication_date != '' ) $formatted_date = DateTime::createFromFormat( '\+Y-m-d\TH:i:s\Z', $this->publication_date )->format( "Y-m-d" );
		return $formatted_date ;
	}

	public static function dateCompare ($a, $b) {
		$adate = $a->publication_date ;
		$bdate = $b->publication_date ;
		if ($adate == $bdate) {
			return 0;
		}
		return ($adate > $bdate) ? -1 : 1 ;
	}
}
?>
