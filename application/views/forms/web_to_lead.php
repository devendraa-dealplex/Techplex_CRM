<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html dir="<?php echo is_rtl(true) ? 'rtl' : 'ltr'; ?>">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?php echo e($form->name); ?>
    </title>

    <?php app_external_form_header($form); ?>
    <?php hooks()->do_action('app_web_to_lead_form_head'); ?>

    <link href="https://fonts.googleapis.com/css2?family=Arimo:wght@400;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: Arimo;
            background: linear-gradient(135deg, rgba(240, 253, 244, 0.3), white);
            margin: 0;
        }

        /* header */

        .dp-header {

            position: sticky;

            top: 0;

            z-index: 10000;

            display: flex;

            height: 83.988px;

            padding: 0 24px;

            justify-content: space-between;

            align-items: center;

            background: #FFFFFF;

            border-bottom: 0.8px solid #4A5565;

            box-sizing: border-box;

        }


        .dp-logo {
            height: 42px;
            display: block;

        }


        .fw-bold {
            font-weight: 700;
        }

        .mb-3 {
            margin-bottom: 16px;
        }



        /* allow header / paragraph styling */
        .dp-col h1,
        .dp-col h2,
        .dp-col h3,
        .dp-col h4,
        .dp-col h5,
        .dp-col h6,
        .dp-col p {
            width: 100%;
            margin-bottom: 15px;
        }

        /* allow text-center from builder */
        .text-center {
            text-align: center !important;
        }

        .text-left {
            text-align: left !important;
        }

        .text-right {
            text-align: right !important;
        }


        /* container */

        .dp-container {
            max-width: 768px;
            margin: 40px auto;
            padding: 20px;
        }

        /* progress */

        .dp-progress-wrapper {
            margin-bottom: 20px;
        }

        .dp-progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .dp-progress-bar-bg {
            height: 8px;
            background: #E5E7EB;
            border-radius: 999px;
        }

        .dp-progress-bar-fill {
            height: 8px;
            width: 0%;
            background: #1A914B;
            border-radius: 999px;
            transition: 0.4s;
        }

        /* card */

        .dp-card {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        /* inputs */

        .dp-card input,
        .dp-card textarea,
        .dp-card select {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid #ddd;
            background: #F3F4F6;
            margin-bottom: 20px;
        }

        /* upload */

        .dp-upload-box {
            width: 100%;
            min-height: 128px;
            background: rgba(249, 250, 251, 0.5);
            border-radius: 14px;
            border: 1.6px solid #D1D5DC;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            flex-direction: column;
            margin-bottom: 20px;
            padding: 10px;
            box-sizing: border-box;
        }

        .dp-upload-box:hover {
            background: #f0fdf4;
        }

        .dp-upload-text {
            font-size: 14px;
            color: #4A5565;
        }

        .dp-upload-sub {
            font-size: 12px;
            color: #6A7282;
        }

        #dp-upload-preview {
            max-width: 100%;
            max-height: 100px;
            height: auto;
            width: auto;
            object-fit: contain;
            display: none;
            margin-top: 8px;
            border-radius: 8px;
        }

        /* file name wrap properly */
        #dp-upload-name {
            display: none;
            color: #1A914B;
            font-size: 13px;
            margin-top: 5px;
            word-break: break-word;
            text-align: center;
        }

        /* recaptcha */

        .dp-recaptcha {
            margin-bottom: 20px;
        }

        .dp-recaptcha-box {
            width: 100%;
            background: #F3F4F6;
            border-radius: 10px;
            border: 1px solid #D1D5DC;
            padding: 12px;
            display: flex;
            justify-content: center;
        }



        .dp-form-row {
            display: flex;
            flex-wrap: wrap;
            margin-left: -10px;
            margin-right: -10px;
        }

        .dp-col {
            /* padding: 10px; */
            box-sizing: border-box;
        }

        .dp-col-12 {
            width: 100%;
        }

        .dp-col-6 {
            width: 50%;
        }

        .dp-col-4 {
            width: 33.333%;
        }

        .dp-col-3 {
            width: 25%;
        }


        /* FIX: force form builder fields to respect column width */

        .dp-col .form-group {
            width: 100% !important;
            display: block !important;
        }

        .dp-col .form-control {
            width: 100% !important;
        }

        .dp-col select {
            width: 100% !important;
        }

        .dp-col textarea {
            width: 100% !important;
        }

        .dp-col input {
            width: 100% !important;
        }

        /* fix select container sometimes inline */
        .dp-col .select-placeholder,
        .dp-col .input-group {
            width: 100% !important;
        }


        .col-12 {
            width: 100%;
        }

        .col-6 {
            width: 50%;
            float: left;
            padding-right: 10px;
        }

        .col-4 {
            width: 33.33%;
            float: left;
            padding-right: 10px;
        }

        .col-3 {
            width: 25%;
            float: left;
            padding-right: 10px;
        }

        .dp-field-wrapper:after {
            content: "";
            display: block;
            clear: both;
        }


        @media(max-width:768px) {

            .dp-col-6,
            .dp-col-4,
            .dp-col-3 {
                width: 100%;
            }
        }


        /* button */

        #form_submit {
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            border: none;
            background: #1A914B;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        #form_submit:hover {
            background: #157a3f;
        }

        /* SUCCESS PAGE */

        /* SUCCESS PAGE WRAPPER */

        #dealplex-success {

            display: none;

            position: fixed;

            top: 83.988px;

            height: calc(100vh - 83.988px);
            left: 0;

            width: 100%;

            /* CHANGE THIS */
            height: 100%;

            /* ADD THIS */
            overflow-y: auto;

            overflow-x: hidden;

            background: linear-gradient(135deg, rgba(240, 253, 244, 0.3), white);

            justify-content: center;

            align-items: flex-start;

            padding-top: 115px;
            padding-bottom: 40px;

            z-index: 9999;

        }



        /* SUCCESS CARD (exact figma size) */

        .success-card {

            width: 672px;

            max-width: 90%;

            min-height: 624px;

            background: #FFFFFF;

            border-radius: 16px;

            box-shadow:
                0px 8px 10px -6px rgba(0, 0, 0, 0.10),
                0px 20px 25px -5px rgba(0, 0, 0, 0.10);

            position: relative;

            padding: 48px;

            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;

            margin-bottom: 40px;

        }



        /* SUCCESS ICON */

        .success-icon {

            width: 96px;
            height: 96px;

            background: #E8F5EE;

            border-radius: 9999px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 24px;

        }


        /* CHECK ICON */

        .success-check {

            width: 56px;
            height: 56px;

            border-radius: 50%;

            border: 5.8px solid #1A914B;

            position: relative;

        }

        .success-check:after {

            content: "";

            position: absolute;

            width: 14px;
            height: 8px;

            border-left: 5.8px solid #1A914B;
            border-bottom: 5.8px solid #1A914B;

            transform: rotate(-45deg);

            top: 18px;
            left: 16px;

        }


        /* TITLE */

        .success-card h2 {

            width: 576px;
            height: 80px;

            font-size: 36px;
            font-weight: 700;
            line-height: 40px;

            margin-bottom: 16px;

        }


        /* DESCRIPTION */

        .success-card p {

            width: 576px;

            font-size: 16px;
            line-height: 26px;

            margin-bottom: 12px;

            color: #4A5565;

        }


        /* BUTTON CONTAINER */

        .success-buttons {

            width: 576px;

            display: flex;
            gap: 16px;
            justify-content: center;

            margin-top: 32px;

        }


        /* HOME BUTTON */

        .btn-home {

            width: 173px;
            height: 48px;

            background: #1A914B;
            color: white;

            border-radius: 10px;

            display: flex;
            align-items: center;
            justify-content: center;

            text-decoration: none;

            box-shadow:
                0px 2px 4px -2px rgba(0, 0, 0, 0.10),
                0px 4px 6px -1px rgba(0, 0, 0, 0.10);

        }


        /* SUBMIT AGAIN BUTTON */

        .btn-another {

            width: 244px;
            height: 48px;

            background: white;

            color: #1A914B;

            border-radius: 10px;

            border: 1.6px solid #1A914B;

            display: flex;
            align-items: center;
            justify-content: center;

            cursor: pointer;

        }


        /* FOOTER CONTAINER */

        .success-footer {

            width: 576px;
            height: 60.8px;

            border-top: 0.8px solid #E5E7EB;

            padding-top: 32.8px;

            margin-top: auto;

            display: flex;
            justify-content: center;
            align-items: center;

        }


        /* INNER ROW */

        .success-footer-inner {

            width: 576px;
            height: 28px;

            display: flex;
            justify-content: center;
            align-items: center;

            gap: 8px;

        }


        /* DEALPLEX NAME */

        .success-brand-name {

            width: 83px;
            height: 28px;

            font-family: Arimo;
            font-weight: 700;
            font-size: 20px;
            line-height: 28px;

            color: #1A914B;

        }


        /* DOT */

        .success-dot {

            width: 6.5px;
            height: 24px;

            font-family: Arimo;
            font-size: 16px;

            color: #99A1AF;

        }


        /* TAGLINE */

        .success-brand-tagline {

            width: 106px;
            height: 20px;

            font-family: Arimo;
            font-weight: 400;
            font-size: 14px;
            line-height: 20px;

            color: #4A5565;

        }



        /* MOBILE RESPONSIVE SUCCESS */

        @media(max-width:768px) {

            #dealplex-success {

                padding: 20px;
                padding-top: 40px;
                align-items: flex-start;
            }

            .success-card {

                width: 100%;
                padding: 24px;
                min-height: auto;
            }

            .success-card h2 {

                width: 100%;
                font-size: 24px;
                line-height: 32px;
            }

            .success-card p {

                width: 100%;
                font-size: 14px;
                line-height: 22px;
            }

            .success-buttons {

                width: 100%;
                flex-direction: column;
                gap: 12px;
            }

            .btn-home,
            .btn-another {

                width: 100%;
            }

            .success-footer {

                width: 100%;
                padding-top: 20px;
            }

            .success-footer-inner {

                width: 100%;
                flex-wrap: wrap;
            }

        }
    </style>

