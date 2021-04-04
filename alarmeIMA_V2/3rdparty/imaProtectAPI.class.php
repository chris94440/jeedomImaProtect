<?php
class imaProtectAPI {

	//Id of equipment
	private $id;
	
	//http sessionId
	private $sessionID;
	
  	//Expiration date for sessionID
  	private $sessionIDExpires;
  
	//http xcsrftoken
	private $xcsrfToken;
  
  	//Expiration date for token xcsrf
  	private $xcsrfTokenExpires;

	//username of ima protect account
	private $username;
	
	//password of ima protect account
	private $password;
	
	//activation code of ima protect account
	private $activationCode;
	
	//psk key ==> installation identification
	private $pk;
	
	//list of rooms and their pk id
	public $rooms;
	
		
	public function __construct($username,$password,$activationCode,$id) {
        log::add('alarmeIMA_V2', 'debug', "			==> constructor of class imaProtectAPi - Start");
		$this->username = $username;
		$this->password = $password;
		$this->activationCode=$activationCode;	
		$this->id=$id;
		$this->sessionID = null;
		$this->xcsrfToken=null;
		$this->pk=null;	
      	$this->sessionIDExpires=null;
      	$this->xcsrfTokenExpires=null;
		$this->rooms=null;
	}


	public function __destruct()  {
	
	}
 
	//Execute all https request to Ima protect API
	private function doRequest($url, $data, $method, $headers) {		
      	log::add('alarmeIMA_V2', 'debug', "			==> doRequest");
      	log::add('alarmeIMA_V2', 'debug', "				==> Params : $url | $data | $method | ".json_encode($headers));
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL,				$url);
      	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($curl, CURLOPT_HEADER, 			true);
      
