<?php
if (! defined('AVH_FRAMEWORK'))
	die('You are not allowed to call this page directly.');

class AVH_FDAS_SpamCheck
{
	/**
	 *
	 * @var AVH_FDAS_Core
	 */
	private $_core;
	/**
	 * @var AVH_Settings_Registry
	 */
	private $_settings;
	/**
	 * @var AVH_Class_registry
	 */
	private $_classes;
	/**
	 * The $use_xx variables are used to determine if that specific 3rd party can used for that check.
	 * For example: We can't use Stop Forum Spam to check every IP, only at comments and register.
	 */
	private $_useStopForumSpam;
	private $_useProjectHoneyPot;
	private $_useCache;
	private $_visiting_ip;
	private $_core_options;
	private $_core_data;
	private $_accessing;
	/**
	 *
	 * @var AVH_FDAS_DB
	 */
	private $_ipcachedb;
	public $spaminfo;
	public $spammer_detected;
	public $ip_in_cache;
	public $ip_in_white_list;

	/**
	 * PHP5 Constructor
	 *
	 */
	public function __construct ()
	{
		// Get The Registry
		$this->_settings = AVH_FDAS_Settings::getInstance();
		$this->_classes = AVH_FDAS_Classes::getInstance();
		// Initialize the plugin
		$this->_core = $this->_classes->load_class('Core', 'plugin', TRUE);
		$this->_ipcachedb = $this->_classes->load_class('DB', 'plugin', TRUE);
		$this->_visiting_ip = AVH_Visitor::getUserIp();
		$this->_core_options = $this->_core->getOptions();
		$this->_core_data = $this->_core->getData();
		$this->spaminfo = null;
		$this->spammer_detected = FALSE;
		$this->ip_in_white_list = FALSE;
		$this->ip_in_cache = FALSE;
	}

	/**
	 *
	 * Run all the checks for the main action.
	 * We don't check with Stop Forum Spam as this overloads their site.
	 *
	 */
	public function doSpamcheckMain ()
	{
		if ($this->_visiting_ip != '0.0.0.0') { // Visiting IP is a private IP, we don't check private IP's
			$this->checkWhitelist();
			if ($this->ip_in_white_list === FALSE) {
				$this->checkBlacklist();
				if ($this->spammer_detected === FALSE) {
					$this->doIpCacheCheck();
					if ($this->ip_in_cache === FALSE) {
						$this->doProjectHoneyPotIpCheck();
					}
				}
				$this->handleResults();
			}
		}
	}

	/**
	 * Run the checks for the action preprocess_comment.
	 *
	 */
	public function doSpamcheckCommentPosted ()
	{
		if ($this->_visiting_ip != '0.0.0.0') { // Visiting IP is a private IP, we don't check private IP's
			$this->checkWhitelist();
			if ($this->ip_in_white_list === FALSE) {
				$this->checkBlacklist();
				if ($this->spammer_detected === FALSE) {
					$this->doIpCacheCheck();
					if ($this->ip_in_cache === FALSE) {
						$this->doStopForumSpamIpCheck();
						$this->doProjectHoneyPotIpCheck();
					}
				}
				$this->handleResults();
			}
		}
	}

	/**
	 * Run the checks for the action pre_comment_on_post.
	 *
	 */
	public function doSpamcheckPreCommentPost ()
	{
		if ($this->_visiting_ip != '0.0.0.0') { // Visiting IP is a private IP, we don't check private IP's
			$this->checkWhitelist();
			if ($this->ip_in_white_list === FALSE) {
				$this->checkBlacklist();
				if ($this->spammer_detected === FALSE) {
					$this->doIpCacheCheck();
					if ($this->ip_in_cache === FALSE) {
						$this->doStopForumSpamIpCheck();
						$this->doProjectHoneyPotIpCheck();
					}
				}
				$this->handleResults();
			}
		}
	}