</head>

<body>


    <!-- HEADER -->

    <div class="dp-header">

        <img src="<?= base_url('assets/images/dealplex_logo.svg') ?>" class="dp-logo">

        <a href="<?= site_url() ?>">Back</a>

    </div>


    <!-- FORM -->

    <div class="dp-container">

        <div class="dp-progress-wrapper">

            <div class="dp-progress-header">
                <div>Partner Application</div>
                <div id="dp-percent">0%</div>
            </div>

            <div class="dp-progress-bar-bg">
                <div class="dp-progress-bar-fill" id="dp-bar"></div>
            </div>

        </div>


        <div class="dp-card">

            <div id="response"></div>

            <?php echo form_open_multipart($this->uri->uri_string(), [
'id'=>$form->form_key,
'class'=>'disable-on-submit'
]); ?>

            <?php echo form_hidden('key', $form->form_key); ?>


            <div class="dp-form-row">

                <?php foreach ($form_fields as $field):

    $width = 12;

    if (isset($field->type) && in_array($field->type, ['header','paragraph'])) {
        $width = 12;
    }
    else if (isset($field->className)) {

        if (strpos($field->className, 'col-6') !== false) {
            $width = 6;
        }
        else if (strpos($field->className, 'col-4') !== false) {
            $width = 4;
        }
        else if (strpos($field->className, 'col-3') !== false) {
            $width = 3;
        }
    }

