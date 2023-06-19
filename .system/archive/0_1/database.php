<?php //database.php / written by kroc camen of camen design
/* ======================================================================================================================= */
require_once 'code.php';

/* === open / create database ============================================================================================ */
//see the `database` class. this is a lazy connection. if the database doesn’t exist the SQL schema below will be created
$database = new database (_root.'/data/content.sqlite',
	//yes, "when" is a reserved word in SQL, but dammit, it’s a simple non-geeky name for the field and I like it,
	//also `[field]` is not commonly used, but it is more reliable and prevents the "named constant" bug in sqlite<3.3.5
	'CREATE TABLE [content] ('.
		'[when]      INT PRIMARY KEY,'.		//INT instead of INTEGER, disables auto-numbering of primary key
		'[updated]   INTEGER,'.			//last edit timestamp (for the RSS) YYYYMMDDHHMM
		'[title]     TEXT,'.			//html
		'[content]   TEXT,'.			//html
		'[tags]      TEXT,'.			//"|tag|tag|tag|tag|"
		'[enclosure] TEXT'.			//"mime-type;filename;preview_filename"
	');'.
	'CREATE TABLE [tags] ('.
		'[tag] CHAR(20) PRIMARY KEY'.
	');'
);

/* ======================================================================================================================= */

//insert a content entry into the database (used by '/data/index.php' the "admin" page)
function addContent ($i_when, $s_title, $s_content, $s_tags, $s_enclosure) {
	global $database;
	
	//add the given content to the database
	$database->exec (
		"INSERT INTO [content] VALUES($i_when, $i_when, '".sqlite_escape_string ($s_title)."', ".
		"'".sqlite_escape_string ($s_content)."', '$s_tags', '$s_enclosure');"
	);
	
	//check if the tags used already exist
	foreach (explode ('|', trim ($s_tags, '|')) as $tag) {
		if (!$database->query ("SELECT [tag] FROM [tags] WHERE [tag]='$tag';", database::query_single)) {
			//tag does not exist, add it
			$database->exec ("INSERT INTO [tags] VALUES('$tag');");
		}
	}
	//clear the cached pages which contains the tagcloud, and the cached tagcloud to let that re-count
	//also empty the rss cache to let that rebuild and let people pull that lovely new content
	deleteCache ('page|tags|rss');
	
	return $i_when;
}

/* ----------------------------------------------------------------------------------------------------------------------- */

function editContent ($i_when, $s_title, $s_content, $s_tags, $s_enclosure, $i_updated, $i_backdate) {
	global $database;
	
	//get the existing content to compare changes:
	@list ($data) = $database->query (
		"SELECT [tags], [enclosure] FROM [content] WHERE [when]=$i_when;", database::query_array
	);
	//find the content type
	$old_content_type = preg_match ('/\|('._content_types.')\|/', $data[0], $_) ? $_[1] : '';
	$new_content_type = preg_match ('/\|('._content_types.')\|/', $s_tags,  $_) ? $_[1] : '';
	
	//if the enclosure was changed, or the content-type was changed, delete the old files
	if ($data[1] && ($data[1] != $s_enclosure || $new_content_type != $old_content_type)) {
		$old_enclosure = explode (';', $data[1]);
		unlink (_root."/data/content-media/$old_content_type/".$old_enclosure[0]);
		//if the file has a preview, delete that too
		if (isset ($old_enclosure[2])) unlink (_root."/data/content-media/$old_content_type/".$old_enclosure[2]);
	}
	
	//if backdating?
	if ($i_backdate) {
		//if you backdate, the 'updated' date is reset to match, so that the content is filed in the RSS correctly
		$i_updated = ($i_when == $i_updated) ? $i_backdate : $i_updated;
	} else {
		$i_backdate = $i_when;
	}
	
	//update the database
	$database->exec (
		"UPDATE [content] SET ".
		"[when]=$i_backdate, [updated]=$i_updated, [title]='".sqlite_escape_string ($s_title)."', ".
		"[content]='".sqlite_escape_string ($s_content)."', [tags]='$s_tags', [enclosure]='$s_enclosure' ".
		"WHERE [when]=$i_when;"
	);
	
	//check if the tags used already exist
	foreach (explode ('|', trim ($s_tags, '|')) as $tag) {
		if (!$database->query ("SELECT [tag] FROM [tags] WHERE [tag]='$tag';", database::query_single)) {
			//tag does not exist, add it
			$database->exec ("INSERT INTO [tags] VALUES('$tag');");
		}
		//todo: also check if a tag became orphaned and has to be deleted from the tags table
	}
	
	//clear the related cache
	deleteCache ("page|$i_when|tags|rss");
	
	return $i_when;
}

