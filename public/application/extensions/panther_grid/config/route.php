<?php defined('BASEPATH') OR exit('No direct script access allowed');

$extension_route['panther_grid'] = 'grid/month';
$extension_route['panther_grid/month/(:num)/(:num)'] = 'grid/month/$1/$2';
$extension_route['panther_grid/(:any)'] = 'grid/$1';
