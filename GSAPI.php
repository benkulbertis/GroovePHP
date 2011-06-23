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
			$test = callRemote('');
			if($test['Result']['code'] !== 202){
				$this->host = '';
				return "Invalid host/API key";
			}
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
		 * Get information about the api key used.
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
		 */
		public function addUserFavoriteSong($songid){
			$result = self::callRemote('addUserFavoriteSong', array('GSAuth' => $this->auth, 'songID' => $songid));
      if ($result['Success'] != 1) {
					$this->error = $result['Result'];
          return false;
      } else {
          return true;
      }
		}

		/**
		 * Create a playlist.
		 * 
		 * @param		string	playlistName
		 * @param		array		songids
		 */
		public function createPlaylist($playlistName, $songids = array()){
			$songids = gs_json_encode($songids);
			$result = self::callRemote('createPlaylist', array('GSAuth' => $this->auth, 'name' => $playlistName, 'songIDs' => $songids));
      if ($result['Success'] != 1) {
					$this->error = $result['Result'];
          return false;
      } else {
          return true;
      }
		}

    /**
     * Authenticates the user for current API session
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
      if ($result['Success'] != 1) {
          return false;
      } else {
          $this->auth = $result['Result'];
          return true;
      }
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
			if($result['Success'] != 1){
				$this->error = $result['Result'];
				return false;
			} else {
				return $result['Result'];
			}
		}

    /**
     * Gets song information
     *
     * @param   int     songID
     * @return  mixed   Song information or error
     */
    public function getSongInfo($songID){
        $result = self::callRemote('getSongInfo', array('songID' => $songID));
        if (isset($result['Result'])) {
            return $result['Result'];
        } elseif (isset($result['errors'])) {
            return array('error' => $result['errors'][0]['code']);
        } else {
            return array('error' => -8);
        }
    }

    /**
     * Gets the favorite songs of the logged-in user
     *
     * @return  mixed   Songs list or error
     */
    public function userGetFavorites(){
        if (empty($this->auth)) {
            return array('error' => 'User Not Logged In');
        }
        $result = self::callRemote('getUserFavorites', array('GSAuth' => $this->auth));
        
        if($result['Success'] != 1 && isset($result['Result']['code'])){
            return $result['Result']['code'].": ".$result['Result']['string'];
        } else {
            return $result['Result'];
        }
    }

    public function getWidgetEmbedCode($width, $height, $ids, $swfName = 'songWidget.swf', $ap = 0, $colors = null, $style = null){
        $ids = 'songIDs=' . $ids['songIDs'];
        $colors = is_array($colors) && !empty($colors) ? '&amp;' . http_build_query($colors) : '';
        $ap = ($ap != 0) ? "&amp;p=$ap" : '';
        $style = '&style=' . $style;
        $embed = "
        <object width='$width' height='$height'>
            <param name='movie' value='http://listen.grooveshark.com/$swfName'></param>
            <param name='wmode' value='window'></param>
            <param name='allowScriptAccess' value='always'></param>
            <param name='flashvars' value='hostname=cowbell.grooveshark.com&amp;{$ids}{$ap}{$colors}{$style}'></param>
            <embed src='http://listen.grooveshark.com/$swfName' type='application/x-shockwave-flash' width='$width' height='$height' flashvars='hostname=cowbell.grooveshark.com&amp;{$ids}{$ap}{$colors}{$style}' allowScriptAccess='always' wmode='window'></embed>
        </object>";
        return $embed;
    }

}
