<?
	error_reporting(E_ALL);
	define('CONFIG'				,"/volume1/homes/admin/torrents/torrents.config.json");
	$config = json_decode(file_get_contents(CONFIG), true);
	
	$excludeShows = "(brrip|1080p|dvdrip|hebsub|dvdr|480p|WEB\-DL|lies)"; // 'lies' is for house of lies
	$excludeMovies= "(hdtv|dvdrip|1080p|hebsub|dvdr|480p|WEB\-DL)";
	
	$historyFile = $config['HISTORY_FILE'];
	
	$sources = $config['sources'];
	$shows = "(".implode("|",$config['shows']).")";
	$movies = "(".implode("|",$config['movies']).")";

	function downloadTorrent($url){
		$filename = md5($url);
		$cmd = 'wget -q -O "'.$config['AUTOTORRENTS_PATH'].$filename.'.torrent" '.$url; 
		exec($cmd."\n");
	}

	function getXML($url){
		$ch = curl_init();
    	curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
		curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 500);
        $output = curl_exec($ch);
		curl_close($ch);
		return $output;
	}



	$downloadList = array();
	foreach($sources as $sourceKey=>$url){
		$data = getXML($url);
		if (!$data) {
			continue;
		}

		if (preg_match("/windows-1251/is",$data)){
			$data = preg_replace("/windows\-1251/is","utf-8",$data);
			$data = mb_convert_encoding($data,'utf-8','windows-1251');
		}

		$data = str_replace("&","&amp;",$data);

		$xml = simplexml_load_string($data,"SimpleXMLElement",LIBXML_NOCDATA);
		// Create a download list
		foreach($xml->channel->item as $i){
			$title = $i->title;
			if (strstr($i->link,'.torrent')){
				$torrent = (string) $i->link;
			} else {
				$torrent =  $i->enclosure['url'];
			}


			// Prepare the downloadList for Tv shows
			if (!preg_match("/$excludeShows/is",$title)){
				if (preg_match("/$shows\s(.*?)S([0-9]+?)E([0-9]+?)\s720p\s/is",$title,$m)){
					$episode = "S".$m[3]."E".$m[4];
					preg_match("/(.*?)$episode(.*?)/is",$title,$cleanTitle);
					$theTitle = ucfirst(trim($cleanTitle[1]));
					$theContent = array($theTitle,$torrent,$episode,$sourceKey);
					$downloadList[$theTitle] = $theContent;
				}
			}


			// Prepare the downloadList for Tv shows
			if (!preg_match("/$excludeMovies/is",$title)){
				if (preg_match("/^$movies\s(.*?)\s720p\s/is",$title,$m)){
					$theTitle = ucwords(strtolower(trim($m[1])));
					$theDate = trim(intval($m[2]));
					//$theTitle = trim($theTitle.' '.$theDate);
					// double check with the size. It should be greater than 2GB
					// TBD
					$theContent = array($theTitle,$torrent,null,$sourceKey);
					$downloadList[$theTitle] = $theContent;
				}
			}
		}
	}
	

	// Download all files inthat download list
	$messages = $files = array();
    $lines = file($historyFile,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($downloadList) foreach($downloadList as $d){
    	$entry = ($d[0]==$d[2]) ? $d[0] : $d[0]." ".$d[2];
    	$download = (in_array($entry,$lines) || in_array($entry,$files)) ? false : true;
    	if ($download) {
    		downloadTorrent($d[1]);
    		$history = fopen($historyFile, 'a');
    		$messages[$entry] = "[".$d[3]."] $entry";
    		$files[] = $entry;
    		fwrite($history,$entry."\n");
    		fclose($history);
    	}
    }

	if ( count($messages)>0 ) {
		system('/bin/echo "'.implode("\n",$messages).'" | /opt/bin/nail -s "Auto Downloads" '.$config['email']);
	}
?>