	/**
	 * Run the checks for the action register_post.
	 *
	 */
	public function doSpamcheckUserRegister ()
	{
		if ($this->_visiting_ip != '0.0.0.0') { // Visiting IP is a private IP, we don't check private IP's
			$this->checkWhitelist();
			if ($this->ip_in_white_list === FALSE) {
				$this->checkBlacklist();
				if ($this->spammer_detected === FALSE) {
					$this->doIpCacheCheck();
					if ($this->ip_in_cache === FALSE) {
						$this->doStopForumSpamIpCheck();
						$this->doProjectHoneyPotIpCheck();
					}
				}
				$this->handleResults();
			}
		}
	}

	/**
	 * Check the cache for the IP
	 *
	 */
	public function doIpCacheCheck ()
	{
		$this->ip_in_cache = FALSE;
		if (1 == $this->_core_options['general']['useipcache']) {
			$time_start = microtime(true);
			$this->ip_in_cache = $this->_ipcachedb->getIP($this->_visiting_ip);
			$time_end = microtime(true);
			$time = $time_end - $time_start;
			if (! (FALSE === $this->ip_in_cache)) {
				if ($this->ip_in_cache->spam === '1') {
					$this->spaminfo['cache']['time'] = $time;
					$this->spammer_detected = TRUE;
				}
			}
		}
	}

	/**
	 * Do Project Honey Pot with Visitor
	 *
	 * Sets the spaminfo['detected'] to true when a spammer is detected.
	 *
	 */
	public function doProjectHoneyPotIpCheck ()
	{
		if ($this->_core_options['general']['use_php']) {
			
			$reverse_ip = implode('.', array_reverse(explode('.', $this->_visiting_ip)));
			$projecthoneypot_api_key = $this->_core_options['php']['phpapikey'];
			$this->spaminfo['php'] = NULL;
			//
			// Check the IP against projecthoneypot.org
			//
			$time_start = microtime(true);
			$lookup = $projecthoneypot_api_key . '.' . $reverse_ip . '.dnsbl.httpbl.org.';
			$info = explode('.', gethostbyname($lookup));
			
			// The first octet needs to be 127.
			// Quote from the HTTPBL Api documentation: If the first octet in the response is not 127 it means an error condition has occurred and your query may not have been formatted correctly.
			// Reference :http://www.projecthoneypot.org/httpbl_api.php
			if ('127' == $info[0]) {
				$this->spammer_detected = TRUE;
				$time_end = microtime(true);
				$time = $time_end - $time_start;
				$this->spaminfo['php']['time'] = $time;
				
				$this->spaminfo['php']['days'] = $info[1];
				$this->spaminfo['php']['type'] = $info[3];
				if ('0' == $info[3]) {
					$this->spaminfo['php']['score'] = '0';
					$this->spaminfo['php']['engine'] = $this->_settings->searchengines[$info[2]];
				} else {
					$this->spaminfo['php']['score'] = $info[2];
				}
			
			}
		}
	}

	/**
	 * Function to handle everythign when a potential spammer is detected.
	 *
	 */
	public function handleResults ()
	{
		global $post;
		
		if (TRUE === $this->spammer_detected) {
			if ('/wp-comments-post.php' == $_SERVER['REQUEST_URI']) {
				$title = isset($post->post_title) ? $post->post_title : '';
				$id = isset($post->ID) ? $post->ID : 0;
				// Trying to post a comment, lets determine which post they are trying to post at
				$this->_accessing = sprintf(__('Commenting on:	"%s" ( %s )', 'avh-fdas'), apply_filters('the_title', $title, $id), get_permalink($post->ID));
			} else {
				$this->_accessing = sprintf(__('Accessing:	%s', 'avh-fdas'), $_SERVER['REQUEST_URI']);
			}
			/*if (!empty($_POST)) {
				$this->_accessing .= "\n".'$_POST data'."\n";
				foreach ($_POST as $key => $value) {
					$this->_accessing .= $key .' => '.$value."\n";
				}
			}*/
			if (is_object($this->ip_in_cache)) {
				$this->_handleSpammerCache();
			} else {
				$this->_handleSpammer();
			}
		} else {
			if (is_object($this->ip_in_cache)) {
				$this->_ipcachedb->updateIpCache(array('ip'=>$this->_visiting_ip, 'lastseen'=>current_time('mysql')));
			} else {
				$this->_ipcachedb->insertIp($this->_visiting_ip, 0);
			}
		}
	}

