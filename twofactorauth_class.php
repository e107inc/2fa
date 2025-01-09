<?php
/*
 * TwoFactorAuth
 *
 * Copyright (C) 2021-2022 e107 Inc. (https://www.e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 */

e107_require_once(e_PLUGIN.'twofactorauth/vendor/autoload.php');
use \RobThree\Auth\TwoFactorAuth;
use \RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;

class tfa_class
{
	// If enabled, write debug messages to debug file 
	public function tfaDebug($message)
	{
		if(e107::getPlugPref('twofactorauth', 'tfa_debug')) 
		{
			e107::getLog()->addDebug($message);
			e107::getLog()->toFile('twofactorauth', 'TwoFactorAuth Debug Information', true);
		}
	}

	// If enabled, log certain events to the Systems Logs. These can be accessed through Admin Area > Tools > System Logs. 
	public function tfaLog($title, $details, $type = E_LOG_INFORMATIVE, $code)
	{
		/*
		LAN_2FA_*

			TFA_01 - TFA enabled on account
			TFA_02 - TFA disabled on account
			
			TFA_03 - TFA TOTP correct
			TFA_04 - TFA TOTP invalid

			TFA_05 - TFA recovery code valid
			TFA_06 - TFA recovery code invalid
			TFA_07 - TFA recovery code floodlimit

		*/

		if(e107::getPlugPref('twofactorauth', 'tfa_eventlogging')) 
		{
			e107::getLog()->add($title, $details, $type, $code);
		}
	}
	
	public function init($data, $eventname)
	{
		$this->tfaDebug(__LINE__." ".__METHOD__.": Init starting");
		$this->tfaDebug(__LINE__." ".__METHOD__.": Event name: ".$eventname);

		// Login - user_validlogin
		if($eventname == 'user_validlogin')
		{
			$user_id = $data; 
		}
		// FPW - user_fpw_request
		else
		{
			// error_log($eventname);
			// error_log(print_r($data, true));
			// return false;
			$user_id = $data["user_id"];
		}

		$this->tfaDebug(__LINE__." ".__METHOD__.": User ID: ".$user_id);

		// Check if 2FA is activated
		if($this->tfaActivated($user_id) == false)
		{
			// 2FA is NOT activated, return false to proceed with core login/fpw process.
			$this->tfaDebug(__LINE__." ".__METHOD__.": TFA is NOT activated for User ID ".$user_id);
			 
			return false; 
		}

		// 2FA is enabled for this user. Continue verification process. Service page to enter TOTP digits, generated by user's authenthicator app.
		$this->tfaDebug(__LINE__." ".__METHOD__.": TFA is activated for User ID ".$user_id);
		$this->tfaDebug(__LINE__." ".__METHOD__.": User will need to enter digits. Redirect to tfa/verify");
		
		// Store some information in a session, so we can retrieve it again later 
		e107::getSession('2fa')->set('user_id', $user_id); // Store User ID
		e107::getSession('2fa')->set('eventname', $eventname); // Store Eventname
		e107::getSession('2fa')->set('previous_page', e_REQUEST_URL); // Store the page the user is logging in from

		// Redirect to page to enter TOTP 
		$url = e107::url('twofactorauth', 'verify'); 
		e107::redirect($url);
	}

	public function tfaActivated($user_id)
	{		
		$this->tfaDebug(__LINE__." ".__METHOD__.": Checking if TFA is activated for User ID ".$user_id);
		if(!e107::getUserExt()->get($user_id, "user_plugin_twofactorauth_secret_key"))
		{
			return false;  
		}
		
		return true; 
	}

