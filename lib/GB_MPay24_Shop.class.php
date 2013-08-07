<?php

include_once 'MPay24Shop.php';

/**
 * Create and update transaction to mPAY24
 *
 */
class GB_MPAY24_Shop extends MPay24Shop {

	/**
	 * transaction id
	 *
	 * @var        string
	 */
	protected $tid;

	/**
	 * total price
	 *
	 * @var        decimal
	 */
	protected $price;

	/**
	 * user interface language in 2 uppercased letters
	 *
	 * @var string
	 */
	protected $language = 'DE';

	/**
	 * customer name from merchant website customer
	 *
	 * @var string
	 */
	protected $customer;

	/**
	 * customer id from merchant website customer
	 *
	 * @var string
	 */
	protected $customerId;

	protected $successUrl = '';
	protected $errorUrl   = '';
	protected $cancelUrl  = '';
	protected $confirmUrl = '';

	/**
	 * logger
	 *
	 * @var object
	 */
	protected $log = null;

	public function getTid() {
		return $this->tid;
	}

	public function getPrice() {
		return $this->price;
	}

	public function getLanguage() {
		return $this->language;
	}

	public function getCustomer() {
		return $this->customer;
	}

	public function getCustomerId() {
		return $this->customerId;
	}

	public function getSuccessUrl() {
		return $this->successUrl;
	}

	public function getErrorUrl() {
		return $this->errorUrl;
	}

	public function getCancelUrl() {
		return $this->cancelUrl;
	}

	public function getConfirmUrl() {
		return $this->confirmUrl;
	}

	public function getLog() {
		return $this->log;
	}

	public function setTid( $v ) {
		if ( null !== $v ) {
			$v = (string) $v;
		}

		if ( $this->tid !== $v ) {
			$this->tid = $v;
		}

		return $this;
	}

	public function setPrice( $v ) {
		if ( null !== $v ) {
			$v = (float) $v;
		}

		if ( $this->price !== $v ) {
			$this->price = $v;
		}

		return $this;
	}

	public function setLanguage( $v ) {
		if ( null !== $v ) {
			$v = (string) $v;
		}

		if ( $this->language !== $v ) {
			$this->language = strtoupper( $v );
		}

		return $this;
	}

	public function setCustomer( $v ) {
		if ( null !== $v ) {
			$v = (string) $v;
		}

		if ( $this->customer !== $v ) {
			$this->customer = $v;
		}

		return $this;
	}

	public function setCustomerId( $v ) {
		if ( null !== $v ) {
			$v = (string) $v;
		}

		if ( $this->customerId !== $v ) {
			$this->customerId = $v;
		}

		return $this;
	}

	public function setSuccessUrl( $v ) {
		if ( null !== $v ) {
			$v = (string) $v;
		}

		if ( $this->successUrl !== $v ) {
			$this->successUrl = $v;
		}

		return $this;
	}

	public function setErrorUrl( $v ) {
		if ( null !== $v ) {
			$v = (string) $v;
		}

		if ( $this->errorUrl !== $v ) {
			$this->errorUrl = $v;
		}

		return $this;
	}

	public function setCancelUrl( $v ) {
		if ( null !== $v ) {
			$v = (string) $v;
		}

		if ( $this->cancelUrl !== $v ) {
			$this->cancelUrl = $v;
		}

		return $this;
	}

	public function setConfirmUrl( $v ) {
		if ( null !== $v ) {
			$v = (string) $v;
		}

		if ( $this->confirmUrl !== $v ) {
			$this->confirmUrl = $v;
		}

		return $this;
	}

	public function setLog( $v ) {
		if ( $this->log !== $v ) {
			$this->log = $v;
		}

		$this->mPay24Api->setDebug( true );

		return $this;
	}

	public function updateTransaction( $tid, $args, $shippingConfirmed ) {
		$payment = Group_Buying_Payment::get_instance( $tid );
		$data = $payment->get_data();
		$data['transaction_data'] = $args;

		$data['transaction_data']['updated_at'] = date( 'Y-m-d H:i:s', time() );

		error_log( 'updateTransaction: ' . print_r( $result, TRUE ) );
	}

	public function getTransaction( $tid = 0 ) {
		if ( !$tid )
			return;

		$payment = Group_Buying_Payment::get_instance( $tid );
		error_log( ' tid ' . print_r( $tid , TRUE ) );
		$data = $payment->get_data();

		$transaction = new Transaction( $tid );
		$transaction->PRICE = $payment->get_amount();
		$transaction->SECRET = $data['secret'];

		error_log( 'get transaction ' . print_r( $data, TRUE ) );

		return $transaction;
	}

	/**
	 * create xml with the order information
	 * required params are Tid and Price
	 *
	 * @param string  $tid - XML
	 * @return string $mdxi - XML
	 * @see MPay24Shop.php and mPAY24 spezification page 63.
	 */
	public function createProfileOrder( $tid ) {}

	public function createExpressCheckoutOrder( $tid ) {}

	public function createFinishExpressCheckoutOrder( $tid, $shippingCosts, $amount, $cancel ) {}

	public function write_log( $operation, $info_to_log ) {
		$result = $operation.$info_to_log;

		error_log( 'write log: ' . print_r( $result, TRUE ) );
		if ( ! is_null( $this->log ) ) {
			$this->log->add( 'mpay24', $result );
		}
	}

	public function createSecret( $tid, $amount, $currency, $timeStamp ) {
		$secret = md5( $tid . $amount . $currency . $timeStamp . mt_rand() );

		return $secret;
	}

	public function getSecret( $tid ) {
		$transaction = $this->getTransaction( $tid );

		return $transaction->SECRET;
	}

