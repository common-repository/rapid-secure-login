<?php 
//*************************************************************************************
//*************************************************************************************
// WordPress Rapid Secure Login Plugin Settings Control
//*************************************************************************************
//*************************************************************************************

//*************************************************************************************
function rpsl_admin_menu() {
	add_options_page ( "RapID Secure Login", "RapID", "manage_options", "rpsl-plugin-settings", "rpsl_settings_page");
}

//*************************************************************************************
function rpsl_settings_page() { 

	$site_configured = rpsl_site_configured();
	// if site is configured, get set tab or default to settings.
	// If not configured, get set tab, or default to configuration.
	// If reconfigure has been clicked ( but site configured), show configured tab
	if($site_configured) {
		$active_tab = isset( $_GET[ 'tab' ] ) ? $_GET[ 'tab' ] : 'settings';
	} else {
		$active_tab = isset( $_GET[ 'tab' ] ) ? $_GET[ 'tab' ] : 'configuration';
	}
	
?>
		<h2 class="nav-tab-wrapper">
<?php
			if(!$site_configured || $active_tab == "configuration") {
				rpsl_display_tab('configuration', 'Configuration', $active_tab);
			}
			rpsl_display_tab('settings', 'Settings', $active_tab);
			rpsl_display_tab('shortcodes', 'Shortcodes', $active_tab);
			rpsl_display_tab('support', 'Support', $active_tab);
			if(RPSL_Configuration::$rpsl_show_manual_configuration_tab) {
				rpsl_display_tab('manual_configuration', 'Manual Configuration', $active_tab);
			}
?>
		</h2>
<?php  
	switch($active_tab){
		case 'shortcodes':
			rpsl_shortcode_tab_output();
		break;
		case 'support':
			rpsl_support_tab_output();
		break;
		case 'manual_configuration':
			rpsl_manual_configuration_tab_output();	
		break;
		case 'configuration':
			$site_configured ? rpsl_reconfigure_tab_output() : rpsl_configuration_tab_output();	
		break;
		case 'settings':
		default:
			rpsl_settings_tab_output();	
		break;
	}
}

function rpsl_display_tab($tab_id, $tab_text, $active_tab) {
	$tab_class = $active_tab == $tab_id ? 'nav-tab-active' : '';
	echo "<a href='?page=rpsl-plugin-settings&tab=$tab_id' class='nav-tab $tab_class'>$tab_text</a>";
}

//*************************************************************************************
// Boolean conversion of rpsl_db_check_configuration
//*************************************************************************************
function rpsl_site_configured(){
	$status = rpsl_db_check_configuration();
	return ($status['CACert'] && $status['RapidAuthKey'] && $status['RapidAuthCert']);
}
//*************************************************************************************
//*************************************************************************************
// SITE CONFIGURATION / REGISTRATION
//*************************************************************************************
//*************************************************************************************

//*************************************************************************************
// This generates a new rapid request ID and QR registration code URL
// QR code contains: <version>S<platform>#<site name>#<request ID>#<login name>#<url>
//*************************************************************************************
function rpsl_generate_site_registration_qrcode() {
	global $wpdb;

	if(!current_user_can('administrator')) {
		rpsl_diag("User does not have permissions to generate site registration QR Code");
		die;
	}
	
	// Generate a pseudo-random ID to map the QRCode to the certificates being received
	$rpsl_requestid = rpsl_UUID::v4();
	$ht_sessionid = session_id();
	$anonid = rpsl_UUID::v4();

	$currentuser = wp_get_current_user();
	$loginname = sanitize_text_field( $currentuser->user_login );
	
	$table_name = $wpdb->prefix . "rpsl_sessions";
	
	// Need to store the $anonid in the session row

	// Add an entry to the sessions table so we can track the confirmation and add the device
	// Note the rpsl_session must be the new UUID,  not the RapID request ID
	$insert = $wpdb->prepare("INSERT INTO " . $table_name .	" (sessionid, rpsession, loginname, action, requested, uuid) " .
				"VALUES (%s, %s, %s, 'site_registration', Now(), %s)", $ht_sessionid, $rpsl_requestid, $loginname, $anonid);
	$results = $wpdb->query( $insert );

	$qr_data = rpsl_qr_data("S", $rpsl_requestid, $loginname, $anonid);
	$qr_base64 = rpsl_qr_base64($qr_data)[0];
	rpsl_trace("New Site Registration QR created for:  $qr_data");
		
	$response_array = array ( 'rpsessionid'=>$rpsl_requestid, 'qrbase64'=>$qr_base64, 'qrData'=>$qr_data);
	
	rpsl_echo(json_encode($response_array));
	rpsl_diag("New Site Registration Request: $rpsl_requestid, user: $loginname ");

	die; // Always die in functions echoing Ajax content
}

