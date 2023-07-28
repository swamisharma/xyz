function leave_reRegularizeAttendance($inputParameters)
{
    global $link;
    $response = [];

    $iid = $inputParameters['iid'];
    $employeeId = $inputParameters['employee_id'];
    $year = $inputParameters['year'];
    $month = $inputParameters['month'];
    $uploadedBy = $inputParameters['regularized_by'];
    
    //Check mandatory fields have some value
    if(!empty($iid) && !empty($employeeId) && !empty($year) && !empty($month))
    {
        $response['success'] = 'true';
        //add auto leaves for employee if employees working hr are less than policy working duration
        $response["UpdateAutoLeaveForEmployeeResponse"] = UpdateAutoLeaveForEmployee($inputParameters);
        date_default_timezone_set('Asia/Kolkata'); 
        $currentDateTime = date("Y-m-d H:i:s");

        $tableName = "leave_employee_attendance";
        $addRegularize = false;
        $attendance = [];
        //Check for same month already exists or not
        $duplicateAttandance_WHERECondition = "iid = ? AND employee_id = ? AND year = ? AND month = ?";

        $duplicateAttandance_selectQuery = $link->prepare("SELECT * FROM ".$tableName." WHERE ".$duplicateAttandance_WHERECondition." ");
        $duplicateAttandance_selectQuery->bind_param("iiii",$iid,$employeeId,$year,$month);
        $duplicateAttandance_selectQuery->execute();
        $duplicateAttandance_selectQueryResult = $duplicateAttandance_selectQuery->get_result();
        if(mysqli_num_rows($duplicateAttandance_selectQueryResult) > 0)
        {
            $addRegularize = true;
            $attendancerow = mysqli_fetch_assoc($duplicateAttandance_selectQueryResult);
            $attendance = json_decode($attendancerow['monthly_attendance_json'],true);
        }
        else
        {
            $response['status'] = 'fail';
            $response['message'] = 'Failed to re-regularize. Please import attendance then try again.';
        }

        //raise regularization 
        if($addRegularize === true)
        {
            $response['Regularization'] = 'is true';
            $query_date = $year."-".$month."-01";
            $firstDate = date('Y-m-01', strtotime($query_date));
            $firstWeekDay = date('l', strtotime($firstDate));
            $lastDate = date('Y-m-t', strtotime($query_date));
            $firstWeekNumber = date('W', strtotime($firstDate));
            $lastDay = date('t', strtotime($firstDate));
            
            //createing object to maintain assigned policies data
            $userAssignedPolicies = []; 
            
            //get leave policy assigned to user
            $policyAssignedtableName = "assign_leave_template";
            $policyAssignedWHERECondition = "iid = ? AND employeeid = ? AND policy_start_date <= '$firstDate' AND policy_end_date >= '$lastDate' ";

            $policyAssignedselectQuery = $link->prepare("SELECT * FROM ".$policyAssignedtableName." WHERE ".$policyAssignedWHERECondition." ");
            $policyAssignedselectQuery->bind_param("ii",$iid,$employeeId);
            $policyAssignedselectQuery->execute();
            $policyAssignedselectQueryResult = $policyAssignedselectQuery->get_result();
            if(mysqli_num_rows($policyAssignedselectQueryResult) > 0)
            {
                while($policyIdResult = mysqli_fetch_assoc($policyAssignedselectQueryResult))
                {
                    $policyUniqueId = $policyIdResult['policy_uid'];
                    $userAssignedPolicies[$policyUniqueId]['policyId'] = $policyIdResult['policy_uid'];
                    $userAssignedPolicies[$policyUniqueId]['policyStartDate'] = $policyIdResult['policy_start_date'];
                    $userAssignedPolicies[$policyUniqueId]['policyEndDate'] = $policyIdResult['policy_end_date'];
                }
                //find the details of working days details for policy id
                foreach($userAssignedPolicies as $pId=>$policyIdDetails)
                {
                    //get details for policy id
                    $policyId = $policyIdDetails['policyId'];
                    $policyStartDate = $policyIdDetails['policyStartDate'];
                    $policyEndDate = $policyIdDetails['policyEndDate'];

                    $policytableName = "leavetemplate";
                    $policyWHERECondition = "iid = ? AND uniqueid = ? ";

                    $policyselectQuery = $link->prepare("SELECT * FROM ".$policytableName." WHERE ".$policyWHERECondition." ");
                    $policyselectQuery->bind_param("ii",$iid,$policyId);
                    $policyselectQuery->execute();
                    $policyselectQueryResult = $policyselectQuery->get_result();

                    $policyTimestructureResult = mysqli_fetch_assoc($policyselectQueryResult);
                    $timestructure = $policyTimestructureResult['timingStructure'];
                    $leaveConsideration = $policyTimestructureResult['leaves_consideration'];
                    
                    $timestructure = json_decode($timestructure,true);
                    $leaveConsideration = json_decode($leaveConsideration,true);
                }

                //set default values
                $week = 1;
                $raisedRegularizaionCounter = 0;
                $addRegularization = false;
                $deleteRegularization = false;

                //get the in-time & out-time settings 
                $allowedDelayMins = $leaveConsideration['delayMins'];
                $delayedHalfDayString = $leaveConsideration['delayHalfDay'];
                $delayedFullDayString = $leaveConsideration['delayFullday'];
                $allowedEarlyGoingMins = $leaveConsideration['earlyGoingMins'];
                $earlyGoingHalfDayString = $leaveConsideration['earlyGoingHalfDay'];
                $earlyGoingFullDayString = $leaveConsideration['earlyGoingFullDay'];
                //Convert inputted days string to array
                $delayedHalfDay = [];

                $delayedHalfDay = explode(",",$delayedHalfDayString);
                $delayedFullDay = explode(",",$delayedFullDayString);
                $earlyGoingHalfDay = explode(",",$earlyGoingHalfDayString);
                $earlyGoingFullDay = explode(",",$earlyGoingFullDayString);

                //initialize the exemption counter
                $allowedDelayExempt = 0;
                $allowedEarlyExempt = 0;
                
                //compare each week day time with working time
                for($i = 1; $i <= $lastDay; $i++)
                {
                    $currentDate = $year."-".$month."-".$i;
                    $weekNumber = date('W', strtotime($currentDate));
                    $weekDay = date('l', strtotime($currentDate));
                    
                    //check for week change
                    if($weekNumber == $firstWeekNumber)
                    {
                        $weekCount = "week".$week;
                    }
                    else
                    {
                        // $firstWeekNumber = $weekNumber;
                        // $week = $week+1;
                        // $weekCount = "week".$week;

                        if($firstWeekDay != "Sunday" || ($firstWeekDay == "Sunday" && $weekDay == "Sunday"))
                        {
                            $firstWeekNumber = $weekNumber;
                            $week = $week+1;
                            $weekCount = "week".$week;
                        }
                    }
                    //checking the current date in applied policies
                    $UserIntime = $attendance[$i]['inTime'];
                    $UserOuttime = $attendance[$i]['outTime'];

                    $workingDay = $timestructure[$weekDay][$weekCount]['working'];
                    $intime = $timestructure[$weekDay][$weekCount]['startTime'];
                    $outtime = $timestructure[$weekDay][$weekCount]['endTime'];

                    if($workingDay == "working")
                    {
                        //get time formatted to compare
                        $userIntime = strtotime($UserIntime);
                        $userOuttime = strtotime($UserOuttime);
                        $intime = strtotime($intime);
                        $outtime = strtotime($outtime);
                        $response[$i]['userIntime'] = $userIntime;
                        $response[$i]['userOuttime'] = $userOuttime;
                        $response[$i]['intime'] = $intime;
                        $response[$i]['outtime'] = $outtime;

                        //check if user in time or out time is empty then raise regularization
                        if(empty($UserIntime) || empty($UserOuttime) || $UserIntime < 0 || $UserOuttime < 0)
                        {
                            $response[$i]['missedTime'] = "yes";
                            $addRegularization = true;
                        }

                        //check diffrence in actual intime & user intime
                        else if($userIntime > $intime || $userOuttime < $outtime)
                        {
                            //find the difference between user intime & the time structure intime
                            $actualDelayedTime = round(abs($intime - $userIntime) / 3600,2);

                            //find the difference between user outtime & the time structure out time
                            $actualEarlyGoingTime = round(abs($userOuttime - $outtime) / 3600,2);
                            
                            if(strtotime($actualDelayedTime) > strtotime($allowedDelayMins) || strtotime($actualEarlyGoingTime) < strtotime($allowedEarlyGoingMins) || in_array($allowedDelayExempt,$delayedHalfDay) || in_array($allowedDelayExempt,$delayedFullDay) || in_array($allowedEarlyExempt,$earlyGoingHalfDay) || in_array($allowedEarlyExempt,$earlyGoingFullDay))
                            {
                                $response['actualEarlyGoingTime'] = $actualEarlyGoingTime;
                                $response['actualDelayedTime'] = $actualDelayedTime;
                                //if the exemption is not allowed then also increase the counter
                                if(in_array($allowedDelayExempt,$delayedFullDay) || in_array($allowedDelayExempt,$delayedHalfDay))
                                {
                                    $allowedDelayExempt++;
                                    $addRegularization = true;
                                    $deleteRegularization = true;
                                    $response['delayedExempCounter'][$i] = $allowedDelayExempt;
                                }

                                if(in_array($allowedEarlyExempt,$earlyGoingHalfDay) || in_array($allowedEarlyExempt,$earlyGoingFullDay))
                                {
                                    $allowedEarlyExempt++;
                                    $addRegularization = true;
                                    $deleteRegularization = true;
                                    $response['EarlyExempCounter'][$i] = $allowedEarlyExempt;
                                }

                                //all the exemption for delay & early going are exceded so if true then raise regularization
                                if($addRegularization === true)
                                {
                                    $response['addRegularization'] = $addRegularization;
                                    $inputData['institute_id'] = $iid;
                                    $inputData['facultyId'] = $employeeId;
                                    $inputData['regularizationDate'] = $currentDate;
                                    $inputData['inTime'] = "$UserIntime";
                                    $inputData['outTime'] = "$UserOuttime";
                                    $inputData['raised_by'] = $uploadedBy;

                                    //raise regularization
                                    $raisedData = autoRaiseRegularization($inputData);

                                    if($raisedData['success'] == 'true')
                                    {
                                        $response['Regularization Success'] = 'regularization success';
                                        $raisedRegularizaionCounter++;
                                        $addRegularization = false;
                                        $deleteRegularization = true;
                                    }
                                }
                            }
                            else
                            {
                                //just increase the exemption counter for delay & early going as this are allowed exemptions from leave for delay & early going
                                if($actualDelayedTime > $allowedDelayMins)
                                {
                                    $allowedDelayExempt++;
                                    $response['delayedExempCounter'][$i] = $allowedDelayExempt;
                                }
                                if($actualEarlyGoingTime < $allowedEarlyGoingMins)
                                {
                                    $allowedEarlyExempt++;
                                    $response['EarlyExempCounter'][$i] = $allowedEarlyExempt;
                                }
                            }
                        }
                        else if($userIntime <= $intime && $userOuttime >= $outtime)
                        {
                            if($deleteRegularization == true)
                            {
                                $tableName = "leave_regularisation";
                                //Delete the regularizations where in time and out time is correct according to policy
                                $duplicateRegularization_WHERECondition = "iid = ? AND employee_id = ? AND regularization_date = '$currentDate' AND status = 'Added' ";
                                $duplicateRegularizationSelectQuery = $link->prepare("SELECT * FROM ".$tableName." WHERE ".$duplicateRegularization_WHERECondition." ");
                                $duplicateRegularizationSelectQuery->bind_param("ii",$iid,$employeeId);
                                $duplicateRegularizationSelectQuery->execute();
                                $duplicateRegularizationSelectQueryResult = $duplicateRegularizationSelectQuery->get_result();

                                if(mysqli_num_rows($duplicateRegularizationSelectQueryResult)>0)
                                {
                                    $response[$i]['wrongRegularizationAvailable'] = "yes";
                                    $regularizationDeleteQuery = $link->prepare("DELETE FROM ".$tableName." WHERE ".$duplicateRegularization_WHERECondition." ");
                                    $regularizationDeleteQuery->bind_param("ii",$iid,$employeeId);
                                    $regularizationDeleteQuery->execute();
                                    $response[$i]['deleteQuery'] = "DELETE FROM ".$tableName." WHERE ".$duplicateRegularization_WHERECondition." $iid,$employeeId";
                                }
                                else
                                {
                                    $response[$i]['wrongRegularizationAvailable'] = "no";
                                }
                            }
                        }
                    }
                }
                $response['status'] = "success";
                $response['message'] = "Re-regularization done successfully.";
            }
            else
            {
                $response['status'] = "fail";
                $response['message'] = "Failed to re-regularize as leave policy is not asigned.";
            }

        }
    }
    else
    {
        $response['success'] = 'false';
        $response['message'] = 'Something went wrong. Please logout of your account and then re-try again. If issue persists, then please send screenshot on email info@vmedulife.com';
    }
    return $response;
}

