<?php
/**
 * Google Play API
 *
 * @author Brandon Martella
 *
 * based off of Niklas Nilsson <splitfeed@gmail.com>
 *
 */
class PlaySession
{
    private $log = null;

    private $authFileName = "auth.txt";
    private $authSubToken = "";
    private $service = "androidmarket";
    private $url_login  = "https://www.google.com/accounts/ClientLogin"; // "https://android.clients.google.com/auth"
    private $account_type_google = "GOOGLE";
    private $account_type_hosted = "HOSTED";
    private $account_type_hosted_or_google = "HOSTED_OR_GOOGLE";

    /**
     * Constructor
     *
     * sets up class and verifies Google login token.
     *
     * @param string $androidId
     * @param string $lang
     * @param bool $debug
     * @throws Exception If auth token invalid
     */
    function __construct ($androidId="", $lang="", $debug=false) {

        global $log;

        $this->log = $log;

        $this->preFetch = array();

        if (!$androidId) {
            $androidId = ANDROID_DEVICEID;
        }

        if (!$lang) {
            $lang = LANG;
        }

        $this->androidId = $androidId;
        $this->lang = $lang;
        $this->debug = $debug;

        // make sure we have valid auth token
        $status = $this->getAuthSubToken();

        if (!$status) {
            throw new Exception("Error getting Auth token");
        }

    }

    /**
     * login
     *
     * If we have $authtoken then return else try to log in to Google
     *
     * @param string $email
     * @param string $password
     * @param string $authtoken
     * @return bool|authtoken
     */
    public function login($email="", $password="", $authtoken="") {

        if ($authtoken) {
            $this->authSubToken = $authtoken;
            return $this->authSubToken;

        } else {
            $postFields = array(
                "Email"         => $email ? $email : GOOGLE_EMAIL,
                "Passwd"        => $password ? $password : GOOGLE_PASSWD,
                "service"       => $this->service,
                "accountType"   => $this->account_type_google,
            );

            $post = "";
            foreach ($postFields as $field => $val) {
                $post .= $field."=".urlencode($val)."&";
            }

            // create a new cURL resource
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->url_login); // https://www.google.com/accounts/ClientLogin
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_VERBOSE, FALSE);


            $headers = array(
                "User-Agent: Android-Finsky/4.3.11 (api=3,versionCode=80230011,sdk=16,device=vanquish,hardware=qcom,product=XT926_verizon)",
                "Content-Type: application/x-www-form-urlencoded",
                "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7",
            );
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $ret = curl_exec($ch);
            curl_close($ch);

            $aRet = explode("\n", $ret);

            //print_r($aRet);

            foreach ($aRet as $line) {
                if (substr($line,0,5) == "Auth=") {
                    $this->authSubToken = substr($line,5);
                    return $this->authSubToken;
                }
            }

