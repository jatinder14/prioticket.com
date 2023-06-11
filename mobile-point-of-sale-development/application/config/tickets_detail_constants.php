<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
$tp_native_api_constant = array(

    'arena_proxy_listener' => array(
        'distributor_id' => 982,
        'token' => 'SMH-TU98-I0BL-S9W',
        'end_point' => 'https://arena.azure-api.net/prioticket/booking_service',
        'timeout_value' => 4000,
        'enabled_museums' => array(3966)
    ),
    /*'burj_khalifa' => array(
        'third_party_code' => 'burj_khalifa',
        'agent_id' => '501',        
        'second_server_hit' => 'https://teststapi.prioticket.com/burj_khalifa_request.php',
        'tp_end_point' => 'http://stagingatthetop.emaar.ae/NewAgentServices/AgentBooking.asmx?WSDL',
        'tp_user' => 'rutger@prioticket.com',
        'tp_password' => 'cl70ac61',
    ),*/
    'burj_khalifa' => array(
        'third_party_code' => 'burj_khalifa',
        'tp_password' => 'Telefoon01@',
        'tp_user' => 'burjkhalifa@prioticket.com',
        'agent_id' => '687',
        'tp_end_point' => 'https://tickets.atthetop.ae/agentservices/AgentBooking.asmx?WSDL',
        'second_server_hit' => 'https://stapi.prioticket.com/burj_khalifa_request.php',
    ),
    'Clorian' => array(
        'third_party_code' => 'Clorian',
        'api_key' => 'k8XUK68Kmy9jNmC3LrCRP4ZVqTWODPy85hfrbvj2',
        'aws_access_key' => 'AKIAJRGAD2SKQXKSKODQ',
        'aws_region' => 'eu-west-1',
        'aws_secret_key' => '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',
        'aws_service_name' => 'execute-api',
        'host_name' => 'test-api-v2.clorian.com',
        'tp_channel_id' => '2',
        'tp_channel_type' => '6',
        'tp_end_point' => 'https://test-api-v2.clorian.com',
        'tp_user' => 'prioticket_api@prioticket.com',
        'tp_password' => 'greyzone170',
    ),
    'dpr' => array(
        'third_party_code' => 'dpr',
        'tp_channel_id' => '14',
        'tp_channel_type' => '14',
        'tp_end_point' => 'https://am-ppr.dubaiparksandresorts.com/wso2',
        'tp_user' => 'j66oyfb7uV_6bK3bHHKjw4DWEGUa',
        'tp_password' => 'owNSf6kZXABjglRav1l5a3iiAx0a',
     ),
    'fareharbor' => array(
        'third_party_code' => 'fareharbor',
        'tp_end_point' => 'https://demo.fareharbor.com/api/external/v1/companies',
        'tp_api_app' => '4c690584-3c98-4b12-8ea2-0b5bc596f3f7',
        'tp_api_user' => array('EUR' => '28b664a5-4e42-497e-a26a-ad6016dc1954', 'SEK' => '28b664a5-4e42-497e-a26a-ad6016dc1954')
    ),
    'gt' => array(
        'prio' => array(
            'api_key' => 'ACC89260-A877-4F13-ADFF-31C8077E4F6C',
            'secret_key' => '314A0219-B5BF-4147-B765-839C7F7EEB41'
        ),
        'blueboat' => array(
            'api_key' => '809B3BD5-1085-4C38-9624-DABC1940FFA8',
            'secret_key' => '17BD1CF7-2E0D-41FA-A7AA-BF7D21FE4AC8'
        ),
        'heniken' => array(
            'api_key' => 'FD4F4F7B-97C4-48AF-8563-993A0D231408',
            'secret_key' => 'EB05511C-5FCE-4DF2-BB6F-40AD52D95033'
        )
    ),
    'hotelbeds' => array(
        'third_party_code' => 'hotelbeds',
        'import_export_password' => '1234@3543%$#0',
        'import_export_user' => 'hotelbeds_1234',
        'tp_channel_id' => '2',
        'tp_channel_type' => '16',
        'tp_end_point' => 'https://api.test.hotelbeds.com/activity-api/3.0',
        'tp_user' => 'tq59yss6bqcm8sk36cx3a6gg',
        'tp_password' => 'FjUvwzkKS6',
    ),
    'iticket' => array(
        'third_party_code' => 'iticket',            
        'ITICKET_SERVER_V5' => 'http://xenon-staging-services.azurewebsites.net/Booking/V5/Soap/BookingService.svc?wsdl',  
        'ITICKET_API_KEY_V5' => array(
            "prio"=>'E263D95866579F0701659F51A8F2E4A5',
            "sweden"=>'4FD46C419750C2F9133E3C67DA193D17',
            "finland" => "1A0819187C92FF3EE2068A3FAC1CCC2C"
        )
    ),
    'nyc_passhub' => array(
        'third_party_code' => 'nyc_passhub',
        'tp_channel_id' => '2',
        'tp_channel_type' => '14',
        'tp_end_point' => 'http://dev.sightseeingpass.com:8092/api',
        'tp_user' => 'prioticket',
        'tp_password' => '2RUDxD8v',
    ),
    'prioapis_native' => array(
        '1' => array( // datatrax
            'third_party_code' => 'prioapis_native',
            'tp_password' => '39ec8605-e9ec-44ba-884e-02c915c66d04',
            'tp_user' => '1',
            'tp_end_point' => 'https://sandbox.datatrax.io/integrations/prioticket/v2.4/booking_service',
            'tp_channel_id' => '',
            'tp_channel_type' => '9',
           ),
        '105' => array(
            'third_party_code' => 'prioapis_native',
            'tp_channel_id' => '2',
            'tp_channel_type' => '9',
            'tp_end_point' => 'http://sandbox.citysightseeing.co.za/api/prio/action',
            'tp_user' => '105',
            'tp_password' => 'K3L-OVX3-A5D0-RS4',
        ),
        '872' => array(
            'third_party_code' => 'prioapis_native',
            'tp_channel_id' => '2',
            'tp_channel_type' => '9',
            'tp_end_point' => 'https://test-api.prioticket.com/v2.4/booking_service',
            'tp_user' => '872',
            'tp_password' => 'A11-Q98P-KG88-L72',
        ),
        '1023' => array(
            'third_party_code' => 'prioapis_native',
            'tp_channel_id' => '2',
            'tp_channel_type' => '9',
            'tp_end_point' => 'https://test-api.prioticket.com/v2.4/booking_service',
            'tp_user' => '1023',
            'tp_password' => 'YNF-7T29-D5G4-Z89',
        )
    ),
    'rijksmuseum' => array(
        'third_party_code' => 'rijksmuseum',
        'tp_end_point' => 'https://api.staging-enviso.io/resellingapi/v1',
        'third_party_account' => array(
            'prio' => array(
                'tp_api_key' => 'eESNC3ffUkaGEgUHCVIX3g==',
                'tp_api_secret_key' => 'fY7iAh3tAkSjAdV73s3r5Q==',
                'tp_api_public_key' => '-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDJMZUZDmP+4PHGQpWyslR+yY3a/jQAjvIl2/s2hU1xXupS04IdhR4E+dAnSe3+TAcnx0VFuJgY8evDGKtk0g+av8DmbWhxuKtI2XIuRVwUnSszFu3cfhKP+7pe8725zT/h86RlM1Hle7q6a2PrXGbPe8ax7IxbdqpK1B7ub2mxUwIDAQAB
-----END PUBLIC KEY-----
',
            ),
            'blueboat' => array(
                'tp_api_key' => '6z43UiMz3kCTtmbz3FAQzg==',
                'tp_api_secret_key' => 'Sbruo8fNX0CaKRtf0KjAzw==',
                'tp_api_public_key' => '-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCc9jI5AxKlyawMagNKadWKmIYK sZ2CVReCEsZIRXJbaypGN/ChW8KAPi2CmUDvvyLbzu8Fi2GTJGmL4glNHWaPDMqS 7JDLZUqiiv8qUYzq2XfBG4ht6sE14KasOvto71CW2BqIQA0R2p4YgR2E2yuRRXJn hBCfazPmRe7JAiLZQwIDAQAB
-----END PUBLIC KEY-----',
            )
        )        
    ),
    'tickethub' => array(
        'third_party_code' => 'tickethub',
        'tp_end_point' => 'https://test.thub.nl:31501/api',
        'tp_user' => 'PrioTicket',
        'tp_password' => 'AaxC78mE3A2JU327',
    ),
    'ticketer' => array(
        '145' => array(
            'third_party_code' => 'ticketer',
            'second_server_hit' => 'https://teststapi.prioticket.com/ticketer_request.php',
            'tp_channel_id' => '2',
            'tp_channel_type' => '15',
            'tp_end_point' => 'https://portal.ticketer.org.uk/api/external/v1/operator/d4fe0f93-a882-4dbc-b4ff-3efe1b1e534c/ticket/qr_code/4',
            'tp_user' => 'PrioticketAPI',
            'tp_password' => 'JvZBjgRjW459wtZH',
        )
    ),
    'TMB' => array(
         'third_party_code' => 'TMB',
         'tp_channel_id' => '11',
         'tp_channel_type' => '11',
         'tp_end_point' => 'https://apl4.beta.tmb.cat/ocicommerce/api/rest',
         'tp_user' => 'citysightseeingtest123',
         'tp_password' => '4kj5h3kj3.$',
     ),
    'trekksoft' => array(
        'third_party_code' => 'trekksoft',
        'tp_end_point' => 'https://api.trekkconnect.com/v1',
        'public_key' => 'pub_a962f4a61903f1a04afc3c7bddfb734016a163fd41',
        'secret_key' => 'sec_0ffb04b288cc7f887f13dbfe31bd20c3e87910eda0',
        'supplier_id' => 'sup_ee9f3fbe-72b7-4677-8a91-a76c5325b635'
    ),
    'ViatorApis_native' => array(
        '654' => array(
            'third_party_code' => 'ViatorApis_native',
            "ResellerId" => "874",
            'tp_channel_id' => '',
            'tp_channel_type' => '',
            'tp_end_point' => 'https://test-api.prioticket.com/rest/api/prioticket',
            'tp_user' => '654',
            'tp_password' => 'B11-Q98P-KG88-L74',
        ),
        '5002262' => array(
            'third_party_code' => 'ViatorApis_native',
            "ResellerId" => "874",
            'tp_channel_id' => '',
            'tp_channel_type' => '',
            'tp_end_point' => 'https://test-api.prioticket.com/rest/api/prioticket',
            'tp_user' => '5002262',
            'tp_password' => 'B11-Q98P-KG88-L74',
        )        
    ),
    'intersolver' => array(
        'museumkart' => array(
            'third_party_code' => 'giftcard',
            'card_prefix' => '70000011',
            'supplier_detail' => array(
                '3872' => array(
                    'tp_user' => "619301002",
                    "tp_password" => "3871"
                ),
                '4458' => array(
                    'tp_user' => "619301003",
                    "tp_password" => "3871"
                ),
                '139' => array(
                    'tp_user' => "619302004",
                    "tp_password" => "3871"
                )
            ),
            'tp_wsdl_url' => 'http://demo.luntronics.com/liabwebservice/giftcard_6_3.asmx?WSDL',
            'tp_end_point' => 'https://acc2.luntronics.com/webservices/WebApiGeneric.asp',
            'header_namespace_url' => 'http://www.loyaltyinabox.com/giftcard_6_3/',
            'distributor_id' => '4457'
        ),
        "holandpass" => array(
            'third_party_code' => 'giftcard',
            'card_prefix' => '70000012',
            'supplier_detail' => array(
                '6636' => array(
                    'tp_user' => "619301001",
                    "tp_password" => "3871"
                ),
                '139' => array(
                    'tp_user' => "619302004",
                    "tp_password" => "3871"
                )
            ),
            'tp_wsdl_url' => 'http://demo.luntronics.com/liabwebservice/giftcard_6_3.asmx?WSDL',
            'tp_end_point' => 'https://acc2.luntronics.com/webservices/WebApiGeneric.asp',
            'header_namespace_url' => 'http://www.loyaltyinabox.com/giftcard_6_3/',
            'distributor_id' => '6637'
        )
    )
);
define("TP_NATIVE_INFO", json_encode($tp_native_api_constant));
