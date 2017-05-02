<?php

/*
 * ******************************************************************************
 * Copyright 2011-2017 DANTE Ltd. and GÉANT on behalf of the GN3, GN3+, GN4-1 
 * and GN4-2 consortia
 *
 * License: see the web/copyright.php file in the file structure
 * ******************************************************************************
 */

/**
 * This file contains code for testing EAP servers
 *
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @author Tomasz Wolniewicz <twoln@umk.pl>
 * @author Maja Gorecka-Wolniewicz <mgw@umk.pl>
 *
 * @package Developer
 * 
 */

namespace core\diag;

use \Exception;

require_once(dirname(dirname(__DIR__))."/config/_config.php");

/**
 * Test suite to verify that an EAP setup is actually working as advertised in
 * the real world. Can only be used if CONFIG['RADIUSTESTS'] is configured.
 *
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @author Tomasz Wolniewicz <twoln@umk.pl>
 *
 * @license see LICENSE file in root directory
 *
 * @package Developer
 */
class RADIUSTests extends AbstractTest {
    
    private $profile;

    /**
     * The variables below maintain state of the result of previous checks.
     * 
     */
    
    private $UDP_reachability_executed;
    private $errorlist;
    private $TLS_certkeys = [];
    
    public $UDP_reachability_result;
    public $TLS_CA_checks_result;
    public $TLS_clients_checks_result;

    /**
     * Constructor for the EAPTests class. The single mandatory parameter is the
     * realm for which the tests are to be carried out.
     * 
     * @param string $realm
     * @param int $profileId
     */
    public function __construct($realm, $profileId = 0) {
        parent::__construct();
        $oldlocale = $this->languageInstance->setTextDomain('diagnostics');

        $this->realm = $realm;
        $this->UDP_reachability_result = [];
        $this->TLS_CA_checks_result = [];
        $this->TLS_clients_checks_result = [];
        $this->TLS_certkeys = [
            'eduPKI' => _('eduPKI'),
            'NCU' => _('Nicolaus Copernicus University'),
            'ACCREDITED' => _('accredited'),
            'NONACCREDITED' => _('non-accredited'),
            'CORRECT' => _('correct certificate'),
            'WRONGPOLICY' => _('certificate with wrong policy OID'),
            'EXPIRED' => _('expired certificate'),
            'REVOKED' => _('revoked certificate'),
            'PASS' => _('pass'),
            'FAIL' => _('fail'),
            'non-eduPKI-accredited' => _("eduroam-accredited CA (now only for tests)"),
        ];
        $this->errorlist = [];
        
        if ($profileId !== 0) {
            $this->profile = \core\ProfileFactory::instantiate($profileId);
            if (!$this->profile instanceof \core\ProfileRADIUS) {
                throw new Exception("The profile is not a ProfileRADIUS! We can only check those!");
            }
        } else {
            $this->profile = FALSE;
        }

        $this->languageInstance->setTextDomain($oldlocale);
    }

    /**
     * This function parses a X.509 server cert and checks if it finds client device incompatibilities
     * 
     * @param array $servercert the properties of the certificate as returned by processCertificate(), 
     *    $servercert is modified, if CRL is defied, it is downloaded and added to the array
     *    incoming_server_names, sAN_DNS and CN array values are also defined
     * @return array of oddities; the array is empty if everything is fine
     */
    private function propertyCheckServercert(&$servercert) {
        $this->loggerInstance->debug(5, "SERVER CERT IS: " . print_r($servercert, TRUE));
// we share the same checks as for CAs when it comes to signature algorithm and basicconstraints
// so call that function and memorise the outcome
        $returnarray = $this->propertyCheckIntermediate($servercert, TRUE);

        if (!isset($servercert['full_details']['extensions'])) {
            $returnarray[] = RADIUSTests::CERTPROB_NO_TLS_WEBSERVER_OID;
            $returnarray[] = RADIUSTests::CERTPROB_NO_CDP_HTTP;
        } else {
            if (!isset($servercert['full_details']['extensions']['extendedKeyUsage']) || !preg_match("/TLS Web Server Authentication/", $servercert['full_details']['extensions']['extendedKeyUsage'])) {
                $returnarray[] = RADIUSTests::CERTPROB_NO_TLS_WEBSERVER_OID;
            }
        }
// check for wildcards
        $commonName = [];
        if (isset($servercert['full_details']['subject']['CN'])) {
            if (is_array($servercert['full_details']['subject']['CN'])) {
                $commonName = $servercert['full_details']['subject']['CN'];
            } else {
                $commonName = [$servercert['full_details']['subject']['CN']];
            }
        }

        $sANlist = [];
        if (isset($servercert['full_details']['extensions']) && isset($servercert['full_details']['extensions']['subjectAltName'])) {
            $sANlist = explode(", ", $servercert['full_details']['extensions']['subjectAltName']);
        }

        $sANdns = [];
        foreach ($sANlist as $subjectAltName) {
            if (preg_match("/^DNS:/", $subjectAltName)) {
                $sANdns[] = substr($subjectAltName, 4);
            }
        }

        $allnames = array_unique(array_merge($commonName, $sANdns));

        if (preg_match("/\*/", implode($allnames))) {
            $returnarray[] = RADIUSTests::CERTPROB_WILDCARD_IN_NAME;
        }

// is there more than one CN? None or one is okay, more is asking for trouble.
        if (count($commonName) > 1) {
            $returnarray[] = RADIUSTests::CERTPROB_MULTIPLE_CN;
        }

// check for real hostname
        foreach ($allnames as $onename) {
            if ($onename != "" && filter_var("foo@" . idn_to_ascii($onename), FILTER_VALIDATE_EMAIL) === FALSE) {
                $returnarray[] = RADIUSTests::CERTPROB_NOT_A_HOSTNAME;
            }
        }
        $servercert['incoming_server_names'] = $allnames;
        $servercert['sAN_DNS'] = $sANdns;
        $servercert['CN'] = $commonName;
        return $returnarray;
    }

