# Introduction

This extension integrates 2Checkout with CiviCRM using the "Payments API".

* https://www.2checkout.com/
* https://www.2checkout.com/payment-api/

This works in a similar way to Stripe where a payment token is generated directly on the client browser 
using an external javascript library provided by 2Checkout.  This way credit card details 
never reach your server and PCI compliance is simplified as you should be able to achieve SAQ-A compliance: https://www.2checkout.com/lp/pci-dss-compliance-faq.html

## Setup

1. Enable the 2Checkout extension in CiviCRM.
1. Go to the CiviCRM system status page (*Administer->Administration Console->System Status*) and ensure that there are no errors/warnings for the 2Checkout extension.

#### Create an account
If you don't already have a 2Checkout account:

##### Live: https://www.2checkout.com/pricing/

##### Test: https://sandbox.2checkout.com/sandbox/signup

#### Create an API token
Login to your account and retrieve the following information:
From https://sandbox.2checkout.com/sandbox/api/:
* Publishable Key
* Private Key

From https://sandbox.2checkout.com/sandbox/acct/detail_company_info (Account->Site Management):
* Secret Word

From Account:
* Account Number

#### Enable extension

1. Create a new payment processor of type "2Checkout (Token)".
    1. Enter the Publishable Key.
    1. Enter the Private Key.
    1. Enter the Account Number and Secret word (separated by "|" character). *Example:* 1231545151|mysecretword
    1. You do not need to change "Site URL" - it is not used.

## IPN / Notifications / Callbacks
In order to receive updates on payments which are pending fraud checks you need to enable the IPN callback.
Otherwise those payments will stay as "Pending" in CiviCRM instead of changing to "Completed" or "Failed".

To set this up you need to work out the correct URL to provide to 2Checkout.  Please read https://docs.civicrm.org/sysadmin/en/latest/setup/payment-processors/recurring/ for more information.

It should look something like: https://example.com/civicrm/payment/ipn/3

This URL must be entered in "Global Settings" on your 2Checkout control panel as described here: https://www.2checkout.com/documentation/notifications 

In the future we may also use this method to handle recurring payments and refunds.
