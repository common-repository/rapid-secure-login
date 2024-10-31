<?php
function rpsl_launch_app() {

    $userdata = isset($_GET["rp"]) && !rpsl_is_empty($_GET["rp"]) ? $_GET["rp"] : false;

    if($userdata === false) {
        rpsl_launch_app_error("No rp variable present in the querystring");
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "rpsl_sessions";
    $session_row_query = $wpdb->prepare("SELECT * FROM $table_name WHERE userdata = %s", $userdata );
    $session_row  = $wpdb->get_row($session_row_query);

    if($session_row === null)
    {
        rpsl_launch_app_error("No matching credential to be collected, $userdata");
    }

    if(rpsl_is_empty($session_row->loginname) || rpsl_is_empty($session_row->userdata)) {
        rpsl_launch_app_error("Invalid Session, missing loginname or userdata. rp: $userdata");
    }

    $qr_data = rpsl_qr_data("E", $session_row->userdata, $session_row->loginname);
    rpsl_launch_app_collection_html($qr_data);
}

function rpsl_launch_app_collection_html($qrdata) {
    $target_location = "rapid02://qr?sess=$qrdata";
    $site_name = rpsl_site_name();
    $rapid_logo = 	esc_url( plugin_dir_url( __FILE__ ) . "images/rapid.png" ); 
    echo <<<REDIRECT_INFO
<html>
<head>
<style>
body {margin: 0; padding: 0; font-size: 100%; line-height: 1.5;}
article, aside, figcaption, figure, footer, header, nav, section {display: block;}
h1, h2, h3, h4 {margin: 1em 0 .5em; line-height: 1.25;}
h1 {font-size: 2em;}
h2 {font-size: 1.5em;}
h3 {font-size: 1.2em;}
ul, ol {margin: 1em 0; padding-left: 40px;}
p, figure {margin: 1em 0;}
a img {border: none;}
sup, sub {line-height: 0;}

body {
    font-family: Verdana, Arial, Helvetica, sans-serif;
    font-size: 13px;
    color:#333
}

p {
    padding: 10px;
}

#wrapper {
    margin: 0 auto;
    width: 1000px;
}

#contentwrap {
    width: 1000;
    float: left;
    margin: 0 auto;
}

#content {
    background: #FFFFFF;
    border-radius: 10px;
    border: 1px solid #ebebeb;
    margin: 5px;
}

#logo {
    float:left;
    margin:10px 30px 10px 10px;
}

h2, h4 {
    color: #1D4B93; 
    margin:8px;
}

</style>
</head>
<body>
<div id="wrapper">
    <div id="contentwrap">
        <div id="content">
            <div>
                <img src="$rapid_logo" id="logo" />
                <h2>RapID Secure Login Enrolment for $site_name</h2>
            </div>
            <p>If this message remains on the screen then you probably do not have the RapID Secure Login app installed.</p>
            <h4>To obtain the RapID Secure Login app follow the appropriate link below:</h4>
            <ul>
                <li><a href="https://itunes.apple.com/us/app/rapid-secure-login/id1185934781">iOS</a></li>
                <li><a href="https://play.google.com/store/apps/details?id=com.intercede.rapidsl">Android</a></li>
            </ul>
            <p>Once the RapID Secure Login app is installed, tap the link in your registration email again.</p>
            
            <h4>Already have the app?</h4>
            <p>If you already have the RapID Secure Login app installed and it didn't launch, please tap <a href="$target_location">here</a> to start.</p>
        </div>
    </div>
</div>
<script type="text/javascript">
    window.location.replace('$target_location');
</script>
</body>
</html>
REDIRECT_INFO;
    die;
}

function rpsl_launch_app_error($message) {

    rpsl_diag("Error during mobile redirect: $message");
    echo <<<REDIRECT_ERROR
<div class="container-fluid">
    <div>
        <p>An error occurred during the enrolment process. Please retry through scanning the QR code from within the app directly or contact your system administrator.</p>
    </div>
</div>
REDIRECT_ERROR;
    die;
}