    /**
     * This function parses a X.509 intermediate CA cert and checks if it finds client device incompatibilities
     * 
     * @param array $intermediateCa the properties of the certificate as returned by processCertificate()
     * @param boolean complain_about_cdp_existence: for intermediates, not having a CDP is less of an issue than for servers. Set the REMARK (..._INTERMEDIATE) flag if not complaining; and _SERVER if so
     * @return array of oddities; the array is empty if everything is fine
     */
    private function propertyCheckIntermediate(&$intermediateCa, $serverCert = FALSE) {
        $returnarray = [];
        if (preg_match("/md5/i", $intermediateCa['full_details']['signatureTypeSN'])) {
            $returnarray[] = RADIUSTests::CERTPROB_MD5_SIGNATURE;
        }
        if (preg_match("/sha1/i", $intermediateCa['full_details']['signatureTypeSN'])) {
            $returnarray[] = RADIUSTests::CERTPROB_SHA1_SIGNATURE;
        }
        $this->loggerInstance->debug(4, "CERT IS: " . print_r($intermediateCa, TRUE));
        if ($intermediateCa['basicconstraints_set'] == 0) {
            $returnarray[] = RADIUSTests::CERTPROB_NO_BASICCONSTRAINTS;
        }
        if ($intermediateCa['full_details']['public_key_length'] < 1024) {
            $returnarray[] = RADIUSTests::CERTPROB_LOW_KEY_LENGTH;
        }
        $validFrom = $intermediateCa['full_details']['validFrom_time_t'];
        $now = time();
        $validTo = $intermediateCa['full_details']['validTo_time_t'];
        if ($validFrom > $now || $validTo < $now) {
            $returnarray[] = RADIUSTests::CERTPROB_OUTSIDE_VALIDITY_PERIOD;
        }
        $addCertCrlResult = $this->addCrltoCert($intermediateCa);
        if ($addCertCrlResult !== 0 && $serverCert) {
            $returnarray[] = $addCertCrlResult;
        }

        return $returnarray;
    }

    /**
     * This function returns an array of errors which were encountered in all the tests.
     * 
     * @return array all the errors
     */
    public function listerrors() {
        return $this->errorlist;
    }

    /**
     * This function performs actual authentication checks with MADE-UP credentials.
     * Its purpose is to check if a RADIUS server is reachable and speaks EAP.
     * The function fills array RADIUSTests::UDP_reachability_result[$probeindex] with all check detail
     * in case more than the return code is needed/wanted by the caller
     * 
     * @param string $probeindex refers to the specific UDP-host in the config that should be checked
     * @param boolean $opnameCheck should we check choking on Operator-Name?
     * @param boolean $frag should we cause UDP fragmentation? (Warning: makes use of Operator-Name!)
     * @return int returncode
     */
    public function UDP_reachability($probeindex, $opnameCheck = TRUE, $frag = TRUE) {
// for EAP-TLS to be a viable option, we need to pass a random client cert to make eapol_test happy
// the following PEM data is one of the SENSE EAPLab client certs (not secret at all)
        $clientcerthandle = fopen(dirname(__FILE__) . "/clientcert.p12", "r");
        $this->loggerInstance->debug(4, "Tried to get a useless client cert from" . dirname(__FILE__) . "/clientcert.p12");
        $clientcert = fread($clientcerthandle, filesize(dirname(__FILE__) . "/clientcert.p12"));
        fclose($clientcerthandle);
        return $this->UDP_login($probeindex, \core\common\EAP::EAPTYPE_ANY, "cat-connectivity-test@" . $this->realm, "eaplab", '', $opnameCheck, $frag, $clientcert);
    }

    /**
     * There is a CRL Distribution Point URL in the certificate. So download the
     * CRL and attach it to the cert structure so that we can later find out if
     * the cert was revoked
     * @param array $cert by-reference: the cert data we are writing into
     * @return int result code whether we were successful in retrieving the CRL
     */
    private function addCrltoCert(&$cert) {
        $crlUrl = [];
        $returnresult = 0;
        if (!isset($cert['full_details']['extensions']['crlDistributionPoints'])) {
            $returnresult = RADIUSTests::CERTPROB_NO_CDP;
        } else if (!preg_match("/^.*URI\:(http)(.*)$/", str_replace(["\r", "\n"], ' ', $cert['full_details']['extensions']['crlDistributionPoints']), $crlUrl)) {
            $returnresult = RADIUSTests::CERTPROB_NO_CDP_HTTP;
        } else { // first and second sub-match is the full URL... check it
            $crlcontent = \core\common\OutsideComm::downloadFile($crlUrl[1] . $crlUrl[2]);
            if ($crlcontent === FALSE) {
                $returnresult = RADIUSTests::CERTPROB_NO_CRL_AT_CDP_URL;
            }
            $crlBegin = strpos($crlcontent, "-----BEGIN X509 CRL-----");
            if ($crlBegin === FALSE) {
                $pem = chunk_split(base64_encode($crlcontent), 64, "\n");
                $crlcontent = "-----BEGIN X509 CRL-----\n" . $pem . "-----END X509 CRL-----\n";
            }
            $cert['CRL'] = [];
            $cert['CRL'][] = $crlcontent;
        }
        return $returnresult;
    }

    /**
     * We don't want to write passwords of the live login test to our logs. Filter them out
     * @param string $stringToRedact what should be redacted
     * @param array $inputarray array of strings (outputs of eapol_test command)
     * @return string[] the output of eapol_test with the password redacted
     */
    private function redact($stringToRedact, $inputarray) {
        $temparray = preg_replace("/^.*$stringToRedact.*$/", "LINE CONTAINING PASSWORD REDACTED", $inputarray);
        $hex = bin2hex($stringToRedact);
        $spaced = "";
        for ($i = 1; $i < strlen($hex); $i++) {
            if ($i % 2 == 1 && $i != strlen($hex)) {
                $spaced .= $hex[$i] . " ";
            } else {
                $spaced .= $hex[$i];
            }
        }
        return preg_replace("/$spaced/", " HEX ENCODED PASSWORD REDACTED ", $temparray);
    }

    /**
     * Filters eapol_test output and finds out the packet codes out of which the conversation was comprised of
     * 
     * @param array $inputarray array of strings (outputs of eapol_test command)
     * @return array the packet codes which were exchanged, in sequence
     */
    private function filterPackettype($inputarray) {
        $retarray = [];
        foreach ($inputarray as $line) {
            if (preg_match("/RADIUS message:/", $line)) {
                $linecomponents = explode(" ", $line);
                $packettypeExploded = explode("=", $linecomponents[2]);
                $packettype = $packettypeExploded[1];
                $retarray[] = $packettype;
            }
        }
        return $retarray;
    }

    const LINEPARSE_CHECK_REJECTIGNORE = 1;
    const LINEPARSE_CHECK_691 = 2;
    const LINEPARSE_EAPACK = 3;

    /**
     * this function checks for various special conditions which can be found 
     * only by parsing eapol_test output line by line. Checks currently 
     * implemented are:
     * * if the ETLRs sent back an Access-Reject because there appeared to
     *   be a timeout further downstream
     * * did the server send an MSCHAP Error 691 - Retry Allowed in a Challenge
     *   instead of an outright reject?
     * * was an EAP method ever acknowledged by both sides during the EAP
     *   conversation
     * 
     * @param array $inputarray array of strings (outputs of eapol_test command)
     * @param int $desiredCheck which test should be run (see constants above)
     * @return boolean returns TRUE if ETLR Reject logic was detected; FALSE if not
     */
    private function checkLineparse($inputarray, $desiredCheck) {
        foreach ($inputarray as $lineid => $line) {
            switch ($desiredCheck) {
                case self::LINEPARSE_CHECK_REJECTIGNORE:
                    if (preg_match("/Attribute 18 (Reply-Message)/", $line) && preg_match("/Reject instead of Ignore at eduroam.org/", $inputarray[$lineid + 1])) {
                        return TRUE;
                    }
                    break;
                case self::LINEPARSE_CHECK_691:
                    if (preg_match("/MSCHAPV2: error 691/", $line) && preg_match("/MSCHAPV2: retry is allowed/", $inputarray[$lineid + 1])) {
                        return TRUE;
                    }
                    break;
                case self::LINEPARSE_EAPACK:
                    if (preg_match("/CTRL-EVENT-EAP-PROPOSED-METHOD/", $line) && !preg_match("/NAK$/", $line)) {
                        return TRUE;
                    }
                    break;
                default:
                    throw new Exception("This lineparse test does not exist.");
            }
        }
        return FALSE;
    }

