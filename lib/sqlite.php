<?php //sqlite.php: database class
//==============================================================================
// camen design, copyright (cc-by) Kroc Camen, 2003-2020
// licenced under Creative Commons Attribution 4.0
// <creativecommons.org/licenses/by/4.0/deed.en_GB>

class database {
	//query types supported, see the `query` method for descriptions
	const QUERY_STANDARD     = 0;
	const QUERY_ARRAY        = 1;
	const QUERY_SINGLE       = 2;
	const QUERY_SINGLE_ARRAY = 3;
	const QUERY_PREPARE      = 4;
	
	private $filepath;
	private $handle;
	private $sql;
	
	function __construct ($filepath, $sql = '') {
		//connect to the database (automatically creates the file on disk if it doesn’t exist)
		$this->handle = new PDO ('sqlite:'.$filepath);
		//build the tables from the SQL originally passed to the class
		if ($sql) $this->exec ($sql);
	}
	
	//execute SQL statement(s) without returning a recordset. instead returns true/false for success
	public function exec ($sql) {
		return $this->handle->exec ($sql);
	}
	
	public function query ($sql, $mode = self::QUERY_STANDARD) {
		return 	//return the entire results as an array
			$mode == self::QUERY_ARRAY  ? $this->handle->query ($sql)->fetchAll (PDO::FETCH_NUM) : (
			//return just the value of the very first column of the first row
			$mode == self::QUERY_SINGLE ? $this->handle->query ($sql)->fetchColumn () : (
			//return a flat array of the first value of each row
			$mode == self::QUERY_SINGLE_ARRAY ? $this->handle->query ($sql)->fetchAll (PDO::FETCH_COLUMN) : (
			//compile an SQL query for repeat execution
			$mode == self::QUERY_PREPARE ? $this->handle->prepare ($sql)
			//else: return a standard result set
			: $this->handle->query ($sql, PDO::FETCH_NUM)
		)));
	}
	
	public function prepare ($sql) {
		return $this->query ($sql, self::QUERY_PREPARE);
	}
	
	function __destruct () {
		$this->handle = NULL;
	}
}

?>