/////////////////////////////////
// Author: John Meah
// Copyright memreas llc 2013
/////////////////////////////////

//var base_url = 'http://memreasdev.elasticbeanstalk.com/eventapp_zend2.1/webservices/index.php?';
var stripe_url = '/stripe/storeCard';
var stripeListCards_url = '/stripe/listCards';
var stripeDeleteCards_url = '/stripe/deleteCards';
var stripeAddValue_url = '/stripe/addValue';
var stripeDecrementValue_url = '/stripe/decrement';
var stripeAccountHistory_url = '/stripe/accounthistory';
var stripeAddSeller_url = '/stripe/addSeller';
var stripeListMassPayee_url = '/stripe/listMassPayee';
var stripePayoutMassPayees_url = '/stripe/payoutMassPayees';
var stripeSubscribe_url = '/stripe/subscribe';

//onEmailFocusOut = function () {
//    if (!isValidEmail($('#inEmail').val())) {
//        alert("Please check your email address");
//    }
//}
//onUserNameFocusOut = function () {
//    jQuery.checkUserName();
//}

//function isValidEmail($email) {
//  var emailReg = /^([\w-\.]+@([\w-]+\.)+[\w-]{2,4})?$/;
//  if( !emailReg.test( $email ) ) {
//    return false;
//  } else {
//    return true;
//  }
//}

/////////////////////
// Add Seller
/////////////////////
jQuery.paypalAddSeller = function () {

	var obj = new Object();
	obj.user_name = $("#add_seller_user_name").val();
	obj.stripe_email_address = $("#add_seller_paypal_email_address").val();
	obj.first_name = $("#add_seller_first_name").val();
	obj.last_name = $("#add_seller_last_name").val();
	obj.address_line_1 = $("#add_seller_address_line_1").val();
	obj.address_line_2 = $("#add_seller_address_line_2").val();
	obj.city = $("#add_seller_city").val();
	obj.state = $("#add_seller_state").val();
	obj.zip_code = $("#add_seller_zip_code").val();
    var json_paypalAddSeller = JSON.stringify(obj);
    var data = "";

    data = '{"action": "paypalAddSeller", ' +
    '"type":"jsonp", ' +
    '"json": ' + json_paypalAddSeller  +
    '}';

	//var results += json_paypalAddSeller;
	$("#add_seller_form_results").val(json_paypalAddSeller, null, '\t');

	$.ajax( {
	  type:'post',
	  url: stripeAddSeller_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $("#add_seller_form_results").val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	$("#add_seller_form_results").val(req_resp);
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
	  	//alert("Inside error jqXHR...");
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	//alert(textStatus);
       	//alert(errorThrown);
	  }
	});
	return false;
}

/////////////////////
// Store Card
/////////////////////
jQuery.stripeStoreCard = function () {

	var obj = new Object();
	obj.first_name = $("#first_name").val();
	obj.last_name = $("#last_name").val();
	obj.credit_card_type = $("#credit_card_type").val();
	obj.credit_card_number = $("#credit_card_number").val();
	obj.expiration_month = $("#expiration_month").val();
	obj.expiration_year = $("#expiration_year").val();
	obj.cvc = $("#cvc").val();
	obj.address_line_1 = $("#address_line_1").val();
	obj.address_line_2 = $("#address_line_2").val();
	obj.city = $("#city").val();
	obj.state = $("#state").val();
	obj.zip_code = $("#zip_code").val();
    var json_paypalStoreCard = JSON.stringify(obj);
    var data = "";

    data = '{"action": "paypalStoreCard", ' +
    '"type":"jsonp", ' +
    '"json": ' + json_paypalStoreCard  +
    '}';

	//var results += json_paypalStoreCard;
	$("#results").val(json_paypalStoreCard, null, '\t');

	//xml_check_username = "<xml><checkusername><username>" + $('#inUserName').val() + "</username></checkusername></xml>";
	//json_check_username = '{"xml": {"checkusername": { "username": "' + $('#inUserName').val() + '" }}}';
	//data = '{"action": "checkusername", "type":"jsonp", "json":{"xml": { "checkusername": {"username": "' + $('#inUserName').val() + '"}}}}';

	$.ajax( {
	  type:'post',
	  url: stripe_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $("#results").val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	$("#results").val(req_resp);
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
	  	//alert("Inside error jqXHR...");
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	//alert(textStatus);
       	//alert(errorThrown);
	  }
	});
	return false;
}

