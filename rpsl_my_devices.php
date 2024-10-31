<?php
//*************************************************************************************
//*************************************************************************************
//   EDIT MY DEVICE Shortcode - add the Register QR Code and Device List
//
//   [rpsl_my_devices showheader='true' showblurb='true' showregister='true']
//
//*************************************************************************************
//*************************************************************************************

function rpsl_my_devices_html($attributes) {
// Allow attributes in the shortcode to limit what gets shown
	$attributes = shortcode_atts( array(
		'showregister'  => "true",
		'showheader'    => "true",
		'showblurb'     => "true"
	), $attributes, 'rpsl_my_devices' );

	$show_credential = current_user_can( RPSL_Configuration::$rpsl_can_have_credential_role );
	$showregister = $attributes["showregister"] === "true";
	$showheader   = $attributes["showheader"  ] === "true";
	$showblurb    = $attributes["showblurb"   ] === "true";

	// This part of Wordpress uses tables for layout rather than divs
	$logoimg = esc_url( plugin_dir_url( __FILE__ ) . "images/rapidwm.png" ); 
	$logobig = esc_url( plugin_dir_url( __FILE__ ) . "images/rapid.png" ); 
	$blank   = esc_url( plugin_dir_url( __FILE__ ) . "images/white.jpg" ); 
	$user = wp_get_current_user();
	if ( !($user instanceof WP_User) ) { return "You must be logged in to managed your devices!"; }

	$target_user_id = $user->ID;
	if ( $target_user_id == 0 ) { return "You must be logged in to manage your devices!"; }

	// i18n Strings
	$mHeader          = __('RapID Secure Login - No more passwords!', 'rp-securelogin');
	$mInstructions    = __('Use RapID for simple, secure login to WordPress. ', 'rp-securelogin');
	$mInstructions   .= __('Just get the free RapID Secure Login app from <a href=\'https://play.google.com/store/apps/details?id=com.intercede.rapidsl&hl=en_GB\' target=\'_blank\'>Google Play</a> or the <a href=\'https://itunes.apple.com/us/app/rapid-secure-login/id1185934781?mt=8\' target=\'_blank\'>AppStore</a>.<br/>', 'rp-securelogin');
	$mInstructions   .= __('Then click or tap on the RapID logo below and scan the QR code with your RapID Secure Login app. ', 'rp-securelogin');
	$mInstructions   .= __('The next time you login to this site, just scan the code and use your finger or a simple PIN. ', 'rp-securelogin');
	$mInstructions   .= esc_html__('The same app can log into multiple accounts on different WordPress sites.', 'rp-securelogin');
	$mUseRapid        = esc_html__('Use RapID - No More Passwords!', 'rp-securelogin');
	$mClickToAdd      = esc_html__('Click or tap the logo to request a RapID credential', 'rp-securelogin');
	$mNewCredAdded    = esc_html__('Your new RapID credential has been added', 'rp-securelogin');
	$mScanPrompt2     = esc_html__('Scan or tap the QR code with the RapID app to create a new RapID credential', 'rp-securelogin');
	$mChecking        = esc_html__('Waiting for credential collection', 'rp-securelogin');
	$mPleaseWait      = esc_html__('Please wait while a credential is prepared', 'rp-securelogin');
	$mConfirmRemove   = esc_html__('You are about to remove this device from your account. Are you sure?', 'rp-securelogin');
	$mDeniedByRole    = esc_html__('Your role does not let you make use of a RapID login, contact your site administrator to request access.', 'rp-securelogin');
	$mNewName         = esc_html__('Please enter a new name for this device', 'rp-securelogin');

	$admin_url = admin_url('admin-ajax.php');
	$ajax_endpoint = RPSL_Configuration::rpsl_ajax_endpoint();
	$ajax_timeout = RPSL_Configuration::$rpsl_ajax_timeout;

	$content = "";
	if ($showheader) {$content .= "<h5>$mHeader</h5> ";};
	if ($showblurb && $show_credential)  { $content .= "<p>$mInstructions</p> "; };
	if (!$show_credential) { $content .= "<p>$mDeniedByRole</p>"; }
	$content .= "<span name='rpsl_device_list'></span><br/><br/> ";
	
	if ($showregister && $show_credential) {
		$content .= <<<RPSL_REGISTER
<span name='rpsl_assoc_prompt'>$mClickToAdd</span> 
<p> 
<div class="rpsl_outer_div"> 
	<div class="rpsl_qr_div" id="rpsl_big_logo" name="rpsl_big_logo"> 
		<img class="rpsl_qr_img" src="$logobig" title="$mClickToAdd"  onClick="rpsl_newRequest();" /> 
	</div> 
	<div class="rpsl_qr_div" id="rpsl_qr_code" name="rpsl_qr_code" style="display:none"> 
		<a name="rpsl_assoc_url" href=''> 
		<img class="rpsl_qr_img"   id="rpsl_assoc_qr" name="rpsl_assoc_qr" src="$blank" title="$mClickToAdd" /> 
		</a> 
		<div id="rpsl_small_logo" name="rpsl_small_logo" style="display:none"> 
			<img class="rpsl_logo_img_click" id="rapidlogo"    name="rapidlogo"    src="$logoimg" title="$mUseRapid" onClick="rpsl_newRequest();" /> 
		</div> 
	</div> 
	<div class="rpsl_qr_div"> 
		<span name='rpsl_assoc_error'>&nbsp;</span>
	</div> 
</div> 
</p> 

<script type='text/javascript' > 
	var rpsl_newIdRequested = false; 
	var rpsl_assocSession   = ''; 
	var rpsl_assocDots      = '.'; 
	var rpsl_user_id        = '$target_user_id'; 
	var rpsl_admin_ajaxurl  = "$admin_url";
	var rpsl_ajaxurl  = "$ajax_endpoint";
	var rpsl_checkRegistered_timeout; if (typeof rpsl_checkRegistered_timeout === 'undefined') rpsl_checkRegistered_timeout = 0; 
	var rpsl_refresh_qrcode = false; 
	var rpsl_refresh_timeout; 

	function rpsl_setRegistrationQR() {  // Calls to the server to get the QR code src
		rpsl_assocDots = ''; 
		jQuery.ajax({
			url :  rpsl_admin_ajaxurl, type: 'POST', data: {'action' :'rpsl_generate_registration_qrcode'},	dataType: 'json', 
			success:  function(data) { 
						if(data.error){
							rpsl_setAssocErrorMessage(data.error);
							rpsl_hideQR();
							return;
						}
						rpsl_assocSession = data.uuid; 
						rpsl_setAssocImages(data.qrbase64, data.qrdata); // src and url data for the image
						rpsl_showQR(); 
						rpsl_newIdRequested = true; 
						rpsl_setAssocErrorMessage('$mChecking' + rpsl_assocDots); 
						clearTimeout(rpsl_refresh_timeout); 
						rpsl_refresh_timeout = null; 
						rpsl_poll_for_registration(); 
					}, 
			error:   function(request, statusText, errorText){ 
						rpsl_setAssocErrorMessage('Error: ' + request.status + ', Retrying...'); 
						rpsl_poll_for_registration(); 
					} 
		});    
	};	 

	function rpsl_checkRegistered() { // Calls to the server to check whether the user has registered their new credential 
		rpsl_assocDots += '.'; if(rpsl_assocDots.length > 10) rpsl_assocDots = '.'; 
		jQuery.ajax({ 
			url :  rpsl_ajaxurl, type: 'POST', data: {'action' :'rpsl_check_registered', 'rpsession' : rpsl_assocSession},	dataType: 'json', 
			success:  function(data) { 
						if (data.status == 'ok') { 
							rpsl_setAssocErrorMessage('$mNewCredAdded'); 
							rpsl_refresh();   // Swap back to static logo
						} else if (data.status == 'error') { 
							rpsl_setAssocErrorMessage(data.message); 
							rpsl_refresh();   // Swap back to static logo
						} else { 
						    rpsl_setAssocErrorMessage(data.status + rpsl_assocDots); 
							rpsl_poll_for_registration(); 
						} 
					}, 
			error:   function(request, statusText, errorText){ 
						rpsl_setAssocErrorMessage('Error: ' + request.status + ', Retrying...'); 
						rpsl_poll_for_registration(); 
					} 
		}); 
	}; 

	function rpsl_poll_for_registration() { 
						// Sessions will timeout, to cover this, refresh the QR code if this token is set
						// Has to be part of poll for login as it will override the assoc session variable
						// Which has to be correct for the form post.
		if(rpsl_refresh_qrcode) {
			rpsl_refresh_qrcode = false; 
			rpsl_wplogin_code_requested = false; 
			rpsl_setRegistrationQR(); 
			return; 
		} 

		// Only set timeout if it is not 0
		if(!rpsl_refresh_timeout) { rpsl_refresh_timeout = setTimeout(rpsl_refreshQRCodeToken, 602000); } 
		// Avoid multiple polling timeouts from duplicate sections on the form 
		if (rpsl_checkRegistered_timeout != 0) clearTimeout(rpsl_checkRegistered_timeout); 
		if (rpsl_newIdRequested) rpsl_checkRegistered_timeout =	setTimeout(rpsl_checkRegistered, $ajax_timeout); 
	} 

	function rpsl_refreshQRCodeToken() { 
		rpsl_refresh_qrcode = true; 
	} 

	function rpsl_setAssocImages(src, data){ 
		var imgs = document.getElementsByName('rpsl_assoc_qr'); 
		var i;		for (i = 0; i < imgs.length; i++){imgs[i].src = src; }; 
		var links = document.getElementsByName('rpsl_assoc_url'); 
		for (i = 0; i < links.length; i++){links[i].href = 'rapid02://qr?sess=' + data; }; 
	}; 

	function rpsl_showQR(){ 
		//Hide the big logo and show the small one plus QRcode 
		var biglogo   = document.getElementsByName('rpsl_big_logo');  
		for (i = 0; i < biglogo.length; i++){biglogo[i].style.display = 'none'}; 

		var qrCodeDiv = document.getElementsByName('rpsl_qr_code'); 
		for (i = 0; i < qrCodeDiv.length; i++){qrCodeDiv[i].style.display = 'block'}; 

		var smalllogo = document.getElementsByName('rpsl_small_logo');  
		for (i = 0; i < smalllogo.length; i++){smalllogo[i].style.display = 'block'}; 

		rpsl_setAssocPromptMessage('$mScanPrompt2'); 
	}; 

	function rpsl_hideQR(){ 
		//Show the big logo and hide the small one plus QRcode 
		var biglogo   = document.getElementsByName('rpsl_big_logo');  
		for (i = 0; i < biglogo.length; i++){biglogo[i].style.display = 'block'}; 

		var qrCodeDiv = document.getElementsByName('rpsl_qr_code'); 
		for (i = 0; i < qrCodeDiv.length; i++){qrCodeDiv[i].style.display = 'none'}; 

		var smalllogo = document.getElementsByName('rpsl_small_logo');  
		for (i = 0; i < smalllogo.length; i++){smalllogo[i].style.display = 'none'}; 

		rpsl_setAssocPromptMessage('$mClickToAdd'); 
	}; 

	function rpsl_setAssocErrorMessage(mess){ 
		var spans = document.getElementsByName('rpsl_assoc_error'); 
		var message = mess; 
		if (message == 'Error: [object Object]') { message = 'Errors: Session has timed out. Please refresh the page'; } 
		var i;		for (i = 0; i < spans.length; i++){	spans[i].innerHTML = message; }; 
				}; 

	function rpsl_setAssocPromptMessage(mess){ 
		var spans = document.getElementsByName('rpsl_assoc_prompt'); 
		var i;		for (i = 0; i < spans.length; i++){	spans[i].innerHTML = mess; }; 
	}; 

	function rpsl_setDeviceList(){ 
		jQuery.ajax({ 
			url :  rpsl_admin_ajaxurl, type: 'POST', data: {'action' :'rpsl_list_devices', 'user' : rpsl_user_id},	dataType: 'html', 
			success:  function(data) { 
					var spans = document.getElementsByName('rpsl_device_list'); 
					var i;		for (i = 0; i < spans.length; i++){	spans[i].innerHTML = data; }; 
					}, 
			error:   function(errorObj, statusText, errorText){ 
						rpsl_setAssocErrorMessage('Error: ' + statusText + ' - ' + errorText); 
					} 
		});    
	}; 

	function rpsl_newRequest() {   // Creates a new RapID credential request and shows the request ID here
		if (!rpsl_newIdRequested) { 
			rpsl_setAssocPromptMessage('$mPleaseWait'); 
			rpsl_setAssocImages('$blank'); 
			rpsl_setRegistrationQR(); 
		} 
	}; 

	// Device Management functions 
	function rpsl_delete_device(uuid) { 
		// Delete the given device. Only allowed from a logged in session.  
		// Called from the table generated by rpsl_dump_devices 
		var carryon = confirm('$mConfirmRemove'); 
		if (carryon) { 
			jQuery.ajax({ 
				url :  rpsl_admin_ajaxurl, type: 'POST', data: {'action' :'rpsl_delete_device', 'uuid' : uuid},	dataType: 'html', 
				success:  function(data) { 
							rpsl_setDeviceList();   //alert('Device deleted ok');
						}, 
				error:   function(errorObj, statusText, errorText){ 
							rpsl_setAssocErrorMessage('Error: ' + statusText + ' - ' + errorText); 
						} 
			}); 
		} 
	}; 

	function rpsl_rename_device(uuid, oldname) { 
		// Rename the given device. Only allowed from a logged in session. 
		// Called from the table generated by rpsl_dump_devices 
		var newname = prompt('$mNewName', oldname); 
		if (newname != null) { 
			jQuery.ajax({ 
				url :  rpsl_admin_ajaxurl, type: 'POST', data: {'action' :'rpsl_rename_device', 'uuid' : uuid, 'name' : newname},	dataType: 'html', 
				success:  function(data) { 
							rpsl_setDeviceList();  //alert(data);
						}, 
				error:   function(errorObj, statusText, errorText){ 
							rpsl_setAssocErrorMessage('Error: ' + statusText + ' - ' + errorText); 
						} 
			}); 
		} 
	}; 

	function rpsl_refresh() { 
		rpsl_hideQR(); rpsl_setDeviceList(); 
		rpsl_newIdRequested = false; 
	} 

	jQuery(document).ready(function($) { rpsl_refresh(); }) 
</script> 
RPSL_REGISTER;
	};

	return $content;
}

add_shortcode( 'rpsl_my_devices', 'rpsl_my_devices_html' );