	/**
	 * Convert the Stop Forum Spam data to something I already was using.
	 *
	 * @param $data
	 */
	private function _convertStopForumSpamCall ($data)
	{
		if (isset($data['Error'])) {
			return ($data);
		}
		if (isset($data['ip'])) {
			return ($data['ip']);
		}
	}

	/**
	 * Check an IP with Stop Forum Spam
	 *
	 * @param $ip Visitor's IP
	 * @return $spaminfo Query result
	 */
	public function doStopForumSpamIpCheck ()
	{
		if ($this->_core_options['general']['use_sfs']) {
			
			$time_start = microtime(true);
			$result = $this->_core->handleRestCall($this->_core->getRestIPLookup($this->_visiting_ip));
			$time_end = microtime(true);
			$this->spaminfo['sfs'] = $this->_convertStopForumSpamCall($result);
			$time = $time_end - $time_start;
			$this->spaminfo['sfs']['time'] = $time;
			if (isset($this->spaminfo['sfs']['Error'])) {
				if ($this->_core_options['sfs']['error']) {
					$error = $this->_core->getHttpError($this->spaminfo['sfs']['Error']);
					$to = get_option('admin_email');
					$subject = sprintf('[%s] AVH First Defense Against Spam - ' . __('Error detected', 'avh-fdas'), wp_specialchars_decode(get_option('blogname'), ENT_QUOTES));
					$message[] = __('An error has been detected', 'avh-fdas');
					$message[] = sprintf(__('Error:	%s', 'avh-fdas'), $error);
					$message[] = '';
					$message[] = sprintf(__('IP:		%s', 'avh-fdas'), $this->_visiting_ip);
					$message[] = sprintf(__('Accessing:	%s', 'avh-fdas'), $_SERVER['REQUEST_URI']);
					$message[] = sprintf(__('Call took:	%s', 'avh-fdas'), $time);
					AVH_Common::sendMail($to, $subject, $message, $this->_settings->getSetting('mail_footer'));
				}
				$this->spaminfo['sfs'] = NULL;
			} else {
				if (1 == $this->spaminfo['sfs']['appears']) {
					$this->spammer_detected = TRUE;
				}
			
			}
		}
	}

	/**
	 * Check blacklist table
	 *
	 * @param string $ip
	 */
	public function checkBlacklist ()
	{
		if ($this->_core_options['general']['useblacklist']) {
			
			$found = $this->_checkList($this->_core->getDataElement('lists', 'blacklist'));
			if ($found) {
				$this->spammer_detected = TRUE;
				$this->spaminfo['blacklist']['time'] = 'Blacklisted';
			
			}
		}
	}

	/**
	 * Check the White list table. Return TRUE if in the table
	 *
	 * @param string $ip
	 * @return boolean
	 *
	 * @since 1.1
	 */
	public function checkWhitelist ()
	{
		if ($this->_core_options['general']['usewhitelist']) {
			$found = $this->_checkList($this->_core->getDataElement('lists', 'whitelist'));
			if ($found) {
				$this->ip_in_white_list = true;
			
			}
		}
	}

	/**
	 * Check if an IP exists in a list
	 *
	 * @param string $ip
	 * @param string $list
	 * @return boolean
	 *
	 */
	private function _checkList ($list)
	{
		$list = explode("\r\n", $list);
		// Check for single IP's, this is much quicker as going through the list
		$inlist = in_array($this->_visiting_ip, $list) ? true : false;
		if (! $inlist) { // Not found yet
			foreach ($list as $check) {
				if ($this->_checkNetworkMatch($check)) {
					$inlist = true;
					break;
				}
			}
		}
		return ($inlist);
	}

