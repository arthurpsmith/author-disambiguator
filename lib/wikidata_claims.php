<?PHP

$wikidata_api_url = 'https://www.wikidata.org/w/api.php' ;

class WDClaim {
	public $id ;
	public $p ;
	public $c ;
	
	public function __construct ( $id = '' ) {
		global $wikidata_api_url ;
		if ( $id != '' ) {
			$this->id = $id ;
			$url = "$wikidata_api_url?action=wbgetclaims&claim=$id&format=json" ;
			$j = json_decode ( file_get_contents ( $url ) ) ;
			$props = array();
			foreach ( $j->claims AS $p => $v ) $props[] = $p ;
			if (count($props) != 1) {
				print("WARNING: unexpected number of properties in wbgetclaims for $id");
			}
			$prop_label = $props[0];
			if (count($j->claims->$prop_label) != 1) {
				print("WARNING: unexpected number of statements in wbgetclaims for $id/$prop_label");
			}
			$this->p = $prop_label ;
			$statements = $j->claims->$prop_label;
			$this->c = $statements[0];
		}
	}
	
	public function getId () {
		return $this->id ;
	}
	
	public function getProps () {
		return [$this->p] ;
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

	public function statementQualifiersToQS ( ) {
		$ret = [] ;
		$qo = 'qualifiers-order' ;
		if ( !isset($this->c->qualifiers) or !isset($this->c->$qo) ) return $ret ;
		foreach ( $this->c->$qo AS $qual_prop ) {
			if ( !isset($this->c->qualifiers->$qual_prop) ) continue ;
			foreach ( $this->c->qualifiers->$qual_prop AS $x ) {
				$v = $this->getSnakValueQS ( $x ) ;
				if ( $v == '' ) continue ;
				$ret[] = "$qual_prop\t$v" ;
			}
		}
		return $ret ;
	}

	public function statementReferencesToQS ( ) {
		$ret = [] ;
		if ( !isset($this->c->references) ) return $ret ;
	
		$so = 'snaks-order' ;
		foreach ( $this->c->references AS $ref ) {
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

?>
