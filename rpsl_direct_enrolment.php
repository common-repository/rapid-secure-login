<?php
//*************************************************************************************
//*************************************************************************************
//   Direct Enrolment Shortcode - Allows a person to add a user account and enrol them into RapID
//
//   [rpsl_direct_enrolment]
//
//*************************************************************************************
//*************************************************************************************

function rpsl_direct_enrolment_html() {

    // Enrolment Validation
    $loggedin_message = __('You must be logged in to use direct enrolment', 'rp-securelogin');

	$user = wp_get_current_user();
	if ( !($user instanceof WP_User) ) { return $loggedin_message; }

	$target_user_id = $user->ID;
	if ( $target_user_id == 0 ) { return $loggedin_message; }

	if (!empty($_POST)) {	
        check_admin_referer("enrolment_form_nonce", "rpsl_enrolment_form_nonce"); // Security check	
        return rpsl_enrolment_form_post_processing();
    } 

    return rpsl_direct_enrolment_form();
}

function rpsl_direct_enrol_add_user($email, $first_name, $last_name, $role)
{
    $password = wp_generate_password(12,true);
	$userinfo = array(
        'user_login'            => $email,
        'user_email'            => $email,
        'user_pass'             => $password, 
        'first_name'			=> $first_name,
        'last_name'				=> $last_name,
        'nickname'				=> $first_name,
        'display_name'			=> $first_name,
        'role'					=> $role,
    );

    $user_id = wp_insert_user( $userinfo );

    return $user_id;
}

function rpsl_check_user_existence_by_email()
{
	$data_json = file_get_contents("php://input");     
	$data = json_decode($data_json);   

    if(rpsl_is_empty($data->email)) {
        $exists = false;
    }
    else {    
        $user = get_user_by( 'email', $data->email);
        $exists = is_a($user, 'WP_User');
    }

    $message = json_encode(array("exists"=>$exists));
	rpsl_echo($message);	
	die;
}

function rpsl_generate_email_content($email_template, $qr_info, $first_name, $last_name)
{
    $url = add_query_arg(
        array(
            'action'=>'rpsl_launch_app',
            'rp' => $qr_info['userdata']
            ),
        admin_url('admin-ajax.php')
        );


    $alt_text = __('Sorry, the QR code could not be resolved, please see the attached image for the QR code to scan or tap here to launch directly on your mobile device.', 'rp-securelogin');

    $formatted_qrcode = "<a href='$url'><img src='$qr_info[qrurl]' alt='$alt_text' title='$alt_text' /></a>";
    $email_values = array(
        "name" => $first_name . " " . $last_name,
        "sitename" => rpsl_site_name(),
        "qrcode" => $formatted_qrcode,
    );

    $email_parser = new rpsl_EmailParser($email_template, $email_values);
    $email_content = $email_parser->generate_email();

    return $email_content;
}

function rpsl_direct_enrol_send_email($message, $email_address)
{
    $sitename = rpsl_site_name();
    $email_subject = "$sitename Registration";
    $headers = "";
    $successfully_sent_to_mta = true;
    add_filter( 'wp_mail_content_type', 'rpsl_set_html_mail_content_type' );

    if(!wp_mail($email_address, $email_subject, $message, $headers)) {
        rpsl_diag($GLOBALS['phpmailer']->ErrorInfo);
        $successfully_sent_to_mta = false;
    }
    // Reset content-type to avoid conflicts -- https://core.trac.wordpress.org/ticket/23578
    remove_filter( 'wp_mail_content_type', 'rpsl_set_html_mail_content_type' );
    return $successfully_sent_to_mta;
}

function rpsl_set_html_mail_content_type() {
    return 'text/html';
}

