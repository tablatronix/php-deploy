<?php

// get configuration
require_once 'deploy.conf';
if(!isset($reponame) || empty($reponame)) die('no reponame found in conf'); // assume branch is master

// set script timeout
$timeLimit = 5000;

// Init
$mode     = 0; // auto install
$response = "";
$force    = isset($_GET['force']);
$repo     = $reponame;
$branch   = 'master';

if(!isset($owner) || empty($owner)) $owner = $username; // assume user is owner
if(!empty($branchname)) $branch = $branchname; // assume branch is master

$node = $branch;

/**
 * Fetch tags or branches from github and do manual choice install.
 * @todo:  this is not yet implemented
 */
if($mode == 1) // manual deploy
{
	function callback ($url, $chunk){
		global $response;
		$response .= $chunk;
		return strlen($chunk);
	};

	$ch = curl_init("https://github.com/$owner/$repo/tags/");

	// curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");

	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent:Mozilla/5.0'));
	curl_setopt($ch, CURLOPT_WRITEFUNCTION, 'callback');
	curl_exec($ch);
	curl_close($ch);

	$changesets = json_decode($response, true);

}

//////////////////////////////////////////////////////
// BEGIN
//////////////////////////////////////////////////////

set_time_limit($timeLimit);

echo "<h1>Deploying repo <b>$owner / $repo / $branch</b> to <b>$dest</b></h1>";

$url = "https://github.com/$owner/$repo/archive/$branch.zip";
$outfile = "tip.zip";

fetchZip($url,$outfile);
if( !verifyZip($outfile)) die("Nothing found at url");
if( checkExist() ) die('Project is already up to date');

$wiperes = rmdirRecursively($dest);
trace('Wipe deploy', $wiperes);

$unzipres = unZip('tip.zip',$dest);

if($unzipres) {
	$unzipstatus = "Extracted to $dest".DIRECTORY_SEPARATOR;
} else {
	$unzipstatus = "Extract to $dest".DIRECTORY_SEPARATOR." FAILED";
}

trace("Extracting archive", $unzipstatus, !$unzipres );

$copyres = copy_recursively("$reponame-$node", $dest);
trace("Copy $dest$reponame-$node to $dest",$copyres);

$wiperepores = rmdirRecursively("$reponame-$node",true);
trace("Remove $dest$reponame-$node contents",$wiperepores);

rmdir("$reponame-$node");

// Delete the repo zip file
echo "Remove temp zip</br>";
unlink("tip.zip");


//////////////////////////////////////////////////////
// Functions
//////////////////////////////////////////////////////


/**
 * output tracing
 */
function trace($title,$contents = '',$open = false){
	echo "<details". ($open ? ' open':'') ."><summary>$title</summary>$contents</details>";
}

function traceSuccess($title,$success){
	echo "$title: <B>" . ($success ? 'SUCCESS' : 'ERROR') . "</b></br>";
}

/**
 * fetchZip
 * Fetches a zip file saves to outfile
 *
 * @param string $url
 * @param string $file
 */
function fetchZip($url,$file){
	GLOBAL $username,$password;

	// download the repo zip file

	$ch = curl_init($url);
	if(!$ch) die('cURL not found');

	$fp = fopen($file, 'w');
	if(!$fp) die('Cannot open file for writing: ' . $file);

	if(!empty($password)) curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");

	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);		
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);		// follow redirects
	curl_setopt($ch, CURLOPT_FILE, $fp);					
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // disable ssl cert verification

	$data = curl_exec($ch);

	if($data === false){ die('Curl error: ' . curl_error($ch)); }

	curl_close($ch);
	fclose($fp);
}


/**
 * @return false if zip is invalid
 */
function verifyZip($file){
	$tipsize = filesize($file);

	// verify zip saved
	if($tipsize < 50)
	{
		return false;
	}

	return true;
}

/**
 * checks that node is not already installed, handle forcing
 * @todo not saving any hashes at the moment simply node name
 */