/////////////////////
// Account History
/////////////////////
jQuery.paypalAccountHistory = function (element) {

	var obj = new Object();
	obj.user_name = $("#account_history_form_user_name").val();
	//obj.user_name = $("#dteFrom").val();
	//obj.user_name = $("#dteTo").val();
    var json_paypalAccountHistory = JSON.stringify(obj, null, '\t');
    var data = "";
    var result = "";

    //if () {}
    data = '{"action": "list", ' +
    '"type":"jsonp", ' +
    '"json": ' + json_paypalAccountHistory  +
    '}';

	$(element).val(json_paypalAccountHistory);
	$.ajax( {
	  type:'post',
	  url: stripeAccountHistory_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $(element).val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	var html_str = "";
	  	var html_button = "";
	  	$(element).val(req_resp);
	  	if (json.Status == "Success") {
	  		//Do Something...
	  	}
	  	return true;
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	//alert(textStatus);
       	//alert(errorThrown);
	  }
	});
	return false;
}

/////////////////////
// Decrement Value
/////////////////////
jQuery.paypalDecrementValue = function (element) {

	//Fetch the list of cards to delete...
	var selected = new Array();

	if(!$.isNumeric($('#decrement_amount').val())) {
		alert("Decrement amount must be numeric.");
		return;
	}

	var obj = new Object();
	obj.amount = $("#decrement_amount").val();
	obj.seller = $("#seller").val();
	obj.memreas_master = $("#memreas_master").val();
    var json_paypalDecrementValue = JSON.stringify(obj, null, '\t');
    var data = "";
    var result = "";

    //if () {}
    data = '{"action": "decrement", ' +
    '"type":"jsonp", ' +
    '"json": ' + json_paypalDecrementValue  +
    '}';

	$(element).val(json_paypalDecrementValue);	
	
	$.ajax( {
	  type:'post',
	  url: stripeDecrementValue_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $(element).val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	var html_str = "";
	  	var html_button = "";
	  	$(element).val(req_resp);
	  	//if (json.Status == "Success") {
	  	//	alert("Inside");
	  	//}
	  	return true;
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	//alert(textStatus);
       	//alert(errorThrown);
	  }
	});
	return false;
}

/////////////////////
// Add Value
/////////////////////
jQuery.paypalAddValue = function (element) {

	//Fetch the list of cards to delete...
	var selected = new Array();
	$("input[name=card_selected]:checked").each(function() {
        card_number = $(this).parents("tr").find('input.card_numbers').val();
	    selected.push(card_number);
	});

	if(selected.length == 0) {
		alert("No cards checked");
		return;
	} else if (selected.length > 1) {
		alert("Please choose only one card");
		return;
	}

	if(!$.isNumeric($('#amount').val())) {
		alert("Amount must be numeric.");
		return;
	}

	var obj = new Object();
	obj.stripe_card_reference_id = selected[0];
	obj.amount = $("#amount").val();
    var json_paypalAddValue = JSON.stringify(obj, null, '\t');
    var data = "";
    var result = "";

    //if () {}
    data = '{"action": "list", ' +
    '"type":"jsonp", ' +
    '"json": ' + json_paypalAddValue  +
    '}';

	$(element).val(json_paypalAddValue);


	$.ajax( {
	  type:'post',
	  url: stripeAddValue_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $(element).val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	var html_str = "";
	  	var html_button = "";
	  	$(element).val(req_resp);
	  	//if (json.Status == "Success") {
	  	//	alert("Inside");
	  	//}
	  	return true;
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	//alert(textStatus);
       	//alert(errorThrown);
	  }
	});
	return false;
}