/* ----------------------------------------------------------------------------------------------------------------------- */

function deleteContent ($i_when) {
	global $database;
	
	//get the existing content to find extra things to delete (enclosures, tags)
	list ($data) = $database->query (
		"SELECT [tags], [enclosure] FROM [content] WHERE [when]=$i_when;", database::query_array
	);
	//find the content type
	$content_type = preg_match ('/\|('._content_types.')\|/', $data[0], $_) ? $_[1] : '';
	
	//if the entry had an enclosure, delete that
	if ($data[1]) {
		$enclosure = explode (';', $data[1]);
		unlink (_root."/data/content-media/$content_type/".$enclosure[0]);
		//if the file has a preview, delete that too
		if (isset ($enclosure[2])) unlink (_root."/data/content-media/$content_type/".$enclosure[2]);
	}
	
	//delete the entry from the database
	$database->exec ("DELETE FROM [content] WHERE [when]=$i_when;");
	
	//todo: check for orphaned tags
	
	//clear the related cache
	deleteCache ("page|$i_when|tags|rss");
}

/* ----------------------------------------------------------------------------------------------------------------------- */

//the edit/delete operations may obsolete some tags that should be removed from the `tags` table
function removeOrphanedTags () {
	
}

/* ======================================================================================================================= */

class database {
	//query types supported, see the `query` method for descriptions
	const query_standard     = 0;
	const query_array        = 1;
	const query_single       = 2;
	const query_single_array = 3;
	const query_prepare      = 4;
	
	private $filepath;
	private $handle;
	private $sql;
	
	function __construct ($s_filepath, $s_sql = '') {
		$this->filepath = $s_filepath;
		$this->sql      = $s_sql;
	}
	
	private function connect () {
		//is the data folder writeable? this is an odd place to put this piece of code, but the reason why is that
		//we don’t want to have to hit the disk every page load just to check this once-off fact
		if (!is_writable (_root.'/data/')) {
			//attempt to make the folder writable:
			chmod (_root.'/data/', 0755);
			die ('data folder is not writeable. chmod attempted, please refresh to recheck.');
		}
		
		//does the database file exist on disk?
		$populate = file_exists ($this->filepath);
		//connect to the database (automatically creates the file on disk if it doesn’t exist)
		$this->handle = new PDO ('sqlite:'.$this->filepath);
		
		//if the database is new, build the tables from the sql originally passed to the class
		if (!$populate) $this->exec ($this->sql);
	}
	
	//execute sql statement(s) without returning a recordset. instead returns true/false for success
	public function exec ($s_sql) {
		//no connection is made to the database until a query is made
		if (!isset ($this->handle)) $this->connect ();
		return $this->handle->exec ($s_sql);
	}
	
	public function query ($s_sql, $i_mode = self::query_standard) {
		//no connection is made to the database until a query is made
		if (!isset ($this->handle)) $this->connect ();
		
		return 	//return the entire results as an array
			$i_mode == self::query_array  ? $this->handle->query ($s_sql)->fetchAll (PDO::FETCH_NUM) : (
			//return just the value of the very first column of the first row
			$i_mode == self::query_single ? $this->handle->query ($s_sql)->fetchColumn () : (
			//return a flat array of the first value of each row
			$i_mode == self::query_single_array
			? $this->handle->query ($s_sql)->fetchAll (PDO::FETCH_COLUMN) : (
			//compile an sql query for repeat execution
			$i_mode == self::query_prepare ? $this->handle->prepare ($s_sql)
			//else: return a standard result set
			: $this->handle->query ($s_sql, PDO::FETCH_NUM)
		)));
	}
	
	function __destruct () {
		$this->handle = null;
	}
}

/* ==================================================================================================== code is art === */ ?>