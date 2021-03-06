<?php

class Traceroute
{
  /**
    A function to calculate distance between a pair of coordinates
  */
  public static function distance($lat1, $lng1, $lat2, $lng2, $miles = true)
  {
    $pi80 = M_PI / 180;
    $lat1 *= $pi80;
    $lng1 *= $pi80;
    $lat2 *= $pi80;
    $lng2 *= $pi80;

    $r = 6372.797; // mean radius of Earth in km
    $dlat = $lat2 - $lat1;
    $dlng = $lng2 - $lng1;
    $a = sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlng / 2) * sin($dlng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $km = $r * $c;

    return ($miles ? ($km * 0.621371192) : $km);
  }

  /**
    A handy function to clean up html code
  */
  public static function strip_only($str, $tags, $stripContent = false)
  {
    $content = '';
    if(!is_array($tags)) {
      $tags = (strpos($str, '>') !== false ? explode('>', str_replace('<', '', $tags)) : array($tags));
      if(end($tags) == ''){
        array_pop($tags);
      }
    }
    foreach($tags as $tag) {
      if ($stripContent){
        $content = '(.+</'.$tag.'[^>]*>|)';
      }
      $str = preg_replace('#</?'.$tag.'[^>]*>'.$content.'#is', '', $str);
    }
    return $str;
  } // end function

  /**
    Key function !! creates a where SQL based on explore submitted constraints
  */
  public static function buildWhere($c,$doesNotChk=false, $paramNum = 1)
  {
    global $dbconn, $ixmaps_debug_mode;
    $trSet = array();
    $w ='';
    $table='';

    $constraint_value = trim($c['constraint4']);
    // apply some default formating to constraint's value

    if($c['constraint1']=='does' || $doesNotChk==true) {
      $selector_s='LIKE';
      $selector_i='=';
    } else {
      $selector_s='NOT LIKE';
      $selector_i='<>';
    }

    if($c['constraint5']=='') {
      $operand='AND';
    } else  {
      $operand=$c['constraint5'];
    }

    /* setting constraints associated to table ip_addr_info */
    if($c['constraint3']=='country') {
      $constraint_value = strtoupper($constraint_value);
      $table = 'ip_addr_info';
      $field='mm_country';
    } else if($c['constraint3']=='region') {
      $constraint_value = strtoupper($constraint_value);
      $table = 'ip_addr_info';
      $field='mm_region';
    } else if($c['constraint3']=='city') {
      $constraint_value = ucwords(strtolower($constraint_value));
      $table = 'ip_addr_info';
      $field='mm_city';
    } else if($c['constraint3']=='ISP') {
      //$constraint_value = ucwords(strtolower($constraint_value));
      $constraint_value = $constraint_value;
      $table = 'as_users';
      $field='name';
    } else if($c['constraint3']=='NSA') {
      $table = 'ip_addr_info';
      $field='mm_city';
    } else if($c['constraint3']=='zipCode') {
      //$constraint_value = strtoupper($constraint_value);
      $table = 'ip_addr_info';
      $field='mm_postal';
    } else if($c['constraint3']=='asnum') {
      $table = 'ip_addr_info';
      $field='asnum';
    } else if($c['constraint3']=='submitter') {
      $table = 'traceroute';
      $field='submitter';
    } else if($c['constraint3']=='zipCodeSubmitter') {
      $table = 'traceroute';
      $field='zip_code';
    } else if($c['constraint3']=='destHostName') {
      $table = 'traceroute';
      $field='dest';
    } else if($c['constraint3']=='ipAddr') {
      $table = 'ip_addr_info';
      $field='ip_addr';
    } else if($c['constraint3']=='trId') {
      $table = 'traceroute';
      $field='id';
    } else if($c['constraint3']=='hostName') {
      $table = 'ip_addr_info';
      $field='hostname';
    }

    if($c['constraint2']=='originate') {
      $w.=" AND tr_item.hop = 1 AND tr_item.attempt = 1";
    } else if($c['constraint2']=='terminate') {

      //$w.=" AND (traceroute.dest_ip=ip_addr_info.ip_addr) AND tr_item.attempt = 1 AND tr_item.hop > 1";

      // old approach
      //$w.=" AND (traceroute.dest_ip=ip_addr_info.ip_addr) AND tr_item.attempt = 1 AND tr_item.hop > 1";

      // new approach: last hop as destination
      // this alredy set on parent function, not needed here. so do nothing ...
      //$w.=" AND (traceroute.id=tr_last_hops.traceroute_id_lh) ";

    } else if($c['constraint2']=='goVia') {

      // this is a wrong assumption.
      //The destination ip is not always the last hop
      //$w.=" AND tr_item.attempt = 1 AND tr_item.hop > 1 AND (traceroute.dest_ip<>ip_addr_info.ip_addr)";

      $w.=" AND tr_item.attempt = 1 AND tr_item.hop > 1 ";

      // FIX ME. need to exclude last ip.


    } else if($c['constraint2']=='contain') {

      $w.=" AND tr_item.attempt = 1 ";

    }

    /* string of int ? */
    /*if (($field=='asnum') || ($field=='id'))
    {
      $w.=" AND $table.$field $selector_i $constraint_value";
      //$w.="  $selector $table.$field $operand_i $constraint_value";
    } else if ($field=='ip_addr') {
      $w.=" AND $table.$field $selector_i '".$constraint_value."'";
    } else {
      $w.=" AND $table.$field $selector_s '%".$constraint_value."%'";
    }

    return $w;
    */

    // Using pg_query_params
    if (($field=='asnum') || ($field=='id') || ($field=='ip_addr')) {
      $w.=" AND $table.$field $selector_i $".$paramNum;
      //$w.="  $selector $table.$field $operand_i $constraint_value";
    } else {
      $w.=" AND $table.$field $selector_s $".$paramNum;
      $constraint_value = "%".$constraint_value."%";
    }
    $rParams = array($w, $constraint_value);
    return $rParams;
  }

