<?php
/*
Plugin Name: Group Buying Payment Processor - mPay24
Version: .1
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: mPay24 Payments Add-on.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action('gb_register_processors', 'gb_load_mpay24');

function gb_load_mpay24() {
	require_once('groupBuyingMPay24.class.php');
}