/////////////////////
// List Cards
/////////////////////
jQuery.paypalListCards = function (element, list) {

	//var element = '#list_delete_cards_results';
	var obj = new Object();
	obj.txt = "";
    var json_paypalListDeleteCards = JSON.stringify(obj, null, '\t');
    var data = "";
    var result = "";

    //if () {}
    data = '{"action": "list", ' +
    '"type":"jsonp", ' +
    '"json": ' + json_paypalListDeleteCards  +
    '}';

	$(element).val(json_paypalListDeleteCards);


	$.ajax( {
	  type:'post',
	  url: stripeListCards_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $(element).val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	var html_str = "";
	  	var html_button = "";
	  	$(element).val(req_resp);
	  	if (json.status == "Success") {
	  		for (var i=0;i<json.payment_methods.length;i++)
			{
				html_str +=
						'<tr>' +
						'<td><input type="radio" onclick="onRadioCardListforSubscribeSelected(this);" id="card_selected_' + i + '" name="card_selected" value="'+ i + '">' +
						//'<td>user_id' +
						//'<td><input type="text" id="user_id" name="user id" value="'+ json.payment_methods[i].user_id + '">' +
						'<td>stripe ref id:' +
						'<td><input type="text" class="card_numbers" id="stripe_card_reference_id_' + i + '" name="stripe_card_reference_id_' + i + '" value="'+ json.payment_methods[i].stripe_card_reference_id + '">' +
						'<td>card type:' +
						'<td><input type="text" id="card_type_' + i + '" name="card_type_' + i + '" value="'+ json.payment_methods[i].card_type + '">' +
						'<td>card number' +
						'<td><input type="text" id="card_number_' + i + '" name="card_number_' + i + '" value="'+ json.payment_methods[i].obfuscated_card_number + '">' +
						'</tr><tr>' +
						'<td>' +
						'<td>first name:' +
						'<td><input type="text" id="first_name_' + i + '" name="first_name_' + i + '" value="'+ json.payment_methods[i].first_name + '">' +
						'<td>last name' +
						'<td><input type="text" id="last_name_' + i + '" name="last_name_' + i + '" value="'+ json.payment_methods[i].last_name + '">' +
						'<td>address line 1:' +
						'<td><input type="text" id="address_line_1_' + i + '" name="address_line_1_' + i + '" value="'+ json.payment_methods[i].address_line_1 + '">' +
						'<td>address line 2:' +
						'<td><input type="text" id="address_line_2_' + i + '" name="address_line_2_' + i + '" value="'+ json.payment_methods[i].address_line_2 + '">' +
						'<td>city:' +
						'<td><input type="text" id="city_' + i + '" name="city_' + i + '" value="'+ json.payment_methods[i].city + '">' +
						'<td>state:' +
						'<td><input type="text" id="state_' + i + '" name="state_' + i + '" value="'+ json.payment_methods[i].state + '">' +
						'<td>zip code:' +
						'<td><input type="text" id="zip_code_' + i + '" name="zip_code_' + i + '" value="'+ json.payment_methods[i].zip_code + '">' +
						'</tr>';
			}
	  		$(list).html(html_str);
	  	}
	  	return true;
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	//alert(textStatus);
       	//alert(errorThrown);
	  }
	});
	return false;
}

/////////////////////
// Delete Cards
/////////////////////
jQuery.paypalDeleteCards = function () {

	//Fetch the list of cards to delete...
	var selected = new Array();
	$("input[name=card_selected]:checked").each(function() {
        card_number = $(this).parents("tr").find('input.card_numbers').val();
	    selected.push(card_number);
	});

	if(selected.length == 0) {
		alert("No cards checked to delete");
		return;
	}

    var json_paypalDeleteCards = JSON.stringify(selected, null, '\t');
    var data = "";
    var result = "";

    //if () {}
    data = '{"action": "delete", ' +
    '"type":"jsonp", ' +
    '"json": ' + json_paypalDeleteCards  +
    '}';

	$("#list_delete_cards_results").val(json_paypalDeleteCards);

	$.ajax( {
	  type:'post',
	  url: stripeDeleteCards_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $("#list_delete_cards_results").val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	$("#list_delete_cards_results").val(req_resp);
	  	return true;
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	//alert(textStatus);
       	//alert(errorThrown);
	  }
	});
	return false;
}

/////////////////////
// List Mass Payees
/////////////////////
jQuery.paypalListMassPayees = function (element, list) {

	//var element = '#list_delete_cards_results';
	var obj = new Object();
	obj.txt = "";
    var json_payPalListMassPayee = JSON.stringify(obj, null, '\t');
    var data = "";
    var result = "";

    //if () {}
    data = '{"action": "list", ' +
    '"type":"jsonp", ' +
    '"json": ' + json_payPalListMassPayee  +
    '}';

	$(element).val(json_payPalListMassPayee);

	$.ajax( {
	  type:'post',
	  url: payPalListMassPayee_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $(element).val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	var html_str = "";
	  	var html_button = "";
	  	$(element).val(req_resp);
	  	if (json.Status == "Success") {

	  		for (var i=0;i<json.accounts.length;i++)
			{
				html_str += '<tr>' +
						'<td><input type="checkbox" id="account_selected_' + i + '" name="account_selected_" value="'+ json.accounts[i].account_id + '">' +
						'<td>account id:' +
						'<td><input type="text" id="account_id_' + i + '" name="account_id" value="'+ json.accounts[i].account_id + '">' +
						'<td>user id:' +
						'<td><input type="text" id="user_id_' + i + '" name="user_id" value="'+ json.accounts[i].user_id + '">' +
						'<td>user name:' +
						'<td><input type="text" id="username_' + i + '" name="username" value="'+ json.accounts[i].username + '">' +
						'<td>account type:' +
						'<td><input type="text" id="account_type_' + i + '" name="account_type" value="'+ json.accounts[i].account_type + '">' +
						'<td>balance:' +
						'<td><input type="text" id="balance_' + i + '" name="balance" value="'+ json.accounts[i].balance + '">' +
						'</tr>';
			}
			$(list).html(html_str);
	  	}
	  	return true;
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	//alert(textStatus);
       	//alert(errorThrown);
	  }
	});
	return false;
}