    /**
     * Which outer ID should we use? Calculate the local part of it.
     * Not trivial: there is 
     * - the inner username (only if no outer ID defined
     * - the outer username for installer rollout (preferred over inner)
     * - the outer username dedicated for our tests (preferred over outer-installer)
     * @param string $innerUser
     * @return string the best-match string
     */
    private function bestOuterLocalpart($innerUser) {
        $matches = [];
        $anonIdentity = ""; // our default of last resort. Will check if servers choke on the IETF-recommended anon ID format.
        if ($this->profile instanceof \core\ProfileRADIUS) { // take profile's anon ID (special one for realm checks or generic one) if known
            $foo = $this->profile;
            $useAnonOuter = $foo->getAttributes("internal:use_anon_outer")[0]['value'];
            $this->loggerInstance->debug(3, "calculating local part with explicit Profile\n");
// did the admin specify a special outer ID for realm checks?
// take this with precedence
            $isCheckuserSet = $foo->getAttributes('internal:checkuser_outer')[0]['value'];
            if ($isCheckuserSet) {
                $anonIdentity = $foo->getAttributes('internal:checkuser_value')[0]['value'];
            }
// if none, take the configured anon outer ID
            elseif ($useAnonOuter == TRUE && $foo->realm == $this->realm) {
                $anonIdentity = $foo->getAttributes("internal:anon_local_value")[0]['value'];
            }
        } elseif (preg_match("/(.*)@.*/", $innerUser, $matches)) {
// otherwise, use the local part of inner ID if provided

            $anonIdentity = $matches[1];
        }
// if we couldn't gather any intelligible information, use the empty string
        return $anonIdentity;
    }