//*************************************************************************************
// This Ajax function is used by the phone to upload the certificates to the WordPress site.
//*************************************************************************************

function rpsl_site_registration() {
	global $wpdb;
	
	rpsl_trace("Entering rpsl_site_registration for Site Registration Attempt");
	$jsonData = json_decode(file_get_contents('php://input'));
	$dataSupplied = isset($jsonData->requestid) && isset($jsonData->tic) && isset($jsonData->sac) &&
					!empty($jsonData->requestid) && !empty($jsonData->tic) && !empty($jsonData->sac);
    	
	if(!$dataSupplied) {
	    $message = json_encode(array("status"=>500, "error"=>"Minimum data of requestId, Tic and Sac not supplied."));
		rpsl_echo($message);
		die;
	}
	
	// Extract Properties for now..
	$request_id = $jsonData->requestid;
	$tic = $jsonData->tic;
	$sac = $jsonData->sac;

	$admin_credential_collected = isset($jsonData->adminCredentialCollected) ? $jsonData->adminCredentialCollected == true : false;
	$device_name = empty($jsonData->deviceName) ? "Administrators Device" : urldecode($jsonData->deviceName);

	// Look for an 'site_registration' entry in the session table - use a SQL escaped method:
	$table_name = $wpdb->prefix . "rpsl_sessions";
	$select = $wpdb->prepare("SELECT * FROM " . $table_name . " WHERE rpsession = %s AND action = 'site_registration'", $request_id );
	$sessionrow = $wpdb->get_row($select);

	if (!isset($sessionrow->sessionid)) {
	    $message = json_encode(array("status"=>500, "error"=>"SessionId not found in database.")); 
		rpsl_echo($message);
		die;
	}
	$sessionid = $sessionrow->sessionid;
	
	//Need to save the certificates, Import Sac
	$sac_content = base64_decode($sac);
	$pfxResult = rpsl_import_sac_certificate($sac_content, $sessionid);
	$issuerCertResult = rpsl_add_issuer_certificate($tic);
	
	if(!$pfxResult || !$issuerCertResult) {
		$message = json_encode(array("status"=>500, "error"=>"Parsing and saving of certificate files failed."));
		rpsl_echo($message);
		die;
	}

	rpsl_diag("Admin Credential Collected by mobile device: $admin_credential_collected");
	if($admin_credential_collected && isset($sessionrow->uuid) && isset($sessionrow->loginname)) {
		
		$rpsl_uuid = $sessionrow->uuid; // Get uuid from session row. 
		$loginname = $sessionrow->loginname; // Get loginname from session row
		
		rpsl_diag("Loginname and UUID exist on the session row, Attempting to add user if $loginname exists");

		// Add device link from user to uuid.
		$user = get_user_by('login', $loginname);

		if($user !== false) {
			rpsl_diag("Adding Device for $loginname, $rpsl_uuid, $device_name");
			rpsl_add_device( $user->ID, $rpsl_uuid, $device_name);
		}
	}

	rpsl_create_session_token_file($request_id, null);
	rpsl_delete_session_row($request_id);

	rpsl_diag("New WordPress site has been configured successfully");
	$message = json_encode(array("status"=>200));
	rpsl_echo($message);	
	die; // Always die in functions echoing Ajax content
}

