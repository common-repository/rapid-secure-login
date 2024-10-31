<?php
//*************************************************************************************
//*************************************************************************************
//   LOGIN / AUTHENTICATION
//*************************************************************************************
//*************************************************************************************


//*************************************************************************************
//   WORDPRESS LOGIN page - COMPLETE SECTION FOR USE AS SHORTCODE
//*************************************************************************************
function get_redirect_location($attributes) {
    $redirect_to = home_url();
	if (isset($attributes["redirect_to"])){ $redirect_to = $attributes["redirect_to"];}
	if (isset($_GET['redirect_to'])){ $redirect_to = $_GET['redirect_to'];}
	return $redirect_to;
}

function rpsl_wplogin_fullpage_html($attributes) {
	if(is_user_logged_in()){
		$escaped_redirect_to = esc_html(get_redirect_location($attributes));
		if ($escaped_redirect_to){
			 return <<<REDIRECT
<script type="text/javascript">
    window.location.replace('$escaped_redirect_to');
</script>
REDIRECT;
        }
		return '';
	}

// Provides the HTML content to inject into the WP Login form
	$logoimg     = esc_url(plugin_dir_url( __FILE__ ) . "images/rapidwm.png"); 
	$blank       = esc_url(plugin_dir_url( __FILE__ ) . "images/white.jpg"); 
	$adminUrl    = esc_url(rpsl_append_admin_url());
	
	$show_login  = rpsl_show_login() != "No";  // Yes, No or Click

	$attributes = shortcode_atts( array(
		'showregister' => "true",
		'redirect_to'  => "/",
	), $attributes, 'rpsl_secure_login' );

	$redirect_to = get_redirect_location($attributes);
	$escaped_redirect_to = esc_html($redirect_to);
		
	// i18n Strings
	$mPleaseUseTheApp = __('Use the <a href="https://www.intercede.com/rapidsl" target="_blank">RapID Secure Login</a> app to scan the QR Code, or just tap the code on your phone.', 'rp-securelogin');
	$mGenerating      = esc_html__('Generating the login code', 'rp-securelogin');
	$mRegister        = esc_html__('Register for a new account', 'rp-securelogin');
	$mLostPassword    = esc_html__('Lost your password?', 'rp-securelogin');
	$mScanWithRapid   = esc_html__('Scan with RapID to sign in - No More Passwords!', 'rp-securelogin');
	$mPasswordShown   = esc_html__('Or enter your username and password below', 'rp-securelogin');

	$mClickToShow = ($show_login
		? esc_html__('Click to show password fields', 'rp-securelogin')
		: esc_html__('Password login has been disabled for this site', 'rp-securelogin'));
	$clickHandler = ($show_login
		? 'onClick = "rpsl_toggle_password_display();" '
		: '');
	$hiddenDiv =  ($show_login
		? ''
		: 'style="display:none"');

	$loginUrl = wp_login_url();

	// Registration link available if shortcode has it set and WP registration is allowed
	$showRegisterLink = $attributes["showregister"] === "true" && get_option( 'users_can_register' ) ? 
		'<br/>	'
		.'<p id="register" >'
		.'	<a href="' .  $loginUrl . '?action=register'. '">' . $mRegister . '</a>'
		.'</p>'
		: '';
		
	// For a shortcode, we must RETURN the output, not just echo it, or it appears in the wrong place
	$content = <<<SHORTCODE
<div>
	<form name="loginform" id="loginform" action="$loginUrl?redirect_to=$escaped_redirect_to" method="post" style="text-align: left;">   
		<div class="rpsl_outer_div" id="rpsl_wplogin_div">
			<span>$mPleaseUseTheApp</span><br/><br/>
			<div class="rpsl_qr_div" style = "position: relative; left:14px; ">	
				<div class="rpsl_spinner" style="top:10px;"	><img id="spinnerPurple"	style="opacity: 0.5;" ></div>
				<div class="rpsl_spinner" style="top:20px;"	><img id="spinnerRoyalBlue"	style="opacity: 0.5;" ></div>
				<div class="rpsl_spinner" style="top:35px;"	><img id="spinnerPink"		></div>
				<div class="rpsl_spinner" style="top:43px;"	><img id="spinnerLightBlue"	></div>
				<div class="rpsl_spinner" style="top:56px;"	><img id="spinnerDarkBlue"	style="opacity: 0.5;" ></div>
				<div class="rpsl_spinner" style="top:6px;"	><img id="spinnerOrange"	></div>
			</div>
			<div class="rpsl_qr_div" style="position:relative; top:10px">
				<a id="rpsl_qr_url" href="">
					<img class="rpsl_qr_img" id="rpsl_login_qr" name="rpsl_login_qr" src="$blank" title="$mScanWithRapid" />
  				</a>
				<div>
					<img class="rpsl_logo_img_click" id="rapidlogo" name="rapidlogo" src="$logoimg" title="$mClickToShow" $clickHandler/>
				</div>
				<span id="rpsl_login_status" >$mGenerating</span>
				<br/><br/><span class="rpsl_login_error" name="rpsl_login_error" id="rpsl_login_error"></span>
			</div>
		</div>
		<div id="loginfields" $hiddenDiv>
			<span id="PasswordMessage">$mPasswordShown</span><br/><br/>
			<label>Username: <input type="text"     name="log"        id="user_login" value="" size="20"    tabindex="1" /></label><br />   
			<label>Password: <input type="password" name="pwd"        id="user_pass"  value="" size="20"    tabindex="2" /></label><br />  
						 <input type="submit"   name="rp-submit"  id="rp-submit"  value="Login &raquo;" tabindex="4" />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
			<div id="lost" style="float: right">
				<a href="$loginUrl?action=lostpassword">$mLostPassword</a>
			</div>
		</div>
		<input type="hidden" name="redirect_to" value="$redirect_to" /> 
	</form>
	$showRegisterLink
</div>
SHORTCODE;
	$content .= rpsl_wplogin_fullpage_script();
	
	return $content;
}

