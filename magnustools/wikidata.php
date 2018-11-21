<?PHP

$wikidata_preferred_langs = ['en','de','nl','fr','es','it','zh'] ;
$wikidata_api_url = 'https://www.wikidata.org/w/api.php' ;

class WDI {

	public $q ;
	public $j ;
	
	public function __construct ( $q = '' ) {
		global $wikidata_api_url ;
		if ( $q != '' ) {
			$q = 'Q' . preg_replace ( '/\D/' , '' , "$q" ) ;
			$this->q = $q ;
			$url = "$wikidata_api_url?action=wbgetentities&ids=$q&format=json" ;
			$j = json_decode ( file_get_contents ( $url ) ) ;
			$this->j = $j->entities->$q ;
		}
	}
	
	public function getQ () {
		return $this->q ;
	}
	
	public function getLabel ( $lang = '' , $strict = false ) {
		global $wikidata_preferred_langs ;
		if ( !isset ( $this->j->labels ) ) return $this->q ;
		if ( isset ( $this->j->labels->$lang ) ) return $this->j->labels->$lang->value ; // Shortcut
		if ( $strict ) return $this->q ;
		
		$score = 9999 ;
		$best = $this->q ;
		foreach ( $this->j->labels AS $v ) {
			$p = array_search ( $v->language , $wikidata_preferred_langs ) ;
			if ( $p === false ) $p = 999 ;
			$p *= 1 ;
			if ( $p >= $score ) continue ;
			$score = $p ;
			$best = $v->value ;
		}
		return $best ;
	}

	public function getAliases ( $lang ) {
		$ret = [] ;
		if ( !isset($this->j->aliases) ) return $ret ;
		if ( !isset($this->j->aliases->$lang) ) return $ret ;
		foreach ( $this->j->aliases->$lang AS $v ) $ret[] = $v->value ;
		return $ret ;
	}
	
	public function getAllAliases () {
		$ret = [] ;
		if ( !isset($this->j->aliases) ) return $ret ;
		foreach ( $this->j->aliases AS $lang => $al ) {
			foreach ( $al AS $v ) $ret[$lang][] = $v->value ;
		}
		return $ret ;
	}
	
	public function getDesc ( $lang = '' , $strict = false ) {
		global $wikidata_preferred_langs ;
		if ( !isset ( $this->j->descriptions ) ) return '' ;
		if ( isset ( $this->j->descriptions->$lang ) ) return $this->j->descriptions->$lang->value ; // Shortcut
		if ( $strict ) return '' ;
		
		$score = 9999 ;
		$best = '' ;
		foreach ( $this->j->descriptions AS $v ) {
			$p = array_search ( $v->language , $wikidata_preferred_langs ) ;
			if ( $p === false ) $p = 999 ;
			if ( $p*1 >= $score*1 ) continue ;
			$score = $p ;
			$best = $v->value ;
		}
		return $best ;
	}
	
	public function getTarget ( $claim ) {
		$nid = 'numeric-id' ;
		if ( !isset($claim->mainsnak) ) return false ;
		if ( !isset($claim->mainsnak->datavalue) ) return false ;
		if ( !isset($claim->mainsnak->datavalue->value) ) return false ;
		if ( !isset($claim->mainsnak->datavalue->value->$nid) ) return false ;
		return 'Q'.$claim->mainsnak->datavalue->value->$nid ;
	}
	
	public function hasLabel ( $label ) {
		if ( !isset($this->j) ) return false ;
		if ( !isset($this->j->labels) ) return false ;
		foreach ( $this->j->labels AS $lab ) {
			if ( $lab->value == $label ) return true ;
		}
		return false ;
	}
	
	public function hasLabelInLanguage ( $lang ) {
		if ( !isset($this->j) ) return false ;
		if ( !isset($this->j->labels) ) return false ;
		if ( !isset($this->j->labels->$lang) ) return false ;
		return true ;
	}

	public function hasDescriptionInLanguage ( $lang ) {
		if ( !isset($this->j) ) return false ;
		if ( !isset($this->j->descriptions) ) return false ;
		if ( !isset($this->j->descriptions->$lang) ) return false ;
		return true ;
	}

	public function hasExternalSource ( $claim ) {
		return false ; // DUMMY
	}

	public function sanitizeP ( $p ) {
		return 'P' . preg_replace ( '/\D/' , '' , "$p" ) ;
	}

	public function sanitizeQ ( &$q ) {
		$q = 'Q'.preg_replace('/\D/','',"$q") ;
	}
	
	public function getStrings ( $p ) {
		$ret = [] ;
		if ( !$this->hasClaims($p) ) return $ret ;
		$claims = $this->getClaims($p) ;
		foreach ( $claims AS $c ) {
			if ( !isset($c->mainsnak) ) continue ;
			if ( !isset($c->mainsnak->datavalue) ) continue ;
			if ( !isset($c->mainsnak->datavalue->value) ) continue ;
			if ( !isset($c->mainsnak->datavalue->type) ) continue ;
			if ( $c->mainsnak->datavalue->type != 'string' ) continue ;
			$ret[] = $c->mainsnak->datavalue->value ;
		}
		return $ret ;
	}
	