function rpsl_shortcode_tab_output() {
	
	$mShortcode		 = __('The <strong>RapID Secure Login</strong> plugin provides shortcodes that provide login, device management, and invitation capabilities.', 'rp-securelogin');
	$mShortcode		.= __('<h2>Login control</h2>', 'rp-securelogin');
	$mShortcode		.= __('To add a RapID login control to your own page, simply use the shortcode:  <b>[rpsl_secure_login]</b><br/>', 'rp-securelogin');
	$mShortcode		.= __('You can modify each instance of the shortcode using the "showregister" and "redirect_to" parameters.<br/>', 'rp-securelogin');
	$mShortcode		.= __('For example :  <b>[rpsl_secure_login showregister="false" redirect_to="/forum"]</b><br/><br/>', 'rp-securelogin');
	$mShortcode		.= __('<b>Note:</b> the login control will only be shown to users that are not logged in.<br/><br/>', 'rp-securelogin');

	$mShortcode		.= __('<h2>Device List</h2>', 'rp-securelogin');
	$mShortcode		.= __('To add a Register QR Code and a Device List use :  <b>[rpsl_my_devices showheader="true" showblurb="true" showregister="true"]</b><br/><br/>', 'rp-securelogin');

	$mShortcode		.= __('<h2>Direct Enrolment</h2>', 'rp-securelogin');
	$mShortcode		.= __('To add a Direct Enrolment control to any page use the shortcode:  <b>[rpsl_direct_enrolment]</b><br/>', 'rp-securelogin');
	$mShortcode		.= __('The Direct Enrolment control displays a form that allows a logged in user to invite another user to collect a credential. ', 'rp-securelogin');
	$mShortcode		.= __('Invitations are sent via email, so your site needs to be correctly configured to send emails.<br/>', 'rp-securelogin');
	$mShortcode		.= __('Note that any logged in user with access to the page containing the shortcode could send invitations, so consider controlling access to that page.<br/>', 'rp-securelogin');
	$mShortcode		.= __('The following items on the Settings tab relate to this shortcode:<ul>', 'rp-securelogin');
	$mShortcode		.= __('<li>The <b>Request Lifetime</b> setting determines how long the invitation remains active.</li>', 'rp-securelogin');
	$mShortcode		.= __('<li>The <b>Email Template</b> setting allows the layout of the email invitation to be altered.</li></ul>', 'rp-securelogin');

	echo "<div class='wrap'><br/>$mShortcode</div";
}

function rpsl_support_tab_output() {
	
	$mSupport		= __('For additional information about RapID Secure Login please go to the <a target="_blank" href="https://forums.intercede.com/forum/rapid-sl/">support forum.</a><br/><br/>', 'rp-securelogin');

	echo "<div class='wrap'><br/>$mSupport</div";
}

function rpsl_configuration_tab_output() { 

	// i18n Strings
	$mInstructions    = __('To get started please follow these simple steps:<br/><br/>', 'rp-securelogin');
	$mInstructions   .= __('<strong>Register:</strong><br/>', 'rp-securelogin');
	$mInstructions   .= __('1. Install the RapID Secure Login app on your phone. <br/>', 'rp-securelogin');
	// Itunes + google logo
	$mInstructions2   = __('2. Open the app to scan the QR code below.<br/>', 'rp-securelogin');
	// QR CODE HERE
	$mInstructions3   = __('3. Follow the in-app instructions to complete the set up. <br/>', 'rp-securelogin');
	$mInstructions3  .= __('4. You can now use your phone to login to your WordPress site. <br/>', 'rp-securelogin');

	$googleimg 			= esc_url( plugin_dir_url( __FILE__ ) . "images/google-play-badge.png" ); 
	$appleimg 			= esc_url( plugin_dir_url( __FILE__ ) . "images/apple-store-logo.svg" ); 
	$qrcode 			= rpsl_configuration_tab_qrcode();

echo <<<RPSL_CONFIGURATION

	<div class="wrap">
		<br/>
		$mInstructions
		<br/>
		<a href='https://play.google.com/store/apps/details?id=com.intercede.rapidsl&hl=en_GB' target='_blank'><img src="$googleimg" title="RapID" /></a>
		<a href='https://itunes.apple.com/us/app/rapid-secure-login/id1185934781?mt=8' target='_blank'><img src="$appleimg" title="RapID" /></a>
		<br/><br/>
		$mInstructions2
		$qrcode
		$mInstructions3
	</div>
RPSL_CONFIGURATION;

}