	/**
	 *              STRING          STATUS : OK, ERROR
	 *              STRING          OPERATION = CONFIRMATION
	 *              STRING          TID : length <= 32
	 *              STRING          TSTATUS : RESERVED, BILLED, REVERSED, CREDITED, ERROR, SUSPENDED
	 *              INTEGER         PRICE : length = 11 (e. g. "10" = "0,10")
	 *              STRING          CURRENCY : length = 3 (ISO currency code, e. g. "EUR")
	 *              STRING          P_TYPE : CC, ELV, EPS, GIROPAY, MAESTRO, MIA, PB, PSC, QUICK
	 *              STRING          BRAND : AMEX, DINERS, JCB, MASTERCARD, VISA, ATOS, HOBEX-AT, HOBEX-DE, HOBEX-NL, ARZ, BA, ERSTE, HYPO, RZB, ONE, T-MOBILE
	 *              INTEGER         MPAYTID : length = 11
	 *              STRING          USER_FIELD
	 *              STRING          ORDERDESC
	 *              STRING          CUSTOMER
	 *              STRING          CUSTOMER_EMAIL
	 *              STRING          LANGUAGE : length = 2
	 *              STRING          CUSTOMER_ID : length = 11
	 *              STRING          PROFILE_STATUS : IGNORED, USED, ERROR, CREATED, UPDATED, DELETED
	 *              STRING          FILTER_STATUS
	 *              STRING          APPR_CODE
	 *              STRING          SECRET
	 *
	 * @return void
	 */
	public function createTransaction() {
		$payment = Group_Buying_Payment::get_instance( $this->getTid() );
		$data = $payment->get_data();

		$transaction = new Transaction( $this->getTid() );

		// setting via magic method __set
		$transaction->PRICE = $this->getPrice();
		$transaction->CURRENCY = 'EUR';

		$secret = $this->createSecret( $this->getTid(), $this->getPrice(), 'EUR', time() );
		$transaction->SECRET = $secret;

		// Set the secret in the payment data
		$data['secret'] = $secret;
		$payment->set_data( $data );
		error_log( 'createTransaction: ' . print_r( $transaction, TRUE ) );
		return $transaction;
	}

	/**
	 * create xml with the order information
	 * required params are Tid and Price
	 *
	 * @param string  $transaction - XML
	 * @return string $mdxi - XML
	 * @see MDXI.xsd and mPAY24 spezification page 78ff.
	 */
	public function createMDXI( $transaction ) {
		$mdxi = new ORDER();
		$subTotal = 0.00;

		$paymentMethods = $this->getPaymentMethods();
		if ( 'OK' != $paymentMethods->getGeneralResponse()->getStatus() ) {
			throw new \Exception( 'EXTERNALSTATUS: ' . $paymentMethods->getExternalStatus() . ' RETURNCODE: ' . $paymentMethods->getGeneralResponse()->getReturnCode() );
		}

		// $mdxi = XML Document
		$mdxi->Order->Tid         = $transaction->TID;
		$mdxi->Order->TemplateSet = 'WEB';
		$mdxi->Order->TemplateSet->setLanguage( $this->getLanguage() );

		$mdxi->Order->PaymentTypes->setEnable( 'true' );

		$pTypes = $paymentMethods->getPTypes();
		$brands = $paymentMethods->getBrands();
		foreach ( $pTypes as $key => $value ) {
			$mdxi->Order->PaymentTypes->Payment( $key+1 )->setType( $value );
			if ( 'CC' == $value || 'ELV' == $value ) {
				$mdxi->Order->PaymentTypes->Payment( $key+1 )->setBrand( $brands[ $key ] );
			}
		}

		$mdxi->Order->Price = $transaction->PRICE;
		/**
		 * styling options
		 *
		 * no semicolon after last rule
		 * example: $mdxi->Order->Price->setStyle('background-color: #DDDDDD; border: none; border-top: 1px solid #5B595D');
		 */
		//$mdxi->Order->Price->setStyle('');
		//$mdxi->Order->Price->setHeaderStyle('');

		$mdxi->Order->Customer = $this->getCustomer();
		$mdxi->Order->Customer->setId( $this->getCustomerId() );
		//$mdxi->Order->Customer->setUseProfile( 'true' ); // proSafe required

		$mdxi->Order->URL->Success      = $this->getSuccessUrl();
		$mdxi->Order->URL->Error        = $this->getErrorUrl();
		$mdxi->Order->URL->Confirmation = $this->getConfirmUrl() . '&token=' . $transaction->SECRET;
		$mdxi->Order->URL->Cancel       = $this->getCancelUrl();

		/**
		 * styling options
		 *
		 * no semicolon after last rule
		 * example: $mdxi->Order->setStyle('font-family: "Helvetica Neue",Helvetica,Arial,sans-serif;');
		 */
		//$mdxi->Order->setStyle('');
		//$mdxi->Order->setLogoStyle('');
		//$mdxi->Order->setPageHeaderStyle('');
		//$mdxi->Order->setPageCaptionStyle('');
		//$mdxi->Order->setPageStyle('');
		//$mdxi->Order->setInputFieldsStyle('');
		//$mdxi->Order->setDropDownListsStyle('');
		//$mdxi->Order->setButtonsStyle('');
		//$mdxi->Order->setErrorsStyle('');
		//$mdxi->Order->setErrorsHeaderStyle('');
		//$mdxi->Order->setSuccessTitleStyle('');
		//$mdxi->Order->setErrorTitleStyle('');
		//$mdxi->Order->setFooterStyle('');

		return $mdxi;
	}
}