	public function getFirstString ( $p ) {
		$strings = $this->getStrings ( $p ) ;
		if ( count($strings) == 0 ) return '' ;
		return $strings[0] ;
	}

	public function getClaims ( $p ) {
		$ret = [] ;
		$p = $this->sanitizeP ( $p ) ;
		if ( !isset($this->j) ) return $ret ;
		if ( !isset($this->j->claims) ) return $ret ;
		if ( !isset($this->j->claims->$p) ) return $ret ;
		return $this->j->claims->$p ;
	}
	
	public function hasTarget ( $p , $q ) {
		$this->sanitizeP ( $p ) ;
		$this->sanitizeQ ( $q ) ;
		$claims = $this->getClaims($p) ;
		foreach ( $claims AS $c ) {
			$target = $this->getTarget($c) ;
			if ( $target == $q ) return true ;
		}
		return false ;
	}
	
	public function hasClaims ( $p ) {
		return count($this->getClaims($p)) > 0 ;
	}
	
	public function getSitelink ( $wiki ) {
		if ( !isset($this->j) ) return ;
		if ( !isset($this->j->sitelinks) ) return ;
		if ( !isset($this->j->sitelinks->$wiki) ) return ;
		return $this->j->sitelinks->$wiki->title ;
	}
	
	public function getSitelinks () {
		$ret = [] ;
		if ( !isset($this->j) ) return $ret ;
		if ( !isset($this->j->sitelinks) ) return $ret ;
		foreach ( $this->j->sitelinks AS $wiki => $x ) $ret[$wiki] = $x->title ;
		return $ret ;
	}
	
	public function getProps () {
		$ret = [] ;
		if ( !isset($this->j) ) return $ret ;
		if ( !isset($this->j->claims) ) return $ret ;
		foreach ( $this->j->claims AS $p => $v ) $ret[] = $p ;
		return $ret ;
	}
	
	public function getClaimByID ( $id ) {
		if ( !isset($this->j) ) return ;
		if ( !isset($this->j->claims) ) return ;
		foreach ( $this->j->claims AS $p => $v ) {
			foreach ( $v AS $dummy => $claim ) {
				if ( $claim->id == $id ) return $claim ;
			}
		}
	}


	public function getSnakValueQS ( $snak ) {
		if ( !isset($snak) or !isset($snak->datavalue) ) {
			// Skip => error message
		} else if ( $snak->datatype == 'string' and $snak->datavalue->type == 'string' ) {
			return '"' . $snak->datavalue->value . '"' ;
		} else if ( $snak->datatype == 'external-id' and $snak->datavalue->type == 'string' ) {
			return '"' . $snak->datavalue->value . '"' ;
		} else if ( $snak->datatype == 'time' and $snak->datavalue->type == 'time' ) {
			return $snak->datavalue->value->time.'/'.$snak->datavalue->value->precision ;
		} else if ( $snak->datatype == 'wikibase-item' and $snak->datavalue->type == 'wikibase-entityid' ) {
			return $snak->datavalue->value->id ;
		}

		if ( 0 ) { // Debug output
			print "Cannot parse snak value:\n" ;
			print_r ( $snak ) ;
			print "\n\n" ;
		}
		return '' ;
	}

	public function statementQualifiersToQS ( $statement ) {
		$ret = [] ;
		$qo = 'qualifiers-order' ;
		if ( !isset($statement->qualifiers) or !isset($statement->$qo) ) return $ret ;
		foreach ( $statement->$qo AS $qual_prop ) {
			if ( !isset($statement->qualifiers->$qual_prop) ) continue ;
			foreach ( $statement->qualifiers->$qual_prop AS $x ) {
				$v = $this->getSnakValueQS ( $x ) ;
				if ( $v == '' ) continue ;
				$ret[] = "$qual_prop\t$v" ;
			}
		}
		return $ret ;
	}

	public function statementReferencesToQS ( $statement ) {
		$ret = [] ;
		if ( !isset($statement->references) ) return $ret ;
	
		$so = 'snaks-order' ;
		foreach ( $statement->references AS $ref ) {
			$ref_ret = [] ;
			foreach ( $ref->$so AS $snak_prop ) {
				if ( !isset($ref->snaks->$snak_prop) ) continue ;
				foreach ( $ref->snaks->$snak_prop AS $ref_snak ) {
					$v = $this->getSnakValueQS ( $ref_snak ) ;
					if ( $v == '' ) continue ;
					$ref_ret[] = "S" . preg_replace ( '/\D/' , '' , $snak_prop ) . "\t$v" ;
				}
			}
			if ( count($ref_ret) > 0 ) $ret[] = $ref_ret ;
		}
	
		return $ret ;
	}

	
}

class WikidataItemList {

	public $testing = false ;
	protected $items = [] ;

	public function sanitizeQ ( &$q ) {
		if ( preg_match ( '/^(?:[PL]\d+|L\d+-[FS]\d+)$/i' , "$q" ) ) {
			$q = strtoupper ( $q ) ;
		} else {
			$q = 'Q'.preg_replace('/\D/','',"$q") ;
		}
	}
	