function rpsl_reconfigure_tab_output() {

	// i18n Strings
	$mInstructions    = __('<strong>Reconfigure:</strong><br/>', 'rp-securelogin');
	$mInstructions   .= __('1. Open the RapID Secure Login app on your phone to scan the QR code below.<br/>', 'rp-securelogin');
	$mInstructions2   = __('2. Follow the in-app instructions to complete the set up. <br/>', 'rp-securelogin');
	$qrcode 			= rpsl_configuration_tab_qrcode();

echo <<<RPSL_RECONFIGURE
	<div class="wrap">
		$mInstructions
		$qrcode
		$mInstructions2
	</div>
RPSL_RECONFIGURE;

}

function rpsl_configuration_tab_qrcode() {

	$admin_url = admin_url('admin-ajax.php');
	$ajax_endpoint = RPSL_Configuration::rpsl_ajax_endpoint();
	$ajax_timeout = RPSL_Configuration::$rpsl_ajax_timeout;
	
	// i18n Strings - Wordpress Configuration
	$logoimg 			= esc_url( plugin_dir_url( __FILE__ ) . "images/rapidwm.png" ); 
	$logobig 			= esc_url( plugin_dir_url( __FILE__ ) . "images/rapid.png" ); 
	$blank   			= esc_url( plugin_dir_url( __FILE__ ) . "images/white.jpg" ); 
	$mUseRapid        	= esc_html__('Use RapID - No More Passwords!', 'rp-securelogin');
	$site_configured_message = rpsl_site_configured() ? 'Site configured.' : '';

$qrcode_output = <<<RPSL_QRCODE

	<script type="text/javascript">
		var rpsl_admin_ajaxurl		= "$admin_url";
		var rpsl_ajaxurl			= "$ajax_endpoint";
		var rpsl_timeout			= $ajax_timeout;
	</script>

	<div class="rpsl_outer_div">
		<div class="rpsl_qr_div" id="rpsl_big_logo" name="rpsl_big_logo">
			<img class="rpsl_qr_img" src="$logobig" title="RapID" />
		</div>
		
		<div class="rpsl_qr_div" id="rpsl_qr_code" name="rpsl_qr_code" style="display:none">
			<a name="rpsl_site_registration_url" href=''>
			<img class="rpsl_qr_img"   id="rpsl_site_registration_qr" name="rpsl_site_registration_qr" src="$blank" title="RapID Site Registration" />
			</a>
			<div id="rpsl_small_logo" name="rpsl_small_logo" style="display:none">
				<img class="rpsl_logo_img_click" id="rapidlogo"    name="rapidlogo" src="$logoimg" title="$mUseRapid" />
			</div>
		</div>
		<div class="rpsl_qr_div">
			<span class="rpsl_qr_message" name="rpsl_qr_message">$site_configured_message</span><br /><br />
		</div>
	</div>
RPSL_QRCODE;

	wp_enqueue_script('rpsl_configure_wordpress_registration');
	return $qrcode_output;
}

