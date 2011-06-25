<?php
/**
 * GSAPI - PHP Class for interfacing with the APISHARK API.
 * 
 * @author Ben Kulbertis <ben@kulbertis.org>
 *
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2
 */

// These functions added for php versions that don't include json functions
function gs_json_decode($content, $arg) {
  if (!extension_loaded('json')) {
    if (!class_exists('GS_Services_JSON')) {
      require_once 'GSJSON.php';
    }
    $json = new GS_Services_JSON(SERVICES_JSON_LOOSE_TYPE);
    return $json->decode($content);
  } else {
    // just use php's json if it is available
    return json_decode($content, $arg);
  }
}

function gs_json_encode($content, $arg) {
  if (!extension_loaded('json')) {
    if (!class_exists('GS_Services_JSON')) {
      require_once 'GSJSON.php';
    }
    $json = new GS_Services_JSON(SERVICES_JSON_LOOSE_TYPE);
    return $json->encode($content);
  } else {
    // just use php's json if it is available
    return json_encode($content, $arg);
  }
}

class GSAPI {
  protected $auth = '';
  protected $callCount = 0;
  private static error = array();
  private static $host = '';
  private static $instance;

  function __construct($host){
    $this->host = $host;
    /* Blank request to check if API key is valid. Wastes an API call, so only use if necessary.
    $test = callRemote('');
    if($test['Result']['code'] !== 202){
      $this->host = '';
      return "Invalid host/API key";
    }
    */
  }
    
