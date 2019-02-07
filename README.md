# Introduction

PHP Class to fetch data from Monzo Bank account.

Please read this blog post before using. Monzo API might change in the future and there are no gurantees of backwards compatibility.

https://monzo.com/blog/2017/05/11/api-update/

# Usage

1. Navigate to https://developers.monzo.com, then to the "Clients" section and create a new OAuth Client with the following settings
```
  a. Confidentiality = Confidential
  b. Redirect URL : http://127.0.0.1/
  c. Name : MyApp
  d. Description : MyApp
```
2. From the newly created OAuth client page take a note of the following details :
```
  a. Client ID
  b. Client secret
  c. Redirect URLs
```
3. When using this class set
```
  a. $bank = new UK_Monzo("Account number", "Client ID", "Client secret", "Redirect URLs");
  b. By default, transactions from the past 4 hours will be returned. You can adjust this using TX_SINCE in the class. 
```
  Where "Account number" is your Monzo Bank account numner Example `11111111`
  Other settings are from the OAuth client page, see step 2.

4. When you launch your application for the first time, you will be taken through OAuth sign in journey. This only needs to happen once. When the session is established, token is stored locally and "refreshed". See example below.

WARNING :  output/.monzo.session file will contain API token. Keep this file safe.

#Example

```
➜ cat Example.php
<?php

  require_once("./Class_UK_Monzo.php");

  $bank = new UK_Monzo("XXXXXXXX", "oauth2client_0000xxxxxxxxxxx", "mnzconf.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxh", "https://127.0.0.1/");
  print_r($bank->getTransactions())

?>
➜ php Example.php
*************************************************   MONZO AUTHENTICATION WORKFLOW ***********************************************************
Please visit the following URL in your browser and follow instructions
###########

https://auth.monzo.com/?client_id=oauth2client_0000xxxxxxxxxxx&redirect_uri=https://127.0.0.1/&response_type=code&state=5c5c5cf42f9fd

###########
When you get email from Monzo, copy the link button (Right click -> Copy link Location) from the email and paste here. Do not click it.
*********************************************************************************************************************************************
Email link: https://127.0.0.1/?code=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx&state=5c5c5cf42f9fd
Array
(
    [0] => Array
        (
            [date] => 1549546458
            [commentary] => ZKPFFR
            [amount] => 101.84
            [balance] => 0
            [transType] => payport_faster_payments
            [transReference] => ZKPFFR
            [transOtherParty] => MR X Y Payer
        )

    [1] => Array
        (
            [date] => 1549549131
            [commentary] => PaymentReferenceXYZ
            [amount] => 250
            [balance] => 0
            [transType] => payport_faster_payments
            [transReference] => PaymentReferenceXYZ
            [transOtherParty] => Robert De Niro
        )

    [2] => Array
        (
            [date] => 1549552978
            [commentary] => M$COMPENSATION
            [amount] => 128.62
            [balance] => 0
            [transType] => payport_faster_payments
            [transReference] => M$COMPENSATION
            [transOtherParty] => Bill Gates
        )

)
➜
```

# Troubleshooting

1. You can only have one session. Trying to use the same client from more than one place will return 401 from Monzo API endpoints.
   To resolve. Remove .monzo.session file and restart. This will initialise new session.
