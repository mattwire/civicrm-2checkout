<?php
/*
 * @file
 * Handle Twocheckout Webhooks for recurring payments.
 */

use CRM_twocheckout_ExtensionUtil as E;

class CRM_Core_Payment_TwocheckoutIPN extends CRM_Core_Payment_BaseIPN {

  use CRM_Core_Payment_TwocheckoutIPNTrait;
  use CRM_Core_Payment_TwocheckoutTrait;

  /**
   * CRM_Core_Payment_TwocheckoutIPN constructor.
   *
   * @param $ipnData
   * @param bool $verify
   *
   * @throws \CRM_Core_Exception
   */
  public function __construct($ipnData) {
    $this->_params = $ipnData;
    $this->getPaymentProcessor();
    $this->_processorName = E::ts('2checkout');
    parent::__construct();
  }

  /**
   * This is the first part of the signature (separated by | char)
   *
   * @return string
   */
  private function getSecretWord() {
    list($sellerId, $secretWord) = explode('|', $this->_paymentProcessor['signature']);
    return (string)trim($secretWord);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function main() {
    $verify = Twocheckout_Notification::check($this->_params, $this->getSecretWord());
    if ($verify['response_code'] !== 'Success') {
      $this->handleError($verify['response_code'], $verify['response_message']);
      return FALSE;
    }

    // We need a contribution ID - from the transactionID (invoice ID)
    try {
      // Same approach as api repeattransaction.
      $contribution = civicrm_api3('contribution', 'getsingle', [
        'return' => ['id', 'contribution_status_id', 'total_amount', 'trxn_id'],
        'contribution_test' => $this->getIsTestMode(),
        'options' => ['limit' => 1, 'sort' => 'id DESC'],
        'trxn_id' => $this->getParam('invoice_id'),
      ]);
      $contributionId = $contribution['id'];
    }
    catch (Exception $e) {
      $this->exception('Cannot find any contributions with invoice ID: ' . $this->getParam('invoice_id') . '. ' . $e->getMessage());
    }

    // See https://www.2checkout.com/documentation/notifications
    switch ($this->getParam('message_type')) {
      case 'FRAUD_STATUS_CHANGED':
        switch ($this->getParam('fraud_status')) {
          case 'pass':
            // Do something when sale passes fraud review.
            // The last one was not completed, so complete it.
            civicrm_api3('Contribution', 'completetransaction', array(
              'id' => $contributionId,
              'payment_processor_id' => $this->_paymentProcessor['id'],
              'is_email_receipt' => $this->getSendEmailReceipt(),
            ));
            break;
          case 'fail':
            // Do something when sale fails fraud review.
            $this->failtransaction([
              'id' => $contributionId,
              'payment_processor_id' => $this->_paymentProcessor['id']
            ]);
            break;
          case 'wait':
            // Do something when sale requires additional fraud review.
            // Do nothing, we'll remain in Pending.
            break;
        }
        break;

      case 'REFUND_ISSUED':
        // To be implemented
        break;
    }

    // Unhandled event type.
    return TRUE;
  }

  public function exception($message) {
    $errorMessage = $this->getPaymentProcessorLabel() . ' Exception: Event: ' . $this->event_type . ' Error: ' . $message;
    Civi::log()->debug($errorMessage);
    http_response_code(400);
    exit(1);
  }

}
