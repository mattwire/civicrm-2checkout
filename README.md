# MOVED TO https://lab.civicrm.org/extensions/2checkout



# 2Checkout

This extension integrates 2Checkout with CiviCRM using the "Payments API".

* https://www.2checkout.com/
* https://www.2checkout.com/payment-api/

This works in a similar way to Stripe where a payment token is generated directly on the client browser 
using an external javascript library provided by 2Checkout.  This way credit card details 
never reach your server and PCI compliance is simplified as you should be able to achieve SAQ-A compliance: https://www.2checkout.com/lp/pci-dss-compliance-faq.html

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.0+
* CiviCRM 5.13+

## Installation

Please see https://docs.civicrm.org/2checkout for more information.
