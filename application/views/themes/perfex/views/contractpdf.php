<?php

defined('BASEPATH') or exit('No direct script access allowed');

// Theese lines should aways at the end of the document left side. Dont indent these lines
$html = <<<EOF
    <p style="font-size:20px;"># {$number}
    <br /><span style="font-size:15px;">{$contract->subject}</span>
    </p>
    <div style="width:680px !important;">
    {$contract->content}
    </div>
    EOF;
$pdf->writeHTML($html, true, false, true, false, '');