	/**
	 * Check if an IP exist in a range
	 * Range can be formatted as:
	 * ip-ip (192.168.1.100-192.168.1.103)
	 * ip/mask (192.168.1.0/24)
	 *
	 * @param string $network
	 * @param string $ip
	 * @return boolean
	 */
	private function _checkNetworkMatch ($network)
	{
		$return = false;
		$network = trim($network);
		$ip = trim($this->_visiting_ip);
		$d = strpos($network, '-');
		if ($d === false) {
			$ip_arr = explode('/', $network);
			if (isset($ip_arr[1])) {
				$network_long = ip2long($ip_arr[0]);
				$x = ip2long($ip_arr[1]);
				$mask = long2ip($x) == $ip_arr[1] ? $x : (0xffffffff << (32 - $ip_arr[1]));
				$ip_long = ip2long($ip);
				$return = ($ip_long & $mask) == ($network_long & $mask);
			}
		} else {
			$from = ip2long(trim(substr($network, 0, $d)));
			$to = ip2long(trim(substr($network, $d + 1)));
			$ip = ip2long($ip);
			$return = ($ip >= $from and $ip <= $to);
		}
		return ($return);
	}

	/**
	 * Handle a known spam IP found by the 3rd party
	 *
	 * @param string $ip - The spammers IP
	 * @param array $info - Information
	 *
	 */
	private function _handleSpammer ()
	{
		// Email
		$sfs_email = isset($this->spaminfo['sfs']) && (int) $this->_core_options['sfs']['whentoemail'] >= 0 && (int) $this->spaminfo['sfs']['frequency'] >= $this->_core_options['sfs']['whentoemail'];
		$php_email = isset($this->spaminfo['php']) && (int) $this->_core_options['php']['whentoemail'] >= 0 && $this->spaminfo['php']['type'] >= $this->_core_options['php']['whentoemailtype'] && (int) $this->spaminfo['php']['score'] >= $this->_core_options['php']['whentoemail'];
		if ($sfs_email || $php_email) {
			// General part of the email
			$to = get_option('admin_email');
			$subject = sprintf('[%s] AVH First Defense Against Spam - ' . __('Spammer detected [%s]', 'avh-fdas'), wp_specialchars_decode(get_option('blogname'), ENT_QUOTES), $this->_visiting_ip);
			$message[] = sprintf(__('Spam IP:	%s', 'avh-fdas'), $this->_visiting_ip);
			$message[] = $this->_accessing;
			$message[] = '';
			// Stop Forum Spam Mail Part
			if ($sfs_email) {
				if ($this->spaminfo['sfs']['appears']) {
					$message[] = __('Checked at Stop Forum Spam', 'avh-fdas');
					$message[] = '	' . __('Information', 'avh-fdas');
					$message[] = '	' . sprintf(__('Last Seen:	%s', 'avh-fdas'), $this->spaminfo['sfs']['lastseen']);
					$message[] = '	' . sprintf(__('Frequency:	%s', 'avh-fdas'), $this->spaminfo['sfs']['frequency']);
					$message[] = '	' . sprintf(__('Call took:	%s', 'avhafdas'), $this->spaminfo['sfs']['time']);
					if ($this->spaminfo['sfs']['frequency'] >= $this->_core_options['sfs']['whentodie']) {
						$message[] = '	' . sprintf(__('Threshold (%s) reached. Connection terminated', 'avh-fdas'), $this->_core_options['sfs']['whentodie']);
					}
				} else {
					$message[] = __('Stop Forum Spam has no information', 'avh-fdas');
				}
				$message[] = '';
				$message[] = sprintf(__('For more information: http://www.stopforumspam.com/search?q=%s'), $this->_visiting_ip);
				$message[] = '';
			}
			if (isset($this->spaminfo['sfs']) && 'no' == $this->spaminfo['sfs']['appears']) {
				$message[] = __('Stop Forum Spam has no information', 'avh-fdas');
				$message[] = '';
			}
			// Project Honey pot Mail Part
			if ($php_email || $this->_core_options['sfs']['emailphp']) {
				if ($this->spaminfo['php'] != null) {
					$message[] = __('Checked at Project Honey Pot', 'avh-fdas');
					$message[] = '	' . __('Information', 'avh-fdas');
					$message[] = '	' . sprintf(__('Days since last activity:	%s', 'avh-fdas'), $this->spaminfo['php']['days']);
					switch ($this->spaminfo['php']['type']) {
						case "0":
							$type = "Search Engine";
							break;
						case "1":
							$type = "Suspicious";
							break;
						case "2":
							$type = "Harvester";
							break;
						case "3":
							$type = "Suspicious & Harvester";
							break;
						case "4":
							$type = "Comment Spammer";
							break;
						case "5":
							$type = "Suspicious & Comment Spammer";
							break;
						case "6":
							$type = "Harvester & Comment Spammer";
							break;
						case "7":
							$type = "Suspicious & Harvester & Comment Spammer";
							break;
					}
					$message[] = '	' . sprintf(__('Type:				%s', 'avh-fdas'), $type);
					if (0 == $this->spaminfo['php']['type']) {
						$message[] = '	' . sprintf(__('Search Engine:	%s', 'avh-fdas'), $this->spaminfo['php']['engine']);
					} else {
						$message[] = '	' . sprintf(__('Score:				%s', 'avh-fdas'), $this->spaminfo['php']['score']);
					}
					$message[] = '	' . sprintf(__('Call took:			%s', 'avhafdas'), $this->spaminfo['php']['time']);
					if ($this->spaminfo['php']['score'] >= $this->_core_options['php']['whentodie'] && $this->spaminfo['php']['type'] >= $this->_core_options['php']['whentodietype']) {
						$message[] = '	' . sprintf(__('Threshold score (%s) and type (%s) reached. Connection terminated', 'avh-fdas'), $this->_core_options['php']['whentodie'], $type);
					}
				} else {
					$message[] = __('Project Honey Pot has no information', 'avh-fdas');
				}
				$message[] = '';
			}
			// General End
			if (! isset($this->spaminfo['blacklist'])) {
				$blacklisturl = admin_url('admin.php?action=blacklist&i=') . $this->_visiting_ip . '&_avhnonce=' . AVH_Security::createNonce($this->_visiting_ip);
				$message[] = sprintf(__('Add to the local blacklist: %s'), $blacklisturl);
			}
			AVH_Common::sendMail($to, $subject, $message, $this->_settings->getSetting('mail_footer'));
		}
		// Check if we have to terminate the connection.
		// This should be the very last option.
		$sfs_die = isset($this->spaminfo['sfs']) && $this->spaminfo['sfs']['frequency'] >= $this->_core_options['sfs']['whentodie'];
		$php_die = isset($this->spaminfo['php']) && $this->spaminfo['php']['type'] >= $this->_core_options['php']['whentodietype'] && $this->spaminfo['php']['score'] >= $this->_core_options['php']['whentodie'];
		$blacklist_die = (isset($this->spaminfo['blacklist']) && 'Blacklisted' == $this->spaminfo['blacklist']['time']);
		if (1 == $this->_core_options['general']['useipcache']) {
			if ($sfs_die || $php_die) {
				$this->_ipcachedb->insertIp($this->_visiting_ip, 1);
			}
		}
		if ($sfs_die || $php_die || $blacklist_die) {
			// Update the counter
			$this->_updateSpamCounter();
			
			// Terminate the connection
			$this->_doTerminateConnection();
		}
	}

