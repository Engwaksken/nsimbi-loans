<?php

function dateDiffInDays($date1, $date2)
  {
      // Calculating the difference in timestamps
      $diff = strtotime($date2) - strtotime($date1);

      // 1 day = 24 hours
      // 24 * 60 * 60 = 86400 seconds
      return abs(round($diff / 86400));
      //return ceil($diff / (60 * 60 * 24));
}

function polarise_account($set1,$set2,$set3){

	if($set1=="" || $set2=="" || $set3=="" ){

		$polarised = "declined";


	}else{ 

		$qOrg = mysqli_query($set3, "select * from organisation where id='".$set2."' order by id desc limit 1 ");
		if(mysqli_num_rows($qOrg)==1){

	    	$fOrg = mysqli_fetch_assoc($qOrg);
	    	if($set1<=$fOrg['current_balance']){

	    		$cBL = $fOrg['current_balance']-$set1;
	    		mysqli_query($set3, "update organisation set current_balance='".$cBL."' where id='".$fOrg['id']."' ");

	    		$polarised = "obtained";

	    	}else{

	    		$polarised = "attained";

	    	}
	    	

	    }else{

	    	$polarised = "false";

	    } 

	}

    return $polarised;
	
}

function excerberate_account($excerb1,$excerb2,$excerb3,$excerb4){
	if($excerb1=="" || $excerb2=="" || $excerb3=="" || $excerb4==""){

		$implied = "declined";


	}else{

		$qOrg = mysqli_query($excerb4, "select * from organisation where id='".$excerb3."' order by id desc limit 1 ");
    	if(mysqli_num_rows($qOrg)==1){

    		$fOrg = mysqli_fetch_assoc($qOrg);
   		 	$cBL1 = $fOrg['current_balance']+$excerb1;

   		 	mysqli_query($excerb4, "insert into top_up (id,amount,organisation,admin) values (NULL,'".$excerb1."','".$fOrg['id']."','".$excerb2."') ");
   		 	mysqli_query($excerb4, " update organisation set current_balance='".$cBL1."' where id='".$excerb3."' ");
   		 	$implied = "processed";

   		 }else{

   		 	$implied = "false";
   		 	
   		 }
       

	}

	return $implied;
}
/*function instill_loans($inst1,$inst2){

	date_default_timezone_set('Africa/Nairobi');

	$explore = mysqli_query($inst2, " select * from loan where status='".$inst1."' ");
	if(mysqli_num_rows($explore)>=1){

		while($fetch_explore = mysqli_fetch_assoc($explore)){

			$now = date("Y-m-d H:i:s");

			if($now>$fetch_explore['due_date']){

              mysqli_query($inst2, "update loan set status='OVERDUE' where id='".$fetch_explore['id']."' " );
			}

			$explored = 1;

	 }

	}else{

		$explored = 0;

		exit();

	}

}*/
function instill_loans($inst1,$inst2){

	date_default_timezone_set('Africa/Nairobi');

	$explore = mysqli_query($inst2, " select * from loan where status='".$inst1."' ");
	if(mysqli_num_rows($explore)>=1){

		while($fetch_explore = mysqli_fetch_assoc($explore)){

			$now = date("Y-m-d H:i:s");

			if($now>$fetch_explore['due_date']){
			    
			    $dateDiff = dateDiffInDays($fetch_explore['due_date'], $now);
			   //$surcharge = $dateDiff*1;
              $surcharge = 0;
			    
			    
			    //get payments
			    $pays = mysqli_query($inst2, " select sum(amount) from payments where loan_id='".$fetch_explore['id']."' && status='SUCCESSFUL'  ");
			    $fet_pay = mysqli_fetch_assoc($pays);
			    $paid = $fet_pay['sum(amount)'];
			    
			    $newBAL = $fetch_explore['amount'] +  $fetch_explore['interest'] +  $surcharge - $paid;

              mysqli_query($inst2, "update loan set status='OVERDUE', sur_charge='".$surcharge."', effective_balance='".$newBAL."' where id='".$fetch_explore['id']."' " );
			}

			$explored = 1;

	 }

	}else{

		$explored = 0;

		exit();

	}

}