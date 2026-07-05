<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

// Temporary diagnostic controller — deployment filesystem inspection only.
// Not gated behind MY_Controller (same pattern as cron.php) so it works
// without a session. Remove after use.
class Diag_fs extends CI_Controller
{
    function index($secret = null)
    {
        if (!getenv('CRON_AUTH_SECRET') || $secret !== getenv('CRON_AUTH_SECRET')) {
            echo 'Not authorized';
            return;
        }

        header('Content-Type: application/json');
        echo json_encode(array(
            'extensions_dir' => is_dir(APPPATH.'extensions') ? scandir(APPPATH.'extensions') : 'MISSING',
            'panther_shell_dir' => is_dir(APPPATH.'extensions/panther_shell') ? scandir(APPPATH.'extensions/panther_shell') : 'MISSING',
            'panther_room_status_controllers' => is_dir(APPPATH.'extensions/panther_room_status/controllers') ? scandir(APPPATH.'extensions/panther_room_status/controllers') : 'MISSING',
        ));
    }
}
