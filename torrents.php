<?
	error_reporting(E_ALL);
	define('DEBUG',	0);
	define('CONFIG'	,dirname ( __FILE__ )."/torrents.config.json");
	$config = json_decode(file_get_contents(CONFIG), true);
	
	$shows = "(".implode("|",$config['shows']).")";
	$movies = "(".implode("|",$config['movies']).")";

	// strips characters and finds episodes number
	function getShowsNumber($episode){
		return preg_replace("[^0-9]", "",$episode);	
	}
		
	// Checks whether this is a new download based on the episode number
	function isNewestFile($show,$episode,$lines){
		preg_match_all("/(.*?)S([0-9]+)E([0-9]+)/is",implode("\n",$lines),$matches);
		$trimmed = array_reverse(array_map('strtolower',array_map('trim',$matches[1])));
		$seasons = array_reverse($matches[2]);
		$episodes = array_reverse($matches[3]);
		$k = array_search(strtolower(trim($show)),$trimmed);
		if (strtolower($show) == strtolower($trimmed[$k])){
			if (getShowsNumber($episode)>getShowsNumber($seasons[$k].$episodes[$k])){
				echo (DEBUG) ? $show." - Will Download: ".getShowsNumber($episode)." is newer than ".getShowsNumber($seasons[$k].$episodes[$k])."\n" : '';
				return false;
			} else {
				echo (DEBUG) ? $show." - Will Skip: ".getShowsNumber($episode)." is older or equal than ".getShowsNumber($seasons[$k].$episodes[$k])."\n" : '';
				return true;
			}
		} else {
			echo (DEBUG) ? 'Will download for the first time '.$show."\n" : '';
			return false;
		}
		
	}

	// Actual torrent downloaded
	function downloadTorrent($url){
		global $config;
		$path = $config['autotorrents_path']; 
		$filename = md5($url);
		$cmd = 'wget -q -O "'.$path.$filename.'.torrent" "'.$url.'"'; 
		if (DEBUG){
			echo $cmd."\n";		
		} else {
			exec($cmd);
		}
	}

	$downloadList = array();	
	foreach($config['sources'] as $sourceKey=>$url){
		try {
			// Support for gzipped xml
			$xml = @new SimpleXMLElement("compress.zlib://$url", NULL, TRUE);
			//print_r($xml);echo "\n\n\n\n\n";
			// Create a download list
			foreach($xml->channel->item as $i){
				$title = $i->title;
				if (strstr($i->link,'.torrent')){
					$torrent = (string) $i->link;
				} else {
					$torrent =  $i->enclosure['url'];
				}
						
				// Prepare the downloadList for Tv shows
				if (!preg_match("/${config['exclude_shows']}/is",$title)){
//					if (preg_match("/$shows\s(.*?)S([0-9]+)E([0-9]+)(.*?)720p\s/is",$title,$m)){
					if (preg_match("/$shows(.*?)S([0-9]+)E([0-9]+)(.*?)720p/is",$title,$m)){
						$episode = "S".$m[3]."E".$m[4];
						preg_match("/(.*?)$episode(.*?)/is",$title,$cleanTitle);
						$theTitle = str_replace(".","",ucfirst(trim($cleanTitle[1])));
						$theContent = array($theTitle,$torrent,$episode,$sourceKey);
						$downloadList[$theTitle] = $theContent;
					}
				}
	
				// Prepare the downloadList for Movies
				if (!preg_match("/${config['exclude_movies']}/is",$title)){
					if (preg_match("/$movies(.*?)([0-9]{4})\s720p\s/is",$title,$m)){
						$theTitle = ucwords(strtolower(trim($m[1])));
						$theDate = trim(intval($m[3]));
						//$theTitle = trim($theTitle.' '.$theDate);
						// double check with the size. It should be greater than 2GB
						// TBD
						$theContent = array($theTitle,$torrent,$theDate,$sourceKey);
						$downloadList[$theTitle] = $theContent;
					}
				}
			}
		} catch (Exception $e) {
			echo $sourceKey.' contains errors in XML: ',  $e->getMessage(), " - Skipping\n";
		}
	}

	if (DEBUG) { print_r($downloadList); }
	
	// Download all files inthat download list
	$messages = $files = array();
	$lines = file($config['history_file'],FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($downloadList) foreach($downloadList as $d){
    	$entry = ($d[0]==$d[2]) ? $d[0] : $d[0]." ".$d[2];
    	// Check if we already have this file on our download list
    	// Also if proper is found, bypass the check and still download it
    	$download = (isNewestFile($d[0],$d[2],$lines) || in_array($entry,$lines) || in_array($entry,$files) || preg_match("/proper/is",$d[1])) ? false : true;
    	if ($download && !DEBUG) {
    		downloadTorrent($d[1]);
    		$history = fopen($config['history_file'], 'a');
    		$messages[$entry] = "[".$d[3]."] $entry";
    		$files[] = $entry;
    		fwrite($history,$entry."\n");
    		fclose($history);
    	}
    }

    if ( count($messages)>0 && !DEBUG) {
    	// or use php's mail() - I'm running this on a synology device that didn't allow it
		system('echo "'.implode("\n",$messages).'" | nail -s "Auto Downloads" '.$config['email']);
	}	
?>