	public function updateItems ( $list ) {
		$last_revs = [] ;
		foreach ( $list AS $q ) {
			if ( !$this->hasItem ( $q ) ) continue ;
			$this->sanitizeQ ( $q ) ;
			$last_revs[$q] = $this->items[$q]->j->lastrevid ;
			unset ( $this->items[$q] ) ;
		}
		$this->loadItems ( $list ) ;

		return ;
		// Paranoia
		foreach ( $list AS $q ) {
			if ( !$this->hasItem ( $q ) ) continue ;
			$this->sanitizeQ ( $q ) ;
			if ( $last_revs[$q] == $this->items[$q]->j->lastrevid ) print "<pre>WARNING! Caching issue with $q</pre>" ;
		}
	}

	public function updateItem ( $q ) {
		$this->updateItems ( [$q] ) ;
	}
	
	protected function parseEntities ( $j ) {
		foreach ( $j->entities AS $q => $v ) {
			if ( isset ( $this->items[$q] ) ) continue ; // Paranoia
			$this->items[$q] = new WDI ;
			$this->items[$q]->q = $q ;
			$this->items[$q]->j = $v ;
		}
	}
		
    function loadItems ( $list ) {
    	global $wikidata_api_url ;
    	$qs = [ [] ] ;
    	foreach ( $list AS $q ) {
    		$this->sanitizeQ($q) ;
    		if ( $q == 'Q' || $q == 'P' ) continue ;
	    	if ( isset($this->items[$q]) ) continue ;
	    	if ( count($qs[count($qs)-1]) == 50 ) $qs[] = [] ;
    		$qs[count($qs)-1][] = $q ;
    	}
    	
    	if ( count($qs) == 1 and count($qs[0]) == 0 ) return ;
    	
    	$urls = [] ;
    	foreach ( $qs AS $k => $sublist ) {
    		if ( count ( $sublist ) == 0 ) continue ;
			$url = "{$wikidata_api_url}?action=wbgetentities&ids=" . implode('|',$sublist) . "&format=json" ;
			$urls[$k] = $url ;
    	}
#print_r ( $urls ) ;
    	$res = $this->getMultipleURLsInParallel ( $urls ) ;
    	
		foreach ( $res AS $k => $txt ) {
			$j = json_decode ( $txt ) ;
			if ( !isset($j) or !isset($j->entities) ) continue ;
			$this->parseEntities ( $j ) ;
		}
    }
    
    function loadItem ( $q ) {
    	return $this->loadItems ( [ $q ] ) ;
    }
    
    function getItem ( $q ) {
    	$this->sanitizeQ($q) ;
    	if ( !isset($this->items[$q]) ) return ;
    	return $this->items[$q] ;
    }
    
    function getItemJSON ( $q ) {
    	$this->sanitizeQ($q) ;
    	return $this->items[$q]->j ;
    }
    
    function hasItem ( $q ) {
    	$this->sanitizeQ($q) ;
    	return isset($this->items[$q]) ;
    }
	
	public function loadItemByPage ( $page , $wiki ) {
		global $wikidata_api_url ;
		$page = urlencode ( ucfirst ( str_replace ( ' ' , '_' , trim($page) ) ) ) ;
		$url = $wikidata_api_url . "?action=wbgetentities&sites=$wiki&titles=$page&format=json" ;
		$j = json_decode ( file_get_contents ( $url ) ) ;
		if ( !isset($j) or !isset($j->entities) ) return false ;
		$this->parseEntities ( $j ) ;
		foreach ( $j->entities AS $q => $dummy ) {
			return $q ;
		}
	}

	protected function getMultipleURLsInParallel ( $urls ) {
		$ret = [] ;
	
		$batch_size = 50 ;
		$batches = [ [] ] ;
		foreach ( $urls AS $k => $v ) {
			if ( count($batches[count($batches)-1]) >= $batch_size ) $batches[] = [] ;
			$batches[count($batches)-1][$k] = $v ;
		}
	
		foreach ( $batches AS $batch_urls ) {
	
			$mh = curl_multi_init();
			curl_multi_setopt  ( $mh , CURLMOPT_PIPELINING , 1 ) ;
			$ch = [] ;
			foreach ( $batch_urls AS $key => $value ) {
				$ch[$key] = curl_init($value);
		//		curl_setopt($ch[$key], CURLOPT_NOBODY, true);
		//		curl_setopt($ch[$key], CURLOPT_HEADER, true);
				curl_setopt($ch[$key], CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch[$key], CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch[$key], CURLOPT_SSL_VERIFYHOST, false);
				curl_multi_add_handle($mh,$ch[$key]);
			}
	
			do {
				curl_multi_exec($mh, $running);
				curl_multi_select($mh);
			} while ($running > 0);
	
			foreach(array_keys($ch) as $key){
				$ret[$key] = curl_multi_getcontent($ch[$key]) ;
				curl_multi_remove_handle($mh, $ch[$key]);
			}
	
			curl_multi_close($mh);
		}
	
		return $ret ;
	}

}

?>