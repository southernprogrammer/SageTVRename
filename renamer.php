<?php
/* 
 * The purpose of this file is to give junction points to the sagetv files that
 * are in a decent XBMC style naming convention
 */

include('xml.php');

$myRenamer = new renamer();
$myRenamer->rename();

class renamer {

    private $_config_file;
    private $_username;
    private $_password;
    private $_context;
    private $_dirs;
    private $_newdir;

    private $_ignore_list;
    private $_replace_list;
    private $_custom_list;
    private $_platForm;
    private $_episodeYearInFolder;
    private $_logHandle;

    public function __construct()
    {
        $this->_config_file = XML_unserialize(file_get_contents("config.xml"));
        $this->_username = $this->_config_file['config']['username'];
        $this->_password = $this->_config_file['config']['password'];
        $this->_context = stream_context_create(array(
                                                'http' => array(
                                                    'header'  => "Authorization: Basic " . base64_encode("$this->_username:$this->_password")
                                                )
                                            ));
        if(is_array($this->_config_file['config']['dirs']['dir']))
            $this->_dirs = $this->_config_file['config']['dirs']['dir'];
        else
        {
            $this->_dirs = array();
            //make it an array and add this guy to it
            $this->dirs[] = $this->_config_file['config']['dirs']['dir'];
        }
        $this->_newdir = $this->_config_file['config']['newdir'];

        $this->_ignore_list = $this->getIgnoreList();
        $this->_custom_list = $this->getCustomList();

        $this->_platForm = $this->_config_file['config']['platform'];
        $this->_platForm = $this->_config_file['config']['platform'];
        $this->_episodeYearInFolder = $this->_config_file['config']['episodeYearInFolder'];
    }

    public function rename()
    {
        $this->_logHandle = fopen("log.txt", 'a') or die("can't open file");
        try{
            //the reading of files from the recording directories is non-recursive
            $processed = XML_unserialize(file_get_contents("processed.xml"));
            if($this->_config_file['config']['purgedeadlinks'] == "True")
                $this->purgeDeadLinks($processed);
            foreach($this->_dirs as $dir)
            {
                $dh = opendir($dir);
                while(($file = readdir($dh)) !== false) {
                    //main loop for file names
                    if($this->isValidFile($dir, $file, $processed))
                    {
                        $sageTvXML = $this->getSageTvXML($file);
                        if($sageTvXML != false)
                        {
                            $internalInfo = $this->getEpisodeInfoFromInternal($sageTvXML);
                            if($internalInfo)
                            {
                                $externalInfo = $this->getEpisodeInfoFromExternal($internalInfo['category'], $internalInfo['title'], $internalInfo['originalairdate'], $internalInfo['episodetitle'], $this->_ignore_list);
                                if($externalInfo)
                                    $this->createSymLink($dir, $file, $externalInfo, $processed, $internalInfo);
                            }
                            else
                            {
                                $this->logWriter("Could not find " . $file . " on SageTV server");
                            }
                        }
                    }
                }
            }
            //write back the information to the processed.xml file
            $xmlfh = fopen("processed.xml", 'w');
            fwrite($xmlfh, XML_serialize($processed));
            fclose($xmlfh);
        }
        catch(Exception $e)
        {
            $this->logWriter($e->getTrace());
        }

        fclose($this->_logHandle);
    }

    private function purgeDeadLinks(&$processed)
    {
        $slash = $this->getOSSlash();
        $count = 0;
        foreach($processed['processed']['record'] as $record)
        {
            if(!file_exists($record['original']))
            {
                //if the original doesn't exist, delete the symlink
                unlink($record['symlink']);
                //and remove the record from processed
                if(is_array($processed['processed']['record']))
                {
                    unset($processed['processed']['record'][$count]);
                    //reindex the array
                    $processed['processed']['record'] = array_merge($processed['processed']['record']);
                }
                else
                    unset($processed['processed']['record']);
            }
            else if(!file_exists($record['symlink']))
            {
                //if the symlink doesn't exist
                //remove the record from processed
                //so that we can recreate the link on rename
                if(is_array($processed['processed']['record']))
                    unset($processed['processed']['record'][$count]);
                else
                    unset($processed['processed']['record']);
            }
            $count++;
        }
    }


