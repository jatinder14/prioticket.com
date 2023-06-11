<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
| 	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	http://codeigniter.com/user_guide/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are two reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['scaffolding_trigger'] = 'scaffolding';
|
| This route lets you set a "secret" word that will trigger the
| scaffolding feature for added security. Note: Scaffolding must be
| enabled in the controller in which you intend to use it.   The reserved 
| routes must come before any wildcard or regular expression routes.
|
*/

/*$route['default_controller'] = "welcome";*/
// $route['default_controller'] = "V1/api";
$route['firebase/(:any)'] = 'V1/firebase/call/$1';
$route['guest_manifest/(:any)'] = 'V1/guest_manifest/call/$1';
$route['host_app/(:any)'] = 'V1/host_app/call/$1';
$route['contiki_tours/(:any)'] = 'V1/contiki_tours/call/$1';
$route['mpos/(:any)'] = 'V1/mpos/$1';
$route['api/(:any)'] = "V1/api/$1";
$route['process_adyen_order'] = 'V1/mpos/checkout';
$route['mpos_scripts/(.*)'] = 'V1/mpos_scripts/$1';
$route['trip'] = "V1/trip";
$route['event_api/(:any)'] = 'V1/event_api/$1';
$route['process_adyen_order_v1'] = 'V1/mpos/checkout_v1';

# Affiliates apis routes
$route['v1.0/contiki_tours/affiliates'] = "V2/Affiliate/affiliates";
$route['v1.0/contiki_tours/affiliates/products'] = "V2/Affiliate/affiliate_tickets_listing";
$route['v1.0/contiki_tours/affiliates/products/(:any)'] = "V2/Affiliate/get_affiliate_ticket_amount/$1";
$route['v1.0/contiki_tours/affiliates/payments'] = "V2/Affiliate/get_affiliate_pay_amount";

$route['clearCache'] = 'V1/Cache/clearCache';
#Toue and Trip apis routes
$route['v1.0/trips/overview'] = "V2/Tours/trip_overview";
$route['v1.0/tour/details'] = "V2/Tours/tour_details";
$route['v1.0/product/details'] = "V2/Tours/ticket_listing";

$route['v1.0/shift/shift_report'] = "V2/Shift_report/end_shift_report";
/* End of file routes.php */
/* Location: ./system/application/config/routes.php */