            return false;
        }
    }

    /**
     * getAuthSubToken
     *
     * Gets auth token in file and validates it. If files doesn't exist or token is invalid
     * then it tries to login again and save new token to file
     *
     * @return bool
     */
    public function getAuthSubToken() {
        $status = false;

        // See if we have auth code in file
        $currentAuth = false;
        if (file_exists(OUTPUT_PATH . $this->authFileName)) {
            $currentAuth = file_get_contents(OUTPUT_PATH . $this->authFileName);
        }


        // No auth code
        if ($currentAuth === false) {
            $this->log->logInfo('NO AUTH CODE ... logging in to get a new one.');

            // Try to login and get an auth code
            $currentAuth = $this->login();

            // Did we get one?
            if ($currentAuth) {
                // write to file for next time.
                $results = file_put_contents(OUTPUT_PATH . $this->authFileName, $currentAuth, LOCK_EX);

                if ($results !== false) {
                    $this->authSubToken = $currentAuth;
                    $status = true;
                }
            }

        // Yes have an auth code
        } else {
            $this->log->logInfo("AUTH CODE: {$currentAuth}");

            $this->authSubToken = $currentAuth;


            // check auth code
            if ($this->validate()) {
                $status = true;

            } else {
                // delete auth file since its bad.
                unlink($this->authFileName);
            }


        }

        return $status;
    }


    /**
     * validate
     *
     * Validates auth with Google by calling a page and checking results
     *
     * @return bool
     */
    public function validate() {

        //Check login by doing a dummy call
        $this->log->logInfo('VALIDATE auth token ... calling /browse page');
        $response = $this->executeRequestApi2("browse?c=0", 1);

        if ($response == false) {
            $this->log->logError('ERROR Login validation error');
            return false;

        } else {
            $this->log->logInfo('GOOD Login validation successful');
        }

        return true;
    }

    /**
     * execute
     *
     * call executeRequestApi2
     *
     * @param string $path url path to call via api
     * @param array|null $datapost optional post data passed via array
     * @return bool|\ResponseWrapper $reponse
     */
    public function execute($path, $datapost=null) {

        $response = $this->executeRequestApi2($path, 5, $datapost);

        return $response;
    }

    /**
     * _try_register_preFetch
     *
     * Checks response from Google if it included prefetch and stores in array.  Sometimes Google will
     * return pages it thinks you are going to call in advance using prefetch.
     *
     * Needs more testing!
     *
     * @param $response
     */
    private function _try_register_preFetch($response) {
        while ($response->hasPreFetch()) {
            $preFetch = $response->popPreFetch();
            $this->preFetch[$preFetch->getUrl()] = $preFetch->getResponse();
        }
    }

    /**
     * executeRequestApi2
     *
     * Calls Google Play API
     *
     * @param string $path url path to call via api
     * @param int $retry number of tried to call the api if error occurs.
     * @param array|null $datapost optional post data passed via array
     * @param string $post_content_type optional post header
     * @return bool|\ResponseWrapper
     */
    private function executeRequestApi2($path, $retry=5, $datapost=null, $post_content_type="application/x-www-form-urlencoded; charset=UTF-8"){
        $try = 0;
        $sleep = 10;
        $fail = false;

        $this->log->logInfo("CALLING https://android.clients.google.com/fdfe/{$path}");


        if (is_null($datapost) && array_key_exists($path, $this->preFetch)) {
            $this->log->logInfo("PREFETCH found for https://android.clients.google.com/fdfe/{$path}");
            $data = $this->preFetch[$path];

        } else {

            do {
                $try++;

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://android.clients.google.com/fdfe/" . $path);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                //@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                //curl_setopt($ch, CURLOPT_USERAGENT, "Android-Finsky/4.3.11 (api=3,versionCode=80230011,sdk=16,device=vanquish,hardware=qcom,product=XT926_verizon)");

                $headers = array(
                    "Accept-Language: en_US",
                    "Authorization: GoogleLogin auth=" . $this->authSubToken,
                    "X-DFE-Enabled-Experiments: cl:billing.purchase_button_show_wallet_3d_icon",
                    "X-DFE-Unsupported-Experiments: nocache:dfe:dc:1,nocache:dfe:uc:US,buyer_currency,buyer_currency_in_app,checkin.set_asset_paid_app_field,cl:billing.purchase_button_show_wallet_icon,cl:billing.select_add_instrument_by_default,content_ratings,localized_images,market_emails,nocache:billing.use_charging_poller,nocache:billing.use_provisioning_poller,nocache:billing.use_provisioning_poller_inapp,nocache:billing.use_provisioning_poller_subs,nocache:cl:warm_welcome.disabled,nocache:enable_play_country,nocache:enable_tablet_large,nocache:encrypted_apk,nocache:recs:automated_weight_adjuster_36,nocache:recs:books_annotate_merch_collection_20130620_75,nocache:recs:movies_annotate_merch_collection_20130620_25,nocache:recs:weights_apps_20130219_00,nocache:recs:weights_books_20130219_00,nocache:recs:weights_movies_20130614_90,nocache:recs:weights_plusones_20130708_00,nocache:recs:weights_track_20130409_35,nocache:remove_plusone_annotation_control,nocache:use_gaia_mint_instead_of_checkout_auth_token,nocache:user_challenge,prod_locale_boost,recent_changes,recs:books_portrait_20121210_25,shekel_test",
                    "X-DFE-Device-Id: $this->androidId",
                    "X-DFE-Client-Id: am-android-verizon",
                    //"X-DFE-Logging-Id"              => "25d9d18b0fa2e62f", // Deprecated?
                    "User-Agent: Android-Finsky/4.3.11 (api=3,versionCode=80230011,sdk=16,device=vanquish,hardware=qcom,product=XT926_verizon)",
                    "X-DFE-SmallestScreenWidthDp: 360",
                    "X-DFE-Filter-Level: 3",
                    //"Accept-Encoding: ''",
                    "Host: android.clients.google.com"
                );

                if ($datapost) {
                    $headers["Content-Type"] = $post_content_type;

                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $datapost);
                }

                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_VERBOSE, FALSE);

                $data = curl_exec($ch);

                //print_r($data);

                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($http_code == 302) {
                    $this->log->logError("ERROR $http_code: Auth error? HTTP request returned error on try $try, sleeping for $sleep seconds.");
                    $fail = true;

                } else if ($http_code != 200) {
                    $this->log->logError("ERROR $http_code: HTTP request returned error on try $try, sleeping for $sleep seconds.");
                    $fail = true;
                    sleep($sleep);

                } else {
                    $fail = false;
                }

                curl_close($ch);

                // Google doesnt seem to be adding gzip
                //$ret = gzdecode($ret);

            } while ($fail && $try < $retry);
        }

        if ($fail) {
            return false;
        }

        $fp = fopen("php://memory", "w+b");
        fwrite($fp, $data, strlen($data));
        rewind($fp);

        $response = new ResponseWrapper($fp);
        //$response->read($fp);

        $this->_try_register_preFetch($response);

        return $response;
    }

    /**
     * reviews
     *
     * Builds review url then calls api
     *
     * @param $packageId
     * @param bool $filterByDevice filter results by requesting phone
     * @param int $sort sort order
     * @param null $nb_results number of results for path
     * @param null $offset  offset for path
     * @return \GetReviewsResponse|bool
     */
    public function reviews($packageId, $filterByDevice = false, $sort = 0, $nb_results = null, $offset = null) {
        $this->log->logInfo("PROCESSING reviews for {$packageId}");

        // Browse reviews.
        // packageName is the app unique ID.
        // If filterByDevice is True, return only reviews for your device."""

        $path = "rev?doc=" . urlencode($packageId);

        if (!is_null($nb_results)) {
            $path .= "&n=" . $nb_results;
        }
        if (!is_null($offset)) {
            $path .= "&o=" . $offset;
        }
        if ($filterByDevice != false) {
            $path .= "&dfil=1";
        }
        // sort { 0 = Most Recent, 1 = Highest Rated, 2 = Most Helpful }
        $path .= "&sort=" . $sort;

        $response = $this->execute($path);

        //print $response;
        if ($response) {
            return $response->getPayload()->getReviewResponse()->getGetResponse();
        }

        return false;
    }

    /**
     * ratings
     *
     * Builds details url then calls api
     *
     * @param $packageId
     * @return \GetReviewsResponse|bool
     */
    public function ratings($packageId) {
        $this->log->logInfo("PROCESSING ratings for {$packageId}");

        $path = "details?doc=" . urlencode($packageId);
        $response = $this->execute($path);

        if ($response) {
            return $response->getPayload()->getDetailsResponse();
        }

        return false;

    }

}