    private function createSymLink($dir, $file, $externalInfo, &$processed, $internalInfo)
    {
        $segmentNumber = $this->parseSegmentNumber($file);
        $link = $this->getSymLinkSaveLocation($externalInfo, $segmentNumber, $internalInfo, $file);
        if($link === null)
            return;
        $target = $dir . $this->getOSSlash() . $file;
        if($this->_platForm == "windows")
        {
            //use ntfs symlinks
            exec("mklink \"$link\" \"$target\"");
        }
        else
        {
            //use unix style symlinks
            exec("ln -s \"$target\" \"$link\"");
        }
        //log this stuff in processedXML
        $this->addToProcessedRecord($link, $target, $processed);
    }

    private function addToProcessedRecord($link, $target, &$processed)
    {
            $newItem['original'] = $target;
            $newItem['symlink'] = $link;
            $processed['processed']['record'][] = $newItem;     
    }
    private function getSymLinkSaveLocation($externalInfo, $segmentNumber, $internalInfo, $file)
    {
        //check if there is a replacement set for the showname
        //set in the config file
        //if so we want to make sure we name it that way
        $counter = 0;
        $showname = $internalInfo['title'];
        $mkdir = "";
        $newdir = $this->_newdir;
        $slash = $this->getOSSlash();
        $ext = substr($file, strripos($file, '.'), strlen($file) - strripos($file, '.'));
        
        if($internalInfo['category'] == 'Movie') // it is going to be moved into the movies folder
        {
                $moviesloc = $newdir . $slash . 'Movies';
                if(!file_exists($moviesloc))
                    mkdir($moviesloc);

                $newfile = $internalInfo['title'] . " " . " (" . $internalInfo['year'] . ")" . ".part" . ((int)$segmentNumber + 1) . $ext;
                $newloc = $moviesloc . $slash . $newfile;

                return $newloc;
        }


        foreach($this->_custom_list as $cl)
        {
                if($showname==$cl)
                        $showname = $this->_replace_list[$counter];
                $counter++;
        }


        if($externalInfo !== false) // if we got results, rename the file
        {
                //$internalInfo['episodetitle'] came from SageTV
                //$externalInfo['title'] came from tvrage.com

                $eptitleformat = $internalInfo['episodetitle'];
                //if the title is empty try the other data we got
                if(empty($eptitleformat))
                        $eptitleformat = $externalInfo['title'];
                //now if that info is not empty we can add parenthesis around the title
                if(!empty($eptitleformat))
                        $eptitleformat = ' (' . $this->replace_illegal_chars($eptitleformat) . ')';
                $showname = $this->replace_illegal_chars($showname); //cannot have illegal chars
                                                                     //in a file name
                if($this->_episodeYearInFolder == "True")
                        $mkdir = $newdir . $slash . $internalInfo['title'] . ' (' . $externalInfo['started'] . ')' . $slash;
                else
                        $mkdir = $newdir . $slash . $internalInfo['title'] . $slash;
                if(!file_exists($mkdir))
                        mkdir($mkdir);

                $newloc = $mkdir;
                $newloc = $newloc . "$showname.S" . $this->leading_zeros($externalInfo['season'], 2);
                $newloc = $newloc . 'E' . $externalInfo['episode'];
                $newloc = $newloc . ".part" . ((int)$segmentNumber + 1);
                $newloc = $newloc . $eptitleformat;
                $newloc = $newloc . $ext;
                //return symlink location
                return $newloc;

        }
        else //else log we can't rename it
        {
                $this->logWriter("$file could not Be found on TVRage.com");
                //move the file to Movies if it's a movie
        }

    }