  private static function callRemote($method, $params = array()){
    $url = 'http://'.self::$host.$method.'/'.self::format_params($params);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 2);
    $result = curl_exec($curl);
    curl_close($curl);
    $decoded = gs_json_decode($result, true);
    $this->callCount = $decoded["RateLimit"]["CallsRemaining"]; // Update CallCount on every request
    return $decoded;
  }
    
  private static function format_params($params){
    $paramstring = '';
    foreach ($params as $key => $value) {
      $paramstring .= "$value/";
    }
    return $paramstring;
  }
  
  private static function getReturn($result = array(), $bool = false){
    if($result['Success'] != 1) {
      $this->error = $result['Result'];
      return false;
    } else {
      if($bool === false)
        return $result['Result'];
      else
        return true;
    }
  }

    /*
     * Gets an instance of the GSAPI object
     * 
     * @param   string	String containing the API host URL
     * @return  GSAPI   Instance of the GSAPI object
     */
  public static function getInstance($host){
    if (!(self::$instance instanceof GSAPI)) {
      self::$instance = new GSAPI($host);
    }
    return self::$instance;
  }

  public function getAuth() {return $this->auth;}
  public function getApiCallsCount() {return $this->callCount;}
  public function getError() {return $this->error;}
		
  /**
   * Get information about the API key used.
   *
   * Does not count as an API call.
   */
  public function APIKeyInfo(){
    return self::callRemote('APIKeyInfo');
  }
		
  /**
   * Add a song to a user's favorites. It is a violation to favorite songs for a user without notification.
   *
   * @param		int		songid
   *
   * @return  boolean
   */
  public function addUserFavoriteSong($songid){
    $result = self::callRemote('addUserFavoriteSong', array('GSAuth' => $this->auth, 'songID' => $songid));
    return self::getReturn($result, true);
  }

  /**
   * Create a playlist.
   * 
   * @param		string	playlistName
   * @param		array		songIDs
   *
   * @return  boolean
   */
  public function createPlaylist($playlistName, $songIDs = array()){
    $songIDs = gs_json_encode($songIDs);
    $result = self::callRemote('createPlaylist', array('GSAuth' => $this->auth, 'name' => $playlistName, 'songIDs' => $songIDs));
    return self::getReturn($result, true);
  }

  /**
   * Authenticates the user for current API session.
   * 
   * Accepts a username and password by default, but if $token is set to true it will accept
   * a pre-hashed token instead for security so that the password does not have to appear in plain text.
   *
   * @param   string  username
   * @param   string  validation_string
   * @param		boolean	token
   */
  public function genGSAuth($username, $validation_string, $token = false){
    if($token === false)	$validation_string = md5($username, md5($validation_string)); // APISHARK token format
    $result = self::callRemote('genGSAuth', array('username' => $username, 'token' => $validation_string));
    return self::getReturn($result, true);
  }

  /**
   * Get a playlist's information and songs. If the playlist doesn't exist, no Name element will be returned.
   * 
   * @param		string	playlistID
   * 
   * @return	mixed		Playlist Information or false
   */
  public function getPlaylistInfo($playlistID){
    $result = self::callRemote('getPlaylistInfo', array('playlistID' => $playlistID));
    return self::getReturn($result);
  }

  /**
   * Gets song information.
   *
   * @param   int     songID
   * @return  mixed   Song information or false
   */
  public function getSongInfo($songID){
    $result = self::callRemote('getSongInfo', array('songID' => $songID));
    return self::getReturn($result);
  }

  /**
   * Gets the favorite songs of the logged-in user.
   *
   * @return  mixed   num array of songs or false
   */
  public function getUserFavorites(){
    $result = self::callRemote('getUserFavorites', array('GSAuth' => $this->auth));
    return self::getReturn($result);
  }
    
  /**
   * Gets the library of the logged-in user.
   *
   * @return  mixed   num array of songs or false
   */
  public function getUserLibrary(){
    $result = self::callRemote('getUserLibrary', array('GSAuth' => $this->auth));
    return self::getReturn($result);
  }
  
  /**
   * Gets a user's playlists.
   *
   * @param   string  type  the way the user whose playlists are to be returned will be specified ["gsauth","username","userid"]
   * @param   string  data  the gsauth, username, or userid for the user (must match type specified in type param)
   *
   * @return  mixed   num array of the playlists or false
   */
  public function getUserPlaylists($type, $data = null){
    if($type === 'gsauth') $data =& $this->auth;
    
    $result = self::callRemote('getUserPlaylistsEx', array('type' => $type, 'data' => $data));
    return self::getReturn($result);
  }
  
  /**
   * Replace the songs of a playlist with the ones given. 
   * BE CAREFUL WITH THIS ONE! This replaces all the songs of a playlist with the specified ones. The old contents are gone forever.
   *
   * @param   int     playlistID
   * @param   array   songIDs
   *
   * @return  boolean
   */
  public function modifyPlaylist($playlistID, $songIDs = array()){
    $songIDs = gs_json_encode($songIDs);
    $result = self::callRemote('modifyPlaylist', array('GSAuth' => $this->auth, 'playlistID' => $playlistID, 'songIDs' => $songIDs));
    return self::getReturn($result, true);
  }
  
  /**
   * Returns whatever publically accessable information there is available about a user.
   *
   * @param   string  type  the way the user whose information is to be returned will be specified ["gsauth","username","userid"]
   * @param   string  data  the gsauth, username, or userid for the user (must match type specified in type param)
   *
   * @param   mixed   array of information or false
   */
  public function userInfo($type = , $data = null){
    if($type === 'gsauth'){
      $result = self::callRemote('userInfoFromGSAuth', array('GSAuth' => $this->auth));
    }
    elseif($type === 'username'){
      $result = self::callRemote('userInfo', array('username' => $data));
    } else {
      $result = self::callRemote('userInfoFromID', array('userID' => $data));
    }
    return self::getReturn($result);
  }

  /**
   * Generate the HTML to embed a song or queue of songs.
   * This is not part of the API, but its awesome. 
   *
   * @param   int     width
   * @param   int     height
   * @param   int     songID
   * @param   boolean autoplay
   * @param   string  style     style of the widget ["metal","wood","water","grass"]
   *
   * @return  string  the widget embed code
   */
  public function getWidgetEmbedCode($width, $height, $songID, $style = "metal", $autoplay = false){
    $songID = 'songIDs=' . $songID;
    $autoplay = ($autoplay === true) ? "&amp;p=1" : '&amp;p=0';
    $style = '&style=' . $style;
    $embed = "
    <object width='$width' height='$height'>
        <param name='movie' value='http://listen.grooveshark.com/songWidget.swf'></param>
        <param name='wmode' value='window'></param>
        <param name='allowScriptAccess' value='always'></param>
        <param name='flashvars' value='hostname=cowbell.grooveshark.com&amp;{$ids}{$ap}{$style}'></param>
        <embed src='http://listen.grooveshark.com/$swfName' type='application/x-shockwave-flash' width='$width' height='$height' flashvars='hostname=cowbell.grooveshark.com&amp;{$ids}{$ap}{$style}' allowScriptAccess='always' wmode='window'></embed>
    </object>
    ";
    return $embed;
  }

}