	/**
	 * Handle a spammer found in the IP cache
	 * @param $info
	 * @return unknown_type
	 */
	private function _handleSpammerCache ()
	{
		if ($this->_core_options['ipcache']['email']) {
			// General part of the email
			$to = get_option('admin_email');
			$subject = sprintf('[%s] AVH First Defense Against Spam - ' . __('Spammer detected [%s]', 'avh-fdas'), wp_specialchars_decode(get_option('blogname'), ENT_QUOTES), $this->_visiting_ip);
			$message = array();
			$message[] = sprintf(__('Spam IP:	%s', 'avh-fdas'), $this->_visiting_ip);
			$message[] = $this->_accessing;
			$message[] = '';
			$message[] = __('IP exists in the cache', 'avh-fdas');
			$message[] = '	' . sprintf(__('Check took:			%s', 'avhafdas'), $this->spaminfo['cache']['time']);
			$message[] = '';
			// General End
			$blacklisturl = admin_url('admin.php?action=blacklist&i=') . $this->_visiting_ip . '&_avhnonce=' . AVH_Security::createNonce($this->_visiting_ip);
			$message[] = sprintf(__('Add to the local blacklist: %s'), $blacklisturl);
			AVH_Common::sendMail($to, $subject, $message, $this->_settings->getSetting('mail_footer'));
		}
		// Update the counter
		$this->_updateSpamCounter();
		
		// Update Last seen value
		$this->_ipcachedb->updateIpCache(array('ip'=>$this->_visiting_ip, 'lastseen'=>current_time('mysql')));
		
		// Terminate the connection
		$this->_doTerminateConnection();
	}