    /**
     * 
     * @param array $eaptype array representation of the EAP type
     * @param string $inner inner username
     * @param string $outer outer username
     * @param string $password the password
     * @return string[] [0] is the actual config for wpa_supplicant, [1] is a redacted version for logs
     */
    private function wpaSupplicantConfig(array $eaptype, string $inner, string $outer, string $password) {
        $eapText = \core\common\EAP::eapDisplayName($eaptype);
        $config = '
network={
  ssid="' . CONFIG['APPEARANCE']['productname'] . ' testing"
  key_mgmt=WPA-EAP
  proto=WPA2
  pairwise=CCMP
  group=CCMP
  ';
// phase 1
        $config .= 'eap=' . $eapText['OUTER'] . "\n";
        $logConfig = $config;
// phase 2 if applicable; all inner methods have passwords
        if (isset($eapText['INNER']) && $eapText['INNER'] != "") {
            $config .= '  phase2="auth=' . $eapText['INNER'] . "\"\n";
            $logConfig .= '  phase2="auth=' . $eapText['INNER'] . "\"\n";
        }
// all methods set a password, except EAP-TLS
        if ($eaptype != \core\common\EAP::EAPTYPE_TLS) {
            $config .= "  password=\"$password\"\n";
            $logConfig .= "  password=\"not logged for security reasons\"\n";
        }
// for methods with client certs, add a client cert config block
        if ($eaptype == \core\common\EAP::EAPTYPE_TLS || $eaptype == \core\common\EAP::EAPTYPE_ANY) {
            $config .= "  private_key=\"./client.p12\"\n";
            $logConfig .= "  private_key=\"./client.p12\"\n";
            $config .= "  private_key_passwd=\"$password\"\n";
            $logConfig .= "  private_key_passwd=\"not logged for security reasons\"\n";
        }

// inner identity
        $config .= '  identity="' . $inner . "\"\n";
        $logConfig .= '  identity="' . $inner . "\"\n";
// outer identity, may be equal
        $config .= '  anonymous_identity="' . $outer . "\"\n";
        $logConfig .= '  anonymous_identity="' . $outer . "\"\n";
// done
        $config .= "}";
        $logConfig .= "}";

        return [$config, $logConfig];
    }

    private function packetCountEvaluation(&$testresults, $packetcount) {
        $reqs = (isset($packetcount[1]) ? $packetcount[1] : 0);
        $accepts = (isset($packetcount[2]) ? $packetcount[2] : 0);
        $rejects = (isset($packetcount[3]) ? $packetcount[3] : 0);
        $challenges = (isset($packetcount[11]) ? $packetcount[11] : 0);
        $testresults['packetflow_sane'] = TRUE;
        if ($reqs - $accepts - $rejects - $challenges != 0 || $accepts > 1 || $rejects > 1) {
            $testresults['packetflow_sane'] = FALSE;
        }

// calculate the main return values that this test yielded

        $finalretval = RADIUSTests::RETVAL_INVALID;
        if ($accepts + $rejects == 0) { // no final response. hm.
            if ($challenges > 0) { // but there was an Access-Challenge
                $finalretval = RADIUSTests::RETVAL_SERVER_UNFINISHED_COMM;
            } else {
                $finalretval = RADIUSTests::RETVAL_NO_RESPONSE;
            }
        } else // either an accept or a reject
// rejection without EAP is fishy
        if ($rejects > 0) {
            if ($challenges == 0) {
                $finalretval = RADIUSTests::RETVAL_IMMEDIATE_REJECT;
            } else { // i.e. if rejected with challenges
                $finalretval = RADIUSTests::RETVAL_CONVERSATION_REJECT;
            }
        } else if ($accepts > 0) {
            $finalretval = RADIUSTests::RETVAL_OK;
        }

        return $finalretval;
    }

    /**
     * generate an eapol_test command-line config for the fixed config filename 
     * ./udp_login_test.conf
     * @param int $probeindex number of the probe to check against
     * @param boolean $opName include Operator-Name in request?
     * @param boolean $frag make request so large that fragmentation is needed?
     * @return string the command-line for eapol_test
     */
    private function eapolTestConfig($probeindex, $opName, $frag) {
        $cmdline = CONFIG['PATHS']['eapol_test'] .
                " -a " . CONFIG['RADIUSTESTS']['UDP-hosts'][$probeindex]['ip'] .
                " -s " . CONFIG['RADIUSTESTS']['UDP-hosts'][$probeindex]['secret'] .
                " -o serverchain.pem" .
                " -c ./udp_login_test.conf" .
                " -M 22:44:66:CA:20:" . sprintf("%02d", $probeindex) . " " .
                " -t " . CONFIG['RADIUSTESTS']['UDP-hosts'][$probeindex]['timeout'] . " ";
        if ($opName) {
            $cmdline .= '-N126:s:"1cat.eduroam.org" ';
        }
        if ($frag) {
            for ($i = 0; $i < 6; $i++) { // 6 x 250 bytes means UDP fragmentation will occur - good!
                $cmdline .= '-N26:x:0000625A0BF961616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161 ';
            }
        }
        return $cmdline;
    }

    /**
     * The big Guy. This performs an actual login with EAP and records how far 
     * it got and what oddities were observed along the way
     * @param int $probeindex the probe we are connecting to (as set in product config)
     * @param array $eaptype EAP type to use for connection
     * @param string $innerUser inner username to try
     * @param string $password password to try
     * @param string $outerUser outer username to set
     * @param boolean $opnameCheck whether or not we check with Operator-Name set
     * @param boolean $frag whether or not we check with an oversized packet forcing fragmentation
     * @param string $clientcertdata client certificate credential to try
     * @return int overall return code of the login test
     * @throws Exception
     */
    public function UDP_login($probeindex, $eaptype, $innerUser, $password, $outerUser = '', $opnameCheck = TRUE, $frag = TRUE, $clientcertdata = NULL) {
        if (!isset(CONFIG['RADIUSTESTS']['UDP-hosts'][$probeindex])) {
            $this->UDP_reachability_executed = RADIUSTests::RETVAL_NOTCONFIGURED;
            return RADIUSTests::RETVAL_NOTCONFIGURED;
        }

// figure out the actual inner and outer identity to use. Inner may or
// may not have a realm; if it has, the realm of inner and outer do not
// necessarily match
// if we weren't told a realm for outer and there is nothing in inner, consider the outer_user a realm only, and prefix with local part
// inner: take whatever we got (it may or may not contain a realm identifier)
        $finalInner = $innerUser;

// outer: if we've been given a full realm spec, take it as-is
        if (preg_match("/@/", $outerUser)) {
            $finalOuter = $outerUser;
        } elseif ($outerUser != "") {// make our own guess: we've been given an explicit realm for outer
            $finalOuter = $this->bestOuterLocalpart($innerUser) . $outerUser;
        } else { // nothing. Use the realm from inner ID if it has one
            $matches = [];
            if (preg_match("/.*(@.*)/", $innerUser, $matches)) {
                $finalOuter = $this->bestOuterLocalpart($innerUser) . $matches[1];
            } elseif ($this->profile instanceof \core\ProfileRADIUS && $this->profile->realm != "") { // hm, we can only take the realm from Profile
                $finalOuter = $this->bestOuterLocalpart($innerUser) . "@" . $this->profile->realm;
            } else { // we have no idea what realm to send this to. Give up.
                return RADIUSTests::RETVAL_INCOMPLETE_DATA;
            }
        }

// we will need a config blob for wpa_supplicant, in a temporary directory
// code is copy&paste from DeviceConfig.php

        $temporary = $this->createTemporaryDirectory('test');
        $tmpDir = $temporary['dir'];
        chdir($tmpDir);
        $this->loggerInstance->debug(4, "temp dir: $tmpDir\n");

        $eapText = \core\common\EAP::eapDisplayName($eaptype);

        if ($clientcertdata !== NULL) {
            $clientcertfile = fopen($tmpDir . "/client.p12", "w");
            fwrite($clientcertfile, $clientcertdata);
            fclose($clientcertfile);
        }

// if we need client certs but don't have one, return
        if (($eaptype == \core\common\EAP::EAPTYPE_ANY || $eaptype == \core\common\EAP::EAPTYPE_TLS) && $clientcertdata === NULL) {
            $this->UDP_reachability_executed = RADIUSTests::RETVAL_NOTCONFIGURED;
            return RADIUSTests::RETVAL_NOTCONFIGURED;
        }
// if we don't have a string for outer EAP method name, give up
        if (!isset($eapText['OUTER'])) {
            $this->UDP_reachability_executed = RADIUSTests::RETVAL_NOTCONFIGURED;
            return RADIUSTests::RETVAL_NOTCONFIGURED;
        }
        $theconfigs = $this->wpaSupplicantConfig($eaptype, $finalInner, $finalOuter, $password);
// the config intentionally does not include CA checking. We do this
// ourselves after getting the chain with -o.
        $wpaSupplicantConfig = fopen($tmpDir . "/udp_login_test.conf", "w");
        fwrite($wpaSupplicantConfig, $theconfigs[0]);
        fclose($wpaSupplicantConfig);

        $testresults = [];
        $testresults['cert_oddities'] = [];
        $cmdline = $this->eapolTestConfig($probeindex, $opnameCheck, $frag);
        $this->loggerInstance->debug(4, "Shallow reachability check cmdline: $cmdline\n");
        $this->loggerInstance->debug(4, "Shallow reachability check config: $tmpDir\n" . $theconfigs[1] . "\n");
        $packetflow_orig = [];
        $time_start = microtime(true);
        exec($cmdline, $packetflow_orig);
        if ($packetflow_orig === NULL) {
            throw new Exception("The output of an exec() call really can't be NULL!");
        }
        $time_stop = microtime(true);
        $this->loggerInstance->debug(5, print_r($this->redact($password, $packetflow_orig), TRUE));
        $packetflow = $this->filterPackettype($packetflow_orig);
// when MS-CHAPv2 allows retry, we never formally get a reject (just a 
// Challenge that PW was wrong but and we should try a different one; 
// but that effectively is a reject
// so change the flow results to take that into account
        if ($packetflow[count($packetflow) - 1] == 11 && $this->checkLineparse($packetflow_orig, self::LINEPARSE_CHECK_691)) {
            $packetflow[count($packetflow) - 1] = 3;
        }
// also, the ETLRs sometimes send a reject when the server is not 
// responding. This should not be considered a real reject; it's a middle
// box unduly altering the end-to-end result. Do not consider this final
// Reject if it comes from ETLR
        if ($packetflow[count($packetflow) - 1] == 3 && $this->checkLineparse($packetflow_orig, self::LINEPARSE_CHECK_REJECTIGNORE)) {
            array_pop($packetflow);
        }
        $this->loggerInstance->debug(5, "Packetflow: " . print_r($packetflow, TRUE));
        $testresults['time_millisec'] = ($time_stop - $time_start) * 1000;
        $packetcount = array_count_values($packetflow);
        $testresults['packetcount'] = $packetcount;
        $testresults['packetflow'] = $packetflow;

// calculate packet counts and see what the overall flow was
        $finalretval = $this->packetCountEvaluation($testresults, $packetcount);

// only to make sure we've defined this in all code paths
// not setting it has no real-world effect, but Scrutinizer mocks
        $ackedmethod = FALSE;

        if ($finalretval == RADIUSTests::RETVAL_CONVERSATION_REJECT) {
            $ackedmethod = $this->checkLineparse($packetflow_orig, self::LINEPARSE_EAPACK);
            if (!$ackedmethod) {
                $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_NO_COMMON_EAP_METHOD;
            }
        }


// now let's look at the server cert+chain
// if we got a cert at all
// TODO: also only do this if EAP types all mismatched; we won't have a
// cert in that case
        if (
                $eaptype != \core\common\EAP::EAPTYPE_PWD &&
                (($finalretval == RADIUSTests::RETVAL_CONVERSATION_REJECT && $ackedmethod) || $finalretval == RADIUSTests::RETVAL_OK)
        ) {

// ALWAYS check: 
// 1) it is unnecessary to include the root CA itself (adding it has
//    detrimental effects on performance)
// 2) TLS Web Server OID presence (Windows OSes need that)
// 3) MD5 signature algorithm (iOS barks if so)
// 4) CDP URL (Windows Phone 8 barks if not present)
// 5) there should be exactly one server cert in the chain
// FOR OWN REALMS check:
// 1) does the incoming chain have a root in one of the configured roots
//    if not, this is a signficant configuration error
// return this with one or more of the CERTPROB_ constants (see defs)
// TRUST_ROOT_NOT_REACHED
// TRUST_ROOT_REACHED_ONLY_WITH_OOB_INTERMEDIATES
// then check the presented names
            $x509 = new \core\common\X509();
// $eap_certarray holds all certs received in EAP conversation
            $eapCertarray = $x509->splitCertificate(fread(fopen($tmpDir . "/serverchain.pem", "r"), "1000000"));
// we want no root cert, and exactly one server cert
            $numberRoot = 0;
            $numberServer = 0;
            $eapIntermediates = 0;
            $catIntermediates = 0;
            $servercert = FALSE;
            $totallySelfsigned = FALSE;

// Write the root CAs into a trusted root CA dir
// and intermediate and first server cert into a PEM file
// for later chain validation

            if (!mkdir($tmpDir . "/root-ca-allcerts/", 0700, true)) {
                throw new Exception("unable to create root CA directory (RADIUS Tests): $tmpDir/root-ca-allcerts/\n");
            }
            if (!mkdir($tmpDir . "/root-ca-eaponly/", 0700, true)) {
                throw new Exception("unable to create root CA directory (RADIUS Tests): $tmpDir/root-ca-eaponly/\n");
            }

            $intermOdditiesEAP = [];

            $testresults['certdata'] = [];

            $serverFile = fopen($tmpDir . "/incomingserver.pem", "w");
            foreach ($eapCertarray as $certPem) {
                $cert = $x509->processCertificate($certPem);
                if ($cert == FALSE) {
                    continue;
                }
// consider the certificate a server cert 
// a) if it is not a CA and is not a self-signed root
// b) if it is a CA, and self-signed, and it is the only cert in
//    the incoming cert chain
//    (meaning the self-signed is itself the server cert)
                if (($cert['ca'] == 0 && $cert['root'] != 1) || ($cert['ca'] == 1 && $cert['root'] == 1 && count($eapCertarray) == 1)) {
                    if ($cert['ca'] == 1 && $cert['root'] == 1 && count($eapCertarray) == 1) {
                        $totallySelfsigned = TRUE;
                        $cert['full_details']['type'] = 'totally_selfsigned';
                    }
                    $numberServer++;
                    $servercert = $cert;
                    if ($numberServer == 1) {
                        fwrite($serverFile, $certPem . "\n");
                    }
                } else
                if ($cert['root'] == 1) {
                    $numberRoot++;
// do not save the root CA, it serves no purpose
// chain checks need to be against the UPLOADED CA of the
// IdP/profile, not against an EAP-discovered CA
                } else {
                    $intermOdditiesEAP = array_merge($intermOdditiesEAP, $this->propertyCheckIntermediate($cert));
                    $intermediateFileEAP = fopen($tmpDir . "/root-ca-eaponly/incomingintermediate$eapIntermediates.pem", "w");
                    fwrite($intermediateFileEAP, $certPem . "\n");
                    fclose($intermediateFileEAP);
                    $intermediateFileAll = fopen($tmpDir . "/root-ca-allcerts/incomingintermediate$eapIntermediates.pem", "w");
                    fwrite($intermediateFileAll, $certPem . "\n");
                    fclose($intermediateFileAll);


                    if (isset($cert['CRL']) && isset($cert['CRL'][0])) {
                        $this->loggerInstance->debug(4, "got an intermediate CRL; adding them to the chain checks. (Remember: checking end-entity cert only, not the whole chain");
                        $cRLFileEAP = fopen($tmpDir . "/root-ca-eaponly/crl$eapIntermediates.pem", "w"); // this is where the root CAs go
                        fwrite($cRLFileEAP, $cert['CRL'][0]);
                        fclose($cRLFileEAP);
                        $cRLFileAll = fopen($tmpDir . "/root-ca-allcerts/crl$eapIntermediates.pem", "w"); // this is where the root CAs go
                        fwrite($cRLFileAll, $cert['CRL'][0]);
                        fclose($cRLFileAll);
                    }
                    $eapIntermediates++;
                }
                $testresults['certdata'][] = $cert['full_details'];
            }
            fclose($serverFile);
            if ($numberRoot > 0 && !$totallySelfsigned) {
                $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_ROOT_INCLUDED;
            }
            if ($numberServer > 1) {
                $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_TOO_MANY_SERVER_CERTS;
            }
            if ($numberServer == 0) {
                $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_NO_SERVER_CERT;
            }
// check server cert properties
            if ($numberServer > 0) {
                if ($servercert === FALSE) {
                    throw new Exception("We incremented the numberServer counter and added a certificate. Now it's gone?!");
                }
                $testresults['cert_oddities'] = array_merge($testresults['cert_oddities'], $this->propertyCheckServercert($servercert));
                $testresults['incoming_server_names'] = $servercert['incoming_server_names'];
            }

// check intermediate ca cert properties
// check trust chain for completeness
// works only for thorough checks, not shallow, so:
            $intermOdditiesCAT = [];
            $verifyResult = 0;
            if ($this->profile) {
                $configuredRootCt = 0;
                $myProfile = $this->profile;
// $ca_store contains certificates configured in the CAT profile
                $cAstore = $myProfile->getAttributes("eap:ca_file");
// make a copy of the EAP-received chain and add the configured intermediates, if any
                foreach ($cAstore as $oneCA) {
                    $x509 = new \core\common\X509();
                    $decoded = $x509->processCertificate($oneCA['value']);
                    if ($decoded === FALSE) {
                        throw new Exception("Unable to parse a certificate that came right from our database and has previously passed all input validation. How can that be!");
                    }
                    if ($decoded['ca'] == 1) {
                        if ($decoded['root'] == 1) { // save CAT roots to the root directory
                            $rootCAEAP = fopen($tmpDir . "/root-ca-eaponly/configuredroot$configuredRootCt.pem", "w"); // this is where the root CAs go
                            fwrite($rootCAEAP, $decoded['pem']);
                            fclose($rootCAEAP);
                            $rootCAAll = fopen($tmpDir . "/root-ca-allcerts/configuredroot$configuredRootCt.pem", "w"); // this is where the root CAs go
                            fwrite($rootCAAll, $decoded['pem']);
                            fclose($rootCAAll);
                            $configuredRootCt = $configuredRootCt + 1;
                        } else { // save the intermadiates to allcerts directory
                            $intermediateFile = fopen($tmpDir . "/root-ca-allcerts/cat-intermediate$catIntermediates.pem", "w");
                            fwrite($intermediateFile, $decoded['pem']);
                            fclose($intermediateFile);

                            $intermOdditiesCAT = array_merge($intermOdditiesCAT, $this->propertyCheckIntermediate($decoded));
                            if (isset($decoded['CRL']) && isset($decoded['CRL'][0])) {
                                $this->loggerInstance->debug(4, "got an intermediate CRL; adding them to the chain checks. (Remember: checking end-entity cert only, not the whole chain");
                                $cRLfile = fopen($tmpDir . "/root-ca-allcerts/crl_cat$catIntermediates.pem", "w"); // this is where the root CAs go
                                fwrite($cRLfile, $decoded['CRL'][0]);
                                fclose($cRLfile);
                            }
                            $catIntermediates++;
                        }
                    }
                }
                if ($numberServer > 0) {
                    $this->loggerInstance->debug(4, "This is the server certificate, with CRL content if applicable: " . print_r($servercert, true));
                }
                $checkstring = "";
                if (isset($servercert['CRL']) && isset($servercert['CRL'][0])) {
                    $this->loggerInstance->debug(4, "got a server CRL; adding them to the chain checks. (Remember: checking end-entity cert only, not the whole chain");
                    $checkstring = "-crl_check_all";
                    $cRLfile1 = fopen($tmpDir . "/root-ca-eaponly/crl-server.pem", "w"); // this is where the root CAs go
                    fwrite($cRLfile1, $servercert['CRL'][0]);
                    fclose($cRLfile1);
                    $cRLfile2 = fopen($tmpDir . "/root-ca-allcerts/crl-server.pem", "w"); // this is where the root CAs go
                    fwrite($cRLfile2, $servercert['CRL'][0]);
                    fclose($cRLfile2);
                }

// save all intermediate certificate CRLs to separate files in root-ca directory
// now c_rehash the root CA directory ...
                system(CONFIG['PATHS']['c_rehash'] . " $tmpDir/root-ca-eaponly/ > /dev/null");
                system(CONFIG['PATHS']['c_rehash'] . " $tmpDir/root-ca-allcerts/ > /dev/null");

// ... and run the verification test
                $verifyResultEaponly = [];
                $verifyResultAllcerts = [];
// the error log will complain if we run this test against an empty file of certs
// so test if there's something PEMy in the file at all
                if (filesize("$tmpDir/incomingserver.pem") > 10) {
                    exec(CONFIG['PATHS']['openssl'] . " verify $checkstring -CApath $tmpDir/root-ca-eaponly/ -purpose any $tmpDir/incomingserver.pem", $verifyResultEaponly);
                    $this->loggerInstance->debug(4, CONFIG['PATHS']['openssl'] . " verify $checkstring -CApath $tmpDir/root-ca-eaponly/ -purpose any $tmpDir/incomingserver.pem\n");
                    $this->loggerInstance->debug(4, "Chain verify pass 1: " . print_r($verifyResultEaponly, TRUE) . "\n");
                    exec(CONFIG['PATHS']['openssl'] . " verify $checkstring -CApath $tmpDir/root-ca-allcerts/ -purpose any $tmpDir/incomingserver.pem", $verifyResultAllcerts);
                    $this->loggerInstance->debug(4, CONFIG['PATHS']['openssl'] . " verify $checkstring -CApath $tmpDir/root-ca-allcerts/ -purpose any $tmpDir/incomingserver.pem\n");
                    $this->loggerInstance->debug(4, "Chain verify pass 2: " . print_r($verifyResultAllcerts, TRUE) . "\n");
                }


// now we do certificate verification against the collected parents
// this is done first for the server and then for each of the intermediate CAs
// any oddities observed will 
// openssl should havd returned exactly one line of output,
// and it should have ended with the string "OK", anything else is fishy
// The result can also be an empty array - this means there were no
// certificates to check. Don't complain about chain validation errors
// in that case.
// we have the following test result possibilities:
// 1. test against allcerts failed
// 2. test against allcerts succeded, but against eaponly failed - warn admin
// 3. test against eaponly succeded, in this case critical errors about expired certs
//    need to be changed to notices, since these certs obviously do tot participate
//    in server certificate validation.
                if (count($verifyResultAllcerts) > 0) {
                    if (!preg_match("/OK$/", $verifyResultAllcerts[0])) { // case 1
                        $verifyResult = 1;
                        if (preg_match("/certificate revoked$/", $verifyResultAllcerts[1])) {
                            $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_SERVER_CERT_REVOKED;
                        } elseif (preg_match("/unable to get certificate CRL/", $verifyResultAllcerts[1])) {
                            $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_UNABLE_TO_GET_CRL;
                        } else {
                            $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_TRUST_ROOT_NOT_REACHED;
                        }
                    } else if (!preg_match("/OK$/", $verifyResultEaponly[0])) { // case 2
                        $verifyResult = 2;
                        if (preg_match("/certificate revoked$/", $verifyResultEaponly[1])) {
                            $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_SERVER_CERT_REVOKED;
                        } elseif (preg_match("/unable to get certificate CRL/", $verifyResultEaponly[1])) {
                            $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_UNABLE_TO_GET_CRL;
                        } else {
                            $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_TRUST_ROOT_REACHED_ONLY_WITH_OOB_INTERMEDIATES;
                        }
                    } else { // case 3
                        $verifyResult = 3;
                    }
                }

// check the incoming hostname (both Subject:CN and subjectAltName:DNS
// against what is configured in the profile; it's a significant error
// if there is no match!
// FAIL if none of the configured names show up in the server cert
// WARN if the configured name is only in either CN or sAN:DNS
                $confnames = $myProfile->getAttributes("eap:server_name");
                $expectedNames = [];
                foreach ($confnames as $tuple) {
                    $expectedNames[] = $tuple['value'];
                }

// Strategy for checks: we are TOTALLY happy if any one of the
// configured names shows up in both the CN and a sAN
// This is the primary check.
// If that was not the case, we are PARTIALLY happy if any one of
// the configured names was in either of the CN or sAN lists.
// we are UNHAPPY if no names match!
                $happiness = "UNHAPPY";
                foreach ($expectedNames as $expectedName) {
                    $this->loggerInstance->debug(4, "Managing expectations for $expectedName: " . print_r($servercert['CN'], TRUE) . print_r($servercert['sAN_DNS'], TRUE));
                    if (array_search($expectedName, $servercert['CN']) !== FALSE && array_search($expectedName, $servercert['sAN_DNS']) !== FALSE) {
                        $this->loggerInstance->debug(4, "Totally happy!");
                        $happiness = "TOTALLY";
                        break;
                    } else {
                        if (array_search($expectedName, $servercert['CN']) !== FALSE || array_search($expectedName, $servercert['sAN_DNS']) !== FALSE) {
                            $happiness = "PARTIALLY";
// keep trying with other expected names! We could be happier!
                        }
                    }
                }
                switch ($happiness) {
                    case "UNHAPPY":
                        $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_SERVER_NAME_MISMATCH;
                        break;
                    case "PARTIALLY":
                        $testresults['cert_oddities'][] = RADIUSTests::CERTPROB_SERVER_NAME_PARTIAL_MATCH;
                        break;
                    default: // nothing to complain about!
                        break;
                }

// TODO: dump the details in a class variable in case someone cares
            }
            $testresults['cert_oddities'] = array_merge($testresults['cert_oddities'], $intermOdditiesEAP);
            if (in_array(RADIUSTests::CERTPROB_OUTSIDE_VALIDITY_PERIOD, $intermOdditiesCAT) && $verifyResult == 3) {
                $key = array_search(RADIUSTests::CERTPROB_OUTSIDE_VALIDITY_PERIOD, $intermOdditiesCAT);
                $intermOdditiesCAT[$key] = RADIUSTests::CERTPROB_OUTSIDE_VALIDITY_PERIOD_WARN;
            }

            $testresults['cert_oddities'] = array_merge($testresults['cert_oddities'], $intermOdditiesCAT);

// mention trust chain failure only if no expired cert was in the chain; otherwise path validation will trivially fail
            if (in_array(RADIUSTests::CERTPROB_OUTSIDE_VALIDITY_PERIOD, $testresults['cert_oddities'])) {
                $this->loggerInstance->debug(4, "Deleting trust chain problem report, if present.");
                if (($key = array_search(RADIUSTests::CERTPROB_TRUST_ROOT_NOT_REACHED, $testresults['cert_oddities'])) !== false) {
                    unset($testresults['cert_oddities'][$key]);
                }
                if (($key = array_search(RADIUSTests::CERTPROB_TRUST_ROOT_REACHED_ONLY_WITH_OOB_INTERMEDIATES, $testresults['cert_oddities'])) !== false) {
                    unset($testresults['cert_oddities'][$key]);
                }
            }
        }
        $this->loggerInstance->debug(4, "UDP_LOGIN\n");
        $this->loggerInstance->debug(4, $testresults);
        $this->loggerInstance->debug(4, "\nEND\n");
        $this->UDP_reachability_result[$probeindex] = $testresults;
        $this->UDP_reachability_executed = $finalretval;
        return $finalretval;
    }

    /**
     * This function parses a X.509 cert and returns all certificatePolicies OIDs
     * 
     * @param array $cert (returned from openssl_x509_parse) 
     * @return array of OIDs
     */
    private function propertyCheckPolicy($cert) {
        $oids = [];
        if ($cert['extensions']['certificatePolicies']) {
            foreach (CONFIG['RADIUSTESTS']['TLS-acceptableOIDs'] as $key => $oid) {
                if (preg_match("/Policy: $oid/", $cert['extensions']['certificatePolicies'])) {
                    $oids[$key] = $oid;
                }
            }
        }
        return $oids;
    }

    /**
     * This function parses a X.509 cert and returns the value of $field
     * 
     * @param array $cert (returned from openssl_x509_parse) 
     * @return string value of the issuer field or ''
     */
    private function getCertificateIssuer($cert) {
        $issuer = '';
        foreach ($cert['issuer'] as $key => $val) {
            if (is_array($val)) {
                foreach ($val as $v) {
                    $issuer .= "/$key=$v";
                }
            } else {
                $issuer .= "/$key=$val";
            }
        }
        return $issuer;
    }

    /**
     * This function parses a X.509 cert and returns the value of $field
     * 
     * @param array $cert (returned from openssl_x509_parse) 
     * @param string $field 
     * @return string value of the extention named $field or ''
     */
    private function getCertificatePropertyField($cert, $field) {
        if ($cert['extensions'][$field]) {
            return $cert['extensions'][$field];
        }
        return '';
    }

    /**
     * This function executes openssl s_client command
     * 
     * @param string $host IP address
     * @param string $arg arguments to add to the openssl command 
     * @param array $testresults by-reference: the testresults array we are writing into
     * @return array result of openssl s_client ...
     */
    private function openssl_s_client($host, $arg, &$testresults) {
// we got the IP address either from DNS (guaranteeing well-formedness)
// or from filter_var'ed user input. So it is always safe as an argument
// but code analysers want this more explicit, so here is this extra
// call to escapeshellarg()
        $escapedHost = escapeshellarg($host);
        $this->loggerInstance->debug(4, CONFIG['PATHS']['openssl'] . " s_client -connect " . $escapedHost . " -tls1 -CApath " . ROOT . "/config/ca-certs/ $arg 2>&1\n");
        $time_start = microtime(true);
        $opensslbabble = [];
        $result = 999; // likely to become zero by openssl; don't want to initialise to zero, could cover up exec failures
        exec(CONFIG['PATHS']['openssl'] . " s_client -connect " . $escapedHost . " -tls1 -CApath " . ROOT . "/config/ca-certs/ $arg 2>&1", $opensslbabble, $result);
        if ($opensslbabble === NULL) {
            throw new Exception("The output of an exec() call really can't be NULL!");
        }
        $time_stop = microtime(true);
        $testresults['time_millisec'] = floor(($time_stop - $time_start) * 1000);
        $testresults['returncode'] = $result;
        return $opensslbabble;
    }

    /**
     * This function parses openssl s_client result
     * 
     * @param string $host IP:port
     * @param string $testtype capath or clients
     * @param array $opensslbabble openssl command output
     * @param array $testresults by-reference: pointer to results array we write into
     * @param string $type type of certificate
     * @param int $resultArrayKey results array key
     * @return int return code
     */
    private function opensslResult($host, $testtype, $opensslbabble, &$testresults, $type = '', $resultArrayKey = 0) {
        $oldlocale = $this->languageInstance->setTextDomain('diagnostics');

        $res = RADIUSTests::RETVAL_OK;
        switch ($testtype) {
            case "capath":
                if (preg_match('/connect: Connection refused/', implode($opensslbabble))) {
                    $testresults[$host]['status'] = RADIUSTests::RETVAL_CONNECTION_REFUSED;
                    $res = RADIUSTests::RETVAL_INVALID;
                }
                if (preg_match('/verify error:num=19/', implode($opensslbabble))) {
                    $testresults[$host]['cert_oddity'] = RADIUSTests::CERTPROB_UNKNOWN_CA;
                    $testresults[$host]['status'] = RADIUSTests::RETVAL_INVALID;
                    $res = RADIUSTests::RETVAL_INVALID;
                }
                if (preg_match('/verify return:1/', implode($opensslbabble))) {
                    $testresults[$host]['status'] = RADIUSTests::RETVAL_OK;
                    $servercertStage1 = implode("\n", $opensslbabble);
                    $servercert = preg_replace("/.*(-----BEGIN CERTIFICATE-----.*-----END CERTIFICATE-----\n).*/s", "$1", $servercertStage1);
                    $data = openssl_x509_parse($servercert);
                    $testresults[$host]['certdata']['subject'] = $data['name'];
                    $testresults[$host]['certdata']['issuer'] = $this->getCertificateIssuer($data);
                    if (($altname = $this->getCertificatePropertyField($data, 'subjectAltName'))) {
                        $testresults[$host]['certdata']['extensions']['subjectaltname'] = $altname;
                    }
                    $oids = $this->propertyCheckPolicy($data);
                    if (!empty($oids)) {
                        foreach ($oids as $resultArrayKey => $o) {
                            $testresults[$host]['certdata']['extensions']['policyoid'][] = " $o ($resultArrayKey)";
                        }
                    }
                    if (($crl = $this->getCertificatePropertyField($data, 'crlDistributionPoints'))) {
                        $testresults[$host]['certdata']['extensions']['crlDistributionPoint'] = $crl;
                    }
                    if (($ocsp = $this->getCertificatePropertyField($data, 'authorityInfoAccess'))) {
                        $testresults[$host]['certdata']['extensions']['authorityInfoAccess'] = $ocsp;
                    }
                }
                break;
            case "clients":
                $ret = $testresults[$host]['ca'][$type]['certificate'][$resultArrayKey]['returncode'];
                $output = implode($opensslbabble);
                if ($ret == 0) {
                    $testresults[$host]['ca'][$type]['certificate'][$resultArrayKey]['connected'] = 1;
                } else {
                    $testresults[$host]['ca'][$type]['certificate'][$resultArrayKey]['connected'] = 0;
                    if (preg_match('/connect: Connection refused/', implode($opensslbabble))) {
                        $testresults[$host]['ca'][$type]['certificate'][$resultArrayKey]['returncode'] = RADIUSTests::RETVAL_CONNECTION_REFUSED;
                        $resComment = _("No TLS connection established: Connection refused");
                    } elseif (preg_match('/sslv3 alert certificate expired/', $output)) {
                        $resComment = _("certificate expired");
                    } elseif (preg_match('/sslv3 alert certificate revoked/', $output)) {
                        $resComment = _("certificate was revoked");
                    } elseif (preg_match('/SSL alert number 46/', $output)) {
                        $resComment = _("bad policy");
                    } elseif (preg_match('/tlsv1 alert unknown ca/', $output)) {
                        $resComment = _("unknown authority");
                        $testresults[$host]['ca'][$type]['certificate'][$resultArrayKey]['reason'] = RADIUSTests::CERTPROB_UNKNOWN_CA;
                    } else {
                        $resComment = _("unknown authority or no certificate policy or another problem");
                    }
                    $testresults[$host]['ca'][$type]['certificate'][$resultArrayKey]['resultcomment'] = $resComment;
                }
                break;
        }

        $this->languageInstance->setTextDomain($oldlocale);
        return $res;
    }

    /**
     * This function executes openssl s_clientends command to check if a server accept a CA
     * @param string $host IP:port
     * @return int returncode
     */
    public function cApathCheck($host) {
        if (!isset($this->TLS_CA_checks_result[$host])) {
            $this->TLS_CA_checks_result[$host] = [];
        }
        $opensslbabble = $this->openssl_s_client($host, '', $this->TLS_CA_checks_result[$host]);
        return $this->opensslResult($host, 'capath', $opensslbabble, $this->TLS_CA_checks_result);
    }

    /**
     * This function executes openssl s_client command to check if a server accept a client certificate
     * @param string $host IP:port
     * @return int returncode
     */
    public function TLS_clients_side_check($host) {
        $res = RADIUSTests::RETVAL_OK;
        if (!is_array(CONFIG['RADIUSTESTS']['TLS-clientcerts']) || count(CONFIG['RADIUSTESTS']['TLS-clientcerts']) == 0) {
            return RADIUSTests::RETVAL_SKIPPED;
        }
        if (preg_match("/\[/", $host)) {
            return RADIUSTests::RETVAL_INVALID;
        }
        foreach (CONFIG['RADIUSTESTS']['TLS-clientcerts'] as $type => $tlsclient) {
            $this->TLS_clients_checks_result[$host]['ca'][$type]['clientcertinfo']['from'] = $type;
            $this->TLS_clients_checks_result[$host]['ca'][$type]['clientcertinfo']['status'] = $tlsclient['status'];
            $this->TLS_clients_checks_result[$host]['ca'][$type]['clientcertinfo']['message'] = $this->TLS_certkeys[$tlsclient['status']];
            $this->TLS_clients_checks_result[$host]['ca'][$type]['clientcertinfo']['issuer'] = $tlsclient['issuerCA'];
            foreach ($tlsclient['certificates'] as $k => $cert) {
                $this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['status'] = $cert['status'];
                $this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['message'] = $this->TLS_certkeys[$cert['status']];
                $this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['expected'] = $cert['expected'];
                $add = ' -cert ' . ROOT . '/config/cli-certs/' . $cert['public'] . ' -key ' . ROOT . '/config/cli-certs/' . $cert['private'];
                if (!isset($this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k])) {
                    $this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k] = [];
                }
                $opensslbabble = $this->openssl_s_client($host, $add, $this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]);
                $res = $this->opensslResult($host, 'clients', $opensslbabble, $this->TLS_clients_checks_result, $type, $k);
                if ($cert['expected'] == 'PASS') {
                    if (!$this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['connected']) {
                        if (($tlsclient['status'] == 'ACCREDITED') && ($cert['status'] == 'CORRECT')) {
                            $this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['returncode'] = RADIUSTests::CERTPROB_NOT_ACCEPTED;
                            $this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['finalerror'] = 1;
                            break;
                        }
                    }
                } else {
                    if ($this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['connected']) {
                        $this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['returncode'] = RADIUSTests::CERTPROB_WRONGLY_ACCEPTED;
                    }

                    if (($this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['reason'] == RADIUSTests::CERTPROB_UNKNOWN_CA) && ($tlsclient['status'] == 'ACCREDITED') && ($cert['status'] == 'CORRECT')) {
                        $this->TLS_clients_checks_result[$host]['ca'][$type]['certificate'][$k]['finalerror'] = 1;
                        echo "koniec zabawy2<br>";
                        break;
                    }
                }
            }
        }
        return $res;
    }

}
