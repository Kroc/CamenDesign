<?php //sqlite.php: database class
//==============================================================================
// camen design, copyright (cc-by) Kroc Camen, 2003-2020
// licenced under Creative Commons Attribution 4.0
// <creativecommons.org/licenses/by/4.0/deed.en_GB>

class database {
	// query types supported, see the `query` method for descriptions
	const QUERY_STANDARD     = 0;
	const QUERY_ARRAY        = 1;
	const QUERY_SINGLE       = 2;
	const QUERY_SINGLE_ARRAY = 3;
	const QUERY_PREPARE      = 4;
	
	private $filepath;
	private $handle;
	private $sql;
	
	function __construct ($filepath, $sql = '') {
		// connect to the database
		// (automatically creates the file on disk if it doesn’t exist)
		$this->handle = new PDO ('sqlite:'.$filepath);
		//build the tables from the SQL originally passed to the class
		if ($sql) $this->exec ($sql);
	}
	
	// execute SQL statement(s) without returning a recordset.
	// instead returns true/false for success
	public function exec ($sql) {
		return $this->handle->exec ($sql);
	}
	
	public function query ($sql, $mode = self::QUERY_STANDARD) {
		// TODO: can use `match` statement in PHP8 to return-with-switch
		switch ($mode){
			case self::QUERY_ARRAY:
				// return the entire results as an array
				return $this->handle->query ($sql)->fetchAll (PDO::FETCH_NUM);
				break;

			case self::QUERY_SINGLE:
				// return just the value of the first column of the first row
				return $this->handle->query ($sql)->fetchColumn ();
				break;

			case self::QUERY_SINGLE_ARRAY:
				// return a flat array of the first value of each row
				return $this->handle->query ($sql)->fetchAll (PDO::FETCH_COLUMN);
				break;

			case self::QUERY_PREPARE:
				// compile an SQL query for repeat execution
				return $this->handle->prepare ($sql);
				break;

			default:
				return $this->handle->query ($sql, PDO::FETCH_NUM);
		}
	}

	public function prepare ($sql) {
		return $this->query ($sql, self::QUERY_PREPARE);
	}
	
	function __destruct () {
		$this->handle = NULL;
	}
}

?>