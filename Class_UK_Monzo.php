<?php
date_default_timezone_set('UTC');
define('SESSION_FILE', '.monzo.session');
define('MONZO_URL', 'https://api.monzo.com');
define('TX_SINCE', '14400'); //how many seconds to go back when fetching transactions. 4 hours
class UK_Monzo
{
    private $logincURL;
    private static $CURL_OPTS = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '',
        CURLOPT_AUTOREFERER => true,
        CURLOPT_CONNECTTIMEOUT => 120,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_MAXREDIRS => 20,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_COOKIESESSION => true,
        CURLOPT_VERBOSE => false,
        CURLOPT_CAINFO => '../cacert.pem',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
    );

    public function __construct($accountNumber, $clientId, $clientSecret, $redirecrUri)
    {
        $this->activeTrade = false;
        $this->activeTradeArray = array();
        $this->bearerToken = null;
        $this->refreshToken = null;
        $this->accountNumber = $accountNumber;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirecrUri;
        $this->accountID = false;
        $this->loginSuccess = false;
        // Initialise cURL
        $this->logincURL = curl_init();
        if ($this->logincURL == false)
        {
            throw new Exception("Could not get initialise cURL");
        }
    }
    /**
     * Curl wrapper
     *
     */

    private function getJson($endpoint, $data = null, $sendAuthHeader = true)
    {
        $curl = $this->logincURL;
        curl_setopt_array($curl, $this::$CURL_OPTS);
        $header = array();
        if ($sendAuthHeader)
        {
            $header[] = "Authorization: Bearer $this->bearerToken";
        }
        else
        {
            $header[] = "";
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        if ($data == null)
        {
            curl_setopt($curl, CURLOPT_HTTPGET, true);
        }
        else
        {
            if (is_array($data))
            {
                $data = http_build_query($data);
            }
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($curl, CURLOPT_URL, MONZO_URL . "{$endpoint}");
        $curlResource = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($curlResource === false || $httpcode != '200')
        {
            throw new Exception('Monzo cURL call failed, you might have another session somewhere. Remove session file in output dir and run again.: ' . curl_error($curl));
        }
        syslog(LOG_DEBUG, "Monzo: cURL call succeeded");
        return json_decode($curlResource, true);
    }

    /**
     * Get Account ID based on the account number
     *
     */

    private function fetchAccountID($accountNumber)
    {
        $accounts = $this->getJson("/accounts");
        if (isset($accounts['accounts']))
        {
            syslog(LOG_DEBUG, "Monzo: Looking for account ID");
            // Loop through all accounts and find the ID of configured bank account number.
            foreach ($accounts['accounts'] as $account)
            {
                if (array_key_exists("account_number", $account) && $account['account_number'] == $this->accountNumber)
                {
                    return $account['id'];
                }
            }
        }
        else
        {
            syslog(LOG_ERR, "Monzo: No accounts found");
            return false;
        }
    }

    public function getVersion()
    {
        return "20190206";
    }
    private function storeTokens($accessToken, $refreshToken)
    {
        file_put_contents(SESSION_FILE, "access_token=$accessToken\nrefresh_token=$refreshToken");
    }

    private function retrieveTokens()
    {
        if (!file_exists(SESSION_FILE))
        {
            $this->storeTokens('blank', 'blank');
        }
        $tokens = array();
        foreach (file(SESSION_FILE) as $line)
        {
            list($key, $value) = explode('=', $line, 2) + array(
                null,
                null
            );
            if ($value !== null)
            {
                $tokens[$key] = $value;
            }
        }
        return $tokens;
    }
    /**
    * This is OAuth Workflow from Monzo. See https://docs.monzo.com/#authentication
    *
    **/
    private function tokenInit()
    {
        $tempSession = uniqid();
        echo "*************************************************   MONZO AUTHENTICATION WORKFLOW ***********************************************************\n";
        echo "Please visit the following URL in your browser and follow instructions \n";
        echo "###########\n";
        echo "\n";
        echo "https://auth.monzo.com/?client_id=$this->clientId&redirect_uri=$this->redirectUri&response_type=code&state=$tempSession \n";
        echo "\n";
        echo "###########\n";
        echo "When you get email from Monzo, copy the link button (Right click -> Copy link Location) from the email and paste here. Do not click it. \n";
        echo "*********************************************************************************************************************************************\n";
        $verificationUrl = readline("Email link: ");
        parse_str(parse_url($verificationUrl, PHP_URL_QUERY) , $urlParams);
        if (array_key_exists('code', $urlParams) && array_key_exists('state', $urlParams))
        {
            if ($urlParams['state'] === $tempSession)
            {
                $data = array(
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $this->redirectUri,
                    'code' => $urlParams['code']
                );
                $tokenData = $this->getJson('/oauth2/token', $data, false);
                if (isset($tokenData['access_token']) && isset($tokenData['refresh_token']))
                {
                    $this->bearerToken = $tokenData['access_token'];
                    $this->refreshToken = $tokenData['refresh_token'];
                    if ($this->validateTokens())
                    {
                        $this->storeTokens($tokenData['access_token'], $tokenData['refresh_token']);
                    }
                    else
                    {
                        syslog(LOG_ERR, "Monzo: Newly created tokens not valid");
                        throw new Exception('Monzo: Newly created Tokens not valid');
                    }
                }
                else
                {
                    syslog(LOG_ERR, "Monzo: Tokens not found");
                    throw new Exception('Monzo: Tokens not found');
                }
            }
            else
            {
                syslog(LOG_ERR, "Monzo: State does not match the one that was sent to Monzo.");
                throw new Exception('Monzo: State does not match the one that was sent to Monzo.');
            }
        }
        else
        {
            syslog(LOG_ERR, "Monzo: Code and State ids not found in the email URL");
            throw new Exception('Monzo: Code and State ids not found in the email URL');
        }
    }
    /**
    * Check if the tokens we have in this session are valid
    *
    **/

    private function validateTokens()
    {
        $whoami = $this->getJson('/ping/whoami');
        if (isset($whoami['authenticated']) && $whoami['authenticated'])
        {
            syslog(LOG_DEBUG, "Monzo: Token is still valid");
            return true;
        }
        else
        {
            return false;
        }
    }

    public function login()
    {
        $sessionTokens = $this->retrieveTokens();
        $this->bearerToken = $sessionTokens['access_token'];
        $this->refreshToken = $sessionTokens['refresh_token'];
        if ($this->bearerToken != 'blank' && $this->refreshToken != 'blank')
        {
            //Tokens are set, are they valid?
            if ($this->validateTokens())
            {
                $this->loginSuccess = true;
                return;
            }
            else
            {
                if($this->refreshToken()){
                  $this->loginSuccess = true;
                  return;
                }else {
                  $this->tokenInit();
                  $this->loginSuccess = true;
                  return;
                }

            }
        }
        else
        {
            //Tokens are default, need to init.
            $this->tokenInit();
            $this->loginSuccess = true;
            return;
        }
    }

    public function logout()
    {
        $this->loginSuccess = false;
    }
    /**
    * Monzo API tokens need to be refreshed. They are only valid for ~30hrs
    *
    **/
    private function refreshToken()
    {
        syslog(LOG_DEBUG, "Monzo: Refreshing Token");
        $data = array(
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken
        );
        $jsonResponse = $this->getJson('/oauth2/token', $data, false);
        if (isset($jsonResponse['access_token']) && isset($jsonResponse['refresh_token']))
        {
            syslog(LOG_DEBUG, "Monzo: Token refreshed, validating");
            $this->refreshToken = $jsonResponse['refresh_token'];
            $this->bearerToken = $jsonResponse['access_token'];
            if ($this->validateTokens())
            {
                syslog(LOG_DEBUG, "Monzo: Token refreshed, validated and stored");
                $this->storeTokens($this->bearerToken, $this->refreshToken);
                return true;
            }else {
                syslog(LOG_DEBUG, "Monzo: Token refreshed, but failed to validate");
                return false;
            }
        }
        else
        {
            syslog(LOG_ERR, "Monzo: Not able to refresh token");
            throw new Exception('Monzo Not able to refresh token');
            return false;
        }
    }

    public function getTransactions()
    {
        if (!$this->loginSuccess)
        {
            $this->login();
        }
        $transactions = array();
        $this->accountID = $this->fetchAccountID($this->accountNumber);
        $sinceDate = date('Y-m-d\TH:i:s\Z', time() - TX_SINCE);
        $returnedTransactions = $this->getJson("/transactions?account_id={$this->accountID}&since={$sinceDate}");
        // Loop through all transactions and construct array to return.
        foreach ($returnedTransactions['transactions'] as $transaction)
        {
            $trans['date'] = strtotime($transaction['created']);
            $trans['commentary'] = $transaction['description'];
            $trans['amount'] = intval($transaction['amount']) / 100;
            $trans['balance'] = $transaction['account_balance'];
            $trans['transType'] = $transaction['scheme'];
            $trans['transReference'] = $transaction['metadata']['notes'];
            $trans['transOtherParty'] = $transaction['counterparty']['name'];
            $transactions[] = $trans;
        }
        return $transactions;
    }
}
