jQuery(document).ready(function($) {

    rpsl_prevent_submission();
    jQuery('#rapid_email').blur(rpsl_check_email);
    jQuery('#rapid_email').focus(rpsl_prevent_submission);
})

function rpsl_check_email()
{
    var checkEndpoint = rpsl_admin_ajaxurl + "?action=rpsl_check_user_existence_by_email";
    var email = jQuery('#rapid_email').val();
    var data = JSON.stringify({'email' : email});
    jQuery.ajax({
        type: "POST",
        url: checkEndpoint,
        data: data,
        contentType: "application/json; charset=utf-8",
        dataType: "json"})
    .done(rpsl_check_email_done)
    .fail(rpsl_check_email_fail);
}

function rpsl_check_email_fail(request, statusText, errorText)
{
    alert(errorText + ":" + statusText);
}

function rpsl_check_email_done(data, statusText, request)
{
    var btnTitle = data.exists ? "Enrol Existing User" : "Add New User" 
    jQuery('#direct_enrol_user').prop('value', btnTitle);
    rpsl_enable_submission();
}

function rpsl_prevent_submission()
{
    jQuery('#direct_enrol_user')
        .prop('disabled', true)
        .fadeTo("slow",0.2, null);
}

function rpsl_enable_submission()
{
    jQuery('#direct_enrol_user')
        .prop('disabled', false)
        .fadeTo("slow", 1.0, null);
}

