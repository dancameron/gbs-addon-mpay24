<?php

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
	protected static $instance;
	private $api_mode = self::MODE_TEST;
	private $user = '';
	private $password = '';
	private $lang = '';
	private $cancel_url = '';
	private $return_url = '';
	private $alt_payment_value = '';

	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {
		parent::__construct();
		$this->user = get_option( self::API_USERNAME_OPTION, '' );
		$this->password = get_option( self::API_PASSWORD_OPTION, '' );
		$this->lang = get_option( self::API_LANG_OPTION, 'DE' );
		$this->api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );
		$this->alt_payment_value = self::__( 'Pay with MPay24' );
		$this->cancel_url = get_option( self::CANCEL_URL_OPTION, Group_Buying_Carts::get_url() );
		$this->return_url = Group_Buying_Checkouts::get_url();

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );

		// Change button
		add_filter( 'gb_checkout_payment_controls', array( $this, 'payment_controls' ), 20, 2 );

		// Remove pages
		add_filter( 'gb_checkout_pages', array( $this, 'remove_checkout_pages' ) );

		// Handle the return of user from mpay24
		add_action( 'gb_load_cart', array( $this, 'back_from_mpay24' ), 10, 0 );

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'MPay24' ) );
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

	private function is_test_mode() {
		return $this->api_mode == self::MODE_TEST;
	}

	public static function returned_from_offsite() {
		return isset( $_GET['s'] ) && !isset( $_REQUEST['gb_checkout_action'] );
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
		// create new payment
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) ),
				'data' => array(
					'uncaptured_deals' => $deal_info
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_PENDING );
		if ( !$payment_id ) {
			return FALSE;
		}

		// Mark purchase as unsettled
		$purchase->set_unsettled_status();
		// Send offsite after checkout is complete but before the confirmation page
		add_action( 'checkout_completed', array( $this, 'send_offsite' ), 10, 3 );

		// send data back to complete_checkout
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_pending', $payment );
		return $payment;
	}

	/**
	 */
	public function send_offsite( Group_Buying_Checkouts $checkout, Group_Buying_Payment $payment, Group_Buying_Purchase $purchase ) {

		$shop = new GB_MPAY24_Shop( $this->user, $this->password, $this->is_test_mode() );
		$shop->setTid( $payment->get_id() );
		$shop->setPrice( gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) ) );
		$shop->setLanguage( $this->lang );
		$customer_name = $checkout->cache['billing']['first_name'].' '.$checkout->cache['billing']['last_name'];
		$shop->setCustomer( $customer_name );
		$shop->setCustomerId( $purchase->get_user() );

		$shop->setSuccessUrl( $this->return_url ); // thank you page
		$shop->setErrorUrl ( $this->return_url );
		$shop->setCancelUrl( $this->cancel_url );
		$shop->setConfirmUrl( $this->return_url );

		$result = $shop->pay();

		if ( $result->getGeneralResponse()->getStatus() != "OK" ) {
			error_log( "Error: " . $result->getExternalStatus() );
			error_log( "Return Code: " . $result->getGeneralResponse()->getReturnCode() );
			error_log( 'result' . print_r( $result, TRUE ) );
			self::set_message( $result->getExternalStatus(), self::MESSAGE_STATUS_ERROR );
			return FALSE;
		}

		// Set the redirect url
		wp_redirect( $result->getLocation() );
		exit();
	}

	public function back_from_mpay24() {
		if ( isset( $_GET['TID'] ) && !isset( $_REQUEST['gb_checkout_action'] ) ) {
			// Process the order
			if ( $this->validate_purchase( $_GET ) ) {
				self::set_message( 'Payment "Pending": please wait for authorization to complete and vouchers to be activated.', self::MESSAGE_STATUS_INFO );
				wp_redirect( add_query_arg( array( 'gb_checkout_action' => 'confirmation' ), Group_Buying_Checkouts::get_url() ) );
				exit();
			} else {
				$error = gb__( 'MPay24 Purchase Error. Contact the Store Owner.' );
				self::set_message( $error, self::MESSAGE_STATUS_ERROR );
				wp_redirect( Group_Buying_Carts::get_url() );
				exit();
			}
		}
	}

	public static function validate_purchase( $get ) {

		do_action( 'payment_handle_ipn', $get );

		foreach ( $get as $key => $value ) {
			if ( $key !== 'TID' )
				$args[$key] = $value;
		}

		if ( self::DEBUG ) {
			error_log( '---------- Eaasy Pay IPN Handler ----------' );
			error_log( "message: " . print_r( wp_parse_args( $get ), true ) );
		}

		$shop = new GB_MPAY24_Shop( $this->user, $this->password, $this->is_test_mode() );
		$confirm = $shop->confirm( $get['TID'], $args );
		error_log( 'confirm payment ' . print_r( $confirm, TRUE ) );

		return true;
	}

	public function check_mpay24_response() {
		@ob_clean();
		if ( ! empty( $_GET ) && self::validate_ipn() ) {
			header( 'HTTP/1.1 200 OK' );
			self::successful_request( $_GET );
		} else {
			wp_die( "mPAY24 IPN Request Failure" );
		}
	}

	function validate_ipn() {
		$get_params = $_GET;
		$args      = array();

		foreach ( $get_params as $key => $value ) {
			if ( 'TID' !== $key ) {
				$args[ $key ] = $value;
			}
		}

		$shop = new GB_MPAY24_Shop( $this->user, $this->password, $this->is_test_mode() );
		if ( isset( $this->log ) ) {
			$shop->setLog( $this->log );
		}
		$shop->confirm( $_GET['TID'], $args );

		return true;
	}

	/**
	 *
	 *
	 * @param array   $posted - request parameters
	 * @access public
	 * @return void
	 */
	public static function successful_request( $posted ) {

		if ( ! empty( $posted['TID'] ) ) {
			$transaction_id = $posted['TID'];
			$payment = Group_Buying_Payment::get_instance( $transaction_id );
			$data = $payment->get_data();
			$transaction_data = $data['transaction_data'];

			//mpay24 transaction status: BILLED,RESERVED,ERROR,SUSPENDED,CREDITED,REVERSED
			// Lowercase
			$tstatus = strtolower( $transaction_data['tstatus'] );

			// We are here so lets check status and do actions
			switch ( $tstatus ) {
			case 'billed':
				// Check order not already completed
				if ( Group_Buying_Payment::STATUS_COMPLETE == $payment->get_status() ) {
					if ( 'yes' == $this->debug ) {
						$this->log->add( 'mpay24', 'Aborting, Order #' . $order->id . ' is already complete.' );
					}
					exit;
				}

				// Payment completed
				self::mark_payment_and_purchase_complete( $payment );

				if ( 'yes' == $this->debug ) {
					$this->log->add( 'mpay24', 'Payment complete.' );
				}
				break;
			case 'reserved':
				// Order pending
				// default is pending, nothing to do.
				break;
			case 'error':
			case 'suspended':
				// Order failed
			case 'credited':
				// Refunded
			case 'reversed':
				// Order cancelled
				$payment->set_status( Group_Buying_Payment::STATUS_VOID );
				break;

			default:
				// No action
				break;
			}
			exit;
		}
	}

	public static function mark_payment_and_purchase_complete( Group_Buying_Payment $payment ) {
		// Mark purchase complete
		// TODO not sure how this would be handled with mixed payments :/
		$purchase_id = $payment->get_purchase();
		$purchase = Group_Buying_Purchase::get_instance( $purchase_id );
		// Mark as pending so the function can proceed
		$purchase->set_pending();
		// Mark as complete, run purchase_completed
		$purchase->complete();

		// Mark payment
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $payment->get_deals() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		do_action( 'payment_authorized', $payment );
		do_action( 'payment_captured', $payment, $items_captured );
		do_action( 'payment_complete', $payment );
		$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
	}

	public function payment_controls( $controls, Group_Buying_Checkouts $checkout ) {
		$style = '';
		if ( isset( $controls['review'] ) ) {
			$controls['review'] = str_replace( 'value="Review"', $style . ' value="'.self::__( 'MPay24' ).'"', $controls['review'] );
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
		add_settings_field( self::API_LANG_OPTION, self::__( 'Entity Key' ), array( $this, 'display_ent_password_field' ), $page, $section );
		add_settings_field( self::CANCEL_URL_OPTION, self::__( 'Cancel URL' ), array( get_class(), 'display_cancel_field' ), $page, $section );
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.$this->user.'" size="10" />';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.$this->password.'" size="10" />';
	}

	public function display_lang_field() {
		echo '<input type="text" name="'.self::API_LANG_OPTION.'" value="'.$this->lang.'" size="3" />';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked( self::MODE_LIVE, $this->api_mode, FALSE ).'/> '.self::__( 'Live' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked( self::MODE_TEST, $this->api_mode, FALSE ).'/> '.self::__( 'Sandbox' ).'</label>';
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
