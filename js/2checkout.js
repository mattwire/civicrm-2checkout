/**
 * @file
 * JS Integration between CiviCRM & token based payments.
 */
CRM.$(function($) {

  // Prepare the form.
  var onclickAction = null;
  $(document).ready(function() {
    // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
    window.onbeforeunload = null;
    // Load Billing block onto the form.
    loadBillingBlock();
    $submit = getBillingSubmit();

    // Store and remove any onclick Action currently assigned to the form.
    // We will re-add it if the transaction goes through.
    onclickAction = $submit.attr('onclick');
    $submit.removeAttr('onclick');

    // Quickform doesn't add hidden elements via standard method. On a form where payment processor may
    //  be loaded via initial form load AND ajax (eg. backend live contribution page with payproc dropdown)
    //  the processor metadata elements will appear twice (once on initial load, once via AJAX).  The ones loaded
    //  via initial load will not be removed when AJAX loaded ones are added and the wrong processor public key etc will
    //  be submitted.  This removes all elements with the class "payproc-metadata" from the form each time the
    //  dropdown is changed.
    $('select#payment_processor_id').on('change', function() {
      $('input.payproc-metadata').remove();
    });
  });

  // Re-prep form when we've loaded a new payproc
  $( document ).ajaxComplete(function( event, xhr, settings ) {
    // /civicrm/payment/form? occurs when a payproc is selected on page
    // /civicrm/contact/view/participant occurs when payproc is first loaded on event credit card payment
    // On wordpress these are urlencoded
    if ((settings.url.match("civicrm(\/|%2F)payment(\/|%2F)form") != null)
      || (settings.url.match("civicrm(\/|\%2F)contact(\/|\%2F)view(\/|\%2F)participant") != null)) {
      // See if there is a payment processor selector on this form
      // (e.g. an offline credit card contribution page).
      if ($('#payment_processor_id').length > 0) {
        // There is. Check if the selected payment processor is different
        // from the one we think we should be using.
        var ppid = $('#payment_processor_id').val();
        if (ppid != getProcessorIdElement().val()) {
          debugging('payment processor changed to id: ' + ppid);
          // It is! See if the new payment processor is also the same type
          // Payment processor. First, find out what the same
          // payment processor type id is (we don't want to update
          // the processor pub key with a value from another payment processor).
          CRM.api3('PaymentProcessorType', 'getvalue', {
            "return": "id",
            "name": "2checkout"
          }).done(function(result) {
            // Now, see if the new payment processor id is the same type
            var this_pp_type_id = result['result'];
            CRM.api3('PaymentProcessor', 'getvalue', {
              "return": "password",
              "id": ppid,
              "payment_processor_type_id": this_pp_type_id,
            }).done(function(result) {
              var pub_key = result['result'];
              if (pub_key) {
                // It is the same payment processor, so update the key.
                debugging("Setting new publishable key to: " + pub_key);
                getPubKeyElement().val(pub_key);
              }
              else {
                debugging("New payment processor is not the same type, setting " + getPubKeyElementName() + " to null");
                getPubKeyElement().val(null);
              }
              // Now reload the billing block.
              loadBillingBlock();
            });
          });
        }
      }
      loadBillingBlock();
    }
  });

  function loadBillingBlock() {
    // Setup payment provider external js
    if (getPubKeyElement().length) {
      loadPaymentProcessorJs();
    }

    // Get the form containing payment details
    $form = getBillingForm();
    if (!$form.length) {
      debugging('No billing form!');
      return;
    }
    $submit = getBillingSubmit();

    // If another submit button on the form is pressed (eg. apply discount)
    //  add a flag that we can set to stop payment submission
    $form.data('submit-dont-process', '0');
    // Find submit buttons which should not submit payment
    $form.find('[type="submit"][formnovalidate="1"], ' +
      '[type="submit"][formnovalidate="formnovalidate"], ' +
      '[type="submit"].cancel, ' +
      '[type="submit"].webform-previous').click( function() {
      debugging('adding submit-dont-process');
      $form.data('submit-dont-process', 1);
    });

    $submit.click( function(event) {
      // Take over the click function of the form.
      debugging('clearing submit-dont-process');
      $form.data('submit-dont-process', 0);

      // Run through our own submit, that executes payproc submission if
      // appropriate for this submit.
      var ret = submit(event);
      if (ret) {
        // True means it's not our form. We are bailing and not trying to
        // process this via this processor.
        // Restore any onclickAction that was removed.
        $form = getBillingForm();
        $submit = getBillingSubmit();
        $submit.attr('onclick', onclickAction);
        $form.get(0).submit();
        return true;
      }
      // Otherwise, this is our submission - don't handle normally.
      // The code for completing the submission is all managed in the
      // tokenResponseHandler which gets execute after the external js finishes.
      return false;
    });

    // Add a keypress handler to set flag if enter is pressed
    $form.find('input#discountcode').keypress( function(e) {
      if (e.which === 13) {
        $form.data('submit-dont-process', 1);
      }
    });

    var isWebform = getIsWebform($form);

    // For CiviCRM Webforms.
    if (isWebform) {
      // We need the action field for back/submit to work and redirect properly after submission
      if (!($('#action').length)) {
        $form.append($('<input type="hidden" name="op" id="action" />'));
      }
      var $actions = $form.find('[type=submit]');
      $('[type=submit]').click(function() {
        $('#action').val(this.value);
      });
      // If enter pressed, use our submit function
      $form.keypress(function(event) {
        if (event.which === 13) {
          $('#action').val(this.value);
          submit(event);
        }
      });
      $('#billingcheckbox:input').hide();
      $('label[for="billingcheckbox"]').hide();
    }
    else {
      // As we use credit_card_number to pass token, make sure it is empty when shown
      $form.find("input#credit_card_number").val('');
      $form.find("input#cvv2").val('');
    }

    function submit(event) {
      event.preventDefault();
      debugging('submit handler');

      if ($form.data('submitted') === true) {
        debugging('form already submitted');
        return false;
      }

      var isWebform = getIsWebform($form);

      // Handle multiple payment options and this one not being chosen.
      if (isWebform) {
        var thisTypeProcessorId;
        var chosenProcessorId;
        thisTypeProcessorId = getProcessorIdElement().val();
        // this element may or may not exist on the webform, but we are dealing with a single processor enabled.
        if (!$('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]').length) {
          chosenProcessorId = thisTypeProcessorId;
        } else {
          chosenProcessorId = $form.find('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked').val();
        }
      }
      else {
        // Most forms have payment_processor-section but event registration has credit_card_info-section
        if (($form.find(".crm-section.payment_processor-section").length > 0)
            || ($form.find(".crm-section.credit_card_info-section").length > 0)) {
          thisTypeProcessorId = getProcessorIdElement().val();
          chosenProcessorId = $form.find('input[name="payment_processor_id"]:checked').val();
        }
      }

      // If any of these are true, we are not using this processor:
      // - Is the selected processor ID pay later (0)
      // - Is this processor ID defined?
      // - Is selected processor ID and this ID undefined? If we only have this ID, then there is only one of this processor on the page
      if ((chosenProcessorId === 0)
          || (thisTypeProcessorId == null)
          || ((chosenProcessorId == null) && (thisTypeProcessorId == null))) {
        debugging('Not a ' + getProcessorName() + ' transaction, or pay-later');
        return true;
      }
      else {
        debugging(getProcessorName() + ' is the selected payprocessor');
      }

      $form = getBillingForm();

      // Don't handle submits generated by other processors
      if (!$('input' + getPubKeyElementName()).length || !($('input' + getPubKeyElementName()).val())) {
        debugging('submit missing ' + getPubKeyElementName() + ' element or value');
        return true;
      }
      // Don't handle submits generated by the CiviDiscount button.
      if ($form.data('submit-dont-process')) {
        debugging('non-payment submit detected - not submitting payment');
        return true;
      }

      $submit = getBillingSubmit();

      if (isWebform) {
        // If we have selected this processor but amount is 0 we don't submit to the processor
        if ($('#billing-payment-block').is(':hidden')) {
          debugging('no payment processor on webform');
          return true;
        }

        // If we have more than one processor (user-select) then we have a set of radio buttons:
        var $processorFields = $('[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]');
        if ($processorFields.length) {
          if ($processorFields.filter(':checked').val() === '0' || $processorFields.filter(':checked').val() === 0) {
            debugging('no payment processor selected');
            return true;
          }
        }
      }

      // This is ONLY triggered in the following circumstances on a CiviCRM contribution page:
      // - With a priceset that allows a 0 amount to be selected.
      // - When we are the ONLY payment processor configured on the page.
      if (typeof calculateTotalFee == 'function') {
        var totalFee = calculateTotalFee();
        if (totalFee == '0') {
          debugging("Total amount is 0");
          return true;
        }
      }

      // If there's no credit card field, no use in continuing (probably wrong
      // context anyway)
      if (!$form.find('#credit_card_number').length) {
        debugging('No credit card field');
        return true;
      }
      // Lock to prevent multiple submissions
      if ($form.data('submitted') === true) {
        // Previously submitted - don't submit again
        alert('Form already submitted. Please wait.');
        return false;
      } else {
        // Mark it so that the next submit can be ignored
        // ADDED requirement that form be valid
        if($form.valid()) {
          $form.data('submitted', true);
        }
      }

      // Disable the submit button to prevent repeated clicks
      $submit.prop('disabled', true);

      getToken();
      debugging('Created token');
      return false;
    }
  }

  function getIsWebform(form) {
    // Pass in the billingForm object
    // If the form has the webform-client-form (drupal 7) or webform-submission-form (drupal 8) class then it's a drupal webform!
    return form.hasClass('webform-client-form') || form.hasClass('webform-submission-form');
  }

  function getBillingForm() {
    // If we have a billing form on the page of this payment processor type
    var $billingForm = $('input' + getPubKeyElementName()).closest('form');
    if (!$billingForm.length) {
      // If we have multiple payment processors to select and this processor is not currently loaded
      $billingForm = $('input[name=hidden_processor]').closest('form');
    }
    return $billingForm;
  }

  function getBillingSubmit() {
    $form = getBillingForm();
    var isWebform = getIsWebform($form);

    if (isWebform) {
      $submit = $form.find('[type="submit"].webform-submit');
      if (!$submit.length) {
        // drupal 8 webform
        $submit = $form.find('[type="submit"].webform-button--submit');
      }
    }
    else {
      $submit = $form.find('[type="submit"].validate');
    }
    return $submit;
  }

  function getPubKeyElement() {
    return $(getPubKeyElementName());
  }

  function getPubKeyElementName() {
    return '#crm-tco-pubkey';
  }

  function getSellerIdElement() {
    return $('#crm-tco-sellerid');
  }

  function getModeElement() {
    return $('#crm-tco-mode');
  }

  function getProcessorIdElement() {
    return $('#crm-tco-id');
  }

  function getTokenElementName() {
    return '#crm-tco-token';
  }

  function getProcessorName() {
    return '2Checkout';
  }

  function loadPaymentProcessorJs() {
    if (!$().TCO) {
      $.getScript('https://www.2checkout.com/checkout/api/2co.min.js', function() {
        try {
          // Pull in the public encryption key for our environment
          TCO.loadPubKey(getModeElement().val());
        } catch(e) {
          alert(e.toSource());
        }
      });
    }
  }

  function getToken() {
    var cc_month = $form.find('#credit_card_exp_date_M').val();
    var cc_year = $form.find('#credit_card_exp_date_Y').val();

    var args = {
      sellerId: getSellerIdElement().val(),
      publishableKey: getPubKeyElement().val(),
      ccNo: $form.find('#credit_card_number').val(),
      cvv: $form.find('#cvv2').val(),
      expMonth: cc_month,
      expYear: cc_year,
    };

    TCO.requestToken(tokenSuccessResponseCallback, tokenErrorResponseCallback, args);
  }

  function tokenErrorResponseCallback(data) {
    // Response from createToken.
    $form = getBillingForm();
    $submit = getBillingSubmit();

    $('html, body').animate({scrollTop: 0}, 300);
    // Show the errors on the form.
    if ($(".messages.crm-error.token-payment-message").length > 0) {
      $(".messages.crm-error.token-message").slideUp();
      $(".messages.crm-error.token-message:first").remove();
    }
    $form.prepend('<div class="messages alert alert-block alert-danger error crm-error token-payment-message">'
      + '<strong>Payment Error Response:</strong>'
      + '<ul id="errorList">'
      + '<li>Error: ' + data.errorMsg + '</li>'
      + '</ul>'
      + '</div>');

    removeCCDetails($form, true);
    $form.data('submitted', false);
    $submit.prop('disabled', false);
  }

  function tokenSuccessResponseCallback(data) {
    $form = getBillingForm();
    $submit = getBillingSubmit();

    // Update form with the token & submit.
    removeCCDetails($form, false);
    $form.find("input" + getTokenElementName()).val(data.response.token.token);

    // Disable unload event handler
    window.onbeforeunload = null;

    // Restore any onclickAction that was removed.
    $submit.attr('onclick', onclickAction);

    // This triggers submit without generating a submit event (so we don't run submit handler again)
    $form.get(0).submit();
  }

  function removeCCDetails($form, $truncate) {
    // Remove the "name" attribute so params are not submitted
    var ccNumElement = $form.find("input#credit_card_number");
    var cvv2Element = $form.find("input#cvv2");
    if ($truncate) {
      ccNumElement.val('');
      cvv2Element.val('');
    }
    else {
      var last4digits = ccNumElement.val().substr(12, 16);
      ccNumElement.val('000000000000' + last4digits);
      cvv2Element.val('000');
    }
  }

  function debugging (errorCode) {
    // Uncomment the following to debug unexpected returns.
    //console.log(new Date().toISOString() + ' 2checkout.js: ' + errorCode);
  }

});