  /**
    Key function !! Get TR data for a given sql query
  */
  public static function getTrSet($sql, $wParam)
  {
    global $dbconn, $dbQueryHtml, $dbQuerySummary;
    //echo $sql;
    $trSet = array();

    // old approach: used only for quick links
    if($wParam==""){
      $result = pg_query($dbconn, $sql) or die('Query failed: ' . pg_last_error());
    } else {
      $result = pg_query_params($dbconn, $sql, array($wParam)) or die('Query failed: incorrect parameters');
    }


    $data = array();
    //$dbQuerySummary.='<hr/>'.$sql;
    //$data1 = array();
    $id_last = 0;

    $c = 0;
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        $c++;
        $id=$line['id'];
        //$city = $line['mm_city'];
        if($id!=$id_last){
          $data[]=$id;
      }
        $id_last=$id;
    }
    $data1 = array_unique($data);
    $dbQuerySummary .= " | Traceroutes: <b>".count($data1).'</b>';
    pg_free_result($result);
    // Closing connection ??
    //pg_close($dbconn);
    unset($data);
    return $data1;
  }

  /**
    Check if in a given city there is an NSA
    Load chotel data
  */
  public static function checkNSA($locationKey)
  {
    global $dbconn;
    //$sql = "select * from chotel where address like '%".$locationKey."%' order by type, nsa";
    $sql = "select * from chotel order by type, nsa";

    $c = 0;
    $data = array();


    $result = pg_query($dbconn, $sql) or die('Query failed: ' . pg_last_error());

    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
      $c++;
      $id = $line['id'];
      $address = explode(',', $line['address']);

      //echo '<hr/>';
      //print_r($address);


      $t=count($address);
      $city = '';
      $region = '';

      //$city = trim($line['city']);
      //$region = trim($line['region']);

      // update new fields

      if(isset($address[$t-2]) && isset($address[$t-1])) {
        $city = trim($address[$t-2]);
        $region = trim($address[$t-1]);
        $aa = ord($city);

        $c_array = str_split($city);

        foreach ($c_array as $c_char)
        {
          echo '<br>'.'"'.$c_char.'" : "'.ord($c_char).'"';
        }

        //echo '<br/>ASCII: '.$aa.'';

        // clean up strange characters
        $city=str_replace(chr(194), '', $city);
        $city=str_replace(chr(160), '', $city);
        //$city=utf8_encode($city);
        $city=trim($city);

        //$region=str_replace(chr(194), '', $region);

        $update = "UPDATE chotel SET city = '".$city."', region='".$region."' WHERE id = ".$id;
        echo '<br/>'.$update;
        echo '<hr/>';
        pg_query($dbconn, $update) or die('Query failed: ' . pg_last_error());
      }

      //$m[] = $line['address'];
      //$m[] = $line['address'];
      //$data[] = $line;

      if($line['type']=='NSA') {
        $data['NSA'][] = array($city,$region);
      } else if ($line['type']=='CH') {
        $data['CH'][] = array($city,$region);
      } else if ($line['type']=='google') {
        $data['google'][] = array($city,$region);
      } else if ($line['type']=='UC') {
        $data['UC'][] = array($city,$region);
      }
    }

    pg_free_result($result);


    //return array('total'=>$c, 'matches'=>$m);
    //print_r($data);
  }

  /**
    Get TR details data for a current TR: this is quite expensive computationally and memory
    Only used to perform advanced analysis that need to look at all the attemps and all the hops in a TR
  */
  public static function getTraceRouteAll($trId)
  {
    global $dbconn;
    $result = array();
    $trArr = array();
    // adding exception to prevent error with tr id wiht no tr_items
    if($trId!=''){
      $sql = 'SELECT traceroute.id, tr_item.* FROM traceroute, tr_item WHERE (tr_item.traceroute_id=traceroute.id) AND traceroute.id = '.$trId.' ORDER BY tr_item.traceroute_id, tr_item.hop, tr_item.attempt';

      $result = pg_query($dbconn, $sql) or die('Query failed on getTraceRouteAll: ' . pg_last_error() . 'SQL: '. $sql . " TRid: ".var_dump($trId));

      //$tot = pg_num_rows($result);
      // get all data in a single array
      $trArr = pg_fetch_all($result);
    }
    return $trArr;
  }


  /**
    Key Function!
    Get TR data for set of constraints
  */
  public static function getTraceRoute($data)
  {
    global $dbconn, $dbQueryHtml, $dbQuerySummary;
    $result = array();
    $trSets = array();
    $conn = 0;
    $limit1 = 4500;
    $limit2 = 5000;
    $offset = 0;
    $doesNotChk = false;

    // loop constraints
    foreach($data as $constraint)
    {
      if($conn>0){
        $dbQuerySummary .= '<br>';
      }
      $dbQuerySummary .= '<b>'.$constraint['constraint1'].' : '.$constraint['constraint2'].' : '.$constraint['constraint3'].' : '.$constraint['constraint4'].' : '.$constraint['constraint5'].'</b>';

      $w = '';
      $wParams = array();

      $sql = "SELECT as_users.num, tr_item.traceroute_id, traceroute.id, ip_addr_info.mm_city, ip_addr_info.ip_addr, ip_addr_info.asnum FROM as_users, tr_item, traceroute, ip_addr_info WHERE (tr_item.traceroute_id=traceroute.id) AND (ip_addr_info.ip_addr=tr_item.ip_addr) AND (as_users.num=ip_addr_info.asnum)";

      $sqlOrder = ' order by tr_item.traceroute_id, tr_item.hop, tr_item.attempt';

      $aa = 0;
      // adding exception for doesnot cases
      if($constraint['constraint1']=='doesNot' && $constraint['constraint2']!='originate' && $constraint['constraint2']!='terminate') {
        //echo "IF: doesNot && !=originate && terminate";

        $oppositeSet = array();
        $positiveSet = array();

        //$w.=''.Traceroute::buildWhere($constraint);
        $wParams = Traceroute::buildWhere($constraint);

        $sqlTemp = $sql;

        //$sqlTemp.=$w.$sqlOrder;
        $sqlTemp.=$wParams[0].$sqlOrder;
        $positiveSet = Traceroute::getTrSet($sqlTemp, $wParams[1]);

        // getting oposite set for diff comparison
          /*$sqlOposite = $sql;
          $sqlOposite .= Traceroute::buildWhere($constraint,$doesNotChk);
          $sqlOposite .= $sqlOrder;
          $oppositeSet = Traceroute::getTrSet($sqlOposite);*/

        $doesNotChk = true;

        $sqlOposite = $sql;

        $wParams = Traceroute::buildWhere($constraint, $doesNotChk);
        //$sqlOposite .= Traceroute::buildWhere($constraint,$doesNotChk);
        $sqlOposite .= $wParams[0].$sqlOrder;

        //$oppositeSet = Traceroute::getTrSet($sqlOposite);
        $oppositeSet = Traceroute::getTrSet($sqlOposite, $wParams[1]);

        //echo '<br/><i>'.$sqlOposite.'</i>';
        //echo '<br/>Opposite Set: '.count($oppositeSet);

        $trSets[$conn] = array_diff($positiveSet,$oppositeSet);
        //echo '<hr/>'.count($trSets[$conn]);

        $doesNotChk = false;
        unset($oppositeSet);
        unset($positiveSet);
        //unset($diff);

      // adding an exception for "terminate": This option is now querying tr_last_hops reference table
      } else if($constraint['constraint2']=='terminate') {

        //echo "IF: terminate";

        $tApproach = 1;

        if($tApproach==0){
        // old approach: using dest_ip

          $sql = "SELECT as_users.num, tr_item.traceroute_id, traceroute.id, ip_addr_info.mm_city, ip_addr_info.ip_addr, ip_addr_info.asnum FROM as_users, tr_item, traceroute, ip_addr_info WHERE (tr_item.traceroute_id=traceroute.id) AND (ip_addr_info.ip_addr=tr_item.ip_addr) AND (as_users.num=ip_addr_info.asnum)";

          $sqlOrder = ' order by tr_item.traceroute_id, tr_item.hop, tr_item.attempt';

          $w.=" AND (traceroute.dest_ip=ip_addr_info.ip_addr) AND tr_item.attempt = 1 AND tr_item.hop > 1";

          //$w.=''.Traceroute::buildWhere($constraint);
          $wParams = Traceroute::buildWhere($constraint);
          $w.=''.$wParams[0];

          //$dbQuerySummary.='<BR/>CASE B:';

        } else if($tApproach==1){
          // new approach: using tr_last_hops
          $sql = "SELECT as_users.num, tr_last_hops.traceroute_id_lh, tr_last_hops.reached, traceroute.id, ip_addr_info.mm_city, ip_addr_info.ip_addr, ip_addr_info.asnum FROM tr_last_hops, as_users, traceroute, ip_addr_info WHERE (as_users.num=ip_addr_info.asnum) AND (traceroute.id=tr_last_hops.traceroute_id_lh) AND (ip_addr_info.ip_addr=tr_last_hops.ip_addr_lh) ";

          $sqlOrder = ' order by traceroute.id';

          // this is doing nothing I believe, as all the sql is not created here
          //$w.=''.Traceroute::buildWhere($constraint);
          $wParams = Traceroute::buildWhere($constraint);
          $w.=''.$wParams[0];

          //$dbQuerySummary.='<BR/>CASE A:';
        }

        $sql .=$w.$sqlOrder;
        //  echo "<hr/>".$sql;

        $trSets[$conn] = Traceroute::getTrSet($sql, $wParams[1]);
        $operands[$conn]=$constraint['constraint5'];

      } else {
        //echo "IF: all the other cases";
        //$w.=''.Traceroute::buildWhere($constraint);
        $wParams = Traceroute::buildWhere($constraint);
        $w.=''.$wParams[0];

        $sql .=$w.$sqlOrder;

        //echo '<br/><i>'.$sql.'</i>';
        $trSets[$conn] = Traceroute::getTrSet($sql, $wParams[1]);

        $operands[$conn]=$constraint['constraint5'];
      }

      //echo '<br/><i>'.$sql.'</i>';

      // add SQL to log file
      //$dbQuerySummary.='<br/>'.$sql;

      $conn++;

    } // end foreach

    $trSetResult = array();

    for($i=0;$i<$conn;$i++)
    {
      $trSetResultTemp = array();
      // only one constraint
      if($i==0)
      {
        //$trSetResult=$trSets[0];
        $trSetResult = array_merge($trSetResult, $trSets[0]);

      // all in between
      } else if ($i>0){
        // OR cases
        if($data[$i-1]['constraint5']=='OR')
        {
          $trSetResultTemp = array_merge($trSetResult,$trSets[$i]);
          //$trSetResultTemp = array_merge($trSets[$i-1],$trSets[$i]);
          $trSetResultTemp = array_unique($trSetResultTemp);

          //echo '<br/>ToT trSetResultTemp: '.count($trSetResultTemp);
          $trSetResult =  array_merge($trSetResult, $trSetResultTemp);

        // AND cases
        } else {
          $trSetResultTemp = array_intersect($trSetResult,$trSets[$i]);
        }

        $trSetResult =  array();
        $empty = array();
        $trSetResult = array_merge($empty, $trSetResultTemp);
      }

      //$dbQuerySummary .='<hr/>'.$sql;
    } // end for
    $trSetResultLast =  array_unique($trSetResult);

    // FIXME: move this to the client. make this count based on the # of TR resulting in the set
    // It's already done. need to fix UI loading of data

    //$dbQuerySummary .= '<br/>Total traceroutes : <b>'.count($trSetResultLast)."</b><br />";

    $dbQuerySummary .= "<br/>";

    //echo '<hr/>getTraceRoute: '.memory_get_usage();
    unset($trSetResult);
    unset($trSetResultTemp);
    unset($trSets);
    //echo '<hr/>getTraceRoute: '.memory_get_usage();

    return $trSetResultLast;
  }

  /**
  Key Function!
  Get TR data for set of constraints
  */
  public static function getTraceRouteOLD($data)
  {
    global $dbconn, $dbQueryHtml, $dbQuerySummary;
    $result = array();
    $trSets = array();
    $conn = 0;
    $limit1 = 4500;
    $limit2 = 5000;
    $offset = 0;
    $doesNotChk = false;

    // loop constraints
    foreach($data as $constraint)
    {
      $dbQuerySummary .= '<br><b>'.$constraint['constraint1'].' : '.$constraint['constraint2'].' : '.$constraint['constraint3'].' : '.$constraint['constraint4'].' : '.$constraint['constraint5'].'</b>';

      $w = '';
      $wParams = array();

      $sql = "SELECT as_users.num, tr_item.traceroute_id, traceroute.id, ip_addr_info.mm_city, ip_addr_info.ip_addr, ip_addr_info.asnum FROM as_users, tr_item, traceroute, ip_addr_info WHERE (tr_item.traceroute_id=traceroute.id) AND (ip_addr_info.ip_addr=tr_item.ip_addr) AND (as_users.num=ip_addr_info.asnum)";

      $sqlOrder = ' order by tr_item.traceroute_id, tr_item.hop, tr_item.attempt';

      $aa = 0;
      // adding exception for doesnot cases
      if($constraint['constraint1']=='doesNot' && $constraint['constraint2']!='originate' && $constraint['constraint2']!='terminate') {
        //echo "IF: doesNot && !=originate && terminate";

        $oppositeSet = array();
        $positiveSet = array();

        //$w.=''.Traceroute::buildWhere($constraint);
        $wParams = Traceroute::buildWhere($constraint);

        $sqlTemp = $sql;

        //$sqlTemp.=$w.$sqlOrder;
        $sqlTemp.=$wParams[0].$sqlOrder;
        $positiveSet = Traceroute::getTrSet($sqlTemp, $wParams[1]);

        // getting oposite set for diff comparison
          /*$sqlOposite = $sql;
          $sqlOposite .= Traceroute::buildWhere($constraint,$doesNotChk);
          $sqlOposite .= $sqlOrder;
          $oppositeSet = Traceroute::getTrSet($sqlOposite);*/

        $doesNotChk = true;

        $sqlOposite = $sql;

        $wParams = Traceroute::buildWhere($constraint, $doesNotChk);
        //$sqlOposite .= Traceroute::buildWhere($constraint,$doesNotChk);
        $sqlOposite .= $wParams[0].$sqlOrder;

        //$oppositeSet = Traceroute::getTrSet($sqlOposite);
        $oppositeSet = Traceroute::getTrSet($sqlOposite, $wParams[1]);

        //echo '<br/><i>'.$sqlOposite.'</i>';
        //echo '<br/>Opposite Set: '.count($oppositeSet);

        $trSets[$conn] = array_diff($positiveSet,$oppositeSet);
        //echo '<hr/>'.count($trSets[$conn]);

        $doesNotChk = false;
        unset($oppositeSet);
        unset($positiveSet);
        //unset($diff);

      // adding an exception for "terminate": This option is now querying tr_last_hops reference table
      } else if($constraint['constraint2']=='terminate') {
        //echo "IF: terminate";
        $tApproach = 1;

        if($tApproach==0){
        // old approach: using dest_ip

          $sql = "SELECT as_users.num, tr_item.traceroute_id, traceroute.id, ip_addr_info.mm_city, ip_addr_info.ip_addr, ip_addr_info.asnum FROM as_users, tr_item, traceroute, ip_addr_info WHERE (tr_item.traceroute_id=traceroute.id) AND (ip_addr_info.ip_addr=tr_item.ip_addr) AND (as_users.num=ip_addr_info.asnum)";

          $sqlOrder = ' order by tr_item.traceroute_id, tr_item.hop, tr_item.attempt';

          $w.=" AND (traceroute.dest_ip=ip_addr_info.ip_addr) AND tr_item.attempt = 1 AND tr_item.hop > 1";

          //$w.=''.Traceroute::buildWhere($constraint);
          $wParams = Traceroute::buildWhere($constraint);
          $w.=''.$wParams[0];

          //$dbQuerySummary.='<BR/>CASE B:';

        } else if($tApproach==1){

          // new approach: using tr_last_hops
          $sql = "SELECT as_users.num, tr_last_hops.traceroute_id_lh, tr_last_hops.reached, traceroute.id, ip_addr_info.mm_city, ip_addr_info.ip_addr, ip_addr_info.asnum FROM tr_last_hops, as_users, traceroute, ip_addr_info WHERE (as_users.num=ip_addr_info.asnum) AND (traceroute.id=tr_last_hops.traceroute_id_lh) AND (ip_addr_info.ip_addr=tr_last_hops.ip_addr_lh) ";

          $sqlOrder = ' order by traceroute.id';

          // this is doing nothing I believe, as all the sql is not created here
          //$w.=''.Traceroute::buildWhere($constraint);
          $wParams = Traceroute::buildWhere($constraint);
          $w.=''.$wParams[0];

          //$dbQuerySummary.='<BR/>CASE A:';
        }

        $sql .=$w.$sqlOrder;
        //  echo "<hr/>".$sql;

        $trSets[$conn] = Traceroute::getTrSet($sql, $wParams[1]);
        $operands[$conn]=$constraint['constraint5'];


      } else {
        //echo "IF: all the other cases";
        //$w.=''.Traceroute::buildWhere($constraint);
        $wParams = Traceroute::buildWhere($constraint);
        $w.=''.$wParams[0];

        $sql .=$w.$sqlOrder;

        //echo '<br/><i>'.$sql.'</i>';
        $trSets[$conn] = Traceroute::getTrSet($sql, $wParams[1]);

        $operands[$conn]=$constraint['constraint5'];
      }

      //echo '<br/><i>'.$sql.'</i>';

      // add SQL to log file
      //$dbQuerySummary.='<br/>'.$sql;

      $conn++;

    } // end for each

    $trSetResult = array();

    for($i=0;$i<$conn;$i++)
    {
      $trSetResultTemp = array();
      // only one constraint
      if($i==0)
      {
        //$trSetResult=$trSets[0];
        $trSetResult = array_merge($trSetResult, $trSets[0]);

      // all in between
      } else if ($i>0){
        if($data[$i-1]['constraint5']=='OR')
        {
          $trSetResultTemp = array_merge($trSetResult,$trSets[$i]);
          //$trSetResultTemp = array_merge($trSets[$i-1],$trSets[$i]);
          $trSetResultTemp = array_unique($trSetResultTemp);

          //echo '<br/>ToT trSetResultTemp: '.count($trSetResultTemp);
          $trSetResult =  array_merge($trSetResult, $trSetResultTemp);

        } else {
          $trSetResultTemp = array_intersect($trSetResult,$trSets[$i]);
        }

        $trSetResult =  array();
        $empty = array();
        $trSetResult = array_merge($empty, $trSetResultTemp);
      }

      //$dbQuerySummary .='<hr/>'.$sql;
    } // end for
      $trSetResultLast =  array_unique($trSetResult);

    // FIXME: move this to the client. make this count based on the # of TR resulting in the set
    // It's already done. need to fix UI loading of data
    $dbQuerySummary .= '<br/>Total traceroutes : <b>'.count($trSetResultLast)."</b><br />";

    //echo '<hr/>getTraceRoute: '.memory_get_usage();
    unset($trSetResult);
    unset($trSetResultTemp);
    unset($trSets);
    //echo '<hr/>getTraceRoute: '.memory_get_usage();

    return $trSetResultLast;
  }

  /**
    Process the quicklinks with canned SQL
  */
  public static function processQuickLink($qlArray)
  {
    global $dbQueryHtml, $dbQuerySummary;
    // base sql
    $sql = "SELECT as_users.num, tr_item.traceroute_id, traceroute.id, ip_addr_info.mm_city, ip_addr_info.ip_addr, ip_addr_info.asnum FROM as_users, tr_item, traceroute, ip_addr_info WHERE (tr_item.traceroute_id=traceroute.id) AND (ip_addr_info.ip_addr=tr_item.ip_addr) AND (as_users.num=ip_addr_info.asnum)";

    if ($qlArray[0]['constraint2']=="lastSubmission") {
      //$dbQueryHtml .= "Displaying <span id='tr-count-db'>1</span> of 1 results";
      //$dbQuerySummary .= "Displaying <span id='tr-count-db'>1</span> of 1 results";
      //will get you the id of the last traceroute submitted
      $sql = "select id from traceroute order by sub_time desc limit 20";
      //echo '<hr/>'.$qlArray[0]['constraint2'].'<br/>SQL: '.$sql;
      return Traceroute::getTrSet($sql, "");
    } else if ($qlArray[0]['constraint2']=="recentRoutes") {
      //$dbQueryHtml .= "Displaying <span id='tr-count-db'>1</span> of 50 results";
      //$dbQuerySummary .= "Displaying <span id='tr-count-db'>1</span> of 50 results";
      $sql = 'select id from traceroute order by id desc limit 50';

      //echo '<hr/>'.$qlArray[0]['constraint2'].'<br/>SQL: '.$sql;
      return Traceroute::getTrSet($sql, "");
    } else {
      return array();
    }
  }

  /**
    Get geographic data for a tr set
  */
  public static function getIxMapsData($data)
  {
    global $dbconn, $trNumLimit, $dbQueryHtml, $dbQuerySummary, $totTrFound;
    $result = array();
    $totTrs = count($data);
    $totTrFound = $totTrs;
    //echo '<br/>Tot: '.$totTrs;

    // set index increase if total traceroutes is > $trNumLimit
    if($totTrs>$trNumLimit)
    {
      $indexJump = $totTrs/$trNumLimit;
      $indexJump = intval($indexJump)+1;
    } else {
      $indexJump = 1;
    }
    //echo '<br/>indexJump: '.$indexJump;
    //echo '<br/>Displaying the following traceroutes IDs: <br/>';

    $longLatArray = array();

    $wTrs = '';
    $trCoordinates = '';
    $trCollected = array();

    $c=0;
    // build SQL where for the given TR set
    //foreach ($data as $trId)
    for ($i=0; $i<$totTrs; $i+=$indexJump)
    // as $trId)
    {
      $trCollected[]=$data[$i];
      //echo ''.$data[$i].' | ';
      if($c==0)
      {
        $wTrs.=' traceroute.id='.$data[$i];

      } else {
        $wTrs.=' OR traceroute.id='.$data[$i];
      }
      $c++;
    }

    if($totTrs>$trNumLimit){
      //$dbQueryHtml .= "<span title='The search produced more routes than can be easily mapped. Every nth route is presented to keep the number mapped to no more than 100.'>Displaying <span id='tr-count-db'>1</span> of ".$c." sampled results (".$totTrs." total)</span>";
      //$dbQuerySummary .= "<span title='The search produced more routes than can be easily mapped. Every nth route is presented to keep the number mapped to no more than 100.'>Displaying <span id='tr-count-db'>1</span> of ".$c." sampled results (".$totTrs." total)</span>";
    } else {
      //$dbQueryHtml .= "Displaying <span id='tr-count-db'>1</span> of ".$c." results";
    }

    if($totTrs>$trNumLimit){
      //$dbQuerySummary .= '<p style="color:red;">Showing a sample of <b>'.$c.' traceroutes</b>.</p>';
    }
    // free some memory
    unset($data);

    $sql = "SELECT
    tr_item.traceroute_id, tr_item.hop, tr_item.rtt_ms,

    traceroute.id, traceroute.dest, traceroute.dest_ip, traceroute.submitter, traceroute.sub_time,

    ip_addr_info.ip_addr, ip_addr_info.hostname, ip_addr_info.lat, ip_addr_info.long, ip_addr_info.mm_country, ip_addr_info.mm_city, ip_addr_info.gl_override,

    as_users.num, as_users.name,

    ip_addr_info.flagged

    FROM tr_item, traceroute, ip_addr_info, as_users WHERE (tr_item.traceroute_id=traceroute.id) AND (ip_addr_info.ip_addr=tr_item.ip_addr) AND (as_users.num=ip_addr_info.asnum) AND tr_item.attempt = 1";
    $sql.=" AND (".$wTrs.")";
    $sql.=" order by tr_item.traceroute_id, tr_item.hop, tr_item.attempt";

    //echo '<textarea>'.$sql.'</textarea>';
    //echo '<hr/>'.$sql;
    // free some memory
    $wTrs='';

    $result = pg_query($dbconn, $sql) or die('Query failed: ' . pg_last_error());
    //$tot = pg_num_rows($result);
    // get all data in a single array
    $trArr = pg_fetch_all($result);

    return $trArr;
  }

  /**
    Get geographic data for a tr set
  */
  public static function getIxMapsDataOld($data)
  {
    global $dbconn, $trNumLimit, $dbQueryHtml, $dbQuerySummary;
    $result = array();
    $totTrs = count($data);
    //echo '<br/>Tot: '.$totTrs;

    // set index increase if total traceroutes is > $trNumLimit
    if($totTrs>$trNumLimit)
    {
      $indexJump = $totTrs/$trNumLimit;
      $indexJump = intval($indexJump)+1;
    } else {
      $indexJump = 1;
    }
    //echo '<br/>indexJump: '.$indexJump;
    //echo '<br/>Displaying the following traceroutes IDs: <br/>';

    $longLatArray = array();

    $wTrs = '';
    $trCoordinates = '';
    $trCollected = array();

    $c=0;
    // build SQL where for the given TR set
    //foreach ($data as $trId)
    for ($i=0; $i<$totTrs; $i+=$indexJump)
    // as $trId)
    {
      $trCollected[]=$data[$i];
      //echo ''.$data[$i].' | ';
      if($c==0) {
        $wTrs.=' traceroute.id='.$data[$i];
      } else {
        $wTrs.=' OR traceroute.id='.$data[$i];
      }
      $c++;
    }

    if($totTrs>$trNumLimit){
      $dbQueryHtml .= "<span title='The search produced more routes than can be easily mapped. Every nth route is presented to keep the number mapped to no more than 100.'>Displaying <span id='tr-count-db'>1</span> of ".$c." sampled results (".$totTrs." total)</span>";
      $dbQuerySummary .= "<span title='The search produced more routes than can be easily mapped. Every nth route is presented to keep the number mapped to no more than 100.'>Displaying <span id='tr-count-db'>1</span> of ".$c." sampled results (".$totTrs." total)</span>";
    } else {
      $dbQueryHtml .= "Displaying <span id='tr-count-db'>1</span> of ".$c." results";
    }

    if($totTrs>$trNumLimit){
      $dbQuerySummary .= '<p style="color:red;">
      Showing a sample of <b>'.$c.' traceroutes</b>.</p>';
    }
    // free some memory
    unset($data);

    $sql = "SELECT
    tr_item.traceroute_id, tr_item.hop, tr_item.rtt_ms,

    traceroute.id, traceroute.dest, traceroute.dest_ip, traceroute.submitter, traceroute.sub_time,

    ip_addr_info.ip_addr, ip_addr_info.hostname, ip_addr_info.lat, ip_addr_info.long, ip_addr_info.mm_country, ip_addr_info.mm_city, ip_addr_info.gl_override,

    as_users.num, as_users.name,

    ip_addr_info.flagged

    FROM tr_item, traceroute, ip_addr_info, as_users WHERE (tr_item.traceroute_id=traceroute.id) AND (ip_addr_info.ip_addr=tr_item.ip_addr) AND (as_users.num=ip_addr_info.asnum) AND tr_item.attempt = 1";
    $sql.=" AND (".$wTrs.")";
    $sql.=" order by tr_item.traceroute_id, tr_item.hop, tr_item.attempt";

    //echo '<textarea>'.$sql.'</textarea>';
    //echo '<hr/>'.$sql;
    // free some memory
    $wTrs='';

    $result = pg_query($dbconn, $sql) or die('Query failed: ' . pg_last_error());
    //$tot = pg_num_rows($result);
    // get all data in a single array
    $trArr = pg_fetch_all($result);

    return $trArr;
  }

  /**
    handy function to test current colours added to ASN array
  */
  public static function getAsNames()
  {
    global $dbconn, $as_num_color;
    $resultArray = array();

    $c=0;
    $sql = 'SELECT num, name FROM as_users where ';
    $w = '';

    foreach($as_num_color as $id => $val)
    {
      $resultArray[$id]['color']=$val;

      $c++;
      if($c==1)
      {
        $w .= ' num = '.$id;
      } else {
        $w .= ' OR num = '.$id;
      }
    }

    $sql .= ''.$w;

    $result = pg_query($dbconn, $sql) or die('Query failed: ' . pg_last_error());

    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC))
    {
      //echo '<br/>'.$line['name'];
      $resultArray[$line['num']]['name'] = $line['name'];
    }

    $html='<table border="1" cellspacing="1" cellpadding="2" style="width: 300px">';
    foreach($resultArray as $asNum=>$asArray)
    {
      if($asArray['color']!='676A6B')
      {
        $html.='
      <tr><td>'.$asArray['name'].'</td><td style="background-color:'.$asArray['color'].'; width: 25px;"></td></tr>';
      }

    }
    $html.='</table>';
    echo $html;
  }

  /**

  */
  public static function writeGmStart ($file,$fh) {

    $mapJs="
      function initializeMap() {
        var myLatLng = new google.maps.LatLng(44, -99);
        var mapOptions = {
        scrollwheel: true,
        navigationControl: true,
        mapTypeControl: false,
        scaleControl: true,
        draggable: true,
            zoom: 4,
            center: myLatLng,
            mapTypeId: google.maps.MapTypeId.ROADMAP
        };
        map = new google.maps.Map(document.getElementById('map_canvas'), mapOptions);
    ";
    //$fh = fopen($file, 'w') or die("can't open file");
    //fwrite($fh, $mapJs);
    fwrite($fh, "");
    //fclose($fh);
  }

  /**

  */
  public static function writeGmEnd ($file,$fh,$jsonData) {
    $mapJs="
      var ixMapsData = '".$jsonData."';
      var ixMapsDataJson;
      loadMapData();
    ";

    //$mapJs.='<div id="map_canvas"></div>';

    //$fh = fopen($file, 'w') or die("can't open file");
    fwrite($fh, $mapJs);
    fclose($fh);
  }


  /**
    New version: Generates json data for gmaps
  */
  public static function generateDataForGoogleMaps($data)
  {

    global $coordExclude, $webUrl, $savePath, $as_num_color;

    $trDataToJson = array();

    // loop 1: TRids
    $totTrs = 0;
    foreach($data as $trId => $hops)
    {
      $totTrs++;
      // loop 2: hops in a TRid
      $totHopsAll = 0;
      for($r=0;$r<count($hops);$r++)
      {
        $totHopsAll++;

        // new approach: use for looping in a way that previous hops' data can be accessed easily

        // minimal data for map generation
        $ip = $hops[$r][0];
        $hopN = $hops[$r][1];
        $lat = $hops[$r][2];
        $long = $hops[$r][3];
        $id = $hops[$r][4];
        $asNum = $hops[$r][5];
        $asName = $hops[$r][6];
        $cEx = "$lat,$long";

        $mm_city = $hops[$r][10];
        $mm_city = str_replace("'"," ",$mm_city);
        //$mm_city = "";

        $gl_override = $hops[$r][14];

        // data set to be exported to json
        $trDataToJson[$id][$hopN]=array(
          'asNum'=>$asNum,
          'asName'=>$asName,
          'ip'=>$ip,
          'lat'=>$lat,
          'long'=>$long,
          //'destHostname'=>$hops[$r][7],
          '8'=>$hops[$r][8],
          '9'=>$hops[$r][9],
          '20'=>$hops[$r][20],
          'hopN'=>$hopN,
          'mm_city'=>$mm_city,
          'mm_country'=>$hops[$r][11],
          //'sub_time'=>$hops[$r][12],
          'rtt_ms'=>$hops[$r][13],
          'gl_override'=>$gl_override,
          'dist_from_origin'=>$hops[$r][15],
          'imp_dist'=>$hops[$r][16],
          'time_light'=>$hops[$r][17],
          'latOrigin'=>$hops[$r][18],
          'longOrigin'=>$hops[$r][19],
          'flagged'=>$hops[$r][21],
          'hostname'=>$hops[$r][22]
        );

      } // end loop 2

    } // end loop 1

    // create results array
    $statsResult = array(
      'totTrs'=>$totTrs,
      'totHops'=>$totHopsAll,
      'result'=>json_encode($trDataToJson)
    );

    return $statsResult;
  }



  /**
    Most of this has been now moved to JS, a cleaning up here is needed
  */
  public static function generateDataForGoogleMaps_OLD (
    $data,
    $addPolylines=false,
    $addMarkers=false, $showHopNums=false,
    $addInfoWin=false,
    $saveKml=false)
  {
    global $coordExclude, $webUrl, $savePath, $as_num_color;

    $trDataToJson = array();

    $date = md5(date('d-m-o_G-i-s'));
    $gmFile = $date.".js";
    $myFile = $savePath."/".$gmFile;
    $fh = fopen($myFile, 'w') or die("can't open file");

    // KML TR coords export
    if($saveKml){
      $kml='';
      $kmlFile = $date.".kml";
      $myKmlFile = $savePath."/".$kmlFile;
      $fhKml = fopen($myKmlFile, 'w') or die("can't open file KML");
    }

    Traceroute::writeGmStart($myFile,$fh);

    $gMapJs = '';
    $markers = '';
    $infoWin ='';
    $infoWinTmpl ='<div></div>';
    $icon_01 = "";
    $trIdsCounter = 0;

    $totHopsAll = 0;
    $totTrs = count($data);
    $kml='';
    $kmlA='';

    $tConn1 = 0;
    $tConn2 = 10;

    $b = strtotime("+12470 seconds");
    //$b = strtotime("+37410 seconds");


    //$a = strtotime("now");
    //$b = $a + (12470*50);
    // loop TRids
    foreach($data as $trId => $hops)
    {
      // set start time
      $a = strtotime("+".$tConn1." seconds");
      $a2 = strtotime("+".$tConn2." seconds");
      //$a+=50;
      //$b = strtotime("+".$tConn2." seconds");
      //$tConn1++;
      $tConn1++;
      $tConn2++;

      $trIdsCounter++;
      $totHops = count($hops);
      $c = 0;

      $trCoordinates = '';

      // save KML
      if($saveKml){
        // calculate time for animated population of TR
        /*$tBegin='2013-01-01T01:05:20Z';
        $tEnd='2013-01-01T21:05:43Z';*/

        $tBegin=date('Y-m-d\TG:i:s\Z', $a);
        $tEnd=date('Y-m-d\TG:i:s\Z', $b);
        $tEndA=date('Y-m-d\TG:i:s\Z', $a2);

        $kml.='
    <Placemark>
      <name>TR-'.$trId.' - '.$tConn1.'</name>
      <styleUrl>#msn_ylw-pushpin</styleUrl>
          <TimeSpan>
            <begin>'.$tBegin.'</begin>
            <end>'.$tEnd.'</end>
          </TimeSpan>
      <LineString>
        <tessellate>1</tessellate>
        <coordinates>';

        $kmlA.='
    <Placemark>
      <name>TR-'.$trId.' - '.$tConn1.'A</name>
      <styleUrl>#msn_ylw-pushpin</styleUrl>
          <TimeSpan>
            <begin>'.$tBegin.'</begin>
            <end>'.$tEndA.'</end>
          </TimeSpan>
      <LineString>
        <tessellate>1</tessellate>
        <coordinates>';
      }


      // loop hops in a TRid
      for($r=0;$r<count($hops);$r++)
      {
        $totHopsAll++;
        $c++;
/*        echo '<hr/>';
        print_r($hop);

*/
        // new approach: use for loopinging in a way that previous hops' data can be accessed easily

        // minimal data for map generation
        $ip = $hops[$r][0];
        $hopN = $hops[$r][1];
        $lat = $hops[$r][2];
        $long = $hops[$r][3];
        $id = $hops[$r][4];
        $asNum = $hops[$r][5];
        $asName = $hops[$r][6];
        $cEx = "$lat,$long";

        $mm_city = $hops[$r][10];
        $mm_city = str_replace("'"," ",$mm_city);
        //$mm_city = "";

        $gl_override = $hops[$r][14];

        // data set to be exported to json
        $trDataToJson[$id][$hopN]=array(
          'asNum'=>$asNum,
          'asName'=>$asName,
          'ip'=>$ip,
          'lat'=>$lat,
          'long'=>$long,
          //'destHostname'=>$hops[$r][7],
          '8'=>$hops[$r][8],
          '9'=>$hops[$r][9],
          '20'=>$hops[$r][20],
          'hopN'=>$hopN,
          'mm_city'=>$mm_city,
          'mm_country'=>$hops[$r][11],
          //'sub_time'=>$hops[$r][12],
          'rtt_ms'=>$hops[$r][13],
          'gl_override'=>$gl_override,
          'dist_from_origin'=>$hops[$r][15],
          'imp_dist'=>$hops[$r][16],
          'time_light'=>$hops[$r][17],
          'latOrigin'=>$hops[$r][18],
          'longOrigin'=>$hops[$r][19],
          'flagged'=>$hops[$r][21],
          'hostname'=>$hops[$r][22]
        );

        if($saveKml && $lat!=0 && $long!=0 && (!in_array($cEx, $coordExclude))) {
          $kml.='
          '.$long.','.$lat.'';
        }

        if($ip) {
          // match ISP name for colouring

          // new approach each hop is represented as a unique polyline

          //  1. exclude first hop
          if($r!=0) {
            //NOT working very well when excluding inaccurate coordinates of pevious hop
            // TODO: we need a record of the hops skipped so there are no holes in the path.

            $lat1 = $hops[$r-1][2];
            $long1 = $hops[$r-1][3];
            $lat2 = $hops[$r][2];
            $long2 = $hops[$r][3];
            $cEx1 = "$lat1,$long1";
            $cEx2 = "$lat2,$long2";

            if($lat1!=0 && $long1!=0 && $lat2!=0 && $long2!=0 && ($lat1!=$lat2 && $long1!=$long2) && (!in_array($cEx1, $coordExclude)) && (!in_array($cEx2, $coordExclude)))
            {

              $hopCoordinates = '
              new google.maps.LatLng('.$lat1.', '.$long1.'),
              new google.maps.LatLng('.$lat2.', '.$long2.')';

              // get colour
              $hopC="";
              if(!isset($as_num_color[$hops[$r-1][5]]))
              {
                $hopC = '#676A6B';
              } else {

                // fixed this notice
                if(isset($as_num_color[$asNum])){
                  $hopC = '#'.$as_num_color[$asNum];
                }
              }

              if($addPolylines) {

                // build Hop polyline obj
                $hopCoordinatesObj = "
                  var hopRoute_".$id."_".$hopN." = [".$hopCoordinates."
                  ];

                  var hopRoutePath_".$id."_".$hopN." = new google.maps.Polyline({
                    path: hopRoute_".$id."_".$hopN.",
                    strokeColor: '".$hopC."',
                    strokeOpacity: 0.6,
                    strokeWeight: 6.0
                  });

                trCollection.push(hopRoutePath_".$id."_".$hopN.");

                  hopRoutePath_".$id."_".$hopN.".setMap(map);
                  ";

                  // adding event listener to polyline object
                  $hopCoordinatesObj .="
                google.maps.event.addListener(hopRoutePath_".$id."_".$hopN.", 'click', function() {
                  trHopClick(".$id.",".$hopN.");
                });

                google.maps.event.addListener(hopRoutePath_".$id."_".$hopN.", 'mouseover', function() {
                  trHopMouseover(".$id.",".$hopN.");
                });

                google.maps.event.addListener(hopRoutePath_".$id."_".$hopN.", 'mouseout', function() {
                  trHopMouseout(".$id.",".$hopN.");
                });
                  ";

                  // add hopCoordinatesObj
                    fwrite($fh, $hopCoordinatesObj);
                  }

            ///////////
          } // end if exclude wrong coordinates
        } // end exclude first hop

          if($c==$totHops) // last hop
          {
            $trCoordinates .= '
          new google.maps.LatLng('.$lat.', '.$long.','.$id.')';
          } else {
          //  '.$ip.' : '.$hopN.'
            $trCoordinates .= '
          new google.maps.LatLng('.$lat.', '.$long.','.$id.'),';
          }

          if($addMarkers) {

            // get icon colour
            if(!isset($as_num_color[$asNum])){
              $iconColour = '676A6B';
            } else {
              $iconColour = $as_num_color[$asNum];
            }

            // add marker
            $markers.= "
            var tr_".$id.'_H'.$hopN." = new google.maps.LatLng(".$lat.",".$long.")
            var marker".$id.'_H'.$hopN." = new google.maps.Marker({
                position: tr_".$id.'_H'.$hopN.",
                map: map,";
                if(!$showHopNums) {
                  $markers.= "
                  icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    fillOpacity: 0.5,
                    fillColor: '#".$iconColour."',
                    strokeOpacity: 1.0,
                    strokeColor: '#000000',
                    strokeWeight: 1.0,
                    scale: 7,
                    },";
              }
                $markers.= "
                title:'".$ip."'
              });";

            if($showHopNums) {
            // set icon
              $markers.= "marker".$id.'_H'.$hopN.".setIcon('".$webUrl."/images/"."hop".$hopN.".png');";
            }

          } // end if markers

          // add info window
          if($addInfoWin) {
            $infoWin .="
            var infoC_".$id.'_H'.$hopN." = '<div class=\"info-win-text\">IP: <b>".$ip."</b> | (".$lat.",".$long.")<br/>TRid: <b>".$id."</b> | hop: <b>".$hopN."</b><br/>AS: <b>".$asNum."</b> <br/>Carrier: <b>".$asName."</b><br/><a href=\"javascript: viewTrDetails(".$id.")\">Traceroute Detail</a></div>';

            var infoW_".$id.'_H'.$hopN." = new google.maps.InfoWindow({
                content: infoC_".$id.'_H'.$hopN.",
            });

            google.maps.event.addListener(marker".$id.'_H'.$hopN.", 'click', function() {
              infoW_".$id.'_H'.$hopN.".open(map,marker".$id.'_H'.$hopN.");
            });";
          } // end if infowin

        } // end geoprecision exclude

      } // end loop 2
      //
      if($saveKml){
        $kml.='
        </coordinates>
      </LineString>
    </Placemark>
        ';
        $kmlA.='
        </coordinates>
        <color>ff00ffff</color>
      </LineString>
    </Placemark>
        ';
      }


      /*old approach, not used for now
      we now build a polyline for each hop pair*/

      // build polyline obj
      $trCoordinatesObj = "
        var traceRoute".$trId." = [".$trCoordinates."
        ];

        var traceRoutePath".$trId." = new google.maps.Polyline({
          path: traceRoute".$trId.",
          strokeColor: '".Traceroute::getColor()."',
          strokeOpacity: 1.0,
          strokeWeight: 3
        });

        traceRoutePath".$trId.".setMap(map);

        ";

          // add traceroute to js
          //$gMapJs .=''.$trCoordinatesObj;

          // write to file
          //fwrite($fh, $trCoordinatesObj);

          if($addMarkers) {
            //fwrite($fh, $markers);
          }
          if($addInfoWin) {
            //fwrite($fh, $infoWin);
        }

          // reset variables
          $trCoordinatesObj = '';
          $markers = '';
          $infoWin = '';


    } // end loop 1

    // add markers
    //$gMapJs.=''.$markers;

    // add info window and click event listeners
    //$gMapJs.=''.$infoWin;

    //echo '<textarea>'.$gMapJs.''.$infoWin.'</textarea>';
    //$date = date('d ');

    $trDataToJsonS = json_encode($trDataToJson);
    //$trDataToJsonS = "";

    unset($trDataToJson);

    Traceroute::writeGmEnd($myFile, $fh, $trDataToJsonS);

    unset($trDataToJsonS);

    //fclose($fh);

    //Traceroute::renderMap($gMapJs);

    $fileSize = filesize($myFile)/1024;
    $fileSize = number_format($fileSize, 2);


    /*echo '<hr/>IXmaps data';
    echo '<br/>TRs: '.$totTrs;
    echo '<br/>Hops: '.$totHopsAll;
    echo '<br/>Name: '.$gmFile;
    echo '<br/>Size: '.$fileSize.' KB';*/

    // create results array
    $statsResult = array(
      'totTrs'=>$totTrs,
      'totHops'=>$totHopsAll,
      'ixdata'=>$gmFile,
      'ixsize'=>$fileSize
    );

    // save kml
    if($saveKml){
      $kmlAll='<?xml version="1.0" encoding="UTF-8"?>
      <kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2" xmlns:kml="http://www.opengis.net/kml/2.2" xmlns:atom="http://www.w3.org/2005/Atom">
        <Document>
          <name>polyline-test.kml</name>
          <Style id="sn_ylw-pushpin">
            <IconStyle>
              <scale>1.1</scale>
              <Icon>
                <href>http://maps.google.com/mapfiles/kml/pushpin/ylw-pushpin.png</href>
              </Icon>
              <hotSpot x="20" y="2" xunits="pixels" yunits="pixels"/>
            </IconStyle>
            <LineStyle>
              <color>ff0000ff</color>
              <width>3</width>
            </LineStyle>
          </Style>
          <StyleMap id="msn_ylw-pushpin">
            <Pair>
              <key>normal</key>
              <styleUrl>#sn_ylw-pushpin</styleUrl>
            </Pair>
            <Pair>
              <key>highlight</key>
              <styleUrl>#sh_ylw-pushpin</styleUrl>
            </Pair>
          </StyleMap>
          <Style id="sh_ylw-pushpin">
            <IconStyle>
              <scale>1.3</scale>
              <Icon>
                <href>http://maps.google.com/mapfiles/kml/pushpin/ylw-pushpin.png</href>
              </Icon>
              <hotSpot x="20" y="2" xunits="pixels" yunits="pixels"/>
            </IconStyle>
            <LineStyle>
              <color>ff0000ff</color>
              <width>3</width>
            </LineStyle>
          </Style>
          <Folder>
            <name>IXmaps Trs</name>
            <open>1</open>
          '.$kml.'
          </Folder>
        </Document>
      </kml>';
      fwrite($fhKml, $kmlAll);
      fclose($fhKml);
    }

    return $statsResult;
    //echo '<script src="'.$webUrl.'/gm-temp/'.$gmFile.'"></script><div style="clear: both;">';
  }


  /**
    Not used: new approach writes js
  */
  public static function renderMap($gMapJs)
  {
    $mapJs="
    <script>

    </script>
    ";

    $mapJs.='<div id="map_canvas"></div>';

    echo $mapJs;

    //echo '<textarea>'.$mapJs.'</textarea>';
  }

  /**
    Transform basic tr results array and gather new data for advanced analysis.
      i.e SoL calculations
  */
  public static function dataTransform($trArr)
  {
    global $savePath, $webUrl;
/*    echo '<textarea>';
    print_r($trArr);
    echo '</textarea>';
*/
    $date = md5(date('d-m-o_G-i-s'));
    $myLogFile = $savePath."/"."_log_".$date.".csv";
    $myLogFileWeb = $webUrl.'/gm-temp/_log_'.$date.".csv";
    //$fhLog = fopen($myLogFile, 'w') or die("can't open file");

    $dist_from_origin=0;
    $latOrigin = 0;
    $longOrigin = 0;
    $originAsn = 0;
    $imp_dist = 0;
    $imp_dist_txt = '"Trid";"Hop";"Country";"City";"ASN";"IP";"Latency";"Time SoL";"Distance From Origin (KM)";"gl_override";"Origin Lat";"Origin Long"; "Origin ASN"';
    //fwrite($fhLog, $imp_dist_txt);

    $time_light_will_do = 0;

    $trData = array();

    // distance speed of light in KM per 1 milsec
    $SL = 200;
    //$SL = 86;

    // get tr data for all attempts only once
    $activeTrId = $trArr[0]['id'];
    $trDetailsAllData = Traceroute::getTraceRouteAll($activeTrId);
/*    echo '<textarea>';
    print_r($trArr);
    echo '</textarea>';
*/
    // analyze min latency for origin
    // calculate if the min latency of the following hop is less than the min latency of the origin,
    // if so assign that min latency to orign and this also applies to following hop; where current hop != from origin and != from last hop.

    // origin data

    // last hop data

    // analyze here all the hops in between first and last

    // assess geocorrection. Based on this analysis we could indicate which IP should be used to replace wrong coordinates of any hop, based on the following logic:
/*
    a) N-1 and N+1 for currentHop, when currentHop != first and != last hop
    b) N+1 for currentHop, when currentHop = first hop
    c) N-1 for currentHop, when currentHop  = last hop

*/
    $totHopsData = count($trDetailsAllData);

    /*FIXME: why is not set?*/
    $lastHop = $trDetailsAllData[$totHopsData-1]['hop'];

    $firstHop = $trDetailsAllData[0]['hop'];

    $latenciesArray = array();

    foreach ($trDetailsAllData as $trDetail => $TrDetailData) {
      $currentHop = $TrDetailData['hop'];

      // collect latencies and exclude values = -1 and = 0
      if($TrDetailData['rtt_ms']!=-1 && $TrDetailData['rtt_ms']!=0){

        // this approach actually works better. Capture all here, then analyze the array.
        $latenciesArray[$TrDetailData['hop']][]=$TrDetailData['rtt_ms'];
        //$latenciesArray[$TrDetailData['hop']][$TrDetailData['rtt_ms']]=0;
      }
    } // end for collecting latencies

    //$ar2 = array(1, 3, 2, 4);
    //array_multisort($latenciesArray,$ar2);
/*
  This approach to calculate speed impossible distance is put
  on standby for now.. will come back to it laters ;)
  It's way to unstable still.
*/
//////////////////////////
/*    $minOriginLatency = sort($latenciesArray[1]);
    $minOriginLatency = $latenciesArray[1][0];
    $latenciesArrayCalculated = array();

    // sort the latencies in the array and get min latencies
    foreach ($latenciesArray as $key => $value) {
      echo 'sorting latencies for TRid: '.$activeTrId.' Hop: '.$key;
      //ksort($latenciesArray[$key], SORT_DESC);
      //rsort($latenciesArray[$key]);
      sort($latenciesArray[$key]);
      // just remove all the other latencies, and keep the min latency
      $latenciesArray[$key]=$latenciesArray[$key][0];
      $minL=$latenciesArray[$key];
      if($minOriginLatency>$minL && $key>1){
        $minOriginLatency=$minL;
      }
    }*/

    /*
      loop again and re-asign the min possible latency based on min value in subsequent hops
      As it works on current Traceroute detail page
    */
  /*    foreach ($latenciesArray as $key => $value) {
        //echo '<br/>... Checking hop '.$key;
        $minLofAllNext = Traceroute::checkMinLatency($key, $latenciesArray);
        $latenciesArrayCalculated[$key]=$minLofAllNext;
      }
*/
    // log: comparison between actual min and calculated latencies
      /*echo '<textarea>$minOriginLatency: '. $minOriginLatency.'';
      print_r($latenciesArray);
      echo '</textarea>';*/

/*      echo '<textarea>--- Calculated Latencies for each hop trId: ['.$activeTrId.']:';
      print_r($latenciesArrayCalculated);
      echo '</textarea>';
*/

//////////////////////////
    // start loop over tr data array, where $i is an index of joined traceroute and tr_item tables
    for($i=0;$i<count($trArr);$i++)
    {
      //echo '****************************'.$trArr[$i]['hostname'];

      // key data for google display

      $trId = $trArr[$i]['id'];
      $hop = $trArr[$i]['hop'];
      $ip = $trArr[$i]['ip_addr'];
/*      $lat = $trArr[$i]['mm_lat'];
      $long = $trArr[$i]['mm_long'];
*/
      $lat = $trArr[$i]['lat'];
      $long = $trArr[$i]['long'];

      $num = $trArr[$i]['num'];
      $nameLen = strlen($trArr[$i]['name']);
      $pattern1 = '/ - /';

      preg_match_all($pattern1, $trArr[$i]['name'], $matches, PREG_SET_ORDER);

      if($nameLen<23){
        $name = $trArr[$i]['name'].'';
      } else if (count($matches)==1) {
        $nameArr = explode(' - ', $trArr[$i]['name']);

        $nameLen1 = strlen($nameArr[1]);
        if($nameLen1>23){
          $name = substr($nameArr[1], 0, 22).'...';
        } else {
          $name = $nameArr[1].'';
        }

        unset($nameArr);
      } else {
        //$nameArr = explode(' ', $trArr[$i]['name']);
        //$name = $nameArr[0].' : 3';
        $name = substr($trArr[$i]['name'], 0, 22).'...';
      }
      unset($matches);


      // data needed for impossible distance calculation
      $dist_from_origin=0;
      $imp_dist = 0;
      $imp_dist_txt = '';
      $time_light_will_do = 0;


      // old approach: use only the first attempt data
      $rtt_ms = $trArr[$i]['rtt_ms'];

      // new approach: use min latency out of the 4 attemps and correct it relative to the min latency of subsequent hops. This seems to be working quite well ;) There seems to be
      // Stil under development, causing a too much processing for Anto standards ;)
      //$rtt_ms = $latenciesArrayCalculated[$hop];

      // calclulate origin assuming it does have a hop number = 1; Note this is not 100% acurate as there might be traceroutes that have missed it and start on a number > 1
      //if($i==0){
      if($hop==1){
        $latOrigin = $lat;
        $longOrigin = $long;
        $originAsn = $num;
      } else {
        // calculate distance from origin
        $dist_from_origin = Traceroute::distance($latOrigin, $longOrigin, $lat, $long, false);
        $time_light_will_do = $dist_from_origin/$SL;
        $time_light_will_do *= 2;

        // is it an imposible time? distance?
        //if($rtt_ms<$time_light_will_do){

        // use $minOriginLatency instead
        if($rtt_ms<$time_light_will_do){
          $imp_dist = 1;
          //$imp_dist_txt = '<b>YES!</b>';
        }
      }
      $lastHopIp=0;
      $trData[$trId][]=array(
        $ip,
        $hop,
        $lat,
        $long,
        $trId,
        $num,
        $name,
        $trArr[$i]['dest'],
        $trArr[$i]['dest_ip'],
        $trArr[$i]['submitter'],
        $trArr[$i]['mm_city'],
        $trArr[$i]['mm_country'],
        $trArr[$i]['sub_time'],
        $trArr[$i]['rtt_ms'],
        $trArr[$i]['gl_override'],
        $dist_from_origin,
        $imp_dist,
        $time_light_will_do,
        $latOrigin,
        $longOrigin,
        $lastHopIp,
        $trArr[$i]['flagged'],
        $trArr[$i]['hostname']
      );

      // write impossible distances to a CSV file: this method seems to be more secure and faster than doing in jQuery: NOTE: this is only for development version. It seems an overhead for production
      if($imp_dist==1){
        $impDistanceLog = ''.$trId.';'.$hop.';"'.$trArr[$i]['mm_country'].'";"'.$trArr[$i]['mm_city'].'";'.$num.';"'.$ip.'";'.$trArr[$i]['rtt_ms'].';'.$time_light_will_do.';"'.$dist_from_origin.'";'.$trArr[$i]['gl_override'].';"'.$latOrigin.'";"'.$longOrigin.'";"'.$originAsn.'"';
        //echo '<br/>'.$imp_dist_txt.$impDistanceLog;

        //fwrite($fhLog, $impDistanceLog);
      }

    } // end for

    //fclose($fhLog);
    //echo '<br/>Impossible Distances log saved at <a href="'.$myLogFileWeb.'">_log_'.$date.'.csv</a>';
    unset($trArr);
    return $trData;

    //unset($trData);

/*    echo '<hr/><textarea>';
    print_r($trData);
    echo '</textarea>';
*/
  }

  /**
  Check if there is a lower latency in subsequent hops and return that value
  */
  public static function checkMinLatency($currentHop, $hops){

    $totHops = count($hops);
    $currentHopLatency = $hops[$currentHop];

    //echo '<hr>Analyzig hop:'.$currentHop;
    //echo '<br/>currentHopLatency: '.$currentHopLatency.'<br/>';
    //print_r($hops);

    $minValReturn = 0;
    $nextHop = $currentHop+1;

    // this does not work because there are missing hops
    //for($i=$nextHop;$i<$totHops;$i++){
    foreach ($hops as $key => $value) {

      // skip all previous hops
      if($key>$currentHop){
        $nextHopLatency = $hops[$key];
        //echo '<br/>.... at hop: '.$key;
        //echo '<br/>nextHopLatency: '.$nextHopLatency;
        if($nextHopLatency<$currentHopLatency){
          $currentHopLatency=$nextHopLatency;
          //echo '<br/>[HERE] nextHopLatency: '.$nextHopLatency;
        }
      }
    }
    //echo '<br/>Hop: '.$currentHop.', Calculated min Latency: '.$currentHopLatency;
    return $currentHopLatency;
  }


  /**

  */
  public static function renderTrSets($data)
  // <th>#</th>
  // <th>TR Id</th>
  // <th>Submitter</th>
  // <th>Date</th>
  // <th>Country</th>
  // <th>Origin city</th>
  // <th>Destination city</th>
  // <th>Destination URL</th>
  // <th>Destination IP</th>
  // <td>'.$c.'</td>
  // <td><a id="tr-a-'.$trId.'" class="tr-list-ids-item '.$active.'" href="'.$onClick.'" '.$onMouseOver.'>'.$trId.'</a></td>
  // <td>'.$trIdData[0][9].'</td>
  // <td>'.$trIdData[0][12].'</td>
  // <td>'.$trIdData[0][11].'</td>
  // <td>'.$trIdData[0][10].'</td>
  // <td>'.$trIdData[$lastHopIdx-1][10].'</td>
  // <td>'.$trIdData[0][7].'</td>
  // <td>'.$trIdData[0][8].'</td>
  {
    $trResultsData = array();
    $html = '<table id="traceroutes-table" class="ui tablesorter selectable celled compact table">
        <thead>
          <tr>
              <th>Origin</th>
              <th>Destination</th>
              <th>TR ID</th>
          </tr>
      </thead><tbody>';
/*
    $html = '
    <div id="tr-list-ids" class="map-info-containers-- tr-list-result">
    <table id="tr-list-table" class="tablesorter">
    <thead>
    <tr>
      <th>Origin</th>
      <th title="Destination Hostnames are the names of the website domains targeted when generating traceroutes.">Dest. Hostname</th>
      <th>Date</th>
      <th>ID</th>
    </tr>
    </thead>
    <tbody>
    ';*/

/*
            <tr>
                <td><i class="colombia flag"></i>CityName, XX</td>
                <td>www.website.com</td>
                <td>4263</td>
            </tr>
*/
    $c=0;

    foreach($data as $trId => $trIdData)
    {
      $c++;
      //print_r($trIdData);
      //$onMouseOver = " onmouseover='showThisTr(".$trId.")'";
      $onMouseOver = " onmouseout='removeTr()' onmouseover='renderTr2(".$trId.")' onfocus='showThisTr(".$trId.")'";
      //$onMouseOver = "";
      //$onClick = "javascript: viewTrDetails(".$trId.");";       // REMOVED THIS FOR PRATT - BUT I THINK WE WANT TO KEEP IT REMOVED
      $onClick = "javascript: showThisTr(".$trId.");";

/*      $active="";
      if(in_array($trId, $collected)){
        $active = " tr-item-active";
      }*/
      $active='';
      $lastHopIdx = count($trIdData);
      // get short date
      $sDate = explode(" ", $trIdData[0][12]);
      $trIdData[0][12]=$sDate[0];
      // set up 'city, country' format if city exists
      $originStr = '';
      if(strlen($trIdData[0][10]) > 0) {
        $originStr = $trIdData[0][10].', '.$trIdData[0][11];
      } else {
        $originStr = $trIdData[0][11];
      }

      $flagIcon = "";
      if($trIdData[0][11]!=""){
        $flagIcon = '<i class="'.strtolower($trIdData[0][11]).' flag"></i> ';
      }

      $html .='
            <tr>
                <td>'.$flagIcon.$trIdData[0][11].' '.$trIdData[0][10].'</td>
                <td>'.$trIdData[0][7].'</td>
                <td><a id="tr-a-'.$trId.'" class="link'.$active.'" href="'.$onClick.'" '.$onMouseOver.'>'.$trId.'</a></td>
            </tr>
            ';

            /*$html .='
      <tr>
        <td><a id="tr-a-'.$trId.'" class="tr-list-ids-item centered-table-cell '.$active.'" href="'.$onClick.'" '.$onMouseOver.'>'.$trId.'</a></td>
        <td>'.$originStr.'</td>
        <td>'.mb_strimwidth($trIdData[0][7], 0, 20, "...").'</td>
        <td>'.$trIdData[0][12].'</td>
      </tr>
      ';*/

      $trResultsData[$trId]=array(
        //"trid"=>$trId,
        "city"=>$trIdData[0][10],
        "country"=>$trIdData[0][11],
        "destination"=>$trIdData[0][7],
        "date"=>$trIdData[0][12]
        );

    } // end foreach

    $html .='</tbody></table>';
    ///return $trResultsData;
    return $html;

  }

  /**

  */
  public static function saveSearch($qArray)
  {
    global $dbconn, $myIp, $myCity;
    $data_json = json_encode($qArray);
    if($myCity==""){
      $myCity="--";
    }
    $myCity=utf8_encode($myCity);
    // last
    $sql = "INSERT INTO s_log (timestamp, log, ip, city) VALUES (NOW(), '".$data_json."', '".$myIp."', '".$myCity."');";
    //echo '<hr/>'.$sql;
    pg_query($dbconn, $sql) or die('Error! Insert Log failed: ' . pg_last_error());
    //pg_close($dbconn);
  }

  /**

  */
  public static function testArrays()
  {
    $a = array(1,2,3,4,5,6);
    $b = array(2,4,10);

    $c =  array_merge($a, $b);
    $d = array_unique($c);

    print_r($c);

    print_r($d);
  }

  public static function testSqlUnique($sql)
  {
    global $dbconn;

    echo '<hr/>'.$sql;

    $trSet = array();
    $result = pg_query($dbconn, $sql) or die('Query failed: ' . pg_last_error());
    $data = array();
    $data1 = array();
    $id_last = 0;
    $c = 0;
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
      //$c++;
      $id=$line['id'];
/*
        if($id!=$id_last){
          $data[]=$id;
      }
*/
      $data[]=$id;
      //$id_last=$id;
    }
    $data1 = array_unique($data);
    //print_r($data);

    echo " | Traceroutes: <b>".count($data1).'</b>';
    echo " | Hops: ".count($data);
    // Free resultset
    pg_free_result($result);

    return $data1;
    // Closing connection
    pg_close($dbconn);
  }

  public static function destinationLastHopCk()
  {
    global $dbconn;

    $ips = array();

    $sql = "select ip_addr, hostname from ip_addr_info order by hostname";
    $result = pg_query($dbconn, $sql) or die('Query failed: ' . pg_last_error());

    $c = 0;
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
      $ip=$line['ip_addr'];
      $hostname=$line['hostname'];

        //$id_last=$id;
      $sql1 = "select COUNT(*) from ip_addr_info where hostname = '".$hostname."'";
      //echo '<br/>'.$sql1;

      $result1 = pg_query($dbconn, $sql1) or die('Query failed: ' . pg_last_error());
      //print_r($result1);
      $c1 = 0;

      while ($line1 = pg_fetch_array($result1, null, PGSQL_ASSOC)) {
        $c1++;
      }
      echo '<br>--'.$c1.' : '.$ip.' : '. $hostname;

/*      if($c1>1)
      {
        $c++;
        echo '<br>'.$c1.' : '.$ip.' : '. $hostname;
      }
*/
    }
    //$data1 = array_unique($data);
    //print_r($data);
    echo '<hr>Tot hostnames with more than one ip: '.$c;
    pg_free_result($result);

    //return $data1;
    // Closing connection
    pg_close($dbconn);
  }

  public static function renderSearchLog()
  {
    global $dbconn;
    $html = '<table border="1">';
    $c = 0;
    $sql = "select * from s_log order by id DESC";
    $result = pg_query($dbconn, $sql) or die('Query failed: ' . pg_last_error());
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
      $id=$line['id'];
      $ip=$line['ip'];
      $city=$line['city'];
      $timestamp=$line['timestamp'];
      $log=$line['log'];
      $log=str_replace('"[', '[', $log);
      $log=str_replace(']"', ']', $log);
      $logToArray = json_decode($log, true);

      $c++;

      $html .= '<tr>';
      $html .= '<td><a href="#">'.$id.'</a></td>';
      $html .= '<td>'.$ip.'</td>';
      $html .= '<td>'.$city.'</td>';
      $html .= '<td>'.$timestamp.'</td>';

      $q = '<td>';
      foreach ($logToArray as $constraint) {
        $q .='<br/> | '
        .$constraint['constraint1'].' | '
        .$constraint['constraint2'].' | '
        .$constraint['constraint3'].' | '
        .$constraint['constraint4'].' | '
        .$constraint['constraint5'].' | ';
        //print_r($constraint);
      }

      //$q .= $log.'<hr/>'.$queryOp.'</td>';
      $q .= '</td>';
      $html .= ''.$q;
      $html .= '</tr>';
    }
    $html .= '</table>';
    pg_free_result($result);
    pg_close($dbconn);
    echo 'Tot queries: '.$c.'<hr/>';
    echo $html;
  }

  public static function getColor()
  {
    $rand = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f');
    $color = '#'.$rand[rand(0,15)].$rand[rand(0,15)].$rand[rand(0,15)].$rand[rand(0,15)].$rand[rand(0,15)].$rand[rand(0,15)];
    return $color;
  }

  // functions for Auto-complete ajax calls
  public static function getAutoCompleteData($sField, $sKeyword)
  {
    global $dbconn;
    // query ip_add_info table
    $minL = 0;

    // proceed only if lenght is > $minL

    if(strlen($sKeyword)>$minL)
    {
      $tColumn = "";
      $tTable = "ip_addr_info";
      $tOrder = "";
      $tWhere = "";
      $tSelect = 'SELECT';

      if($sField=="country")
      {
        $tColumn = 'mm_country';
        $sKeyword = strtoupper($sKeyword);
        $tOrder = $tColumn;

      } else if($sField=="region") {
        $tColumn = 'mm_region';
        $sKeyword = ucwords(strtolower($sKeyword));
        $tOrder = $tColumn;

      } else if($sField=="city") {
        $tColumn = 'mm_city';
        $sKeyword = ucwords(strtolower($sKeyword));
        $tOrder = $tColumn;
      } else if($sField=="zipCode") {
        $tColumn = 'mm_postal';
        $sKeyword = ucwords(strtolower($sKeyword));
        $tOrder = $tColumn;
      } else if($sField=="ISP") {
        $tColumn = 'num, name';
        $sKeyword = ucwords(strtolower($sKeyword));
        $tOrder = "name";
        $tTable = "as_users";
        $tWhere = "WHERE short_name is not null";
      } else if($sField=="submitter") {
        $tSelect = 'SELECT distinct';
        $tColumn = 'submitter';
        $sKeyword = $sKeyword;
        $tOrder = "submitter";
        $tTable = "traceroute";
        $tWhere = "";//WHERE submitter NOT LIKE '%$%'";
        //select distinct submitter from traceroute order by submitter
      }

      //$sql = "SELECT $tColumn FROM ip_addr_info WHERE $tColumn LIKE '$sKeyword%' ORDER BY $tColumn";

      // loading all approach
      $sql = "$tSelect $tColumn FROM $tTable $tWhere ORDER BY $tOrder";
      //echo '<hr/>';

      $result = array();
      $autoC = array();

      $result = pg_query($dbconn, $sql) or die('Query failed: ' . pg_last_error());

      while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        if($sField=="ISP") {
          $autoC[$line['num']]=$line['name'];
        } else {
          $autoC[]=$line[$tColumn];
        }
      }
      $unique = array_unique($autoC);
      sort($unique);
      pg_free_result($result);
      pg_close($dbconn);

      return json_encode($unique);

    } else {
      echo 'keyword is too short';
    }// end if
  }
} // end class