    private function logWriter($text)
    {
        $time = date("m-d-Y g:i:s a");
        $message = $time . " - " . $text . "\r\n";
        print $message;
        fwrite($this->_logHandle, $message);
    }
    private function getOSSlash()
    {
        if($this->_platForm == "windows")
        {
            return "\\";
        }
        else
        {
            return "/";
        }
    }
    private function getEpisodeInfoFromInternal($sageTvXML)
    {
        //we should now have SageTV's metadata

        if($sageTvXML === null || !isset($sageTvXML['sageShowInfo']))
        {
            return false;
        }

        $baseInfo = $sageTvXML['sageShowInfo']['showList']['show'];
        $internalInfo['category'] = $baseInfo['category'];
        $internalInfo['title'] = $baseInfo['title'];
        //the format requires that we take everything before T
        //FORMAT: 2010-05-06T00:00:00.00Z
        $dateSplit = explode("T", $baseInfo['originalAirDate']);
        $datePlusOne = $dateSplit[0];
        //also the date also seems to always be a day later than what we want, must be a GMT thing
        //so we're going to subtract a day (86400 seconds)
        $internalInfo['originalairdate'] = date("Y-m-d", strtotime($datePlusOne) - 86400);
        if(isset($baseInfo['episode']))
            $internalInfo['episodetitle'] = $baseInfo['episode'];
        else
            $internalInfo['episodetitle'] = "";

        if(isset($baseInfo['year']))
            $internalInfo['year'] = $baseInfo['year'];
        else
            $internalInfo['year'] = "";

        return $internalInfo;
    }

    private function getEpisodeInfoFromExternal($category, $title, $originalairdate, $episodetitle, $ignore_list)
    {
            //if the category is 'Movie', there is no need to do a lookup, but we can report success
            //if the showname isn't suppose to be looked up, no need to lookup, report failure
            if($category == 'Movie')
                return true;
            else if(in_array($title, $ignore_list))
            {
                $this->logWriter($title . " being ignored.");
                return false;
            }

            //if an episodetitle begins with "A" or "The" remove it for better regex matching
            //we only do the episode title search if there is no airdate information, or it wasn't
            //found with the airdate
            if($ans = preg_match('/A .*$/', $episodetitle))
                    $episodetitle = substr($episodetitle, 2, strlen($episodetitle) - 2);
            if(preg_match('/The .*$/', $episodetitle))
                    $episodetitle = substr($episodetitle, 4, strlen($episodetitle) - 4);

            $episodeinfo = false;
            $showxml = file_get_contents("http://www.tvrage.com/feeds/search.php?show=" . urlencode($title));
            if($showxml === false) //we should at least get an empty xml file
                    die('Died on Show XML');
            $possible_shows_xml = XML_unserialize($showxml);

            if($possible_shows_xml['Results'] == 0) //if there are no results
            {
                $this->logWriter($title . " could not be found on TVRage.com");
                return false;
            }
            //if it only returned 1 show
            //put shows in the correct array structure
            if(!in_array('0', array_keys($possible_shows_xml['Results']['show'])))
                    $shows[0] = $possible_shows_xml['Results']['show'];
            else
                    $shows = $possible_shows_xml['Results']['show'];

            foreach($shows as $show) //for each of the found shows do an exhausive search for the air-date
            {
                    //if what we got for showname doesn't exist within the text of the showtitle
                    //we don't want any results
                    if(!strstr(strtolower($show['name']), strtolower($title)))
                            break;
                    $showid = $show['showid'];
                    $episode_xml = file_get_contents("http://www.tvrage.com/feeds/episode_list.php?sid=" . $showid);
                    if($episode_xml === false) //we should at least get something
                            die('Died on Episode XML');
                    $episodes_xml = XML_unserialize($episode_xml);
                    //if there is only 1 season, we have to modify the structure a little
                    if(isset($episodes_xml['Show']['Episodelist']['Season']['episode']))
                    {
                            $newarr[0] = $episodes_xml['Show']['Episodelist']['Season'];
                            $episodes_xml['Show']['Episodelist']['Season'] = $newarr;
                    }
                    $seasonnum = 1; //if there is only 1 season, 'no' will not be found, not sure why
                    foreach($episodes_xml['Show']['Episodelist']['Season'] as $season)
                    {
                            //get the season number or do the search
                            if(in_array('no', array_keys($season)))
                            {
                                    $seasonnum = $season['no'];
                            }
                            else //else we are looking at the actual season
                            {
                                    if(!is_array($season['episode']))
                                            $season['episode'][0] = $season['episode']; //in the case of 1 episode, make it an array
                                    foreach($season['episode'] as $episode)
                                    {
                                            if($episode['airdate'] == $originalairdate)
                                            {
                                                    $episodeinfo['season'] = $seasonnum;
                                                    $episodeinfo['episode'] = $episode['seasonnum'];
                                                    $episodeinfo['title'] = $episode['title'];
                                                    $episodeinfo['started'] = $show['started'];
                                                    return $episodeinfo;
                                            }

                                            //if the episodetitle that I passed into the function (and maybe took the words
                                            //"A" and "The" out of the front exists within the episode title that I found
                                            if($this->is_episode($episodetitle, $episode['title']))
                                            {
                                                    $episodeinfo['season'] = $seasonnum;
                                                    $episodeinfo['episode'] = $episode['seasonnum'];
                                                    $episodeinfo['title'] = $episode['title'];
                                                    $episodeinfo['started'] = $show['started'];
                                                    //we don't return episode info, because we want to search airdates first
                                                    //and return based on that first, if that fails we will return
                                                    //any information that was logged here
                                            }
                                    }
                            }
                    }
            }
            if($episodeinfo === false)
                $this->logWriter(" no episode information found for " . $title);
            return $episodeinfo; //if nothing was found, it is false
    }

