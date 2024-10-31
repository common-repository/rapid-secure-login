var rpsl_wplogin_session  = "";
var rpsl_wplogin_code_requested;        // Needed to stop duplicate requests
var rpsl_images = [];
var rpsl_wplogin_pwform_visible = true; // Track state of password form
var rpsl_refresh_qrcode = false;

var rpsl_wplogin_timeout,
	rpsl_refresh_timeout,
	rpsl_rotateQr_timeout;

if (typeof rpsl_wplogin_timeout === 'undefined') rpsl_wplogin_timeout = 0;
var rpsl_currentQR = 2;

function rpsl_rotateQrCodes(qrwidth) {
	return; // Suppress multiple QR codes - user push-back
//	if(qrwidth != 164 && qrwidth != 180) return;
//	rpsl_currentQR = (rpsl_currentQR + 1) % 4;
//	rpsl_getQrElement().attr("src",rpsl_images[rpsl_currentQR].src);
//	rpsl_rotateQr_timeout = setTimeout(rpsl_rotateQrCodes.bind(null, qrwidth), (rpsl_currentQR == 0 ? 450 : 120));
}

function rpsl_getQrElement() {
	if(typeof rpsl_qrElement == 'undefined'){
		return jQuery("#rpsl_login_qr");
	}
	return rpsl_qrElement;
}

function rpsl_setSpinnerImages(qrwidth) {
	var scale = (
		qrwidth == 164 ? "1.1" :
		qrwidth == 180 ? "1.2" : "0");
	var transformY =  qrwidth == 180 ? " translateY(8px)" : "";

	jQuery(".rpsl_spinner").css("transform", "scale(" + scale + ")" + transformY);
	jQuery(".rpsl_be_spinner").css("transform", "scale(" + scale + ")" + transformY);

	if(qrwidth != 164 && qrwidth != 180) return;

	jQuery("#spinnerPurple").attr("src", rpsl_image_directory + "images/spinnerPurple.gif");
	jQuery("#spinnerRoyalBlue").attr("src", rpsl_image_directory + "images/spinnerRoyalBlue.gif");
	jQuery("#spinnerPink").attr("src", rpsl_image_directory + "images/spinnerPink.gif");
	jQuery("#spinnerLightBlue").attr("src", rpsl_image_directory + "images/spinnerLightBlue.gif");
	jQuery("#spinnerDarkBlue").attr("src", rpsl_image_directory + "images/spinnerDarkBlue.gif");
	jQuery("#spinnerOrange").attr("src", rpsl_image_directory + "images/spinnerOrange.gif");
}

function rpsl_setQrImages(qrwidth) {
	rpsl_images[0] = new Image();

	if(qrwidth != 164 && qrwidth != 180) return;

	rpsl_images[1] = new Image();			rpsl_images[1].src = rpsl_image_directory + "images/qr1_" + qrwidth + ".png";
	rpsl_images[2] = new Image();			rpsl_images[2].src = rpsl_image_directory + "images/qr2_" + qrwidth + ".png";
	rpsl_images[3] = new Image();			rpsl_images[3].src = rpsl_image_directory + "images/qr3_" + qrwidth + ".png";
}

function rpsl_setInitialQrImage(base64src, data){
	rpsl_images[0].src = base64src;
	jQuery("#rpsl_qr_url").attr("href", "rapid02://qr?sess=" + data);
}

function rpsl_refreshQRCodeToken() {
	rpsl_refresh_qrcode = true;
}

function rpsl_setLoginErrorMessage(message){
	rpsl_setRawErrorMessage(message);
}

function rpsl_setRawErrorMessage(message){
	try { document.getElementById("rpsl_login_error").innerHTML = message; } catch(e) {}
}

function rpsl_setLoginStatusMessage(message){
	try { document.getElementById("rpsl_login_status").innerHTML = message; } catch(e) {}
}

function rpsl_setNamedValue(tagName, value){
	try { document.getElementById(tagName).value = value; } catch(e) {}
}