function rpsl_settings_tab_output() { 

	//i18n strings
	$mLoginLabel      = esc_html__('Display password login ', 'rp-securelogin');
	$mLoginYes        = esc_html__('Always', 'rp-securelogin');
	$mLoginNo         = esc_html__('Never', 'rp-securelogin');
	$mLoginClick      = esc_html__('Click logo to show it', 'rp-securelogin');
	$mSiteName        = esc_html__('RapID Site Name ', 'rp-securelogin');
	$mEmailTemplate   = esc_html__('Email Template ', 'rp-securelogin');
	$mCredentialLifetime = esc_html__('Request Lifetime ', 'rp-securelogin');
	$mRolesLabel 	  = __('User roles allowed to have RapID credentials','rp-securelogin');
	
	// if user not confirmed, show message
	$site_configured = rpsl_site_configured();
	$error_messages = "";
	$mInformation     = "";
	$mInformation    .= rpsl_has_user_confirmed_email() ? '' : __('<strong>Confirm your email to collect your 1000 free licenses.</strong><br/><br/>', 'rp-securelogin');
	$mInformation    .= __('<strong>Need more licenses?</strong><br/>', 'rp-securelogin');
	$mInformation    .= __('You can purchase more through the <a target="_blank" href="https://rapidportal.intercede.com/">RapID dashboard</a>.<br/>', 'rp-securelogin');
	$mReConfigure    = $site_configured ? __('<br/><a href="?page=rpsl-plugin-settings&tab=configuration">Click here to reconfigure your site</a>.<br/>', 'rp-securelogin') : '';

	if (!empty($_POST)) {		
		
		check_admin_referer("settings_form_nonce", "rpsl_settings_form_nonce"); // Security check
		$settings_update_array = array();
		
		$site_name = sanitize_text_field( $_POST['rapid_site_name'] );
		if ($site_name != "") {
			$settings_update_array["site_name"] = $site_name;
		} else
		{
			$error_messages .= "<li>Site name must be provided.</li>";
		}

		$show_login = sanitize_text_field( $_POST['rapid_login_pw'] );
		if($show_login == "Yes" || $show_login == "No" || $show_login == "Click" ) { 
			$settings_update_array["show_login"] = $show_login;
		} else {
			$error_messages .= "<li>Show login must be selected from the radio buttons.</li>";
		}
		
		$credential_lifetime = sanitize_text_field( $_POST['rapid_credential_lifetime'] );
		if(is_numeric($credential_lifetime) && $credential_lifetime >= 1) { 
			$settings_update_array["request_lifetime"] = $credential_lifetime;
		} else {
			$error_messages .= "<li>Credential Lifetime value must be a number greater than 0.</li>";
		}
		
		$credential_lifetime_unit = sanitize_text_field( $_POST['rapid_credential_lifetime_unit'] );
		if(in_array($credential_lifetime_unit, array("d","D","h","H"))) { 
			$settings_update_array["request_lifetime_unit"] = $credential_lifetime_unit;
		} else {
			$error_messages .= "<li>Credential Lifetime Unit must be in days or hours.</li>";
		}

		$email_template = sanitize_textarea_field( $_POST['rapid_email_template'] );
		if (rpsl_EmailParser::validate_email_template($email_template))
		{
			$settings_update_array["email_template"] = $email_template;
		} else {	
			$template_message = rpsl_EmailParser::email_template_validation_message();
			$error_messages .= "<li>$template_message</li>";
		}		

		rpsl_db_update_site_settings($settings_update_array);

		$rapid_roles = !empty($_POST['rpsl_roles']) ? $_POST['rpsl_roles'] : array();
		rpsl_set_allowed_roles($rapid_roles);
		$rapid_enroles = !empty($_POST['rpsl_enroles']) ? $_POST['rpsl_enroles'] : array();
		rpsl_set_allowed_enroles($rapid_enroles);

		if(!empty($error_messages)){
			$error_messages = "<div style='color:red;'><p><strong>Invalid Settings Entered:</strong></p>" . $error_messages . "</div>";
		}
	} else {
		$site_settings = rpsl_db_site_settings();
		$show_login = $site_settings["show_login"];
		$site_name = $site_settings["site_name"];
		$credential_lifetime = $site_settings["request_lifetime"];
		$credential_lifetime_unit = $site_settings["request_lifetime_unit"];
		$email_template = $site_settings["email_template"];
	}
	$selectedLifetimeDays = $selectedLifetimeHours = "";
	$selected = "selected";
	switch($credential_lifetime_unit)
	{
		case 'd':
		case 'D':
			$selectedLifetimeDays = $selected;
			break;
		case 'H':
		case 'h':
			$selectedLifetimeHours = $selected;
			break;
	}

	// show login settings
	$show_login_yes = $show_login == 'Yes' ? 'checked' : '';
	$show_login_no =  $show_login == 'No' ? 'checked' : '';
	$show_login_click = $show_login == 'Click' ? 'checked' : '';

	// Rapid Roles Settings
	global $wp_roles;
	$roles = $wp_roles->get_names();
	$roles_control = "";

	$roles_control .= '<table><tr><th>Allowed to have RapID</th><th>Can enrol by email</th></tr>';
	foreach($roles as $role_key => $role_name) {
		if($role_name == 'Administrator' )  { continue; }    // This is always assigned in rpsl_set_roles.
		if(substr($role_key,0,4) == 'bbp_' ){ continue; }    // Cannot assign capabilities to bbPress roles
		$checked_val1 = get_role($role_key)->has_cap( RPSL_Configuration::$rpsl_can_have_credential_role ) ? ' checked ' : ''; 
		$checked_val2 = get_role($role_key)->has_cap( RPSL_Configuration::$rpsl_can_enrol_credential_role ) ? ' checked ' : ''; 
		
		
		$roles_control .= "<tr>";
		$roles_control .= "<td style='padding:0'><input type='checkbox' $checked_val1 name='rpsl_roles[]'   value='$role_key'>$role_name</input></td>";
		$roles_control .= "<td style='padding:0'><input type='checkbox' $checked_val2 name='rpsl_enroles[]' value='$role_key'></input></td>";
		$roles_control .= "</tr>";
	}
	$roles_control .= "</table>";
	
	$settings_form_nonce = wp_nonce_field("settings_form_nonce", "rpsl_settings_form_nonce");

echo <<<RPSL_SETTINGS
		<div class="wrap">
		<br/>
		$mInformation
		$mReConfigure
		<ul style="list-style-type:disc">$error_messages</ul>
			<form method="post" enctype="multipart/form-data">
				<div class="wrap">
					<table class="form-table">
						<tr><th style="text-align:left">$mSiteName</th> 
						<td>
							<input type="text" name="rapid_site_name" id="rapid_site_name" value="$site_name">
						</td>
						</tr> 
					</table>
				</div>
				<div class="wrap">
					<table class="form-table">
						<tr><th style="text-align:left">$mCredentialLifetime</th>
						<td>
								<input type="text" name="rapid_credential_lifetime" id="rapid_credential_lifetime" value="$credential_lifetime" style="width:65px; vertical-align: bottom;">
							
								<select id="rapid_credential_lifetime_unit" name="rapid_credential_lifetime_unit" style="vertical-align: bottom;">                      
									<option $selectedLifetimeDays value="D">Days</option>
									<option $selectedLifetimeHours value="H">Hours</option>
								</select>
							</div>
						</td>
						</tr> 
					</table>
				</div>
				<div class="wrap">
					<table class="form-table">
						<tr><th style="text-align:left">$mEmailTemplate</th> 
						<td>
							<textarea rows="5" cols="100" type="text" wrap="soft" maxlength="2000" 
								name="rapid_email_template" id="rapid_email_template">$email_template</textarea>
						</td>
						</tr> 
					</table>
				</div>
				<div class="wrap">
					<table class="form-table">
						<tr><th style="text-align:left">$mLoginLabel</th> 
							<td>
								<fieldset>
								<input type="radio"    name="rapid_login_pw"       id="rapid_login_pw_Yes"    value="Yes" 		$show_login_yes   > $mLoginYes   <br>
								<input type="radio"    name="rapid_login_pw"       id="rapid_login_pw_No"     value="No"  		$show_login_no    > $mLoginNo    <br>
								<input type="radio"    name="rapid_login_pw"       id="rapid_login_pw_Click"  value="Click"		$show_login_click > $mLoginClick
								</fieldset>
							</td>
						</tr> 
					</table>
				</div>
				<div class="wrap">
					<table class="form-table">
					<tr id="rpsl_user_roles_panel">
					<th><label for="">$mRolesLabel</label></th>
					<td>$roles_control</td>
					</tr>
					</table>
				</div>
			<input type="submit" name="save_settings" id="save_settings" value="&nbsp; Save &nbsp;" class="button-primary">
			$settings_form_nonce
			</form>
		</div>
RPSL_SETTINGS;
}