	public function showTotpInputForm($action = 'login', $secret = '')
	{
		$text = '';

		switch($action) 
		{
			case 'login':
				$action = 'submit';
				$button_name = "enter-totp-login";
				break;
			case 'fpw':
				$action = 'submit';
				$button_name = "enter-totp-fpw";
				break;
			case 'enable':
				$action = 'submit';
				$button_name = "enter-totp-enable";
				break; 
			case 'disable':
				$action = 'delete';
				$button_name = "enter-totp-disable"; 
				break; 
			default:
				$action = 'submit';
				$button_name = "enter-totp-login";
				break;
		}

		$form_options = array(
			//"size" 		=> "small", 
			'required' 		=> 1, 
			'placeholder'	=> LAN_2FA_ENTER_TOTP_PLACEHOLDER, 
			'autofocus' 	=> true,
		);

		// Display form to enter TOTP 
		$text .= e107::getForm()->open('enter-totp');
		$text .= e107::getForm()->text("totp", "", 80, $form_options);
		
		if(!empty($secret))
		{
			$text .= e107::getForm()->hidden("secret_key", $secret);
		}
		$text .= "<br>";
		$text .= e107::getForm()->button($button_name, LAN_VERIFY, $action);
		$text .= e107::getForm()->close(); 

		return $text; 
	}