/////////////////////
// Payout Mass Payees
/////////////////////
jQuery.paypalPayoutMassPayees = function (element, list) {
	//Fetch the list of cards to delete...
	var selected = new Array();
	$("input:checked").each(function() {
	    selected.push($(this).attr('value'));
	});

	if(selected.length == 0) {
		alert("No accounts checked to payout");
		return;
	}

alert(JSON.stringify(selected, null, '\t'));
	var json_paypalPayoutMassPayees = JSON.stringify(selected, null, '\t');
    var data = "";
    var result = "";

    data = '{"action": "delete", ' +
    '"type":"jsonp", ' +
    '"json": ' + json_paypalPayoutMassPayees  +
    '}';

	$(element).val(json_paypalPayoutMassPayees);

	$.ajax( {
	  type:'post',
	  url: paypalPayoutMassPayees_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $(element).val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	$(element).val(req_resp);
	  	return true;
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
       	alert(jqXHR.responseText);
       	alert(jqXHR.status);
    	//alert(textStatus);
       	//alert(errorThrown);
	  }
	});
	return false;
}


///////////////////
//Subscribe
/////////////////////
jQuery.payPalSubscribe = function (element) {
/*
	//Fetch the list of cards to delete...
	var selected = new Array();
	$("input:checked").each(function() {
		alert("val ---> " + $(this).val())
		alert("attr.val ---->" + $(this).attr('value'));
		selected.push($(this).attr('value'));
	});

	if(selected.length == 0) {
		alert("No cards checked");
		return;
	} else if (selected.length > 1) {
		alert("Please choose only one card");
		return;
	}
*/
	alert('subscribing ' + $("#subscribe_first_name").val());
	var selected = $("input[type='radio'][name='card_selected']:checked");
	if (selected.length > 0) {
	    selectedVal = selected.val();
	}
	alert('selected ' + selected.val());
	//var objSubscribe = new Object();
	var objSubscribe = {};
	//objSubscribe.paypal_card_reference_id = selected[0];
	objSubscribe.plan = $("#plan").val();
	objSubscribe.subscribe_first_name = $("#subscribe_first_name").val();
	objSubscribe.subscribe_last_name = $("#subscribe_last_name").val();
	objSubscribe.subscribe_address_line_1 = $("#subscribe_address_line_1").val();
	objSubscribe.subscribe_address_line_2 = $("#subscribe_address_line_2").val();
	objSubscribe.subscribe_city = $("#subscribe_city").val();
	objSubscribe.subscribe_state = $("#subscribe_state").val();
	objSubscribe.subscribe_zip_code = $("#subscribe_zip_code").val();
	objSubscribe.subscribe_credit_card_type = $("#subscribe_credit_card_type").val();
	objSubscribe.subscribe_credit_card_number = $("#subscribe_credit_card_number").val();
	objSubscribe.subscribe_expiration_month = $("#subscribe_expiration_month").val();
	objSubscribe.subscribe_expiration_year = $("#subscribe_expiration_year").val();

	var json_paypalSubscribe = JSON.stringify(objSubscribe, null, "\t");
	var data = "";
	var result = "";

	alert(JSON.stringify(json_paypalSubscribe));

	data = '{"action": "subscribe", ' +
		'"type":"jsonp", ' +
		'"json": ' + json_paypalSubscribe  +
		'}';

	$(element).val(json_paypalSubscribe);
	$.ajax( {
	  type:'post',
	  url: stripeSubscribe_url,
	  dataType: 'jsonp',
	  data: 'json=' + data,
	  success: function(json){
	  	var req_resp = $(element).val() + "\n\n" + JSON.stringify(json, null, '\t');
	  	var html_str = "";
	  	var html_button = "";
	  	$(element).val(req_resp);
	  	//if (json.Status == "Success") {
	  	//	alert("Inside");
	  	//}
	  	return true;
	  },
	  error: function (jqXHR, textStatus, errorThrown) {
    	alert(jqXHR.responseText);
    	alert(jqXHR.status);
 	//alert(textStatus);
    	//alert(errorThrown);
	  }
	});
	return false;
}