		//voir la gestion de $cookie
		switch($method)  {
			case "GET":
            case "DELETE":
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, 	true);
				curl_setopt($curl, CURLOPT_HTTPHEADER, 		$headers);
				break;
			case "POST":
				curl_setopt($curl, CURLOPT_POST, 				1);
				curl_setopt($curl, CURLOPT_POSTFIELDS, 		$data);
				//curl_setopt($curl, CURLOPT_HEADER, 			true);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, 	true);
				curl_setopt($curl, CURLOPT_HTTPHEADER, 		$headers);
				break;
			case "PUT":
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_ENCODING, "");
                curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
                curl_setopt($curl, CURLOPT_TIMEOUT, 0);
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
				break;
		}
				
		$resultCurl = curl_exec($curl);
        $httpRespCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
      	$header = substr($resultCurl, 0, $header_size);
		$body = substr($resultCurl, $header_size);
      	curl_close($curl);
      
      	if ($method == "POST") {
			$cookies = array();
          	preg_match_all('/^Set-Cookie:\s*(.*?);(.*?);/mi', $header, $matches);
            foreach($matches[1] as $item) {
                parse_str($item, $cookie);
                $cookies = array_merge($cookies, $cookie);
            }
          	$this->sessionID = $cookies['sessionid'];
            $this->xcsrfToken=$cookies['csrftoken'];
			
          	$int=0;
          	foreach($matches[2] as $item) {
               	parse_str($item, $id);
               	if ($int == 0) {
                  $this->sessionIDExpires=$id['expires'];
                }
               	if ($int == 1) {
                  $this->xcsrfTokenExpires=$id['expires'];
                }
               	++$int;
            }
        }
		
      	log::add('alarmeIMA_V2', 'debug', "				==> Response");
      	log::add('alarmeIMA_V2', 'debug', "					# Code Http : $httpRespCode");
      	log::add('alarmeIMA_V2', 'debug', "					# Response  : ".$resultCurl);
      	log::add('alarmeIMA_V2', 'debug', "					# Body  : ".$body);
      	log::add('alarmeIMA_V2', 'debug', "					# Header  : ".$header);
				
		return array($httpRespCode, $body);
	}


	private function setHeaders()   {				//Define headers
		
		$headers = array();
      	if (isset($this->sessionID) && !empty($this->sessionID)) {
          if (isset($this->xcsrfToken) && !empty($this->xcsrfToken)) {
            $headers[] = sprintf('Cookie: sessionid=%s; csrftoken=%s', $this->sessionID,$this->xcsrfToken);
          } else {
            $headers[] = sprintf('Cookie: sessionid=%s', $this->sessionID);
          }
        }
      	
      	if (isset($this->xcsrfToken) && !empty($this->xcsrfToken)) {
          $headers[]="X-CSRFToken: ".$this->xcsrfToken;
        }
      	$headers[] = "Referer: https://pilotageadistance.imateleassistance.com";
      	$headers[] = "Content-Type: application/x-www-form-urlencoded";
     	return $headers;
	}	


	private function setParams($request,$pwd) {			//Set params for https request to Verisure Cloud
		
		switch($request)  {
			case "LOGIN":
				$params = array( 'username' => $this->username, 'password' => $this->password );
				break;		
          	case "ALARM_OFF":
            	$params = array( 'status' => 'off','code' => $pwd);
            	break;
			case "ALARM_ON":
            	$params = array( 'status' => 'on');
            	break;
			case "ALARM_PARTIAL":
            	$params = array( 'status' => 'partial');
            	break;
		}
		$params_string = http_build_query($params);
		return $params_string;
    }
	
	private function cookieIsValid($cookieExpiredDate){
		if (isset($cookieExpiredDate)) {
			$diff=round(strtotime($cookieExpiredDate)-time(),1);
			if ($diff > 10) {
				log::add('alarmeIMA_V2', 'debug', "				* sessionID is valid");
				return true;
			} else {
				log::add('alarmeIMA_V2', 'debug', "				* sessionID is expired");
				return false;
			}
		} else {
			log::add('alarmeIMA_V2', 'debug', "			==> Expiration of sessionID is missing");
			return false;
		}
	}
  
	private function storeContextToTmpFile($contextArray){
		log::add('alarmeIMA_V2', 'debug', "			==> storeContextToTmpFile ");
		if(isset($contextArray)){
			if (isset($this->id)) {
				$tmpFile=sys_get_temp_dir()."/alarmeIMA_V2_session_".$this->id;
				$fd=fopen($tmpFile, "w");
				fputs($fd,json_encode($contextArray));
				fclose($fd);
			} else {
				//ToDo Log error
				log::add('alarmeIMA_V2', 'debug', "			==> Equipment ID null ... impossible to follow !!!");
				return false;
			}
		} else {
			log::add('alarmeIMA_V2', 'debug', "			==> No datas send to store in temporary file !!!");
			return false;
		}
	}
	
	
	//Recover datas from config file
	public function getContextFromTmpFile(){
		if (isset($this->id)) {
          	$tmpFile=sys_get_temp_dir()."/alarmeIMA_V2_session_".$this->id;
			if (is_file($tmpFile)) {
				$fd=fopen($tmpFile, "r");
              	$readLine=fgets($fd);

				if (isset($readLine)) {
                  	log::add('alarmeIMA_V2', 'debug', "			==> Read config file .. datas $readLine");
					$arrayDecode=json_decode($readLine,true);
					if (isset($arrayDecode["sessionID"])) {
						$this->sessionID=$arrayDecode["sessionID"];
					}
					if (isset($arrayDecode["sessionIDExpires"])) {
						$this->sessionIDExpires=$arrayDecode["sessionIDExpires"];
					}
					if (isset($arrayDecode["xcsrfToken"])) {
						$this->xcsrfToken=$arrayDecode["xcsrfToken"];
					}
					if (isset($arrayDecode["xcsrfTokenExpires"])) {
						$this->xcsrfTokenExpires=$arrayDecode["xcsrfTokenExpires"];
					}
					if (isset($arrayDecode["pk"])) {
                      $this->pk=$arrayDecode["pk"];
					}
					
					if (isset($arrayDecode["rooms"])) {
                      $this->rooms=$arrayDecode["rooms"];
					}
                  	
                  	return $this->cookieIsValid($this->sessionIDExpires);
				} else {
					log::add('alarmeIMA_V2', 'debug', "			==> No datas read in temporary file !!!");
					return false;
				}
              	fclose($fd);
				return true;
			} else {
				log::add('alarmeIMA_V2', 'debug', "			==> No config file ... init to call");
				return false;
			}
		} else {
			log::add('alarmeIMA_V2', 'debug', "			==> Equipment ID null ... impossible to follow !!!");
			return false;
		}
	}
    
  	private function manageErrorMessage($httpCode,$error) {
      	log::add('alarmeIMA_V2', 'debug', "			Function manageErrorMessage : " . $error . "|" .$httpCode);
      	$errorMessage="Unknown error";
		if (!$this->IsNullOrEmpty($error)) {
			$errorMessage=$error;
			$errorArray=json_decode($error,true);
			if (!$this->IsNullOrEmpty($errorArray["localizable_title"])) {
				$errorMessage=$errorArray["localizable_title"];
				if (!$this->IsNullOrEmpty($errorArray["localizable_description"])) {
					$errorMessage.=  " ==> " . $errorArray["localizable_description"];
				}
				if (!$this->IsNullOrEmpty($errorArray["error_code"])) {
					$errorMessage.= "(return code : " .$errorArray["error_code"] . ")";
				}
			}
		}
		
		if (!$this->IsNullOrEmpty($httpCode)) {
			$errorMessage .= "(". $httpCode . ")";
		}
		
      	log::add('alarmeIMA_V2', 'debug', "			==> errorMessage : " . $errorMessage);
      	return $errorMessage;
    }
  
  	private function IsNullOrEmpty($input){
    	return (!isset($input) || trim($input)==='');
	}
	
	//Log to IMA Account
	public function Login()  {			
		$urlLogin ="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/keychain/web-login/";
		$method = "POST";
		$params = $this->setParams("LOGIN",null);
		$headers = $this->setHeaders();
		list($httpcode, $result) = $this->doRequest($urlLogin,$params, $method, $headers);
      	if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        }
	}
	
	//Get IMA other info like room id
	public function getOtherInfo() {
		log::add('alarmeIMA_V2', 'debug', "			==> getOtherInfo ");
		$urlOtherInfo="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/". $this->pk . "/?_=".time()."000";
		$method = "GET";
		$headers = $this->setHeaders();
      	list($httpcode, $result) = $this->doRequest($urlOtherInfo,"", $method, $headers);
      
      	if (isset($httpcode) and $httpcode >= 400 ) {
			throw new Exception($result);
        } else {
			$roomsInfo=$this->readResponseForGetOtherInfo($result);
			if(isset($roomsInfo)) {
				$this->rooms=$roomsInfo;
			     $contextArray =	array(
                    "sessionID" => $this->sessionID,
                    "sessionIDExpires" => $this->sessionIDExpires,
                    "xcsrfToken" =>  $this->xcsrfToken,
                    "xcsrfTokenExpires" => $this->xcsrfTokenExpires,
                    "pk" => $this->pk,
					"rooms"=> $this->rooms
                );
                $this->storeContextToTmpFile($contextArray);
				
                return true;
            } else {
				throw new Exception("Error extracting rooms informations");
			}
        }
	}
	
	//Recover pk of rooms
	private function readResponseForGetOtherInfo($result) {
		log::add('alarmeIMA_V2', 'debug', "				==> readResponseForGetOtherInfo - Start");
		$response= array();
		$resultArr=json_decode($result,true);
		
		foreach($resultArr as $event) {
          foreach($event as $key=>$value){
            if ($key == "fields") {
				foreach($value as $detailEventkey=>$detailEventValue) {
					if ($detailEventkey == "device_set") {
						foreach($detailEventValue as $equipmentKey=>$equipmentValue) {
							log::add('alarmeIMA_V2', 'debug', "					==> name : " .$equipmentValue["fields"]["name"] . "| pk : " .$equipmentValue["pk"]);
							array_push($response,array("room"=>$equipmentValue["fields"]["name"],"pk"=>$equipmentValue["pk"]));
						}
						
					}
				  }     
            }
          }	
        }
		log::add('alarmeIMA_V2', 'debug', "				==> readResponseForGetOtherInfo - End -> response :  ".json_encode($response));
		return $response;
	}
	
	
	//Get IMA info in order to retrieve id psk of installation and other informations
	public function getIMAAccountInfo(){
      	log::add('alarmeIMA_V2', 'debug', "			==> getIMAAccountInfo ");
		$urlImaAccount="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/me/?_=".time()."000";
		$method = "GET";
		$headers = $this->setHeaders();
      	list($httpcode, $result) = $this->doRequest($urlImaAccount,"", $method, $headers);
      
      	if (isset($httpcode) and $httpcode >= 400 ) {
			throw new Exception($this->manageErrorMessage($httpcode,$result));
        } else {
			$this->pk=($this->getPK($result));
			if (!isset($this->pk)) {
              	log::add('alarmeIMA_V2', 'debug', "				# key pk empty");
				throw new Exception("key pk empty");
            }
          	
        }	
	}
  
  	private function getPK($input) {
      log::add('alarmeIMA_V2', 'debug', "			==> getPK - input : $input");
      $resultArr=json_decode($input,true);
      
      //ToDo ==> code à améliorer
      $pk=$resultArr[0]["fields"]["contract_set"][0]["fields"]["site"]["fields"]["hss_pk"];
      $brandLogo=$resultArr[0]["fields"]["contract_set"][0]["fields"]["site"]["fields"]["branding_logo_url"];
      $brandName=$resultArr[0]["fields"]["contract_set"][0]["fields"]["site"]["fields"]["partner_name"];
      //$userAdr=[0]["fields"]["contract_set"][0]["fields"]["site"]["fields"]["address_1"] . " " . [0]["fields"]["contract_set"][0]["fields"]["site"]["fields"]["city_name"] . " " . [0]["fields"]["contract_set"][0]["fields"]["site"]["fields"]["address_1"] . " ( " . [0]		["fields"]["contract_set"][0]["fields"]["site"]["fields"]["postal_code"];
      log::add('alarmeIMA_V2', 'debug', "			    ## pk : $pk");
      log::add('alarmeIMA_V2', 'debug', "			    ## Brand logo : $brandLogo");
      log::add('alarmeIMA_V2', 'debug', "			    ## Brand name : $brandName");
      //log::add('alarmeIMA_V2', 'debug', "			    ## User adresse : $userAdr");
      return $pk;
    }
	
	//Get alarm status
	public function getAlarmStatus() {
      	log::add('alarmeIMA_V2', 'debug', "			==> getAlarmStatus ");
		$urlAlarmStatus="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/". $this->pk . "/status/?_=".time()."000";
		$method = "GET";
		$headers = $this->setHeaders();
		list($httpcode, $result) = $this->doRequest($urlAlarmStatus,"", $method, $headers);
      
      	if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        } else {
      		$resultArr=json_decode($result,true);
      		$alarmeStatut=$resultArr[0]["fields"]["status"];
      		return $alarmeStatut;   

        }
	}
  
	//Set alarm to off
	public function setAlarmToOff($pwd) {
      	log::add('alarmeIMA_V2', 'debug', "			==> setAlarmToOff");
		$urlAlarmStatus="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/". $this->pk . "/status/?_=".time()."000";
		$method = "PUT";
      	$params = $this->setParams("ALARM_OFF",$pwd);
		$headers = $this->setHeaders();
		list($httpcode, $result) = $this->doRequest($urlAlarmStatus,$params, $method, $headers);
      	//$httpcode=400;
      	//$result="Bad password";
      	if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        }
	}
	
	//set alarm to on
	public function setAlarmToOn() {
      	log::add('alarmeIMA_V2', 'debug', "			==> setAlarmToOn");
		$urlAlarmStatus="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/". $this->pk . "/status/?_=".time()."000";
		$method = "PUT";
      	$params = $this->setParams("ALARM_ON",null);
		$headers = $this->setHeaders();
		list($httpcode, $result) = $this->doRequest($urlAlarmStatus,$params, $method, $headers);
      	if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        }
	}
  
	//Set alarm to partial
	public function setAlarmToPartial() {
      	log::add('alarmeIMA_V2', 'debug', "			==> setAlarmToPartial");
		$urlAlarmStatus="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/". $this->pk . "/status/?_=".time()."000";
		$method = "PUT";
      	$params = $this->setParams("ALARM_PARTIAL",null);
		$headers = $this->setHeaders();
		list($httpcode, $result) = $this->doRequest($urlAlarmStatus,$params, $method, $headers);
      	if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        }
	}
	
	
	//Get alarm events
	public function getAlarmEvent(){
      	log::add('alarmeIMA_V2', 'debug', "			==> getAlarmEvent ");
		$urlEvents="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/". $this->pk . "/events/?_=".time()."000";
		$method = "GET";
		$headers = $this->setHeaders();
      	list($httpcode, $result) = $this->doRequest($urlEvents,"", $method, $headers);
      	
		if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        } else {
			return $result;
		}

	}
  
	//Get selected picture
  	public function getPictures($pictureUrl) {
      	log::add('alarmeIMA_V2', 'debug', "			==> getPictures : $pictureUrl");
		$urlGetPicture="https://pilotageadistance.imateleassistance.com$pictureUrl";
		$method = "GET";
		$headers = $this->setHeaders();
      	list($httpcode, $result) = $this->doRequest($urlGetPicture,"", $method, $headers);
      	
      	if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        } else {
			return $result;
		}
    }
  
  //Delete selected picture
  	public function deletePictures($picture) {
      	log::add('alarmeIMA_V2', 'debug', "			==> deletePictures : $pictureUrl");
		$urlDeletePictures="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/". $this->pk . "/captures/$picture";
		$method = "DELETE";
		$headers = $this->setHeaders();
      	list($httpcode, $result) = $this->doRequest($urlDeletePictures,"", $method, $headers);
      	
      	if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        } else {
			return $result;
		}
    }
  
	//Get camera snapshot of alarm
	public function getCamerasSnapshot() {
      	$urlCamera="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/". $this->pk . "/captures/?_=".time()."000";
		$method = "GET";
		$headers = $this->setHeaders();
		list($httpcode, $result) = $this->doRequest($urlCamera,"", $method, $headers);
      	
      	if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        } else {
			return $result;
		}
	}

	//Get camera snapshot of alarm
	public function takeSnapshot($roomID) {
      	log::add('alarmeIMA_V2', 'debug', "			==> takeSnapshot : $roomID");
		$urlTakeSnapshot="https://pilotageadistance.imateleassistance.com/proxy/api/1.0/hss/devices/$roomID/captures/";
		$method = "POST";
		$headers = $this->setHeaders();
      	list($httpcode, $result) = $this->doRequest($urlTakeSnapshot,"", $method, $headers);
      	
      	if (isset($httpcode) and $httpcode >= 400 ) {
          	throw new Exception($this->manageErrorMessage($httpcode,$result));
        } else {
			return $result;
		}
    }
}

?>