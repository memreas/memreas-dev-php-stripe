/////////////////////////////////
// Author: John Meah
// Copyright memreas llc 2013
/////////////////////////////////

//var base_url = 'http://memreasdev.elasticbeanstalk.com/eventapp_zend2.1/webservices/index.php?';
var base_url = 'http://192.168.1.9/eventapp_zend2.1/webservices/index_json.php';
var isUserNameValid = false;

onEmailFocusOut = function () {
    if (!isValidEmail($('#inEmail').val())) {
        alert("Please check your email address");
    }
}
onUserNameFocusOut = function () {
    jQuery.checkUserName();
}

function isValidEmail($email) {
  var emailReg = /^([\w-\.]+@([\w-]+\.)+[\w-]{2,4})?$/;
  if( !emailReg.test( $email ) ) {
    return false;
  } else {
    return true;
  }
}

jQuery.checkUserName = function () {
	var url_check_username = base_url + "action=checkusername";
	var xml_check_username;
	var json_check_username;
	var data;
	var xml_register;

	xml_check_username = "<xml><checkusername><username>" + $('#inUserName').val() + "</username></checkusername></xml>";
	json_check_username = '{"xml": {"checkusername": { "username": "' + $('#inUserName').val() + '" }}}';
	data = '{"action": "checkusername", "type":"jsonp", "json":{"xml": { "checkusername": {"username": "' + $('#inUserName').val() + '"}}}}';

	$.ajax( {
	  type:'post', 
	  url: base_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	//alert("Success ----> " + JSON.stringify(json));
	  	//sample json -> {"checkusernameresponse":{"status":"Failure","message":"Username is not taken","isexist":"No"}}
	  	//alert("json.checkusernameresponse.status ----> " + json.checkusernameresponse.status);
	  	//alert("json.checkusernameresponse.isexist ----> " + json.checkusernameresponse.isexist);
	  	if (json.checkusernameresponse.isexist == "No") {
//	  		alert("Returning true");
	  		return true;
	  	} else {
	  		alert("Username is taken - please choose another");
	  		return false;
	  	}
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	alert(textStatus);
       	alert(errorThrown);
	  }
	});
	return false;
}


jQuery.logout = function () {
error_log("Inside jQuery.logout script");
	var logout_url = '/index/logout';
	var url_check_username = base_url + "action=checkusername";
	var xml_check_username;
	var json_check_username;
	var data;
	var xml_register;

	$.ajax( {
	  type:'post', 
	  url: logout_url,
	  //dataType: 'jsonp',
	  //data: 'json=' + data,
	  success: function(result){
  		alert ("Finished Logout...");
  		return true;
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	alert(textStatus);
       	alert(errorThrown);
	  }
	});
	return false;
}