//*************************************************************************************
//   WORDPRESS LOGIN page - add the scripts for the Shortcode login page
//*************************************************************************************
function rpsl_wplogin_fullpage_script() {

	// i18n Strings
	$mWaitingForAuth  = esc_html__('Waiting for authorization', 'rp-securelogin');
	$mAuthorized      = esc_html__('Authorized for user ', 'rp-securelogin');
 	$toggle_login     = rpsl_show_login() === "Click";  // Yes, No or Click
	$mPasswordShown   = esc_html__('Or enter your username and password below', 'rp-securelogin');
	$mPasswordHidden  = esc_html__('Or click the RapID logo to use a password', 'rp-securelogin');

	wp_enqueue_script('rpsl_qr_animation');

	// For a shortcode, we must RETURN the output, not just echo it, or it appears in the wrong place
	$content = "";
	$content .= '<script type="text/javascript" >';
	$content .= '	var rpsl_image_directory    = "' . plugin_dir_url( __FILE__ ) . '"; ';

	$content .= '	var waiting_for_auth = "'. $mWaitingForAuth .'"; ';
	$content .= '	var authorized_message = "'. $mAuthorized .'"; ';
	$content .= '	var rpsl_wplogin_diags    = false; ';    // Diagnostics flag

	$content .= '	var rpsl_ajaxurl  = "' . RPSL_Configuration::rpsl_ajax_endpoint() . '"; ';
	$content .= '	var rpsl_ajax_timeout  = ' . RPSL_Configuration::$rpsl_ajax_timeout . '; ';
	$content .= '	var rpsl_wplogin_ajaxurl  = "' . admin_url('admin-ajax.php') . '"; ';

	$content .= '	window.onbeforeunload = function() { ';
	$content .= '		isPageBeingRefreshed = true; ';
	$content .= '	}; ';
		
	$content .= '	function rpsl_toggle_password_display() {';
	$content .= '		if (rpsl_wplogin_pwform_visible) {';
	$content .= '			document.getElementById("loginfields").style.display = "none";';
	$content .= '			document.getElementById("PasswordMessage").innerText = "' . $mPasswordHidden . '";';
	$content .= '			rpsl_wplogin_pwform_visible = false;';
	$content .= '		} else {';
	$content .= '			document.getElementById("loginfields").style.display = "block";';
	$content .= '			document.getElementById("PasswordMessage").innerText = "' . $mPasswordShown . '";';
	$content .= '			rpsl_wplogin_pwform_visible = true;';
	$content .= '		}';
	$content .= '	};';

	$content .= '	jQuery(document).ready(function($) { ';
	$content .= $toggle_login ? ' rpsl_toggle_password_display();' : '' ;
	$content .= '		rpsl_setLoginQR(); ';
	$content .= '	})';
		
	$content .= '</script>';
	
	return $content;
}

add_shortcode( 'rpsl_secure_login', 'rpsl_wplogin_fullpage_html' );