<?php
  function dbOpen() {
    $host = 'localhost:UNIFRESH_LOCAL';
    $user = 'SYSDBA';
    $pass = 'masterkey';
    $dbh = ibase_connect($host, $user, $pass) or die (ibase_errmsg());
    return $dbh;
  }

  function dbClose($dbh) {
    ibase_close($dbh);
  }

  function doLegacyLog() {
    $dbh = dbOpen();
    $sql = "insert into ITEMALTERATIONHISTORY 
              (ITEMCODE, CUSTOMER, \"COLUMN\", UPDATEDVALUE) 
              values ('NA', 'NA', 'BATCHINVOICEEMAILS', '0')";
    ibase_query($dbh, $sql) or die(ibase_errmsg());
    dbClose($dbh);
  }

  function getBatchLogId() {
    $dbh = dbOpen(); 
    $nextVal = ibase_gen_id("UF_INVOICE_EMAIL_BATCH_LOG", 1, $dbh);
    dbClose($dbh);
    return $nextVal;
  }

  function doLog($dbh, $batchLogId, $action, $info="") {
    $sql = sprintf("insert into UF_INVOICE_EMAIL_BATCH_LOG values(
    next value for UF_INVOICE_EMAIL_LOG, '%s', '%s', '%s', current_timestamp)",
    $batchLogId, $action, $info);

    #[https://bugs.php.net/bug.php?id=72175]
    #$dbh = dbOpen();
    ibase_query($dbh, $sql) or die(ibase_errmsg());
    #dbClose($dbh);
  }

  function do_post_request($url, $postdata = "", $logId) {
    $params = ['http' => ['method' => 'POST',
                          'content' => $postdata]];

    $ctx = stream_context_create($params);

    $dbh = dbOpen();
    #info field is max 2000 chars, so logging only maximum 1990 to be safe
    doLog($dbh, $logId, __FUNCTION__, $url);
    doLog($dbh, $logId, __FUNCTION__, substr($postdata, 0, 1990));

    $fp = @fopen($url, 'rb', false, $ctx);
    if (!$fp) {
      doLog($dbh, $logId, __FUNCTION__, $php_errormsg);
      dbClose($dbh);
      throw new Exception("Problem with $url, $php_errormsg");
    }

    $response = @stream_get_contents($fp);
    if ($response === false) {
      doLog($dbh, $logId, __FUNCTION__, $php_errormsg);
      dbClose($dbh);
      throw new Exception("Problem reading data from ${url}, $php_errormsg");
    }
    doLog($dbh, $logId, __FUNCTION__, "Success");
    dbClose($dbh);
    return $response;
  }

  function getInvoiceGroups($logId) {
    $sql = 
      "select distinct 
      CASE when (CUSTOMERMASTER.ADDITIONALFIELD_47 is null OR 
          CUSTOMERMASTER.ADDITIONALFIELD_47 = 'No Group') then 
        CUSTOMERMASTER.CUSTOMER 
      else 
        CUSTOMERMASTER.ADDITIONALFIELD_47 
      END as INVGRP 
      from CUSTOMERMASTER where 
      CUSTOMERMASTER.ADDITIONALFIELD_55 = 'True' and 
      (CUSTOMERMASTER.ADDITIONALFIELD_47 <> 'Do Not Email' OR 
        CUSTOMERMASTER.ADDITIONALFIELD_47 IS NULL) and 
      CUSTOMERMASTER.CUSTOMER in 
      (
        select distinct CUSTOMERMASTER.CUSTOMER 
        from CUSTOMERMASTER, SALESINVOICEHEADER 
        where 
        CUSTOMERMASTER.CUSTOMER = SALESINVOICEHEADER.CUSTOMER and 
        CUSTOMERMASTER.ADDITIONALFIELD_55 = 'True' and 
        SALESINVOICEHEADER.INVOICEDATE >= '2014-05-30' and 
        SALESINVOICEHEADER.INVOICEDATE < current_date and 
        SALESINVOICEHEADER.INVOICEEMAILBATCH is null
      )
      order by INVGRP";

    $dbh = dbOpen();
    $result = ibase_query($dbh, $sql) or die(ibase_errmsg());
    dbClose($dbh);
   
    $invGroups = [];
    while($row = ibase_fetch_object($result)) {
      $invGroups[] = $row->INVGRP;
    }
    ibase_free_result($result);
    doLog($dbh, $logId, __FUNCTION__, "Count: ".count($invGroups));
    return $invGroups;
  }

  function gatherEmails($invGroups, $logId) {
    $sql = " select ADDITIONALFIELD_54 as EMAIL from CUSTOMERMASTER where 
              CASE when ( CUSTOMERMASTER.ADDITIONALFIELD_47 is null OR 
                          CUSTOMERMASTER.ADDITIONALFIELD_47 = 'No Group') then 
                CUSTOMERMASTER.CUSTOMER 
              else 
                CUSTOMERMASTER.ADDITIONALFIELD_47 
              END = '%s'
              and additionalfield_54 is not null
              and customerstatus = 'Active' ";

    $cust_info = [];
    $dbh = dbOpen();
    foreach($invGroups as $invGroup) {
      $emails = [];
      $res = ibase_query($dbh, sprintf($sql, $invGroup)) or die(ibase_errmsg());
      while($emailRow = ibase_fetch_object($res)){
        #each emailRow can have multiple emails separated by ";"
        #e.g. "darren.wuttke@rr-sa.com.au; accounts@rr-sa.com.au"
        $emails = array_merge($emails, explode(";", $emailRow->EMAIL));
      }
      #strip, de-duplicate,remove empty, re-index emails and put in cust_info
      $cust_info[$invGroup]["emails"] = array_values(
                                          array_unique(
                                            array_filter(
                                              array_map("trim",$emails)
                                            )
                                          )
                                        );
      ibase_free_result($res);
      doLog($dbh, $logId, __FUNCTION__, 
        count($cust_info[$invGroup]["emails"]).
        " emails gathered for $invGroup");
    }
    dbClose($dbh);
    return $cust_info;
  }

  function allocBatchNums($cust_info, $logId) {
    $dbh = dbOpen();
    foreach($cust_info as $k=>$v) {
      $nextVal = ibase_gen_id("INVOICEEMAILBATCH", 16, $dbh);
      $cust_info[$k]["newid"] = $nextVal;
    }
    doLog($dbh, $logId, __FUNCTION__, "Done");
    dbClose($dbh);
    return $cust_info;
  }

  function gatherCustomerNames($cust_info, $logId) {
    $dbh = dbOpen();
    $sql = "select trim(customer) as CUST from customermaster 
              where additionalfield_54 like '%%%s%%' and 
              customerstatus = 'Active'";

    #for all invoicegroups
    foreach($cust_info as $k=>$v) {
      $cust_names = [];
      #multiple emails are there, so gather customer names for all
      foreach($cust_info[$k]["emails"] as $email) {
        $res = ibase_query($dbh, sprintf($sql,$email));
        #get all customers for this email address
        while($row = ibase_fetch_object($res)) 
          $cust_names[] = $row->CUST; 
        ibase_free_result($res);
      }
      $cust_info[$k]["custs"] = array_unique($cust_names);
      doLog($dbh, $logId, __FUNCTION__, 
        count($cust_info[$k]["custs"])." names gathered for $k");
    }
    dbClose($dbh);
    return $cust_info;
  }

  function updateBatchID($invgrp, $newid, $logId) {
    $sql = " 
      update SALESINVOICEHEADER set INVOICEEMAILBATCH = '%s' 
      where INVOICENUMBER in (
        select  
        SALESINVOICEHEADER.INVOICENUMBER 
        from 
        CUSTOMERMASTER, 
        SALESINVOICEHEADER 
        where 
          CUSTOMERMASTER.CUSTOMER = SALESINVOICEHEADER.CUSTOMER and 
          CUSTOMERMASTER.ADDITIONALFIELD_55 = 'True' and 
          SALESINVOICEHEADER.INVOICEDATE >= '2014-05-25' and 
          SALESINVOICEHEADER.INVOICEDATE < current_date and 
          CASE when 
            (CUSTOMERMASTER.ADDITIONALFIELD_47 is null OR 
            CUSTOMERMASTER.ADDITIONALFIELD_47 = 'No Group') 
          then 
            CUSTOMERMASTER.CUSTOMER 
          else 
            CUSTOMERMASTER.ADDITIONALFIELD_47 
          END = '%s' and 
          SALESINVOICEHEADER.INVOICEEMAILBATCH is null
      )";
    $dbh = dbOpen();
    $res = ibase_query(sprintf($sql, $newid, $invgrp));
    doLog($dbh, $logId, __FUNCTION__, "$newid applied to $invgrp in $res rows");
    dbClose($dbh);
    return $res == TRUE;
  }

  function sendWeeksBatchEmail($cust_info, $logId) {
    foreach($cust_info as $k=>$v) {
      $group = $k;
      $batchnum = $v["newid"];
      $email = implode("; ", $v["emails"]);

      #adding my email for testing
      $email .= "; gurpreet.singh@unifresh.com.au";

      $postDat = "";
      $postDat .= "BATCHGROUP=" . rawurlencode($group);
      $postDat .= "&BATCHNUMBER=" . rawurlencode($batchnum);
      $postDat .= "&BATCHEMAIL=" . rawurlencode($email);

      #we are about to wander into uncharted territory...
      #so good idea to make a log entry beforehand
      $dbh = dbOpen();
      doLog($dbh, $logId, __FUNCTION__, 
        "about to send email to invgrp $k ($email)");
      dbClose($dbh);

      #go and send email
      #but make sure we have atleast one email address to send mail to
      if(count($v["emails"]) >= 1) {
        $xmlstr = do_post_request(
          'http://mail.unifresh.com.au:3333/Email_batchinvoices.php', 
          $postDat, $logId);
      }

      #good we came back unharmed
      #time to celebrate by updating invoiceemailbatch
      if($xmlstr == "<p>Message successfully sent!</p>")
        updateBatchId($k, $batchnum, $logId);
    }
  }

  function sendConfirmationEmail($cust_info, $logId) {
    $output = "BATCHDATA=";
    foreach($cust_info as $k=>$v) {
      $cust_names = implode(", ", $v["custs"]);
      $emails = implode(", ", $v["emails"]);

      $link = "<a 
        href='http://www.unifresh.com.au/batchemaildownload.php?
        qt=BF&grp={$k}&ib={$v['newid']}'>
          {$v['newid']}
        </a>";

      $output .= rawurlencode("
        <tr>
          <td width='500'>
            <div style='border-bottom: solid 1px #000;'> $cust_names </div>
          </td>
          <td width='100'>
            <div> $link </div>
          </td>
          <td width='200'>
            <div> $emails </div>
          </td>
        </tr>
      \n");

    }
    #we are about to wander into uncharted territory (again)...
    #so good idea to make a log entry beforehand
    $dbh = dbOpen();
    doLog($dbh, $logId, __FUNCTION__, "about to send confirmation email");
    dbClose($dbh);

    #echo $output;
    $xmlstr = do_post_request(
      "http://mail.unifresh.com.au:3333/Email_batchinvoices_confirmation.php", 
      $output, $logId);
    return $xmlstr == "<p>Message successfully sent!</p>";
  }

  function start() {
    #give upto 10 mins for execution
    set_time_limit(600);
    
    #it's good to follow the tradition
    doLegacyLog();
    
    #get unique batchid from sequence, to be used in logging
    $logId = getBatchLogId();

    #get list of invoice groups
    $invGroups = getInvoiceGroups($logId);

    #gather emails of invoice groups in an assoc array named $cust_info
    #cust_info: invgrp => [emails array]
    $cust_info = gatherEmails($invGroups, $logId);
    $cust_info = allocBatchNums($cust_info, $logId);
    $cust_info = gatherCustomerNames($cust_info, $logId);
   
    #send invoice emails
    #and update batch number if email is sent successfully
    #sendWeeksBatchEmail($cust_info, $logId);

    #send confirmation email back to us
    #sendConfirmationEmail($cust_info, $logId);
  }

  start();
?>
