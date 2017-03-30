<?php

/*
 * ******************************************************************************
 * Copyright 2011-2017 DANTE Ltd. and GÉANT on behalf of the GN3, GN3+, GN4-1 
 * and GN4-2 consortia
 *
 * License: see the web/copyright.php file in the file structure
 * ******************************************************************************
 */
?>
<?php

/**
 * Skin selection for user pages
 *
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @package Core
 */
require_once(dirname(dirname(dirname(__FILE__))) . "/config/_config.php");
require_once("../admin/inc/input_validation.inc.php");

$cleanToken = FALSE;
$operatingSystem = FALSE;
$tokenStatus = ["status" => \core\ProfileSilverbullet::SB_TOKENSTATUS_INVALID,
    "cert_status" => \core\ProfileSilverbullet::SB_CERTSTATUS_NONEXISTENT,];
$profile = NULL;
$idp = NULL;
$fed = NULL;

$Gui = new \core\UserAPI();
$operatingSystem = $Gui->detectOS();
// let's be a ChromeOS.
// $operatingSystem = ['device' => 'chromeos', 'display' => 'ChromeOS', 'group' => 'chrome'];

if (isset($_REQUEST['token'])) {
    $cleanToken = valid_token($_REQUEST['token']);
    if ($cleanToken) {
        // check status of this silverbullet token according to info in DB:
        // it can be VALID (exists and not redeemed, EXPIRED, REDEEMED or INVALID (non existent)
        $tokenStatus = \core\ProfileSilverbullet::tokenStatus($cleanToken);
    }
} elseif (isset($_SERVER['SSL_CLIENT_SAN_Email']) || isset($_SERVER['SSL_CLIENT_SAN_Email_0']) ) {
    // maybe the user authenticated with his client cert? Then pick any of his
    // tokens to go on
    $certname = $_SERVER['SSL_CLIENT_SAN_Email'] ?? $_SERVER['SSL_CLIENT_SAN_Email_0'];
    $userInfo = \core\ProfileSilverbullet::findUserIdFromCert($certname);
    $profile = new \core\ProfileSilverbullet($userInfo['profile']);
    $allTokens = $profile->userStatus($userInfo['user']);
    $cleanToken = $allTokens[0]['value'];
    
    $tokenStatus = $profile->userStatus($userInfo['user'])[0];
}

if ($tokenStatus['status'] != \core\ProfileSilverbullet::SB_TOKENSTATUS_INVALID) { // determine skin to use based on NROs preference
    $profile = new \core\ProfileSilverbullet($tokenStatus['profile'], NULL);
    $idp = new \core\IdP($profile->institution);
    $fed = valid_Fed($idp->federation);
    $fedskin = $fed->getAttributes("fed:desired_skin");
}
// ... unless overwritten by direct GET/POST parameter in the request
// ... with last resort being the default skin (first one in the configured skin list is the default)

$skinObject = new \web\lib\user\Skinjob($_REQUEST['skin'] ?? $fedskin[0] ?? CONFIG['APPEARANCE']['skins'][0]);

$statusInfo = ["token" => $cleanToken,
    "tokenstatus" => $tokenStatus,
    "OS" => $operatingSystem,
    "profile" => $profile,
    "idp" => $idp,
    "fed" => $fed,
];

// and now, serve actual data
include("../skins/" . $skinObject->skin . "/accountstatus/accountstatus.php");