function rpsl_direct_enrolment_form($enrol_error = "")
{
	
    $no_roles   = __('No roles have been set for direct enrolment.<br>Please use RapID Settings to assign some.', 'rp-securelogin');
    $admin_url  = admin_url('admin-ajax.php');
    $first_name = "";
    $last_name  = "";
	$role       = "Subscriber";   // Set a default role
	
	if (!empty($_POST)) {	
        $first_name     = sanitize_text_field( $_POST['rapid_first_name'] );
        $last_name      = sanitize_text_field( $_POST['rapid_last_name'] );
        $email          = sanitize_text_field( $_POST['rapid_email'] );
        $email_template = sanitize_text_field( $_POST['rapid_email_template']);
    } else {
        $site_settings  = rpsl_db_site_settings();    
        $email_template = $site_settings["email_template"];
        $email = "";
    }
    
	// Add a Role selector
	$role_picker = '<select name="rapid_role" id="rapid_role" class="  class="rpsl_de_pick">';
	$all_roles = wp_roles()->roles;
	$roles_found = false;
	foreach ( $all_roles as $role_key=>$value ) {
	   // Filter to only those roles allowed credentials.  
	   // Administrator automatically has ALL capabilities though, so never list it
		if(get_role($role_key)->has_cap( RPSL_Configuration::$rpsl_can_enrol_credential_role )
		   && $value['name'] != 'Administrator'){
			  $role_picker .=  '<option value="'.$role_key.'">'.$value['name'].'</option>';
			  $roles_found = true;
		}
	}
	$role_picker .= '</select>';	
	if(!$roles_found) { $role_picker = $no_roles; }
	
    $error_message_row =  !empty($enrol_error) ? "<tr><td colspan='2'><strong>$enrol_error<strong></td></tr>" : "";    
    $enrolment_form_nonce = wp_nonce_field("enrolment_form_nonce", "rpsl_enrolment_form_nonce");
    
$content = <<<RPSL_DirectEnrolment
	<script type="text/javascript">
		var rpsl_admin_ajaxurl		= "$admin_url";
	</script>
<div>
    <form method="post" enctype="multipart/form-data">
        <div class="wrap">
            <table class="form-table">
                $error_message_row
                <tr>
                    <th style="text-align:left">First Name</th> 
                    <td class="rpsl_de_td"><input type="text"  class="rpsl_de_tx" name="rapid_first_name" id="rapid_first_name" value="$first_name"></td>
                </tr> 
                <tr>
                    <th style="text-align:left">Last Name</th> 
                    <td class="rpsl_de_td"><input type="text"  class="rpsl_de_tx" name="rapid_last_name" id="rapid_last_name" value="$last_name"></td>
                </tr> 
               <tr>
                    <th style="text-align:left">Role</th> 
                    <td class="rpsl_de_td">$role_picker</td>
                </tr> 
				
                <tr>
                    <th style="text-align:left">Email</th> 
                    <td class="rpsl_de_td"><input type="text" class="rpsl_de_tx" name="rapid_email" id="rapid_email" value="$email"></td>
                </tr> 
                <tr>
                    <th style="text-align:left; vertical-align:top">Email Template</th> 
                    <td class="rpsl_de_td">
                        <textarea rows="10" cols="100" type="text" wrap="soft" maxlength="2000" name="rapid_email_template" id="rapid_email_template">$email_template</textarea>
                    </td>
                </tr>
                <tr>
                    <td></td>
                    <td>			
                        <input type="submit" name="direct_enrol_user" id="direct_enrol_user" value="Add New User" class="button-primary">
                        $enrolment_form_nonce
                    </td>
                </tr> 
            </table>
        </div>
    </form>
</div>
RPSL_DirectEnrolment;
wp_enqueue_script('rpsl_direct_enrolment');
	
return $content;
}

function rpsl_enrolment_form_post_processing()
{
   	$minimum_data_message           = __('First name, last name and email must be supplied.', 'rp-securelogin');
    $invalid_email_address_message  = __('A valid email address must be supplied.', 'rp-securelogin');
    $invalid_email_template_message = __('The email template must contain all required tags.', 'rp-securelogin');
    $enrol_error = "";
    
    $first_name     = sanitize_text_field( $_POST['rapid_first_name'] );
    $last_name      = sanitize_text_field( $_POST['rapid_last_name'] );
	$role           = sanitize_text_field( $_POST['rapid_role'] );    // CE Added
    $email          = sanitize_text_field( $_POST['rapid_email'] );
    $email_template = sanitize_textarea_field( $_POST['rapid_email_template']);
    $enrol_error    = "";

    if(rpsl_is_empty($email) || rpsl_is_empty($first_name) || rpsl_is_empty($last_name) || rpsl_is_empty($role)) {
        $enrol_error = $minimum_data_message;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $enrol_error = $invalid_email_address_message;
    } elseif (!rpsl_EmailParser::validate_email_template($email_template)) {
        $enrol_error = rpsl_EmailParser::email_template_validation_message();
    }

    if(!empty($enrol_error))
    {
        return rpsl_direct_enrolment_form($enrol_error);
    }

    $response_content = <<<RPSL_EnrolmentResponseTemplate
<div>
    <div class="wrap">
        <h2>%s</h2> 
        <p>%s</p>
    </div>
</div>
RPSL_EnrolmentResponseTemplate;

    $existing_user = false;
    $user = get_user_by( 'email', $email);
    
    if(is_a($user, 'WP_User')) {
        $existing_user = true;
        $user_id = $user->ID;
    } else {
        
        $user_id = rpsl_direct_enrol_add_user($email, $first_name, $last_name, $role);

        if( is_wp_error($user_id) ){
            $enrol_error = $user_id->get_error_message();
            rpsl_diag($enrol_error);
            return "Unable to create a WordPress user: $enrol_error";
        }
    }

    $qr_result = rpsl_generate_direct_enrolment_qrcode($user_id);
    if( array_key_exists("error", $qr_result) ) {
        $message = "The attempt to request a RapID credenital for user $first_name $last_name has failed. Error message: $qr_result[error].";
        return sprintf($response_content,"Enrolment Failure", $message);
    }

    $email_content = rpsl_generate_email_content($email_template, $qr_result, $first_name, $last_name);
    $successfully_sent_to_mta = rpsl_direct_enrol_send_email($email_content, $email);

    if(!$successfully_sent_to_mta)
    {
        if(!$existing_user){
            wp_delete_user( $user_id );
        }

        $message = "The user $first_name $last_name has not been enrolled due to an email error. Please check with your site administrator that email functionality is correctly configured for the WordPress site.";
        return sprintf($response_content,"Enrolment Failure", $message);
    }
    
    $message = "The user $first_name $last_name has had a RapID credential requested. They will receive an email with instructions on how to collect the login.";
    return sprintf($response_content,"Enrolment Request Successful", $message);
}