	private function verifyTotp($user_id = USERID, $totp)
	{
		$tfa_library = new TwoFactorAuth(new EndroidQrCodeProvider());

		// Retrieve secret_key of this user, stored in the database
		$secret_key = e107::getUserExt()->get($user_id, "user_plugin_twofactorauth_secret_key");
		
		$this->tfaDebug(__LINE__." ".__METHOD__.": User ID: ".$user_id);
		$this->tfaDebug(__LINE__." ".__METHOD__.": Secret Key: ".$secret_key);
		$this->tfaDebug(__LINE__." ".__METHOD__.": Entered TOTP: ".$totp);
		
		// Check if the entered TOTP is correct. 
		if($tfa_library->verifyCode($secret_key, $totp, 2) === true) 
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": The TOTP code that was entered, is correct");
			return true;
		}
		else
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": The TOTP code that was entered, is INCORRECT");
			return false; 
		}
	}

	public function showRecoveryCodeInputForm()
	{
		$form_options = array(
			//"size" 		=> "small", 
			'required' 		=> 1, 
			'placeholder'	=> LAN_2FA_ENTER_RECOVERYCODE_PLACEHOLDER, 
			'autofocus' 	=> true,
		);

		// Display form to enter recovery code
		$text .= e107::getForm()->open('enter-recovery-code');
		$text .= e107::getForm()->text("recovery-code", "", 80, $form_options);
		
		$text .= "<br>";
		$text .= e107::getForm()->button('enter-recovery-code', LAN_VERIFY, 'submit');
		$text .= e107::getForm()->close(); 

		return $text; 
	}

	public function processLogin($user_id = USERID, $totp)
	{
		$this->tfaDebug(__LINE__." ".__METHOD__.": Start processing login");

		if($this->verifyTotp($user_id, $totp))
		{
			e107::getLog()->addDebug(__LINE__." ".__METHOD__.": LOGIN - TOTP is correct, continue logging in");
			$this->tfaLog(LAN_2FA_TFA_03, 'Login', E_LOG_INFORMATIVE, "TFA_03"); 
			
			// Continue processing login 
			$user = e107::user($user_id);
			$ulogin = new userlogin();
			$ulogin->validLogin($user);

			// Get previous page the user was on before logging in. 
			$previous_page = e107::getSession('2fa')->get('previous_page');

			$this->tfaDebug(__LINE__." ".__METHOD__.": Session Previous page: ".$previous_page);

	
			// Clear session data
			e107::getSession('2fa')->clearData();

			// Redirect to previous page or otherwise to homepage
			if($previous_page)
			{
				$this->tfaDebug(__LINE__." ".__METHOD__.": Redirecting to ".$previous_page);
				e107::getRedirect()->redirect($previous_page);
			}
			else
			{
				$this->tfaDebug(__LINE__." ".__METHOD__.": Redirecting to homepage");
				e107::redirect();
			}
		
		}
		// The entered TOTP is INCORRECT
		else
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": LOGIN - TOTP is incorrect. Return false");
			$this->tfaLog(LAN_2FA_TFA_04, 'Login', E_LOG_WARNING, "TFA_04"); 

			return false; 
		}
	}

	public function processFpw($user_id = USERID, $totp)
	{
		$this->tfaDebug(__LINE__." ".__METHOD__.": Start processing FPW");
			
		if($this->verifyTotp($user_id, $totp))
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": FPW - TOTP is correct, return true");
			//return true; // TODO - hook back into fpw process somehow, just like validLogin();
		}
		// The entered TOTP is INCORRECT
		else
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": FPW - TOTP is incorrect. Return false");
			return false;
		}
	}

	private function verifyRecoveryCode($user_id = USERID, $recovery_code)
	{
		$this->tfaDebug(__LINE__." ".__METHOD__.": Start verifying recovery code");

		$user_ip 		= e107::getIPHandler()->getIP(); 
		$now 			= time(); 
		$timesetback 	= strtotime('-24 hours', $now); 
		
		$fails = e107::getDb()->count("generic", "(*)", "WHERE gen_ip = '{$user_ip}' AND gen_type = 'tfa_failed_recovery_code' AND `gen_datestamp` BETWEEN '{$timesetback}' AND '{$now}'"); 
		$failLimit = e107::getPlugPref('twofactorauth', 'tfa_recoverycodesattempts', 3); 

		$this->tfaDebug(__LINE__." ".__METHOD__.": user_ip: ".$user_ip);
		$this->tfaDebug(__LINE__." ".__METHOD__.": timesetback: ".$timesetback);
		$this->tfaDebug(__LINE__." ".__METHOD__.": fails: ".$fails);
		$this->tfaDebug(__LINE__." ".__METHOD__.": faillimit: ".$failLimit);
		
		if($fails > $failLimit)
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": Flood protection triggered because user has reached the faillimit, banning the IP addres now.");
			$this->tfaLog(LAN_2FA_TFA_07, '', E_LOG_WARNING, "TFA_07"); 
			
			$reason	= e107::getParser()->lanVars(LAN_2FA_RECOVERY_CODE_REACHED_FAILLIMIT, $failLimit);

			if(true === e107::getIPHandler()->add_ban(2, $reason, $user_ip, 0))
			{
				$ip = e107::getIPHandler()->ipDecode($user_ip);
				e107::getEvent()->trigger('user_ban_flood', $ip);

				return false; 
			}
			else
			{
				$this->tfaDebug(__LINE__." ".__METHOD__.": ERROR: Flood protection triggered but could not add ban for some reason. Localhost?");
			}
		}

		// Retrieve array from DB and unserialize
		$codes_serialized = e107::getUserExt()->get($user_id, "user_plugin_twofactorauth_recovery_codes");
		$codes = unserialize($codes_serialized);

		// Start looping through
		foreach ($codes as $key => $hash) 
		{
			// Check if OTP is valid
			if(password_verify($recovery_code, $hash)) 
			{
				// Yes, OTP is valid. First let's remove it from the database so it cannot be used again
				$this->tfaDebug(__LINE__." ".__METHOD__.": The entered recovery code is valid. Removing it from the saved recovery codes now.");

				unset($codes[$key]);

				$codes_array_hashed = serialize($codes);
				e107::getUserExt()->set($user_id, "user_plugin_twofactorauth_recovery_codes", $codes_array_hashed, 'array'); 

				// Notify user that a recovery code was used, and inform of how many recovery codes are left. 
				$this->tfaDebug(__LINE__." ".__METHOD__.": Notifying user of successful login with recovery code");
				$this->tfaLog(LAN_2FA_TFA_05, '', E_LOG_INFORMATIVE, "TFA_05"); 

				$tfa_event_data = array(
					'user_id' 		=> $user_id, 
					'user_ip' 		=> e107::getIPHandler()->getIP(true), 
					'valid' 		=> true, 
					'remaining' 	=> count($codes),
				);

				e107::getEvent()->trigger('twofactorauth_recovery_code_used', $tfa_event_data);

				// Then, return true, so user can login
				$this->tfaDebug(__LINE__." ".__METHOD__.": Returning true so user can login");
			    return true; 
			} 
			// No, OTP is not valid, check if it it was the last, otherwise restart the loop. 
			else 
			{	
				// Check if it was the last OTP that is stored in the DB. 
				if ($key === array_key_last($codes)) 
				{
		       		// Yes it was the last stored OTP. So this is a definite NO. 
		       		$this->tfaDebug(__LINE__." ".__METHOD__.": The entered recovery code is INVALID. Log it to floodprotection");
					$this->tfaLog(LAN_2FA_TFA_06, '', E_LOG_WARNING, "TFA_06");

		   			// Log it for floodprotection
		   			$insert = array(
						'gen_id'    	=> 0,
						'gen_type'  	=> 'tfa_failed_recovery_code',
						'gen_datestamp' => time(),
						'gen_user_id'   => $user_id,
						'gen_ip'        => e107::getIPHandler()->getIP(),
						'gen_intdata'   => 0, 
						'gen_chardata'  => ''
					);

					if(!e107::getDb()->insert('generic', $insert))
					{
						$this->tfaDebug(__LINE__." ".__METHOD__.": Could not insert failed recovery code attempt to generic table in database");
						$this->tfaDebug(e107::getDb()->getLastErrorText());
					}
				
					// Notify user of invalid attempt to use recovery code
					$this->tfaDebug(__LINE__." ".__METHOD__.": Notifying user of invalid attempt to use recovery code");

					$tfa_event_data = array(
						'user_id' 		=> $user_id, 
						'user_ip' 		=> e107::getIPHandler()->getIP(true), 
						'valid' 		=> false, 
					);

					e107::getEvent()->trigger('twofactorauth_recovery_code_used', $tfa_event_data);

		       		// Return false message
		       		return false; 
		    	}

		    	// OTP was not the last, there's more to check. Restart loop.  	
			    continue; 
			}   
		}
	}

	public function processRecoveryCode($user_id = USERID, $recovery_code)
	{
		$this->tfaDebug(__LINE__." ".__METHOD__.": Start processing Recovery Code");
		
		if($this->verifyRecoveryCode($user_id, $recovery_code))
		{
			// Get previous page the user was on before logging in. 
			$previous_page = e107::getSession('2fa')->get('previous_page');

			$this->tfaDebug(__LINE__." ".__METHOD__.": Session Previous page: ".$previous_page);

			if(!str_contains($previous_page, 'fpw.php'))
			{
				$this->tfaDebug(__LINE__." ".__METHOD__.": Previous page was not fpw.php, so continue logging in the user");
				$user = e107::user($user_id);
				$ulogin = new userlogin();
				$ulogin->validLogin($user);
			}
			else
			{
				// TODO FPW CODE
			}
				
			// Clear session data
			e107::getSession('2fa')->clearData();

			// Redirect to previous page or otherwise to homepage
			if($previous_page)
			{
				$this->tfaDebug(__LINE__." ".__METHOD__.": Redirecting to ".$previous_page);
				e107::getRedirect()->redirect($previous_page);
			}
			else
			{
				$this->tfaDebug(__LINE__." ".__METHOD__.": Redirecting to homepage");
				e107::redirect();
			}
			
			$this->tfaDebug(__LINE__." ".__METHOD__.": This should never be reached");
			return false; 
		}
		// The entered recovery code is INCORRECT, return false
		else
		{
			return false; 
		}
	}

	public function processEnable($user_id = USERID, $secret_key, $totp)
	{
		$this->tfaDebug(__LINE__." ".__METHOD__.": Start processing enabling TFA");

		$tfa_library = new TwoFactorAuth(new EndroidQrCodeProvider());

		// Verify code
		if($tfa_library->verifyCode($secret_key, $totp, 2) === false) 
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": Entered TOTP is incorrect: ".$totp);
			$this->tfaDebug(__LINE__." ".__METHOD__.": Secret Key: ".$secret_key);
			$this->tfaLog(LAN_2FA_TFA_04, 'Upon enable', E_LOG_WARNING, "TFA_04");

			e107::getMessage()->addError(LAN_2FA_INCORRECT_TOTP);
			return false; 
		}

		$this->tfaDebug(__LINE__." ".__METHOD__.": Entered TOTP is correct. Continue adding secret key to EUF.");

		if(!e107::getUserExt()->set($user_id, "user_plugin_twofactorauth_secret_key", $secret_key))
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": Could not add secret key to EUF");
			$this->tfaDebug(e107::getDb()->getLastErrorText());

			e107::getMessage()->addError(LAN_2FA_DATABASE_ERROR);
			return false; 
		}

		$this->tfaDebug(__LINE__." ".__METHOD__.": Secret key has been added to the EUF");
		$this->tfaLog(LAN_2FA_TFA_01, '', E_LOG_INFORMATIVE, "TFA_01"); 

		return true; 
	}

	public function generateRecoveryCodes($user_id = USERID)
	{
		$this->tfaDebug(__LINE__." ".__METHOD__.": Start generating recovery codes");

		$codes_array_readable 	= array(); 
		$codes_array_hashed 	= array(); 

		$randomizer = new \Random\Randomizer();

	    for ($x = 0; $x <= 9; $x++) 
	    {
	  		$code = $randomizer->getBytesFromString('0123456789ABCDEFGHJKMNPQRSTVWXYZ', 16); 
	  		$code = implode('-', str_split($code, 4)); 
	  		
	  		array_push($codes_array_readable, $code);  

	  		// now hash the code, store it in the hashed codes array
	  		$hashed_code = password_hash($code, PASSWORD_DEFAULT);	
	  		array_push($codes_array_hashed, $hashed_code);
		}

		$codes_array_serialized = serialize($codes_array_hashed);

		$this->tfaDebug(__LINE__." ".__METHOD__.": Serialized recovery codes");
		
		if(!e107::getUserExt()->set($user_id, "user_plugin_twofactorauth_recovery_codes", $codes_array_serialized, 'array'))
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": Could not add recovery codes to EUF");

			e107::getMessage()->addError(LAN_2FA_DATABASE_ERROR);
			return false; 
		}

		// Present readable format to user. 
	 	return $codes_array_readable;
	}

	public function removeRecoveryCodes($user_id = USERID)
	{
		$this->tfaDebug(__LINE__." ".__METHOD__.": Start removing recovery codes");

		if(!e107::getUserExt()->set($user_id, "user_plugin_twofactorauth_recovery_codes", ''))
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": Could not remove recovery codes from EUF");
			return false; 
		}

		return true; 

	}

	public function processDisable($user_id = USERID, $totp)
	{
		$this->tfaDebug(__LINE__." ".__METHOD__.": Start disabling TFA");

		$tfa_library = new TwoFactorAuth(new EndroidQrCodeProvider());

		// Retrieve secret_key of this user, stored in the database
		$secret_key = e107::getUserExt()->get($user_id, "user_plugin_twofactorauth_secret_key");

		// Verify code
		if($tfa_library->verifyCode($secret_key, $totp, 2) === false) 
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": Entered TOTP is incorrect: ".$totp);
			$this->tfaLog(LAN_2FA_TFA_04, 'Upon disable', E_LOG_WARNING, "TFA_04");

			e107::getMessage()->addError(LAN_2FA_INCORRECT_TOTP);
			return false; 
		}

		if(!e107::getUserExt()->set($user_id, "user_plugin_twofactorauth_secret_key", ''))
		{
			$this->tfaDebug(__LINE__." ".__METHOD__.": Could not empty secret_key EUF field");

			e107::getMessage()->addError(LAN_2FA_DATABASE_ERROR);
			return false; 
		}

		$this->tfaDebug(__LINE__." ".__METHOD__.": secret_key has been removed from EUF field");
		$this->tfaLog(LAN_2FA_TFA_02, '', E_LOG_INFORMATIVE, "TFA_02");

		return true; 
	}
}