?>

                <div class="dp-col dp-col-<?php echo $width; ?>">

                    <?php

// FIX: manually render header with class
if ($field->type == 'header') {

    $class = isset($field->className) ? $field->className : '';

    echo '<h1 class="'.$class.'">'.$field->label.'</h1>';

}

// FIX: manually render paragraph with class
else if ($field->type == 'paragraph') {

    $class = isset($field->className) ? $field->className : '';

    echo '<p class="'.$class.'">'.$field->label.'</p>';

}

// normal fields
else {

    render_form_builder_field($field);

}

?>

                </div>

                <?php endforeach; ?>

            </div>




            <!-- recaptcha -->

            <?php if (show_recaptcha() && $form->recaptcha == 1) { ?>

            <div class="dp-recaptcha">

                <div class="dp-recaptcha-box">

                    <div class="g-recaptcha" data-sitekey="<?php echo get_option('recaptcha_site_key'); ?>">
                    </div>

                </div>

                <div id="recaptcha_response_field"></div>

            </div>

            <?php } ?>


            <button id="form_submit">

                <i class="fa fa-spinner fa-spin hide"></i>

                Apply Now

            </button>


            <?php echo form_close(); ?>

        </div>

    </div>



    <!-- SUCCESS PAGE -->

    <div id="dealplex-success">

        <div class="success-card">

            <div class="success-icon">
                <div class="success-check"></div>
            </div>

            <h2>
                Application Submitted Successfully!
            </h2>

            <p>
                Thank you for applying to become a
                <b style="color:#1A914B;">
                    Dealplex Dark Store Partner
                </b>.
            </p>

            <p>
                Our expansion team will contact you within <b>24 hours</b>.
            </p>

            <p>
                Meanwhile, please keep your documents ready for verification.
            </p>

            <div class="success-buttons">

                <a href="<?= site_url() ?>" class="btn-home">
                    Go to Homepage
                </a>

                <div class="btn-another" onclick="location.reload()">
                    Submit Another Application
                </div>

            </div>

            <!-- BOTTOM BRANDING -->

            <div class="success-footer">

                <div class="success-footer-inner">

                    <div class="success-brand-name">
                        Dealplex
                    </div>

                    <div class="success-dot">
                        •
                    </div>

                    <div class="success-brand-tagline">
                        Deals to Delivery
                    </div>

                </div>

            </div>


        </div>

    </div>




    <?php app_external_form_footer($form); ?>


    <script>

        var form_id = '#<?php echo e($form->form_key); ?>';


        /* progress */

        function updateProgress() {

            var total = 0, filled = 0;

            $(form_id).find('input,textarea,select').each(function () {

                if ($(this).prop('required')) {
                    total++;
                    if ($(this).val()) filled++;
                }

            });

            var percent = total ? Math.round(filled / total * 100) : 0;

            $('#dp-percent').text(percent + '%');
            $('#dp-bar').css('width', percent + '%');

        }

        $(document).on('input change',
            form_id + ' input,' +
            form_id + ' textarea,' +
            form_id + ' select',
            updateProgress);


        /* upload preview */

        $(form_id + ' input[type=file]').each(function () {

            var original = $(this);
            var wrapper = original.closest('.form-group');

            original.hide();

            var custom = $(`
<div class="dp-upload-box">
<div class="dp-upload-text">Click to upload files</div>
<div class="dp-upload-sub">Images, PDF, or Documents</div>
<img id="dp-upload-preview">
<div id="dp-upload-name"></div>
</div>
`);

            wrapper.append(custom);

            custom.click(function () {
                original.click();
            });

            original.change(function () {

                var file = this.files[0];

                if (!file) return;

                wrapper.find('#dp-upload-name')
                    .show()
                    .text(file.name);


                if (file.type.startsWith('image/')) {
                    var reader = new FileReader();
                    reader.onload = function (e) {
                        wrapper.find('#dp-upload-preview')
                            .attr('src', e.target.result)
                            .show();

                    };
                    reader.readAsDataURL(file);
                }

            });

        });

        /* MOBILE VALIDATION */

        $(document).on('input', form_id + ' input[name="phonenumber"], ' + form_id + ' input[type="tel"]', function () {

            // allow only numbers
            this.value = this.value.replace(/\D/g, '');

            // max 10 digits
            if (this.value.length > 10) {
                this.value = this.value.slice(0, 10);
            }

        });



        /* submit */

        $(form_id).appFormValidator({

            onSubmit: function (form) {

                var mobile = $(form).find('input[name="phonenumber"], input[type="tel"]').val();

                var mobileRegex = /^[6-9][0-9]{9}$/;

                if (!mobileRegex.test(mobile)) {

                    alert('Please enter valid 10 digit mobile number');

                    return false;
                }

                $('#form_submit .fa-spin').removeClass('hide');

                var formData = new FormData(form);

                $.ajax({

                    type: 'POST',
                    url: form.action,
                    data: formData,
                    contentType: false,
                    processData: false

                })
                    .done(function (res) {

                        res = JSON.parse(res);

                        if (res.success) {

                            $('.dp-container').hide();

                            $('#dealplex-success').css('display', 'flex');

                            window.scrollTo(0, 0);

                            // run sparkle
                            runSparkleEffect();

                        }


                        else {

                            $('#recaptcha_response_field').html(res.message);

                        }

                    })
                    .always(function () {

                        $('#form_submit .fa-spin').addClass('hide');

                        if (typeof (grecaptcha) != 'undefined') {
                            grecaptcha.reset();
                        }

                    });

                return false;

            }

        });

        function runSparkleEffect() {

            var duration = 3000;
            var end = Date.now() + duration;

            var colors = ['#1A914B', '#22c55e', '#16a34a', '#4ade80'];

            (function frame() {

                confetti({
                    particleCount: 4,
                    angle: 60,
                    spread: 55,
                    origin: { x: 0 },
                    colors: colors
                });

                confetti({
                    particleCount: 4,
                    angle: 120,
                    spread: 55,
                    origin: { x: 1 },
                    colors: colors
                });

                if (Date.now() < end) {
                    requestAnimationFrame(frame);
                }

            })();

        }

    </script>


    <?php hooks()->do_action('app_web_to_lead_form_footer'); ?>

    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>

</body>

</html>