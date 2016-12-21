<?php
/* 
 *******************************************************************************
 * Copyright 2011-2017 DANTE Ltd. and GÉANT on behalf of the GN3, GN3+, GN4-1 
 * and GN4-2 consortia
 *
 * License: see the web/copyright.php file in the file structure
 *******************************************************************************
 */
?>
<?php
require_once(dirname(dirname(dirname(__FILE__))) . "/config/_config.php");

require_once("Helper.php");
require_once("Logging.php");

require_once("inc/common.inc.php");
require_once("inc/input_validation.inc.php");
require_once("../resources/inc/header.php");
require_once("../resources/inc/footer.php");
require_once("inc/option_parse.inc.php");

$loggerInstance = new Logging();

pageheader(_("User Attributes - Summary of submitted data"), "USERMGMT");

$user = new User($_SESSION['user']);
if (!isset($_POST['submitbutton']) || $_POST['submitbutton'] != BUTTON_SAVE) { // what are we supposed to do?
    echo "<p>" . _("The page was called with insufficient data. Please report this as an error.") . "</p>";
    footer();
    exit(0);
}
?>
<h1>
    <?php _("Submitted attributes"); ?>
</h1>
<?php
$remaining_attribs = $user->beginflushAttributes();

if (isset($_POST['option'])) {
    foreach ($_POST['option'] as $opt_id => $optname) {
        if ($optname == "user:fedadmin") {
            echo "Security violation: user tried to make himself federation administrator!";
            exit(1);
        }
    }
}
?>
<table>
    <?php
    $killlist = processSubmittedFields($user, $_POST, $_FILES, $remaining_attribs);
    $user->commitFlushAttributes($killlist);
    $loggerInstance->writeAudit($_SESSION['user'], "MOD", "User attributes changed");
    ?>
</table>
<br/>
<form method='post' action='overview_user.php' accept-charset='UTF-8'>
    <button type='submit'>
        <?php echo _("Continue to user overview page"); ?>
    </button>
</form>
<?php
footer();