function rpsl_generate_direct_enrolment_qrcode($user_id) {
    global $wpdb;
    
    $ht_sessionid     = session_id();
    $rpsl_uuid        = rpsl_UUID::v4();
    $request_lifetime = rpsl_get_request_lifetime();
    $rpsl_requestid   = rpsl_get_new_rapid_request($rpsl_uuid, $request_lifetime);
    
    if( is_a($rpsl_requestid,'rpsl_Rapid_Error') ) {
        
        $error_message = $rpsl_requestid->rawErrorMessage();            
        rpsl_diag("rpsl_generate_registration_qrcode error: $error_message");
        return array("error" => $rpsl_requestid->rpsl_extract_rapid_service_message());
    }

    $user = get_user_by("ID", $user_id);
    $loginname = sanitize_text_field( $user->user_login );

    $table_name = $wpdb->prefix . "rpsl_sessions";
    $insert = $wpdb->prepare("INSERT INTO " . $table_name . " (sessionid, rpsession, loginname, action, requested, uuid, userdata) " .
                "VALUES (%s, %s, %s, 'user_enrol', Now(), %s, %s)", $ht_sessionid, $rpsl_uuid, $loginname, $rpsl_uuid, $rpsl_requestid);
    $results = $wpdb->query( $insert );

    $qr_data = rpsl_qr_data("E", $rpsl_requestid, $loginname);
    $qrcode_url = rpsl_qrcode_asfile($qr_data);

    return array("qrurl" => $qrcode_url, "userdata" => $rpsl_requestid);
}

// Note - assumption here is that site settings are valid
function rpsl_get_request_lifetime() {
    $site_settings = rpsl_db_site_settings();
    $credential_lifetime = $site_settings["request_lifetime"];
    $credential_lifetime_unit = $site_settings["request_lifetime_unit"];

    $credential_lifetime *= 60;
    if( strtolower($credential_lifetime_unit) == 'h' ) {
        return $credential_lifetime;
    }

    $credential_lifetime *= 24;
    return $credential_lifetime;
}

add_shortcode( 'rpsl_direct_enrolment', 'rpsl_direct_enrolment_html' );

class rpsl_EmailParser {

    static $_openingTag = '{{';
    static $_closingTag = '}}';
    static $_mandatoryKeys = array("qrcode", "name", "sitename");

    protected $_email_values;
    protected $_email_template;

    public function __construct($email_template, $email_values) {
        $this->_email_template = $email_template; 
        $this->_email_values = $email_values;
    }

    public function generate_email() {
        $html = $this->_email_template;
        foreach ($this->_email_values as $key => $value) {
            $tag = rpsl_EmailParser::get_tag($key);
            $html = str_replace($tag, $value, $html);
        }
        return nl2br($html);
    }

    public static function get_tag($tagname)
    {
        return rpsl_EmailParser::$_openingTag . $tagname . rpsl_EmailParser::$_closingTag;
    }

    public static function validate_email_template($email_template)
    {
        foreach (rpsl_EmailParser::$_mandatoryKeys as $key) {
            $tag = rpsl_EmailParser::get_tag($key);

            if(strpos($email_template, $tag) === false) {
                return false;
            }
        }

        return true;
    }

    public static function email_template_validation_message()
    {
        $invalid_template_message = __('The email template must contain the following keys: ', 'rp-securelogin');
        $implode_separator = rpsl_EmailParser::$_closingTag . ", " . rpsl_EmailParser::$_openingTag;
        return $invalid_template_message . rpsl_EmailParser::$_openingTag . implode($implode_separator, rpsl_EmailParser::$_mandatoryKeys) . rpsl_EmailParser::$_closingTag;
    }
}