// This is obsolete and should be removed in a future release
function rpsl_manual_configuration_tab_output() { 

	// i18n Strings
	$mConfigStatus		= esc_html__('Current configuration status',         'rp-securelogin');
	$mCACertOK  		= esc_html__('Trusted CA Certificate is OK',         'rp-securelogin');
	$mCACertMissing		= esc_html__('Trusted CA Certificate is missing',    'rp-securelogin');
	$mRapIDKeyOK		= esc_html__('RapID Service Key is OK',              'rp-securelogin');
	$mRapIDKeyMissing	= esc_html__('RapID Service Key is missing',         'rp-securelogin');
	$mRapIDCertOK		= esc_html__('RapID Service Certificate is OK',      'rp-securelogin');
	$mRapIDCertMissing	= esc_html__('RapID Service Certificate is missing', 'rp-securelogin');
	$mCERLabel        = __('Upload your RapID Trusted CA Certificate CER file  ', 'rp-securelogin');
	$mPFXLabel        = __('Upload your RapID Server authentication key PFX file ', 'rp-securelogin');
	$mPswLabel        = __('PFX file password', 'rp-securelogin');
	$mCACertOk        = esc_html__('Your RapID trusted CA certificate was successfully imported', 'rp-securelogin');
	$mAuthKeyOk       = esc_html__('Your RapID service authentication key was successfully imported', 'rp-securelogin');

?>
	<div id="rpsl_manual_configuration">
		<br />
		<div style="background-color: #eeee66"><b>
		<?php
		
		if (!empty($_POST)) {
			// Upload the CER file
			if (isset($_POST['upload_trusted_ca_cert']) && $_FILES) {
				check_admin_referer("upload_trusted_ca_cert", "rp_cer_nonce");  // Security check
				$uploaded_file = $_FILES['trusted_ca_cert'];
				// Check size and file type
				$expected_type = 'application/x-x509-ca-cert';
				$uploaded_type = $uploaded_file['type'];
				if ($uploaded_type == $expected_type) {
					$filesize = $uploaded_file['size'];
					if ( $filesize > 1000 && $filesize < 3000 ) {
						// Get contents of issuer certificate
						$file_content = file_get_contents($uploaded_file['tmp_name']);
						$ok = rpsl_add_issuer_certificate($file_content);
						if ($ok) { 
							echo __('Your RapID trusted CA certificate was successfully imported', 'rp-securelogin');
						} else {
							echo __('<b>Error</b> - unable to import this file, it is corrupted.', 'rp-securelogin');
						}
					} else {
						printf( __('<b>Error</b> - this file is not an acceptable size - %s bytes. Expected between 1000 and 3000 bytes', 'rp-securelogin'), $filesize) ;
					}
				} else {
					printf( __('<b>Error</b> - this file is not the correct type - %s. Expected %s', 'rp-securelogin'), $uploaded_type, $expected_type) ;
				}
			}
			
			// Upload the PFX file
			if (isset($_POST['upload_rapid_server_key']) && $_FILES) {
				check_admin_referer("upload_rapid_server_key", "rp_pfx_nonce"); // Security check
				$password = sanitize_text_field( $_POST['pfx_password']) ;
				$uploaded_file = $_FILES['rapid_server_key'];
				$expected_type = 'application/x-pkcs12';
				$uploaded_type = $uploaded_file['type'];
				// Check size and file type
				if ($uploaded_type == $expected_type) {
					$filesize = $uploaded_file['size'];
					if ($filesize > 1000 && $filesize < 4000 ) {
							// Attempt to process the PFX file
							$file_content = file_get_contents($uploaded_file['tmp_name']); 
							$ok = rpsl_import_sac_certificate($file_content, $password);
							if ($ok) {
								echo __('Your RapID service authentication key was successfully imported', 'rp-securelogin');
							} else {
								echo __('<b>Error</b> - unable to import your RapID service authentication key. Please check the password', 'rp-securelogin');
							}
					} else {
						printf( __('<b>Error</b> - this file is not an acceptable size - %s bytes. Expected between 1000 and 3000 bytes', 'rp-securelogin'), $filesize) ;
					}
				} else {
					printf( __('<b>Error</b> - this file is not the correct type - %s. Expected %s', 'rp-securelogin'), $uploaded_type, $expected_type) ;
				}
				
			}
			
			// Set site name or id
			if (isset($_POST['set_rapid_site_name'])) {
				check_admin_referer("set_rapid_site_name", "rpsl_set_site_name_nonce"); // Security check
				$site_name = sanitize_text_field( $_POST['rapid_site_name'] );
				if ($site_name != "") {
					rpsl_db_update_site_settings(array("site_name" => $site_name));
				}
			}

			// Set Password logon Visibility
			if (isset($_POST['set_rapid_login_pw'])) {
				check_admin_referer("set_rapid_login_pw", "rpsl_set_rapid_login_pw"); // Security check
				$show_login   = sanitize_text_field( $_POST['rapid_login_pw'] );
				if ($show_login != "") {
					rpsl_db_update_site_settings(array("show_login" => $show_login));
				}
			}

			// Set permitted roles
			if (isset($_POST['set_rapid_roles'])) {
				check_admin_referer("set_rapid_roles", "rpsl_set_rapid_roles"); // Security check
				$rapid_roles = !empty($_POST['rpsl_roles']) ? $_POST['rpsl_roles'] : array();
				rpsl_set_allowed_roles($rapid_roles);
				$rapid_enroles = !empty($_POST['rpsl_enroles']) ? $_POST['rpsl_enroles'] : array();
				rpsl_set_allowed_enroles($rapid_enroles);
			}
		}
					
		$status = rpsl_db_check_configuration();
		//DIAG echo rp_cert_folder();
		$statusReport = "";
		if ($status['CACert'])        { $statusReport .= $mCACertOK . "<br/>";    } else { $statusReport .= $mCACertMissing . "<br/>";    };
		if ($status['RapidAuthKey'])  { $statusReport .= $mRapIDKeyOK . "<br/>";  } else { $statusReport .= $mRapIDKeyMissing . "<br/>";  };
		if ($status['RapidAuthCert']) { $statusReport .= $mRapIDCertOK . "<br/>"; } else { $statusReport .= $mRapIDCertMissing . "<br/>"; };
		?>

	</b></div> <!-- Close background color div-->
	<b><?php echo $mConfigStatus ?> </b><br/> 
	<?php echo $statusReport ?>
		<div class="wrap">
		<form method="post" enctype="multipart/form-data">
				<table class="form-table">
					<tr><th style="text-align:left"><label><?php echo $mCERLabel ?></label></th>
						<td>
							<fieldset>
							<input type="file"     name="trusted_ca_cert"  id="trusted_ca_cert" ><br><br>
							<input type="submit"   name="upload_trusted_ca_cert"  id="upload_trusted_ca_cert" 
									value="Upload"  class="button-primary">
							</fieldset>
						</td>
					</tr> 
				</table>
				<?php wp_nonce_field("upload_trusted_ca_cert", "rp_cer_nonce"); ?>
			</form>
		</div>
		<div class="wrap">
			<form method="post" enctype="multipart/form-data">
				<table class="form-table">
					<tr><th style="text-align:left"><?php echo $mPFXLabel ?></th> 
					<td>
						<input type="file"     name="rapid_server_key" id="rapid_server_key"><br>
						<?php echo $mPswLabel ?>
						<input type="password" name="pfx_password"     id="pfx_password"    ><br><br>
						<input type="submit"   name="upload_rapid_server_key"  id="upload_rapid_server_key" value="Upload" class="button-primary">
					</td>
					<tr>
				</table>
				<?php wp_nonce_field("upload_rapid_server_key", "rp_pfx_nonce"); ?>
			</form>
		</div>
	</div> <!-- Close manual configuration div -->
<?php
} // end manual configuration output
?>