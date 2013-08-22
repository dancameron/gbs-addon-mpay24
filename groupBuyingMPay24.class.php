<?php
//http://prime.gbmu.dev/checkout/?TID=3014&LANGUAGE=DE&USER_FIELD=&BRAND=VISA
require_once 'lib/GB_MPay24_Shop.class.php';


class Group_Buying_MPay24 extends Group_Buying_Offsite_Processors {

	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';
	const API_USERNAME_OPTION = 'gb_mpay24_username';
	const API_PASSWORD_OPTION = 'gb_mpay24_password';
	const API_LANG_OPTION = 'gb_mpay24_lang';
	const API_MODE_OPTION = 'gb_mpay24_mode';
	const PAYMENT_METHOD = 'MPay24';
	const CANCEL_URL_OPTION = 'gb_paypal_cancel_url';
	const RETURN_URL_OPTION = 'gb_paypal_return_url';

	const TOKEN_KEY = 'gb_token_key'; // Combine with $blog_id to get the actual meta key
	const TRANSACTION_TYPE = 'gb_mpay24_transaction'; // Combine with $blog_id to get the actual meta key

	protected static $instance;
	private static $api_mode = self::MODE_TEST;
	private static $user = '';
	private static $password = '';
	private static $lang = '';
	private static $cancel_url = '';
	private static $return_url = '';
	private static $alt_payment_value = '';

	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'mPAY24' ) );
	}

	public static function checkout_icon() {
		return '<img src="https://www.mpay24.com/web/img/logos/payment-mpay24.png" title="Paypal Payments" id="paypal_icon"/>';
	}

	private static function is_test_mode() {
		return self::$api_mode == self::MODE_TEST;
	}

	public static function returned_from_offsite() {
		return isset( $_GET['TID'] );
	}

	protected function __construct() {
		parent::__construct();
		self::$user = get_option( self::API_USERNAME_OPTION, '' );
		self::$password = get_option( self::API_PASSWORD_OPTION, '' );
		self::$lang = get_option( self::API_LANG_OPTION, 'DE' );
		self::$api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );
		self::$alt_payment_value = self::__( 'Pay with mPAY24' );
		self::$cancel_url = get_option( self::CANCEL_URL_OPTION, Group_Buying_Carts::get_url() );
		self::$return_url = Group_Buying_Checkouts::get_url();

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );

		// Send offsite and handle the return
		add_action( 'gb_send_offsite_for_payment', array( $this, 'send_offsite' ), 10, 1 );
		add_action( 'gb_load_cart', array( $this, 'back_from_mpay24' ), 10, 0 );

		// Remove pages
		add_filter( 'gb_checkout_pages', array( $this, 'remove_checkout_pages' ) );

		// payment processing
		add_action( 'purchase_completed', array( $this, 'capture_purchase' ), 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'capture_pending_payments' ) );
		if ( self::DEBUG ) {
			add_action( 'init', array( $this, 'capture_pending_payments' ), 10000 );
		}

		// Change button
		add_filter( 'gb_checkout_payment_controls', array( $this, 'payment_controls' ), 20, 2 );

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );
	}

	/**
	 * The review page is unnecessary (or, rather, it's offsite)
	 *
	 * @param array   $pages
	 * @return array
	 */
	public function remove_checkout_pages( $pages ) {
		unset( $pages[Group_Buying_Checkouts::REVIEW_PAGE] );
		return $pages;
	}

	public static function init_shop() {
		$shop = new GB_MPAY24_Shop( self::$user, self::$password, self::is_test_mode() );
		return $shop;
	}

	/**
	 */
	public function send_offsite( Group_Buying_Checkouts $checkout ) {
		$cart = $checkout->get_cart();
		if ( $cart->get_total() < 0.01 ) { // for free deals.
			return;
		}

		$filtered_total = self::get_payment_request_total( $checkout );
		if ( $filtered_total < 0.01 ) {
			return array();
		}

		$transaction_id = Group_Buying_Record::new_record( null, self::TRANSACTION_TYPE, 'mPay24 Transaction', get_current_user_id() );
		self::set_token( $transaction_id ); // Set the transaction id
		if ( self::DEBUG ) error_log( 'record id' . print_r( $transaction_id, TRUE ) );


		$shop = self::init_shop();
		$shop->setTid( $transaction_id );
		$shop->setPrice( gb_get_number_format( $filtered_total ) );
		$shop->setLanguage( self::$lang );
		$customer_name = $checkout->cache['billing']['first_name'].' '.$checkout->cache['billing']['last_name'];
		$shop->setCustomer( $customer_name );
		$shop->setCustomerId( get_current_user_id() );

		$shop->setSuccessUrl( self::$return_url ); // thank you page
		$shop->setErrorUrl( self::$return_url );
		$shop->setCancelUrl( self::$cancel_url );
		$shop->setConfirmUrl( self::$return_url );

		$result = $shop->pay();
		if ( self::DEBUG ) error_log( 'result' . print_r( $result, TRUE ) );

		if ( $result->getGeneralResponse()->getStatus() != "OK" ) {
			if ( self::DEBUG ) error_log( "Error: " . $result->getExternalStatus() );
			if ( self::DEBUG ) error_log( "Return Code: " . $result->getGeneralResponse()->getReturnCode() );
			self::set_message( $result->getExternalStatus(), self::MESSAGE_STATUS_ERROR );
			return FALSE;
		}

		// Set the redirect url
		wp_redirect( $result->getLocation() );
		exit();
	}

	/**
	 * Back from mPay24
	 * exaple: http://prime.gbmu.dev/checkout/?TID=3942&LANGUAGE=DE&USER_FIELD=&BRAND=VISA
	 * @return
	 */
	public function back_from_mpay24() {
		if ( self::returned_from_offsite() ) {
			// Process the order
			if ( $this->validate_purchase( $_GET ) ) {
				$_REQUEST['gb_checkout_action'] = 'back_from_mpay24';
			} else {
				$error = gb__( 'MPay24 Purchase Error. Contact the Store Owner.' );
				self::set_message( $error, self::MESSAGE_STATUS_ERROR );
				wp_redirect( Group_Buying_Carts::get_url() );
				exit();
			}
		} elseif ( !isset( $_REQUEST['gb_checkout_action'] ) ) {
			// this is a new checkout. clear the token so we don't give things away for free
			self::unset_token();
		}
	}

	public static function validate_purchase( $get ) {

		foreach ( $get as $key => $value ) {
			if ( $key !== 'TID' )
				$args[$key] = $value;
		}

		if ( self::DEBUG ) {
			error_log( '---------- mPay24 GET ----------' );
			error_log( "message: " . print_r( $get, true ) );
		}

		$shop = self::init_shop();
		$confirm = $shop->confirm( $get['TID'], $args );
		$status = self::get_transaction_status( $get['TID'], FALSE );
		if ( $status->getGeneralResponse()->getStatus() != "OK" || $status->params['TSTATUS'] == 'ERROR' || $status->params['TSTATUS'] == 'SUSPENDED' ) {
			if ( self::DEBUG ) error_log( "Error: " . $status->getExternalStatus() );
			if ( self::DEBUG ) error_log( "Return Code: " . $status->getGeneralResponse()->getReturnCode() );
			self::set_message( $status->getExternalStatus(), self::MESSAGE_STATUS_ERROR );
			return FALSE;
		}
		return $status;
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {

		if ( $purchase->get_total( self::get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		$deal_info = array(); // create loop of deals for the payment post
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][self::get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}

		// Transaction id
		$transaction_id = ( isset( $_GET['TID'] ) && $_GET['TID'] != '' ) ? $_GET['TID'] : self::get_token() ;
		self::unset_token();

		// create new payment
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) ),
				'data' => array(
					'tid' => $transaction_id,
					'uncaptured_deals' => $deal_info
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_PENDING );
		if ( !$payment_id ) {
			return FALSE;
		}

		// send data back to complete_checkout
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );

		// finalize
		return $payment;
	}

	/**
	 * Capture a pre-authorized payment
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function capture_purchase( Group_Buying_Purchase $purchase ) {
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->maybe_capture_payment( $payment );
		}
	}

	/**
	 * Try to capture all pending payments
	 *
	 * @return void
	 */
	public function capture_pending_payments() {
		$payments = Group_Buying_Payment::get_pending_payments();
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->maybe_capture_payment( $payment );
		}
	}

	public function maybe_capture_payment( Group_Buying_Payment $payment ) {

		if ( $payment->get_payment_method() == $this->get_payment_method() && $payment->get_status() != Group_Buying_Payment::STATUS_COMPLETE ) {
			$data = $payment->get_data();

			if ( isset( $data['tid'] ) && $data['tid'] ) { // transaction id required.

				$items_to_capture = $this->items_to_capture( $payment );

				if ( $items_to_capture ) {
					$status = self::get_transaction_status( $data['tid'], FALSE );

					// mpay24 transaction status: BILLED,RESERVED,ERROR,SUSPENDED,CREDITED,REVERSED
					switch ( $status->params['TSTATUS'] ) {
					case 'BILLED':
						// Change payment data
						foreach ( $items_to_capture as $deal_id => $amount ) {
							unset( $data['uncaptured_deals'][$deal_id] );
						}
						if ( !isset( $data['capture_response'] ) ) {
							$data['capture_response'] = array();
						}
						$data['capture_response'][] = $status;
						$payment->set_data( $data );

						// Payment completed
						do_action( 'payment_captured', $payment, array_keys( $items_to_capture )  );
						$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
						do_action( 'payment_complete', $payment );
						break;
					case 'RESERVED':
						// Order pending
						// default is pending, nothing to do.
						break;
					case 'ERROR':
					case 'SUSPENDED':
						// Order failed
					case 'CREDITED':
						// Refunded
					case 'REVERSED':
						// Order cancelled
						$payment->set_status( Group_Buying_Payment::STATUS_VOID );
						break;

					default:
						// No action
						break;
					}
				}
			}

		}

	}

	public static function get_transaction_status( $tid, $tstatus_only = TRUE ) {
		$shop = self::init_shop();
		$transaction = $shop->updateTransactionStatus( $tid );
		if ( self::DEBUG ) error_log( 'get_transaction_status transaction' . print_r( $transaction, TRUE ) );
		if ( $tstatus ) {
			return $transaction->params['TSTATUS'];
		}
		return $transaction;
	}

	public static function set_token( $token ) {
		global $blog_id;
		update_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, $token );
	}

	public static function unset_token() {
		global $blog_id;
		delete_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY );
	}

	public static function get_token() {
		global $blog_id;
		return get_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, TRUE );
	}

	public function payment_controls( $controls, Group_Buying_Checkouts $checkout ) {
		$style = '';
		if ( isset( $controls['review'] ) ) {
			//$style = 'style="box-shadow: none;-moz-box-shadow: none;-webkit-box-shadow: none; display: block; width: 208px; height: 32px; background-color: transparent; background-image: url(https://www.mpay24.com/web/img/logos/payment-mpay24.png); background-position: 0 0; background-repeat: no-repeat; padding: 42px 0 0 0; border: none; cursor: pointer; text-indent: -9000px; margin-top: 12px;"';
			$controls['review'] = str_replace( 'value="Review"', $style . ' value="'.self::__( 'Pay with mPAY24' ).'"', $controls['review'] );
		}
		return $controls;
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_mpay24_settings';
		add_settings_section( $section, self::__( 'MPay24' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_MODE_OPTION );
		register_setting( $page, self::API_USERNAME_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );
		register_setting( $page, self::API_LANG_OPTION );
		register_setting( $page, self::CANCEL_URL_OPTION );
		add_settings_field( self::API_MODE_OPTION, self::__( 'Mode' ), array( $this, 'display_api_mode_field' ), $page, $section );
		add_settings_field( self::API_USERNAME_OPTION, self::__( 'API Username' ), array( $this, 'display_api_username_field' ), $page, $section );
		add_settings_field( self::API_PASSWORD_OPTION, self::__( 'API Password' ), array( $this, 'display_api_password_field' ), $page, $section );
		add_settings_field( self::API_LANG_OPTION, self::__( 'Language' ), array( $this, 'display_lang_field' ), $page, $section );
		add_settings_field( self::CANCEL_URL_OPTION, self::__( 'Cancel URL' ), array( $this, 'display_cancel_field' ), $page, $section );
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.self::$user.'" size="10" />';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.self::$password.'" size="10" />';
	}

	public function display_lang_field() {
		echo '<select name="'.self::API_LANG_OPTION.'">
			<option value="BG" '.selected( self::$lang, 'BG', FALSE ).'>Bulgarian</option>
			<option value="CS" '.selected( self::$lang, 'CS', FALSE ).'>Czech</option>
			<option value="DE" '.selected( self::$lang, 'DE', FALSE ).'>German</option>
			<option value="EN" '.selected( self::$lang, 'EN', FALSE ).'>English</option>
			<option value="ES" '.selected( self::$lang, 'ES', FALSE ).'>Spanish</option>
			<option value="FR" '.selected( self::$lang, 'FR', FALSE ).'>French</option>
			<option value="HU" '.selected( self::$lang, 'HU', FALSE ).'>Hungarian</option>
			<option value="NL" '.selected( self::$lang, 'NL', FALSE ).'>Dutch</option>

		</select>';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked( self::MODE_LIVE, self::$api_mode, FALSE ).'/> '.self::__( 'Live' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked( self::MODE_TEST, self::$api_mode, FALSE ).'/> '.self::__( 'Sandbox' ).'</label>';
	}

	public function display_cancel_field() {
		echo '<input type="text" name="'.self::CANCEL_URL_OPTION.'" value="'.self::$cancel_url.'" size="80" />';
	}

	public function display_exp_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/exp-only.php';
	}

	public function display_price_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-dyn-price.php';
	}

	public function display_limits_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-tipping.php';
	}
}
Group_Buying_MPay24::register();
