<?php

return array(

    /** The default gateway name */
    'gateway' => 'Verifone',

    /** The default settings, applied to all gateways */
    'defaults' => array(
        'testMode' => false
    ),

    'verifone' => array(
        'systemId' => 0,
        'systemPasscode' => 0,
        'systemGUID' => 0,
        'accountId' => 0,
        'client' => '',
        'testClient' => '',
        'accountPasscode' => '' //Leave blank
    )

);
