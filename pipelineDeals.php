<?php
session_start();
require 'vendor/autoload.php';

class PipelineDealsQuery{
	private $apiObject;
	private $queryFields = array('totals'=>'true');

	function __construct($key) {
      	$this->apiObject = new Weblab\Pipelinedeals($key);
    }

    public function setPage($pageNumber){
    	$this->queryFields['page']=$pageNumber;
    	return $this;
    }
    public function setPerPage($numberOfResults){
    	$this->queryFields['per_page']=$numberOfResults;
    	return $this;
    }
    public function setTotals($includeTotalsBool){
    	$this->queryFields['totals']=$includeTotalsBool;
    	return $this;
    }
    public function setSorting($sortType){
    	$this->queryFields['sort']=$sortType;
    	return $this;
    }
    public function setOwner($ownerId){
    	$this->queryFields['deal_owner']=$ownerId;
    	return $this;
    }
    public function setSource($sourceName){
    	$this->queryFields['deal_source']=$sourceName;
    	return $this;
    }
    public function setStage($stageParam){
    	$stages = $this->getStages();
    	$pendingStages = array();
    	foreach ($stages as $stage) {
    		if(strtolower($stage['name']) == 'won'){$wonId=$stage['id'];}
    		else if(strtolower($stage['name']) == 'lost'){$lostId=$stage['id'];}
    		else{$pendingStages[]=$stage['id'];}
    	}
    	switch ($stageParam) {
    		case 'won':
    			$this->queryFields['deal_stage']=$wonId;
    			break;
    		case 'lost':
    			$this->queryFields['deal_stage']=$lostId;
    			break;
    		case '':
    			unset($this->queryFields['deal_stage']);
    			break;
    		
    		default:
    			break;
    	}
    	
    	return $this;
    }
    public function setClosedRange($dateRange){ //format: YYYY-MM-DD
    	$this->queryFields['deal_closed_time']["from_date"]=$dateRange[0];
    	$this->queryFields['deal_closed_time']["to_date"]=$dateRange[1];
    	return $this;
    }
    public function setCreatedRange($dateRange){ //format: YYYY-MM-DD
    	$this->queryFields['deal_created']["from_date"]=$dateRange[0];
    	$this->queryFields['deal_created']["to_date"]=$dateRange[1];
    	return $this;
    }
    public function getUsers(){
    	$response = $this->apiObject->call('/users.json')->entries;
    	$users = array();
    	foreach ($response as $user) {
    		$users[]= array('name' => $user->first_name." ".$user->last_name ,'id' => $user->id);
    	}
    	return $users;
    }
    public function getStages(){
    	$response = $this->apiObject->call('/admin/deal_stages.json')->entries;
    	$stages = array();
    	foreach ($response as $stage) {
    		$stages[]= array('name' => $stage->name,'id' => $stage->id);
    	}
    	//echo var_dump($stages);
    	return $stages;
    }
    public function getSources(){
        $response = $this->apiObject->call('/admin/lead_sources.json')->entries;
        $sources = array();
        foreach ($response as $source) {
            $sources[]= array('name' => $source->name,'id' => $source->id);
        }
        //echo var_dump($stages);
        return $sources;
    }
    public function createQuery(array $parameters=null, $currentQuery=''){
    	if($currentQuery==''){$currentQuery.="?";}
    	if(is_null($parameters)){$parameters=$this->queryFields;}
    	foreach ($parameters as $param => $value) {
    		$currentQuery!='?'?$currentQuery.="&":false;
    		switch ($param) {
    			case 'page':
    				$currentQuery.="page=".$value;
    				break;
    			case 'per_page':
    				$currentQuery.="per_page=".$value;
    				break;
    			case 'totals':
    				$currentQuery.="totals=".$value;
    				break;
    			case 'sort':
    				$currentQuery.="sort=".$value;
    				break;
    			default:
    				if(is_array($value)){
    					foreach ($value as $subValue => $data) {
    						$currentQuery.='conditions['.$param.']['.$subValue.']='.$data;
                            $currentQuery.='&';
    					}
    				}
    				else{
    					$currentQuery.="conditions[".$param."]=".$value;
    					break;
    				}
    		}	
    	}
        syslog(LOG_INFO, $currentQuery);
    	//echo var_dump($currentQuery);
    	return $currentQuery;
    }
    public function get(){
    	//echo "deals.json".$this->createQuery()."<br>";
    	return $this->apiObject->call("deals.json".$this->createQuery());
    }
    
}
class PipelineDealsStats{
	private $apiKey;
	
	function __construct($key) {
      	$this->apiKey = $key;
    }

	
    public function getDeals_byEmployee($startDate = null,$endDate = null,$dateType=null,$category = null,$source = null,$stage=null){
        $logArray = array('startDate'=>$startDate,'endDate'=>$endDate,'dateType'=>$dateType,'category'=>$category,'source'=>$source,'stage'=>$stage,'companyId'=>$_SESSION['companyId']);
        $memResp = new Memcache;
        if ($memResp->get(json_encode($logArray))!=null) {
            return json_decode($memResp->get(json_encode($logArray)),true);
        }
        syslog(LOG_INFO, print_r($logArray,true));
    	$query = new PipelineDealsQuery($this->apiKey);
    	$users = $query->getUsers();
    	$breakDown = array();
    	foreach ($users as $user) {
    		$result=$query->setOwner($user['id'])->setPerPage(1);
    		if($startDate!=null && $endDate!=null){
    			switch ($dateType) {
    				case 'closed':
    					$result->setClosedRange(array($startDate,$endDate));
    					break;
    				case 'created':
    					$result->setCreatedRange(array($startDate,$endDate));
    					break;
    				default:
    					$result->setClosedRange(array($startDate,$endDate));
    					break;
    			}
    			
    		}
    		if($source!=null){$result->setSource($source);}
    		if($stage!=null){$result->setStage($stage);}
    		if($stage=='pending'){
    			$result->setStage('won');
    			$ret = $result->get();
    			$wonNumberTotal = $ret->pagination->total;
    			$wonValueTotal = $ret->totals->deal_value;
    			
    			$result->setStage('lost');
    			$ret = $result->get();
    			$lostNumberTotal= $ret->pagination->total;
    			$lostValueTotal = $ret->totals->deal_value;

    			$result->setStage('');
    			$ret = $result->get();
    			$totNumberTotal= $ret->pagination->total;
    			$totValueTotal = $ret->totals->deal_value;


    			$breakDown['users'][$user['name']] = array(
    			'numberOfDeals' => $totNumberTotal-$wonNumberTotal-$lostNumberTotal,
    			'valueOfDeals' => $totValueTotal-$wonValueTotal-$lostValueTotal
    			);
    			// echo var_dump($breakDown);
    			// echo "<br><br><br><br>";
    		}
    		else{
	    		$result = $result->get();
	    		$breakDown['users'][$user['name']] = array(
	    			'numberOfDeals' => $result->pagination->total,
	    			'valueOfDeals' => $result->totals->deal_value
	    		);
	    	}

    	}
    	$totals = array();
    	foreach ($breakDown['users'] as $user) {
    		$totals['numberOfDeals']+=$user['numberOfDeals'];
    		$totals['valueOfDeals']+=$user['valueOfDeals'];
    	}
    	$breakDown['stats']['totals']=$totals;
        $memResp->set(json_encode($logArray),json_encode($breakDown));
    	return $breakDown;
    }
    

}





?>