function rpsl_setLoginQR() {  // Calls to the server to get the QR code src
	if (!rpsl_wplogin_code_requested) {
		rpsl_wplogin_code_requested = true;
		jQuery.ajax({
			url :  rpsl_wplogin_ajaxurl, type: 'POST', data: {'action' :'rpsl_generate_login_qrcode'},	dataType: 'json',
			success:  function(data) {
						if(data.status == "error"){
							rpsl_setLoginErrorMessage("Error - Cannot create QR Code: " + data.message);
							return;
						} else{
							rpsl_setQrImages(data.qrwidth);

							rpsl_wplogin_session = data.rpsessionid;
							rpsl_setInitialQrImage(data.qrbase64, data.qrdata);
							rpsl_setLoginStatusMessage(waiting_for_auth);
							clearTimeout(rpsl_refresh_timeout);
							clearTimeout(rpsl_rotateQr_timeout);
							rpsl_refresh_timeout = null;
							rpsl_rotateQr_timeout = null;

							rpsl_poll_for_login();
							rpsl_getQrElement().attr("src",rpsl_images[0].src);

							rpsl_setSpinnerImages(data.qrwidth);
							rpsl_rotateQrCodes(data.qrwidth);
						}
					},
			error:   function(jqXHR, status, errorThrown){
						rpsl_setLoginErrorMessage("Error - Cannot create QR Code: "+ errorThrown);
					}
		}); 
	}
}

function rpsl_checkAuth() {  // Checks whether this session has been authenticated yet
	jQuery.ajax({
		url: rpsl_ajaxurl,
		type: 'POST',
		data: {	'action' :'rpsl_check_login', 'rpsession' : rpsl_wplogin_session},
		dataType: 'json',
		success:function(data) {
			var rpStatus = data.status;

			// DIAG rpsl_setRawErrorMessage("code refreshed");
			
			if (rpStatus == "request_expired") {			// We must refresh the QR code
				window.location.reload(true);
			   
			} else if (rpStatus == "waiting_for_auth") {	// Wait and try again
				rpsl_setLoginStatusMessage(waiting_for_auth + rpsl_getDots());
				rpsl_poll_for_login();
			   
			} else if (rpStatus.substr(0,2) == "zz") {	    // Diagnostics
				rpsl_setLoginStatusMessage(rpStatus);
				rpsl_poll_for_login();
			} else if (rpStatus == "waiting_for_association") {	 // Not meant for this page! Ignore
				rpsl_poll_for_login();
				
			} else if(rpStatus == "error") {
				rpsl_setLoginStatusMessage(data.message);
			} 
			else {										// Login name is set - submit
				if (rpStatus.trim() != "" ) {
					if(rpsl_wplogin_pwform_visible)
					{
						rpsl_toggle_password_display();
					}
					rpsl_setLoginStatusMessage(authorized_message + rpStatus);
					rpsl_setNamedValue("user_login", "RapID-User");
					rpsl_setNamedValue("user_pass", rpsl_wplogin_session);

					jQuery("#wp-submit").click(); 
					jQuery("#rp-submit").click(); 
				}
			}
		},
		error: function(jqXHR, status, errorThrown){
		    if(isPageBeingRefreshed)
		    {
		        return;
		    }
		    rpsl_setLoginErrorMessage("An error occurred, please refresh the page to re-attempt to login. " + errorThrown);
		}
	});   
}

var rpsl_wplogin_dots = "";
function rpsl_getDots() {
	rpsl_wplogin_dots += "."; 
	if (rpsl_wplogin_dots.length > 10) { rpsl_wplogin_dots = ".";}
	return rpsl_wplogin_dots;
}

function rpsl_poll_for_login() {
	// Sessions will timeout, to cover this, refresh the QR code if this token is set
	// Has to be part of poll for login as it will override the assoc session variable
	// Which has to be correct for the form post.
	if(rpsl_refresh_qrcode) {
		rpsl_refresh_qrcode = false;
		rpsl_wplogin_code_requested = false;
		rpsl_setLoginQR();
		return;
	}
	
	// 18 minutes
	// Only set timeout if it is not 0
	if(!rpsl_refresh_timeout) { rpsl_refresh_timeout = setTimeout(rpsl_refreshQRCodeToken, 1080000); }
	// Avoid multiple polling timeouts from duplicate sections on the form
	if (rpsl_wplogin_timeout != 0) clearTimeout(rpsl_wplogin_timeout);
	rpsl_wplogin_timeout = setTimeout(rpsl_checkAuth, rpsl_ajax_timeout);
}