	/**
	 *
	 * Updates the spam counter
	 */
	private function _updateSpamCounter ()
	{
		// Update the counter
		$period = date('Ym');
		if (array_key_exists($period, $this->_core_data['counters'])) {
			$this->_core_data['counters'][$period] += 1;
		} else {
			$this->_core_data['counters'][$period] = 1;
		}
		$this->_core->saveData($this->_core_data);
	}

	/**
	 *
	 * Terminates the connection.
	 */
	private function _doTerminateConnection ()
	{
		/**
		 * This tells the following plugins to not cache this page
		 * W3 Total cache
		 * WP-Supercache
		 */
		define('DONOTCACHEPAGE', true);
		
		if (1 == $this->_core_options['general']['diewithmessage']) {
			if (is_object($this->ip_in_cache)) {
				$m = sprintf('<h1>' . __('Access has been blocked.', 'avh-fdas') . '</h1><p>' . __('Your IP [%s] has been identified as spam', 'avh-fdas') . '</p>', $this->_visiting_ip);
			} else {
				if (isset($this->spaminfo['blacklist']) && 'Blacklisted' == $this->spaminfo['blacklist']['time']) {
					$m = sprintf('<h1>' . __('Access has been blocked.', 'avh-fdas') . '</h1><p>' . __('Your IP [%s] is registered in our <em>Blacklisted</em> database.', 'avh-fdas') . '<BR /></p>', $this->_visiting_ip);
				} else {
					$m = sprintf('<h1>' . __('Access has been blocked.', 'avh-fdas') . '</h1><p>' . __('Your IP [%s] is registered in the Stop Forum Spam or Project Honey Pot database.', 'avh-fdas') . '<BR />' . __('If you feel this is incorrect please contact them', 'avh-fdas') . '</p>', $this->_visiting_ip);
				}
			}
			$m .= '<p>' . __('Protected by: ', 'avh-fdas') . 'AVH First Defense Against Spam</p>';
			if ($this->_core_options['php']['usehoneypot']) {
				$m .= $this->getHtmlHoneyPotUrl();
			}
			wp_die($m);
		} else {
			die();
		}
	}

	/**
	 *
	 * Display the honeypot URL
	 */
	public function getHtmlHoneyPotUrl ()
	{
		return ('<p><div style="display: none;"><a href="' . $this->_core->getOptionElement('php', 'honeypoturl') . '">AVH Software</a></div></p>');
	}
}