BBT
===

Tools for Prestashop integration with the Buy-By-Touch project


catalogue.php
-------------
Generates product catalogue which conforms to the http://backoffice.buy-by-touch.com/dtd/eshop-api/1.9/eshop-api-product-catalogue.dtd . Products are enumerated using the PrestaShop Web Services API

Usage:

* Enable Web Services API in your PrestaShop
* Update config.php
    * Enter your shop URL into PS_SHOP_PATH
    * Generate the key and enter it into the PS_WS_AUTH_KEY variable
* Invoke the script from the console: php -f catalogue.php
