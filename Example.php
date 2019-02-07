<?php

	require_once("./Class_UK_Monzo.php");

  $bank = new UK_Monzo("XXXXXXXX", "oauth2client_0000xxxxxxxxxxx", "mnzconf.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxh", "https://127.0.0.1/");
  print_r($bank->getTransactions())

?>