    private function is_episode($sagetitle, $tvragetitle)
    {
            //if the title exists within the tvragetitle
            if(preg_match('/^.*' . strtolower($sagetitle) . '.*$/', strtolower($tvragetitle)))
                    return true;

            //if all the words in the title exist in the tvragetitle/////////////
            $find_arr = array(':', ';', '-');
            $replace_arr = array(' ', ' ', ' ');
            $exploded = explode(' ', str_replace($find_arr, $replace_arr, $sagetitle));
            $count = 0;
            foreach($exploded as $ex)
            {
                    if(preg_match('/^.*' . strtolower($ex) . '.*$/', strtolower($tvragetitle)))
                    {
                            $count++;
                    }
            }
            if($count == count($exploded))
                    return true;
            /////////////////////////////////////////////////////////////////////
            return false;
    }

    private function parseAiringId($file)
    {
        //get the exploded array getting rid of the stuff after the last dash
        $splitAtDashes = explode("-", $file, -1);
        if(count($splitAtDashes) < 2)
            return false;

        //count($splitAtDashes) - 1 is the last thing in the array, which is our airingid
        //because indexing starts at 0
        return $splitAtDashes[count($splitAtDashes) - 1];
    }

    private function parseSegmentNumber($file)
    {
        $splitDashes = explode("-", $file);
        $splitPeriod = explode(".", $splitDashes[count($splitDashes) - 1]);
        return $splitPeriod[0];
    }

    private function getSageTvXML($file)
    {
        $sageServer = $this->_config_file['config']['server'];
        $airingId = $this->parseAiringId($file);

        if($airingId == false)
            return false;

        $url = $sageServer . "?AiringId=" . $airingId . "&xml=yes";
        $xml = XML_unserialize($this -> getLocalXML($url));
        if($xml === null)
        {
            $this->logWriter(" could not find " . $file . " on sagetv server");
            return false;
        }
        else
            return $xml;
    }