//Purpose : This function will Re-regularize the attendance
//Input parameters : institute id($iid), employee id($employee_id),year($year), month($month),user who re-regularize($re-regularized_by)
// Mandatory Fields: $iid,$employee_id,$year,$month,$uploaded_by
//Output : Will return status true if details added successfully or failed if failed to add.
//Reference Id:SS2807202301

function leaveTypeReRegularizationAttendance($inputParameters) {
    global $link;
    $responce = []

    $iid = $inputParameters['iid'];
    $employeeId = $inputParameters['employee_id'];
    $uploadedBy = $inputParameters['faculty_id'];
    $year = $inputParameters['year'];
    $month = $inputParameters['month'];

    // Check  mandatory fields have some value
    if (!empty($iid) && !empty($employeeId) && !empty($year) && !empty($month)) {
        $responce['success'] = 'true';
        
    }
}

// Purpose : This function is used to get employee's attendance
// Input : $iid,$employeeId
// Mandatory Fields: $iid,$employeeId
// Output : Will return success as true with employee attendance. details else fail.
// Reference Id : TS0912202002
//
    function UpdateAutoLeaveForEmployee($inputParameter)
    {
        global $link;
        $response = [];
        $response['data'] = [];

        $iid = $inputParameter['iid'];
        $year = $inputParameter['year'];
        $month = $inputParameter['month'];
        $employeeId = $inputParameter['employee_id'];
        $facultyId = $inputParameter['regularized_by'];
        //Check mandatory field have some value
        if(!empty($iid) && !empty($employeeId))
        {
            $response['success'] = 'true';
            $policywiseLeaveCountObject = [];

            $query_date = $year."-".$month."-01";
            $firstDate = date('Y-m-01', strtotime($query_date));
            $lastDate = date('Y-m-t', strtotime($query_date));
            $firstWeekDay = date('l', strtotime($firstDate));
            $firstWeekNumber = date('W', strtotime($firstDate));
            $lastDay = date('t', strtotime($firstDate));
            
            if(empty($month))
            {
                $month = "1,2,3,4,5,6,7,8,9,10,11,12";
            }

            //createing object to maintain assigned policies data
            $userAssignedPolicies = []; 
            $timestructure = [];      
            $weeklyOff = 0; $leaves_consideration = [];


            //get leave policy assigned to user
            $policyAssignedtableName = "assign_leave_template";
            $policyAssignedWHERECondition = "iid = ? AND employeeid = ? AND ((policy_start_date < '$firstDate' AND policy_end_date >= '$firstDate') OR (policy_start_date >= '$firstDate' AND policy_start_date <= '$lastDate' )) ";
        
            $policyAssignedselectQuery = $link->prepare("SELECT * FROM ".$policyAssignedtableName." WHERE ".$policyAssignedWHERECondition." ");
            $policyAssignedselectQuery->bind_param("ii",$iid,$employeeId);
            $policyAssignedselectQuery->execute();
            $policyAssignedselectQueryResult = $policyAssignedselectQuery->get_result();
            if(mysqli_num_rows($policyAssignedselectQueryResult) > 0)
            {
                while($policyIdResult = mysqli_fetch_assoc($policyAssignedselectQueryResult))
                {
                    $policyUniqueId = $policyIdResult['policy_uid'];
                    $userAssignedPolicies[$policyUniqueId]['policyId'] = $policyIdResult['policy_uid'];
                    $userAssignedPolicies[$policyUniqueId]['policyStartDate'] = $policyIdResult['policy_start_date'];
                    $userAssignedPolicies[$policyUniqueId]['policyEndDate'] = $policyIdResult['policy_end_date'];

                    
                }           
                $autoLeaveType = "";
                //find the details of working days details for policy id
                foreach($userAssignedPolicies as $pId=>$policyIdDetails)
                {
                    //get details for policy id
                    $policyId = $policyIdDetails['policyId'];
                    $policyStartDate = $policyIdDetails['policyStartDate'];
                    $policyEndDate = $policyIdDetails['policyEndDate'];

                    $policytableName = "leavetemplate";
                    $policyWHERECondition = "iid = ? AND uniqueid = ? ";

                    $policyselectQuery = $link->prepare("SELECT * FROM ".$policytableName." WHERE ".$policyWHERECondition." ");
                    $policyselectQuery->bind_param("ii",$iid,$policyId);
                    $policyselectQuery->execute();
                    $policyselectQueryResult = $policyselectQuery->get_result();

                    $policyTimestructureResult = mysqli_fetch_assoc($policyselectQueryResult);
                
                
                    if(!empty($policyTimestructureResult['timingStructure']))
                    {
                        $timestructure = $policyTimestructureResult['timingStructure'];
                        $timestructure = json_decode($timestructure,true);
                        $leaves_consideration = $policyTimestructureResult['leaves_consideration'];
                        $leaves_consideration = json_decode($leaves_consideration,true);
                        $autoLeaveType = $policyTimestructureResult["autoLeaveType"];

                        //if autoLeaveType is 0 auto leave will not be added 
                        if($autoLeaveType == 0)
                        {
                            $response["status"] = "failed";
                            $response["message"] = "Unable to add leaves as leave type is not selected for auto leave.";
                            return $response;
                        }else{
                            //get paid unpaid leaves count 
                            $policywiseLeaveCountObject = getleaveDetails($employeeId,$iid,$policyStartDate,$policyEndDate,false,$policyStartDate,$policyEndDate)["data"];
                        }
                    }
                
                }
                $response['policywiseLeaveCountObject'] =  $policywiseLeaveCountObject;
                $response["autoLeaveType"] = $autoLeaveType;
                // $response["leaves_consideration"] = $leaves_consideration;
                $totalPaidLeavesCount = isset($policywiseLeaveCountObject["PaidLeavesCount"]) ? $policywiseLeaveCountObject["PaidLeavesCount"] :0;
                $totalUnPaidLeavesCount = isset($policywiseLeaveCountObject["UnpaidLeavesCount"]) ? $policywiseLeaveCountObject["UnpaidLeavesCount"] :0;

                //get employee attendance
                $tableName = "leave_employee_attendance";
                $WHERECondition = "iid = ? AND employee_id = ? AND year = ? AND month IN ($month)";

                $selectQuery = $link->prepare("SELECT * FROM ".$tableName." WHERE ".$WHERECondition." ");
                $selectQuery->bind_param("iii",$iid,$employeeId,$year);
                $selectQuery->execute();
                $selectQueryResult = $selectQuery->get_result();

                if(mysqli_num_rows($selectQueryResult) > 0)
                {
                    //dont send db column name as it is to frontend. 
                    while($row = mysqli_fetch_assoc($selectQueryResult))
                    {
                        $uniqueId = $row['unique_id'];
                        $response['data'][$uniqueId] = [];
                        $response['data'][$uniqueId]['Uniqueid'] = $row['unique_id'];
                        $response['data'][$uniqueId]['Year'] = $row['year'];
                        $response['data'][$uniqueId]['Month'] = htmlspecialchars_decode($row['month']);
                        
                        
                        $attendance = json_decode($row['monthly_attendance_json'],true);
                        
                        //define arrays
                        $userLeaves = []; 
                        $daycount = [];
                        $leaveTypeArray = [];
                        $leaveDayTypeArray = [];
                        $leaveReasonArray = [];
                        $leaveStatusArray = [];
                        $holidayDayCount = [];
                        $holidayTitleArray = [];                   
                        $allRegularizationDays = [];
                        $allRegularizationArray = [];
                        
                        //get user requested leaves details 
                        $leavetableName = "faculty_leaves";
                        $leaveWHERECondition = "InstiId = $iid AND FacultyId = $employeeId AND StartDate BETWEEN '".$firstDate."' AND '".$lastDate."' AND EndDate  BETWEEN '".$firstDate."' AND '".$lastDate."' ";

                        $sql1 = "SELECT * FROM ".$leavetableName." WHERE ".$leaveWHERECondition." ";
                        $result1 = mysqli_query($link, $sql1);
                        if(mysqli_num_rows($result1) > 0)
                        {
                            while($requestedLeaveResult = mysqli_fetch_assoc($result1))
                            {
                                $requestedLeaveUniqueId = $requestedLeaveResult['UniqueId'];
                                $userLeaves[$requestedLeaveUniqueId]['leaveId'] = $requestedLeaveResult['UniqueId'];
                                $userLeaves[$requestedLeaveUniqueId]['leaveStartDate'] = $requestedLeaveResult['StartDate'];
                                $userLeaves[$requestedLeaveUniqueId]['leaveEndDate'] = $requestedLeaveResult['EndDate'];
                                $userLeaves[$requestedLeaveUniqueId]['leaveType'] = $requestedLeaveResult['LeaveType'];
                                $userLeaves[$requestedLeaveUniqueId]['leaveStatus'] = $requestedLeaveResult['Status'];
                                $userLeaves[$requestedLeaveUniqueId]['paidLeavesCount'] = $requestedLeaveResult['paid_leaves_count'];
                                $userLeaves[$requestedLeaveUniqueId]['unpaidLeavesCount'] = $requestedLeaveResult['unpaid_leaves_count'];
                                

                                $leaveStartDate= $requestedLeaveResult['StartDate'];
                                $leaveEndDate= $requestedLeaveResult['EndDate'];
                                $startDayCount = date('j', strtotime($leaveStartDate));
                                $endDayCount = date('j', strtotime($leaveEndDate));
                                for($day = $startDayCount; $day <= $endDayCount; $day++)
                                {
                                    array_push($daycount,$day);
                                    $leaveTypeArray[$day] = $requestedLeaveResult['LeaveType'];
                                    $leaveStatusArray[$day] = $requestedLeaveResult['Status'];
                                    $leaveDayTypeArray[$day] = $requestedLeaveResult['leave_day_type'];
                                    $leaveReasonArray[$day] = $requestedLeaveResult['leave_reason'];
                                }
                            }
                        }
                        
                        $userRegularizations = []; 
                        
                        //get user regularizations approved details 
                        $regularizationtableName = "leave_regularisation";

                        $userAllRegularizations = []; 
                        //get user regularizations approved details 
                        $allRegularizationWHERECondition = "iid = ? AND employee_id = ? AND regularization_date BETWEEN '$firstDate' AND '$lastDate' ";

                        $allRegularizationSelectQuery = $link->prepare("SELECT * FROM ".$regularizationtableName." WHERE ".$allRegularizationWHERECondition." ");
                        $allRegularizationSelectQuery->bind_param("ii",$iid,$employeeId);
                        $allRegularizationSelectQuery->execute();
                        $allRegularizationSelectQueryResult = $allRegularizationSelectQuery->get_result();
                        
                        if(mysqli_num_rows($allRegularizationSelectQueryResult) > 0)
                        {
                            while($allRegularizationResult = mysqli_fetch_assoc($allRegularizationSelectQueryResult))
                            {
                                $allRegularizationUniqueId = $allRegularizationResult['unique_id'];
                                $userallRegularizations[$allRegularizationUniqueId]['allRegularizationId'] = $allRegularizationResult['unique_id'];
                                $userallRegularizations[$allRegularizationUniqueId]['requestRaisedOn'] = $allRegularizationResult['regularization_date'];
                                $userallRegularizations[$allRegularizationUniqueId]['requestInTime'] = $allRegularizationResult['in_time'];
                                $userallRegularizations[$allRegularizationUniqueId]['requestOutTime'] = $allRegularizationResult['out_time'];
                                $allRegularizationDate = $allRegularizationResult['regularization_date'];
                                $date = date('j', strtotime($allRegularizationDate));
                                $allRegularizationArray[$date]['requestInTime'] = $allRegularizationResult['user_in_time'];
                                $allRegularizationArray[$date]['requestOutTime'] = $allRegularizationResult['user_out_time'];
                                $allRegularizationArray[$date]['requestStatus'] = $allRegularizationResult['status'];
                                array_push($allRegularizationDays,$date);
                            }
                        }

                        $holiday = [];
                        
                        //get holiday for a month
                        $holidaytableName = "holidayslist";
                        $holiday_WHERECondition = "iid = ? AND ((fromdate BETWEEN '$firstDate' AND '$lastDate') OR (todate BETWEEN '$firstDate' AND '$lastDate')) ";
                        $holiday_WHERECondition = $link->prepare("SELECT * FROM ".$holidaytableName." WHERE ".$holiday_WHERECondition." ");
                        $holiday_WHERECondition->bind_param("i",$iid);
                        $holiday_WHERECondition->execute();
                        $holiday_selectQueryResult = $holiday_WHERECondition->get_result();
                        if(mysqli_num_rows($holiday_selectQueryResult) > 0)
                        {
                            while($holidayResult = mysqli_fetch_assoc($holiday_selectQueryResult))
                            {
                                $holidayUniqueId = $holidayResult['uniqueid'];
                                $holiday[$holidayUniqueId]['holidayId'] = $holidayResult['uniqueid'];
                                $holiday[$holidayUniqueId]['holidayTitle'] = $holidayResult['title'];
                                $holiday[$holidayUniqueId]['holidayCode'] = $holidayResult['leavecode'];
                                $holiday[$holidayUniqueId]['holidayFromDate'] = $holidayResult['fromdate'];
                                $holiday[$holidayUniqueId]['holidayToDate'] = $holidayResult['todate'];
                                
                                $holidayStartDate= $holidayResult['fromdate'];
                                $holidayEndDate= $holidayResult['todate'];
                                $startDayCount = date('j', strtotime($holidayStartDate));
                                $endDayCount = date('j', strtotime($holidayEndDate));
                                for($day = $startDayCount; $day <= $endDayCount; $day++)
                                {
                                    array_push($holidayDayCount,$day);
                                    $holidayTitleArray[$day] = $holidayResult['title'];
                                }
                            }
                        }

                            //set default values
                            $week = 1;
                            $extraWorkingCounter = 0;
                            $currentMonthLeaveCounter = 0;
                            $employeeAttandance = [];
                            //compare each week day time with working time
                            for($i = 1; $i <= $lastDay; $i++)
                            {
                                $currentDate = $year."-".$month."-".$i;
                                $weekNumber = date('W', strtotime($currentDate));
                                $weekDay = date('l', strtotime($currentDate));
                                
                                //check for week change
                                if($weekNumber == $firstWeekNumber)
                                {
                                    $weekCount = "week".$week;
                                }
                                else
                                {
                                    // $firstWeekNumber = $weekNumber;
                                    // $week = $week+1;
                                    // $weekCount = "week".$week;

                                    if($firstWeekDay != "Sunday" || ($firstWeekDay == "Sunday" && $weekDay == "Sunday"))
                                    {
                                        $firstWeekNumber = $weekNumber;
                                        $week = $week+1;
                                        $weekCount = "week".$week;
                                    }
                                }
                                $employeeAttandance[$i]["date"] = date('jS M, Y', strtotime($currentDate));
                            
                                $workingDay = $timestructure[$weekDay][$weekCount]['working'];
                                
                                $timeStampInTime = isset($timestructure[$weekDay][$weekCount]['startTime']) ? $timestructure[$weekDay][$weekCount]['startTime'] :"";
                                $timeStampOutTime = isset($timestructure[$weekDay][$weekCount]['endTime']) ? $timestructure[$weekDay][$weekCount]['endTime'] :"";

                                //check employee have leave for current date & update the attendance json
                                if(in_array($i,$daycount))
                                {
                                    $employeeAttandance[$i]['approvedInTime'] = "";
                                    $employeeAttandance[$i]['approvedOutTime'] = "";
                                    $employeeAttandance[$i]['inTime'] = $attendance[$i]['inTime'];
                                    $employeeAttandance[$i]['outTime'] = $attendance[$i]['outTime'];

                                    $leaveTypeId = $leaveTypeArray[$i];
                                    if($leaveDayTypeArray[$i] == "halfday-1" || $leaveDayTypeArray[$i] == "halfday-2" )
                                    {
                                        $currentMonthLeaveCounter += 0.5;
                                    }else{
                                        $currentMonthLeaveCounter++;
                                    }
                                    $employeeAttandance[$i]['leaveTypeId'] = $leaveTypeId;
                                    $employeeAttandance[$i]['leaveStatus'] = $leaveStatusArray[$i];
                                    $employeeAttandance[$i]['leaveReason'] = $leaveReasonArray[$i];
                                    $employeeAttandance[$i]['leaveDayType'] = $leaveDayTypeArray[$i];
                                    $employeeAttandance[$i]['regularizationApproved'] = '';
                                }
                                else
                                {
                                    $employeeAttandance[$i]['approvedInTime'] = "";
                                    $employeeAttandance[$i]['approvedOutTime'] = "";
                                    $employeeAttandance[$i]['inTime'] = $attendance[$i]['inTime'];
                                    $employeeAttandance[$i]['outTime'] = $attendance[$i]['outTime'];
                                    $employeeAttandance[$i]['leave'] = "";
                                    $employeeAttandance[$i]['leaveStatus'] = "";
                                    $employeeAttandance[$i]['leaveDayType'] = "";
                                    $employeeAttandance[$i]['leaveReason'] = "";
                                    $employeeAttandance[$i]['regularizationApproved'] = '';
                                    $employeeAttandance[$i]['holidayDetails'] = '';
                                    $employeeAttandance[$i]['extraWorking'] = '';

                                }
                                
                                //  //check employee have regularization for current date
                                //  if(in_array($i,$allRegularizationDays))
                                //  {
                                //     $employeeAttandance[$i]['regularizationApproved'] = 'Regularization raised';
                                //  }

                                //check employee have regularization for current date & update the attendance json
                                if(in_array($i,$allRegularizationDays))
                                {
                                    $employeeAttandance[$i]['approvedInTime'] = $allRegularizationArray[$i]['requestInTime'];
                                    $employeeAttandance[$i]['approvedOutTime'] = $allRegularizationArray[$i]['requestOutTime'];
                                    $employeeAttandance[$i]['inTime'] = $attendance[$i]['inTime'];
                                    $employeeAttandance[$i]['outTime'] = $attendance[$i]['outTime'];
                                    $regularizationStatus = isset($allRegularizationArray[$i]['requestStatus']) ? $allRegularizationArray[$i]['requestStatus'] : "";
                                    $employeeAttandance[$i]['regularizationStatus'] = isset($allRegularizationArray[$i]['requestStatus']) ? $allRegularizationArray[$i]['requestStatus'] : "";
                                    if($regularizationStatus == 'Approved')
                                    {
                                        $employeeAttandance[$i]['regularizationApproved'] = 'Regularized';
                                    }
                                    else
                                    {
                                        $employeeAttandance[$i]['regularizationApproved'] = 'Regularization';
                                    }

                                    $employeeAttandance[$i]['holidayDetails'] = '';
                                    $employeeAttandance[$i]['extraWorking'] = '';
                                }


                                $extraWorkingInTime = $attendance[$i]['inTime'];
                                $extraWorkingOutTime = $attendance[$i]['outTime'];
                                //check for holiday for day
                                if(in_array($i,$holidayDayCount))
                                {
                                    $employeeAttandance[$i]['holidayDetails'] = $holidayTitleArray[$i];
                                    $employeeAttandance[$i]['extraWorking'] = '';
                                }

                                //check for extra working
                                if($workingDay == 'off')
                                {
                                    if(strtotime($extraWorkingInTime) == strtotime("00:00:00") && strtotime($extraWorkingOutTime)== strtotime("00:00:00"))
                                    {
                                        $employeeAttandance[$i]['extraWorking'] = 'Off';
                                    }
                                    else
                                    {
                                        if(!empty($extraWorkingInTime) || !empty($extraWorkingOutTime))
                                        {
                                            $employeeAttandance[$i]['extraWorking'] = 'Extra working';
                                            $extraWorkingCounter++;
                                        }
                                    }
                                    $weeklyOff++;
                                }

                                //check if employee came late or leave early
                                //get max late in time
                                $delayMins = isset($leaves_consideration["delayMins"]) && !empty($leaves_consideration["delayMins"])? $leaves_consideration["delayMins"] :0;                       
                                $earlyGoingMins = isset($leaves_consideration["earlyGoingMins"]) && !empty($leaves_consideration["earlyGoingMins"]) ? $leaves_consideration["earlyGoingMins"] :0;
                                

                                $maxLateGoingTime = strtotime("+$delayMins minutes", strtotime($timeStampInTime));
                                $minearlyGoingTime = strtotime("-$earlyGoingMins minutes", strtotime($timeStampOutTime));
                                
                                $employeeAttandance[$i]['isLateMark'] = "";
                                $employeeAttandance[$i]['isEarlyGoing'] = "";
                                $employeeAttandance[$i]['workingDay'] = $workingDay == "off" ? "Off" :"";

                                $employeeInTime = $employeeAttandance[$i]["inTime"];
                                $employeeOutTime = $employeeAttandance[$i]["outTime"];
                                if($employeeAttandance[$i]["regularizationApproved"] == "Regularized")
                                {
                                    $employeeInTime = $employeeAttandance[$i]['approvedInTime'];
                                    $employeeOutTime = $employeeAttandance[$i]['approvedOutTime'];
                                }                           

                                if(!empty($employeeInTime))
                                {

                                    if(strtotime($employeeInTime) > $maxLateGoingTime)
                                    {   
                                        $employeeAttandance[$i]['isLateMark'] = "Late Coming";
                                    }else{
                                        $employeeAttandance[$i]['isLateMark'] = "On Time";
                                    }
                                }
                                if(!empty($employeeOutTime))
                                {
                                    if(strtotime($employeeOutTime) < $minearlyGoingTime)
                                    {   
                                        $employeeAttandance[$i]['isEarlyGoing'] = "Early Going";
                                    }else{
                                        $employeeAttandance[$i]['isEarlyGoing'] = "On Time";
                                    }
                                }
                                
                                //get the duration diff in in time and out time
                                $employeeWorkingDuration = strtotime($employeeOutTime)- strtotime($employeeInTime);
                                //gey duration according to policy
                                $policyWorkingDuration = strtotime($timeStampOutTime)- strtotime($timeStampInTime);
                                if($employeeWorkingDuration< $policyWorkingDuration)
                                {
                                    $employeeAttandance[$i]['isLessWorkDuration'] = true;

                                    //if isLessWorkDuration then add half day leave for that day
                                    $insertLeaveDayType = "";
                                    if($employeeAttandance[$i]['isEarlyGoing'] == "Early Going")
                                    {
                                        $insertLeaveDayType = "halfday-2";
                                    }
                                    if($employeeAttandance[$i]['isLateMark'] == "Late Coming")
                                    {
                                        $insertLeaveDayType = "halfday-1";
                                    }
                                    $employeeAttandance[$i]['insertLeaveDayType'] = $insertLeaveDayType;
                                    //CHECK IF LEAVE OR HOLIDAY IS ALREADY ADDED ON THE DAY
                                    if(!empty($insertLeaveDayType) && empty($employeeAttandance[$i]['holidayDetails']) && empty($employeeAttandance[$i]['leaveTypeId']) && $employeeAttandance[$i]['workingDay'] != "Off")
                                    {
                                        $leaveReason = "Auto half day leave"; $generatedFileName = "";
                                        $insetLeaveType = $autoLeaveType; $status = "Approved";
                                        $approverIds = ""; $leaveDayCount = 0.5;
                                        if($totalPaidLeavesCount > 0)
                                        {
                                            $paidleavesCount = 0;
                                            $totalPaidLeavesCount -= 0.5;
                                            $unpaidleavesCount = 0.5;
                                        }else{
                                            $paidleavesCount = 0.5;
                                            $unpaidleavesCount = 0;
                                        }
                                        $approveorderno = ""; 
                                        

                                        $tableName = "faculty_leaves";
                                        $leaveWHERECondition = "InstiId = ? AND FacultyId = ?  AND (StartDate BETWEEN '$currentDate' AND '$currentDate' OR EndDate BETWEEN '$currentDate' AND '$currentDate') ";
                                        //check is there any applied leave for the user entere leave start date & end date
                                        $leaveselectQuery = $link->prepare("SELECT * FROM ".$tableName." WHERE ".$leaveWHERECondition." ");
                                        $leaveselectQuery->bind_param("ii",$iid,$employeeId);
                                        $leaveselectQuery->execute();
                                        $leaveselectQueryResult = $leaveselectQuery->get_result();
                                
                                        if(mysqli_num_rows($leaveselectQueryResult) > 0)
                                        {
                                            $employeeAttandance[$i]['isDulicateEmployee'] = "true";
                                        }
                                        else
                                        {
                                            
                                        
                                            //INSERT LEAVE                                         
                                            $columnNameString = "InstiId,FacultyId,StartDate,EndDate,leave_day_type,LeaveType,leave_reason,Status,to_be_approved_by,attachment_json,approver_order_number,leave_day_count,added_by,paid_leaves_count,unpaid_leaves_count";
                                            
                                            $insertQuery = $link->prepare("INSERT INTO ".$tableName." (".$columnNameString.") VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ");
                                            $insertQuery->bind_param("iisssissssisiss",$iid,$employeeId,$currentDate,$currentDate,$insertLeaveDayType,$insetLeaveType,$leaveReason,$status,$approverIds,$generatedFileName,$approveorderno,$leaveDayCount,$facultyId,$paidleavesCount,$unpaidleavesCount);
                                            $insertQuery->execute();
                                            $insertQueryResult = $insertQuery->get_result();

                                            if($insertQuery)
                                            {
                                                $requestId = mysqli_insert_id($link);
                                                $employeeAttandance[$i]['requestInsertedId'] = $requestId;
                                            }
                                        }
                                    }

                                }else{
                                    $employeeAttandance[$i]['isLessWorkDuration'] = false;
                                }

                                $employeeAttandance[$i]['policyWorkingDuration'] = gmdate("H:i", $policyWorkingDuration);
                                $employeeAttandance[$i]['employeeWorkingDuration'] = gmdate("H:i", $employeeWorkingDuration);
                            }

                        $response['data'][$uniqueId]['MonthlyAttendance'] = $employeeAttandance;                    
                    }
                }
            }
            else
            {
                $response['status'] = 'fail';
                $response['message'] = 'Failed to get attendance as leave policy is not assigned.';
            }
        }
        else
        {
            $response['success'] = 'false';
            $response['message'] = 'Something went wrong. Please logout of your account and then re-try again. If issue persists, then please send screenshot on email info@vmedulife.com';
        }
        return $response;
    }