$as_num_color = array (
   "174"  => "E431EB",
   "3356"  => "EB7231",
   "7018"  => "42EDEA",
   "7132"  => "42EDEA",
   "-1"  => "676A6B",
   "577"  => "3D49EB",
   "1239"  => "ECF244",
   "6461"  => "E3AEEB",
   "6327"  => "9C6846",
   "6453"  => "676A6B",
   "3561"  => "676A6B",
   "812"  => "ED0924",
   "20453"  => "ED0924",
   "852"  => "4BE625",
   "13768"  => "419C6B",
   "3257"  => "676A6B",
   "1299"  => "676A6B",
   "22822"  => "676A6B",
   "6939"  => "676A6B",
   "376"  => "676A6B",
   "32613"  => "676A6B",
   "6539"  => "3D49EB",
   "15290"  => "676A6B",
   "5769"  => "676A6B",
   "855"  => "676A6B",
   "26677"  => "676A6B",
   "271"  => "676A6B",
   "6509"  => "676A6B",
   "3320"  => "676A6B",
   "23498"  => "676A6B",
   "549"  => "676A6B",
   "239"  => "676A6B",
   "11260"  => "676A6B",
   "1257"  => "676A6B",
   "20940"  => "676A6B",
   "23136"  => "676A6B",
   "5645"  => "676A6B",
   "21949"  => "676A6B",
   "8111"  => "676A6B",
   "13826"  => "676A6B",
   "16580"  => "676A6B",
   "9498"  => "676A6B",
   "802"  => "676A6B",
   "19752"  => "676A6B",
   "11854"  => "676A6B",
   "7992"  => "676A6B",
   "17001"  => "676A6B",
   "611"  => "676A6B",
   "19080"  => "676A6B",
   "26788"  => "676A6B",
   "12021"  => "676A6B",
   "33554"  => "676A6B",
   "30528"  => "676A6B",
   "16462"  => "676A6B",
   "11700"  => "676A6B",
   "14472"  => "676A6B",
   "13601"  => "676A6B",
   "11032"  => "676A6B",
   "12093"  => "676A6B",
   "10533"  => "676A6B",
   "26071"  => "676A6B",
   "32156"  => "676A6B",
   "5764"  => "676A6B",
   "27168"  => "676A6B",
   "33361"  => "676A6B",
   "32489"  => "676A6B",
   "15296"  => "676A6B",
   "10400"  => "676A6B",
   "10965"  => "676A6B",
   "18650"  => "676A6B",
   "36522"  => "676A6B",
   "19086"  => "676A6B"
);
?>


