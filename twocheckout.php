<?php

require_once 'twocheckout.civix.php';
require_once __DIR__.'/vendor/autoload.php';

use CRM_twocheckout_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function twocheckout_civicrm_config(&$config) {
  _twocheckout_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function twocheckout_civicrm_xmlMenu(&$files) {
  _twocheckout_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function twocheckout_civicrm_install() {
  _twocheckout_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function twocheckout_civicrm_postInstall() {
  _twocheckout_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function twocheckout_civicrm_uninstall() {
  _twocheckout_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function twocheckout_civicrm_enable() {
  _twocheckout_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function twocheckout_civicrm_disable() {
  _twocheckout_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function twocheckout_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _twocheckout_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function twocheckout_civicrm_managed(&$entities) {
  _twocheckout_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function twocheckout_civicrm_caseTypes(&$caseTypes) {
  _twocheckout_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function twocheckout_civicrm_angularModules(&$angularModules) {
  _twocheckout_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function twocheckout_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _twocheckout_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function twocheckout_civicrm_entityTypes(&$entityTypes) {
  _twocheckout_civix_civicrm_entityTypes($entityTypes);
}

// Flag so we don't add the stripe scripts more than once.
static $_twocheckout_scripts_added;

/**
 * Implementation of hook_civicrm_alterContent
 *
 * Adding 2checkout.js in a way that works for webforms and (some) Civi forms.
 * hook_civicrm_buildForm is not called for webforms
 *
 * @return void
 */
function twocheckout_civicrm_alterContent( &$content, $context, $tplName, &$object ) {
  global $_twocheckout_scripts_added;
  /* Adding stripe js:
   * - Webforms don't get scripts added by hook_civicrm_buildForm so we have to user alterContent
   * - (Webforms still call buildForm and it looks like they are added but they are not,
   *   which is why we check for $object instanceof CRM_Financial_Form_Payment here to ensure that
   *   Webforms always have scripts added).
   * - Almost all forms have context = 'form' and a paymentprocessor object.
   * - Membership backend form is a 'page' and has a _isPaymentProcessor=true flag.
   *
   */
  if (($context == 'form' && !empty($object->_paymentProcessor['class_name']))
    || (($context == 'page') && !empty($object->_isPaymentProcessor))) {
    if (!$_twocheckout_scripts_added || $object instanceof CRM_Financial_Form_Payment) {
      $stripeJSURL = CRM_Core_Resources::singleton()
        ->getUrl('twocheckout', 'js/2checkout.js');
      $content .= "<script src='{$stripeJSURL}'></script>";
      $_twocheckout_scripts_added = TRUE;
    }
  }
}

/**
 * Add 2checkout.js to forms, to generate token
 * hook_civicrm_alterContent is not called for all forms (eg. CRM_Contribute_Form_Contribution on backend)
 *
 * @param string $formName
 * @param CRM_Core_Form $form
 */
function twocheckout_civicrm_buildForm($formName, &$form) {
  global $_twocheckout_scripts_added;
  if (!isset($form->_paymentProcessor)) {
    return;
  }
  $paymentProcessor = $form->_paymentProcessor;
  if (!empty($paymentProcessor['class_name'])) {
    if (!$_twocheckout_scripts_added) {
      CRM_Core_Resources::singleton()
        ->addScriptFile('twocheckout', 'js/2checkout.js');
    }
    $_twocheckout_scripts_added = TRUE;
  }
}
