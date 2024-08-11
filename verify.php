<?php
/*
 * TwoFactorAuth
 *
 * Copyright (C) 2021-2022 e107 Inc. (https://www.e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 */

if(!defined('e107_INIT'))
{
	require_once(__DIR__.'/../../class2.php');
}

// Make this page inaccessible when plugin is not installed. 
if (!e107::isInstalled('twofactorauth'))
{
	e107::redirect();
	exit;
}

$session_user_id 		= e107::getSession('2fa')->get('user_id');
$session_previous_page 	= e107::getSession('2fa')->get('previous_page');

// No need to access this file directly or when already logged in. 
if(empty($session_user_id) || USER)
{
	if(USER)
	{
		$url = e107::url('twofactorauth', 'setup'); 
		e107::redirect($url);
	}
	else
	{
		e107::redirect();
	}

	e107::redirect($url);
	exit;
}

// Check action
if(strpos($session_previous_page, 'fpw.php') !== false) // PHP 8 - str_contains()
{
	$action = 'fpw';
}
else
{
	$action = 'login';
}

// Load required files (TwoFactorAuth Library and twofactorauth class)
// e107_require_once(e_PLUGIN.'twofactorauth/vendor/autoload.php');
// use \RobThree\Auth\TwoFactorAuth;
// $tfa_library = new TwoFactorAuth();

require_once(e_PLUGIN."twofactorauth/twofactorauth_class.php");
$tfa_class = new tfa_class();

// Load LAN files
e107::lan('twofactorauth', false, true);
$caption = LAN_2FA_TITLE." - ".LAN_VERIFY;
e107::title($caption);

require_once(HEADERF);
$text = "";

// Process TOTP code and verify against secret key
if(isset($_POST['enter-totp-login']))
{
	// Retrieve user ID from session 
	$user_id = e107::getSession('2fa')->get('user_id');

	// Set $totp, entered by user
	$totp = intval($_POST['totp']);
	$totp = (string) $totp;

	if(!$tfa_class->processLogin($user_id, $totp))
	{
		e107::getMessage()->addError(LAN_2FA_INCORRECT_TOTP); 
	}
}

// Process TOTP code and verify against secret key
if(isset($_POST))
{
	// Retrieve user ID from session 
	$user_id = e107::getSession('2fa')->get('user_id');

	// Set $totp, entered by user
	$totp = intval($_POST['totp']);
	$totp = (string) $totp;

	if(isset($_POST['enter-totp-login']))
	{
		if(!$tfa_class->processLogin($user_id, $totp))
		{
			e107::getMessage()->addError(LAN_2FA_INCORRECT_TOTP); 
		}
	}

	if(isset($_POST['enter-totp-fpw']))
	{
		if(!$tfa_class->processFpw($user_id, $totp))
		{
			e107::getMessage()->addError(LAN_2FA_INCORRECT_TOTP); 
		}
		else
		{
			return true; 
		}
	}
	
}

// TEMP FOR DEV PURPOSES
// $secret 		= e107::getDB()->retrieve('twofactorauth', 'secret_key', "user_id='1'");
// $correct_totp 	= $tfa_library->getCode($secret);
// $text 			.= $correct_totp; 

// Display form to enter TOTP 
e107::getMessage()->addInfo(e107::getParser()->toHTML(LAN_2FA_VERIFY_INSTRUCTIONS, true));
$text .= $tfa_class->showTotpInputForm($action); 
$text .= '<p class="font-italic">'.LAN_2FA_FALLBACK_INSTRUCTIONS.'</p>';

// Let's render and show it all!
e107::getRender()->tablerender($caption, e107::getMessage()->render().$text);

require_once(FOOTERF);
exit;