function checkExist(){
	GLOBAL $force,$node;

	if(!$force && file_exists('lastcommit.hash')){
		$lastcommit = file_get_contents('lastcommit.hash');
		if($lastcommit == $node) return true;
	}
	
	file_put_contents('lastcommit.hash', $node);
}

/**
 * unZip
 * unzip file to dest folder
 *
 * @param string $file filepath to zip
 * @param string $dest dest path
 * @return ziparchive open return
 */
function unZip($file,$dest){
	// unzip
	$zip = new ZipArchive;
	$res = $zip->open($file);
	if ($res !== TRUE) {
		return $res;
	}

	$res = $zip->extractTo("$dest".DIRECTORY_SEPARATOR);
	$zip->close();

	return $res;
}

/**
 * function to delete all files in a directory recursively
 * @param string $dir directory to delete
 * @param bool $noExclude skip exclusions
 */
function rmdirRecursively($dir,$noExclude=false) {
	global $exc;
	$trace = '';

	// $noExclude |= ( preg_match('/\w{0,}-\w{0,}-[0-9|a|b|c|d|e|f]{12}/',$dir) > 0);
	# var_dump($noExclude);

	$trace.= "Erase dir: " . $dir ."<Br/>";

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	// FilesystemIterator::SKIP_DOTS ./ 5.3+

	$excludeDirsNames = isset($exc["folders"]) ? $exc["folders"] : array();
	$excludeFileNames = isset($exc["files"]) ? $exc["files"] : array();

	foreach ($it as $entry) {

		if ($entry->isDir()) {
			
			// php 5.2 support for skipdot            
			if($entry->getFilename() == '.' or $entry->getFilename() == '..'){
				continue;
			}
				
			if ($noExclude || !in_array(getRootName($entry->getPathname()), $excludeDirsNames)) {
				$trace.= rmdirRecursively($entry->getPathname());
				rmdir($entry->getPathname()); // remove dir after its empty
			}
			else{
				$trace.= "Erase dir: " . $entry->getPathname() . " <mark><b>SKIPPED</b></mark><br/>";
			}
		} elseif ( $noExclude || ( !in_array($entry->getFilename(), $excludeFileNames) && !in_array(getRootName($entry->getPathname()), $excludeDirsNames)) ) {
			$trace.=  "--Erase file: " . $entry->getPathname() ."<br/>";
			unlink($entry->getPathname());
		}
		else{
			$trace.=  "--Erase file: " . $entry->getPathname() . " <mark><b>SKIPPED</b></mark><br/>";
		}        
	}

	return $trace;
}

function getRootName($path){
	$path = str_replace('.'.DIRECTORY_SEPARATOR,'',$path);
	$parts = explode(DIRECTORY_SEPARATOR,$path);
	return $parts[0];
}

/**
 * function to copy all files in a directory to another recursively
 * @param string $src path
 * @param string $dest destination path
 */
function copy_recursively($src, $dest) {
	global $exc;

	$trace = '';
	$trace .= "Copy Recursive: $src $dest<br/>";

	$excludeDirsNames = isset($exc["folders"]) ? $exc["folders"] : array();
	$excludeFileNames =isset($exc["files"]) ? $exc["files"] : array();

	if (is_dir(''.$src)){
		@mkdir($dest);
		$files = scandir($src);

		foreach ($files as $file){
			if (!in_array(getRootName($dest), $excludeDirsNames)){
				if ($file != "." && $file != ".."){
					$trace.= copy_recursively("$src/$file", "$dest/$file");
				}    
			}
		}
	}
	else if (file_exists($src)){
		$filename = pathinfo($src, PATHINFO_FILENAME);
		//$filename = $filename[count( $filename)-2];
		if (!in_array( $filename, $excludeFileNames)){
			copy($src, $dest);
		}
	}

	return $trace;
}

if($mode != 1) echo 'Done';
?>