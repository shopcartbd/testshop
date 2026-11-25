<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
use AllStore\Core\Auth;
use AllStore\Core\Session;
use AllStore\Core\Response;
Auth::logout();
Session::flash('success','Administrator signed out.');
Response::redirect('/admin/login');
