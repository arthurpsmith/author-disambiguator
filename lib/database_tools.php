<?PHP

class DatabaseTools {
	private $mysql_user ;
	private $mysql_password ;
	private $mysql_host ;
	private $mysql_port ;

	public function __construct ( $passwd_file ) {
                $this->getDBpassword( $passwd_file ) ;
	}

	public function openToolDB($dbname) {
		$dbname = $this->mysql_user . '__' . $dbname;
		$db = @new mysqli($this->mysql_host, $this->mysql_user, $this->mysql_password , $dbname, $this->mysql_port);

		assert ( $db->connect_errno == 0 , 'Unable to connect to database [' . $db->connect_error . ']' ) ;
		return $db ;
	}

	private function getDBpassword($passwordfile) {
		$config = parse_ini_file( $passwordfile );
		if ( isset( $config['user'] ) ) {
			$this->mysql_user = $config['user'];
		}
		if ( isset( $config['password'] ) ) {
			$this->mysql_password = $config['password'];
		}
		if ( isset( $config['host'] ) ) {
			$this->mysql_host = $config['host'];
		} else {
			$this->mysql_host = 'tools.db.svc.eqiad.wmflabs';
		}
		if ( isset( $config['port'] ) ) {
			$this->mysql_port = $config['port'];
		} else {
			$this->mysql_port = '3306';
		}
	}
}