    private function isValidFile($dir, $file, $processed_file)
    {
        $slash = $this->getOSSlash();

        //first check if the file we are checking isn't a directory, isn't hidden and has a valid extension
        if($file == '.' || $file == '..' || is_dir($this->_dir . '\\' . $file) || !$this->has_valid_extension($file))
                return false;

        //check if the file has already been processed
        if(isset($processed_file['processed']['record']) && is_array($processed_file['processed']['record']))
        {
            foreach($processed_file['processed']['record'] as $itr)
            {
                if($this->safe_string_equal($dir . $slash . $file, $itr['original']))
                {
                    //if it has it's not valid
                    return false;
                }
            }
        }
        else if(isset($processed_file['processed']['record']))
        {
            if($this->safe_string_equal($dir . $slash . $file, $processed_file['processed']['record']['original']))
            {
                //if it has it's not valid
                return false;
            }
        }

        return true;
    }

    private function safe_string_equal($string1, $string2)
    {
        return trim(strtolower($string1)) == trim(strtolower($string2));
    }

    private function has_valid_extension($file)
    {
        $config_file = $this->_config_file;
        if(isset($config_file['config']['validExtensions']['extension']) && is_array($config_file['config']['validExtensions']['extension']))
        {
            foreach($config_file['config']['validExtensions']['extension'] as $itr)
            {
                if($this->EndsWith($file, $itr))
                    return true;
            }
        }
        else if(isset($config_file['config']['validExtensions']['extension']))
        {
            if($this->EndsWith($file, $config_file['config']['validExtensions']['extension']))
                return true;
        }
        else
        {
            //default formats are mpeg, mpg, and ts no matter what the config file says
            if($this->EndsWith($file, ".mpeg") || $this->EndsWith($file, ".mpg") || $this->EndsWith($file, ".ts"))
                return true;
        }
        return false;
    }

    function EndsWith($FullStr, $EndStr)
    {
            $EndStr = trim($EndStr);
            // Get the length of the end string
            $StrLen = strlen($EndStr);
            // Look at the end of FullStr for the substring the size of EndStr
            $FullStrEnd = substr($FullStr, strlen($FullStr) - $StrLen);
            // If it matches, it does end with EndStr

            return $FullStrEnd == $EndStr;
    }

    private function getIgnoreList()
    {
        $config_file = $this->_config_file;
        $ignore_list = array();
        if(isset($config_file['config']['ignore']) && is_array($config_file['config']['ignore']))
                $ignore_list = $config_file['config']['ignore'];
        else if(isset($config_file['config']['ignore']))
                $ignore_list[0] = $config_file['config']['ignore'];
        return $ignore_list;
    }

    private function getCustomList()
    {
        $config_file = $this->_config_file;
        $custom_list = array();
        $this->_replace_list = array();
        if(isset($config_file['config']['customname']) && is_array($config_file['config']['customname']))
        {
                foreach($config_file['config']['customname'] as $itr)
                {
                        $custom_list[] = $itr['from'];
                        $this->_replace_list[] = $itr['to'];
                }
        }
        else if(isset($config_file['config']['customname']))
        {
                $custom_list[0] = $config_file['config']['customname']['from'];
                $this->_replace_list[0] = $config_file['config']['customname']['to'];
        }

        return $custom_list;
    }

    private function getLocalXML($url)
    {
        $config_file = $this->_config_file;
        if($config_file['config']['authentication'] == "True")
        {
            return file_get_contents($url, false, $this->_context);
        }
        else
        {
            return file_get_contents($url);
        }
    }

    function replace_illegal_chars($thestring)
    {
            $find_arr = array(':', '"', '?', '<', '>', '|', '\\', '/');
            $replace_arr = array('_', '_', '_', '_', '_', '_', '_', '_');
            $thestring = str_replace($find_arr, $replace_arr, $thestring);
            return $thestring;
    }

    function leading_zeros($value, $places){
            if(is_numeric($value)){
                    $leading = '';
                    for($x = 1; $x <= $places; $x++){
                            $ceiling = pow(10, $x);
                            if($value < $ceiling){
                                    $zeros = $places - $x;
                                    for($y = 1; $y <= $zeros; $y++){
                                            $leading .= "0";
                                    }
                            $x = $places + 1;
                            }
                    }
                    $output = $leading . $value;
            }
            else{
                    $output = $value;
            }
            return $output;
    }
}

?>
