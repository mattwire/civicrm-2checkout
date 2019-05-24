<?php

/*
 * Payment Processor class for TwoCheckout
 */

use CRM_twocheckout_ExtensionUtil as E;

class CRM_Core_Payment_Twocheckout extends CRM_Core_Payment {

  use CRM_Core_Payment_TwocheckoutTrait;

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @return void
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = $this->_paymentProcessor['name'];
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return null|string
   *   The error message if any.
   */
  public function checkConfig() {
    $error = [];

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = E::ts('The decryption password has not been set.');
    }

    $sig = explode('|', $this->_paymentProcessor['signature']);
    if (count($sig) < 2) {
      $error[] = E::ts('You need to specify sellerId|secretword correctly.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * We can use the 2checkout processor on the backend
   * @return bool
   */
  public function supportsBackOffice() {
    return TRUE;
  }

  /**
   * We can edit recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return FALSE;
  }

  /**
   * We can configure a start date
   * @return bool
   */
  public function supportsFutureRecurStartDate() {
    return FALSE;
  }

  private function getHiddenPaymentFields() {
    return [
      'crm_tco_id' => 'crm-tco-id',
      'crm_tco_token' => 'crm-tco-token',
      'crm_tco_pubkey' => 'crm-tco-pubkey',
      'crm_tco_sellerid' => 'crm-tco-sellerid',
      'crm_tco_mode' => 'crm-tco-mode',
    ];
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields() {
    $paymentFields = [
      'credit_card_type',
      'credit_card_number',
      'cvv2',
      'credit_card_exp_date',
    ];
    foreach ($this->getHiddenPaymentFields() as $name => $id) {
      $paymentFields[] = $name;
    }
    return $paymentFields;
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    $creditCardType = ['' => E::ts('- select -')] + CRM_Contribute_PseudoConstant::creditCard();
    $paymentFields = [
      'credit_card_number' => [
        'htmlType' => 'text',
        'name' => 'credit_card_number',
        'title' => E::ts('Card Number'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 20,
          'autocomplete' => 'off',
        ],
        'is_required' => TRUE,
      ],
      'cvv2' => [
        'htmlType' => 'text',
        'name' => 'cvv2',
        'title' => E::ts('Security Code'),
        'attributes' => [
          'size' => 5,
          'maxlength' => 10,
          'autocomplete' => 'off',
        ],
        'is_required' => TRUE,
      ],
      'credit_card_exp_date' => [
        'htmlType' => 'date',
        'name' => 'credit_card_exp_date',
        'title' => E::ts('Expiration Date'),
        'attributes' => CRM_Core_SelectValues::date('creditCard'),
        'is_required' => TRUE,
        'month_field' => 'credit_card_exp_date_M',
        'year_field' => 'credit_card_exp_date_Y',
        'extra' => ['class' => 'crm-form-select'],
      ],

      'credit_card_type' => [
        'htmlType' => 'select',
        'name' => 'credit_card_type',
        'title' => E::ts('Card Type'),
        'attributes' => $creditCardType,
        'is_required' => FALSE,
      ],
    ];

    foreach ($this->getHiddenPaymentFields() as $name => $id) {
      $paymentFields[$name] = [
        'htmlType' => 'hidden',
        'name' => $name,
        'title' => $name,
        'attributes' => [
          'id' => $id,
          'class' => 'payproc-metadata',
        ],
        'is_required' => TRUE,
      ];
    }
    return $paymentFields;
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // Set default values
    $defaults = [
      'crm_tco_id' => CRM_Utils_Array::value('id', $form->_paymentProcessor),
      'crm_tco_pubkey' => CRM_Utils_Array::value('user_name', $form->_paymentProcessor),
      'crm_tco_sellerid' => CRM_Utils_Array::value('signature', $form->_paymentProcessor),
      'crm_tco_mode' => $this->getIsTestMode() ? 'sandbox' : 'production',
    ];
    $form->setDefaults($defaults);
  }

  private function getPrivateKey() {
    return (string)trim($this->_paymentProcessor['password']);
  }

  /**
   * This is the first part of the signature (separated by | char)
   *
   * @return string
   */
  private function getSellerId() {
    list($sellerId, $secretWord) = explode('|', $this->_paymentProcessor['signature']);
    return (string)trim($sellerId);
  }

  private function getPaymentToken() {
    $paramName = 'crm_tco_token';
    $paymentToken = NULL;
    if (!empty($this->getParam($paramName))) {
      $paymentToken = $this->getParam($paramName);
    }
    else if(!empty(CRM_Utils_Array::value($paramName, $_POST, NULL))) {
      $paymentToken = CRM_Utils_Array::value($paramName, $_POST, NULL);
    }
    else {
      CRM_Core_Error::statusBounce(E::ts('Unable to complete payment! Please report this to the site administrator with a description of what you were trying to do.'));
      Civi::log()->debug($paramName . ' token was not passed!  Report this message to the site administrator. $params: ' . print_r($this->_params, TRUE));
    }
    return $paymentToken;
  }

  /**
   * Process payment
   * Submit a payment using 2checkout's PHP API:
   * https://github.com/2checkout/php-2checkout-client
   *
   * Payment processors should set payment_status_id and trxn_id (if available).
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function doPayment(&$params, $component = 'contribute') {
    // Set default contribution status
    $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $params = $this->setParams($params);

    Twocheckout::privateKey($this->getPrivateKey());
    Twocheckout::sellerId($this->getSellerId());
    Twocheckout::sandbox($this->getIsTestMode());
    Twocheckout::verifySSL($this->getIsTestMode());

    $name = !empty($this->getParam('billing_first_name')) ? $this->getParam('billing_first_name') : NULL;
    $name .= !empty($this->getParam('billing_last_name')) ? $this->getParam('billing_last_name') : NULL;

    $chargeParams = [
      "sellerId" => $this->getSellerId(),
      "merchantOrderId" => $this->getParam('invoiceID'),
      "token" => $this->getPaymentToken(),
      "currency" => $this->getCurrency($params),
      "total" => $this->getAmount($params),
      "billingAddr" => [
        "name" => $name,
        "addrLine1" => $this->getParam('street_address'),
        "city" => $this->getParam('city'),
        "state" => $this->getParam('state_province'),
        "zipCode" => $this->getParam('postal_code'),
        "country" => $this->getParam('country'),
        "email" => $this->getBillingEmail($params, $this->getContactId($params)),
        //"phoneNumber" => $this->getBillingPhone($params, $this->getContactId($params)),
      ],
    ];

    try {
      $charge = Twocheckout_Charge::auth($chargeParams);

    } catch (Twocheckout_Error $e) {
      self::handleError('Unauthorized', $e->getMessage(), $params['error_url']);

    }

    $contributionParams['trxn_id'] = $charge['response']['transactionId'];
    $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');

    if ($this->getContributionId($params)) {
      $contributionParams['id'] = $this->getContributionId($params);
      civicrm_api3('Contribution', 'create', $contributionParams);
      unset($contributionParams['id']);
    }
    $params = array_merge($params, $contributionParams);

    // We need to set this to ensure that contributions are set to the correct status
    if (!empty($params['contribution_status_id'])) {
      $params['payment_status_id'] = $params['contribution_status_id'];
    }
    return $params;
  }

  /**
   * Default payment instrument validation.
   *
   * Implement the usual Luhn algorithm via a static function in the CRM_Core_Payment_Form if it's a credit card
   * Not a static function, because I need to check for payment_type.
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    // Use $_POST here and not $values - for webform fields are not set in $values, but are in $_POST
    CRM_Core_Form::validateMandatoryFields($this->getMandatoryFields(), $_POST, $errors);
  }

  /**
   * Process incoming payment notification (IPN).
   * https://2checkout.com/docs/invoice-callbacks
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function handlePaymentNotification() {
    $params = [];
    foreach ($_POST as $k => $v) {
      $params[$k] = $v;
    }

    $ipnClass = new CRM_Core_Payment_TwocheckoutIPN($params);
    if ($ipnClass->main()) {
      //Respond with HTTP 200, so Twocheckout knows the IPN has been received correctly
      http_response_code(200);
    }
  }

}

