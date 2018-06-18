<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once(dirname(__FILE__) . '/vendor/autoload.php');

class automater extends Module
{
    protected $apiKey;
    protected $apiSecret;
    protected $apiActive;

    public $defaultShopId;

    /* Automater SDK handle */
    protected $_automater;

    public function __construct()
    {
        $this->name = 'automater';
        $this->tab = 'administration';
        $this->version = '1.0.3';
        $this->author = 'Automater sp. z o.o.';
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        $this->apiKey = empty(Configuration::get('VCAUTOMATER_KEY')) ? '' : Configuration::get('VCAUTOMATER_KEY');
        $this->apiSecret = empty(Configuration::get('VCAUTOMATER_SECRET')) ? '' : Configuration::get('VCAUTOMATER_SECRET');
        $this->apiActive = empty(Configuration::get('VCAUTOMATER_ACTIVE')) ? false : Configuration::get('VCAUTOMATER_ACTIVE');

        $this->_automater = new AutomaterSDK\Client\Client($this->apiKey, $this->apiSecret);

        $this->displayName = $this->l('Automater.pl');
        $this->description = $this->l('Automater.pl integration plugin');
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.6.9');
        $this->defaultShopId = Configuration::get('PS_SHOP_DEFAULT');
    }

    // Install module and create tables associated with it.
    public function install()
    {
        if (!parent::install() ||
            !$this->registerHook('actionValidateOrder') ||
            !$this->registerHook('actionProductUpdate') ||
            !$this->registerHook('displayAdminProductsExtra') ||
            !$this->registerHook('actionOrderStatusUpdate')
        ) {
            return false;
        } else {
            $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'vcautomater_trans` (
				`trans_id` INT(11) NOT NULL AUTO_INCREMENT,
				`pshop_order_id` INT(10) NOT NULL ,
				`pshop_cart_id` INT(10) NOT NULL ,
				`automaterpl_cart_id` INT(10) NOT NULL ,
				`status` varchar(100) NOT NULL ,
				PRIMARY KEY (`trans_id`)
				) ENGINE = ' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';
            if (!Db::getInstance()->execute($sql))
                return false;

            $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'automater_products` (
				`id` INT(11) NOT NULL ,
				`name` text NOT NULL ,
				 PRIMARY KEY (`id`)
				) ENGINE = ' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';
            if (!Db::getInstance()->execute($sql))
                return false;

            $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'product ';
            $row = Db::getInstance()->getRow($sql);
            $keys = array_keys($row);
            if (!in_array('automater_product_id', $keys)) {
                $sql = 'ALTER TABLE  `' . _DB_PREFIX_ . 'product` ADD  COLUMN `automater_product_id` INT(11) NOT NULL ';
                if (!Db::getInstance()->execute($sql))
                    return false;
            }

            Configuration::updateValue('VCAUTOMATER_KEY', '');
            Configuration::updateValue('VCAUTOMATER_SECRET', '');
            Configuration::updateValue('VCAUTOMATER_ACTIVE', 0);
            Configuration::updateValue('VCAUTOMATER_TRANSACTION_STATUS', 1);

            return true;
        }
    }

    // Uninstall module and drop tables associated with it.
    public function uninstall()
    {
        Configuration::deleteByName('VCAUTOMATER_KEY');
        Configuration::deleteByName('VCAUTOMATER_SECRET');
        Configuration::deleteByName('VCAUTOMATER_ACTIVE');
        Configuration::updateValue('VCAUTOMATER_TRANSACTION_STATUS');

        $sql = ' DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'vcautomater_trans`';
        if (!Db::getInstance()->execute($sql)) return false;

        $sql = ' DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'automater_products`';
        $res = Db::getInstance()->execute($sql);
        if (!$res) return false;

        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'product ';
        $row = Db::getInstance()->getRow($sql);
        $keys = array_keys($row);
        if (in_array('automater_product_id', $keys)) {
            $sql = 'ALTER TABLE  `' . _DB_PREFIX_ . 'product` DROP  COLUMN `automater_product_id` ';
            $res = Db::getInstance()->execute($sql);
            if (!$res)
                return false;
        }

        return parent::uninstall();
    }

    // Action hook for Orderstatus Update Called for Api Payment Transaction.
    public function hookactionOrderStatusUpdate($params)
    {
        if ($params["newOrderStatus"]->id == 2) {
            $this->payTransaction($params["id_order"]);
        }
    }

    // Creates tab in the admin  Product Page  to map porduct with automater product.
    public function hookDisplayAdminProductsExtra($params)
    {
        $productId = (int)Tools::getValue('id_product');
        if (Validate::isLoadedObject($product = new Product($productId))) {
            $this->context->smarty->assign(array(
                'assignedProductId' => $this->getAutomaterProductIdBasedOnShopProductId($productId),
                'automaterProducts' => $this->getProductsFromAPI()
            ));

            return $this->display(__FILE__, 'templates/display_admin_products_extra.tpl');
        }
    }

    // Cron job to update stock for each products
    public function cronjobimportProduct()
    {
        $products = $this->getProductsFromAPI();
        if ($products) {
            foreach ($products as $product) {
                $productid = $this->getProductByAutomaterProductId($product->getId());
                if ($productid) {
                    $qty = $product->getAvailableCodes();
                    StockAvailable::setQuantity((int)$productid, 0, $qty, $this->defaultShopId);
                }
            }
        }
    }

    // Action Hook  called during  Product update to to map porduct with automater product.
    public function hookactionProductUpdate($params)
    {
        $id_product = (int)Tools::getValue('id_product');
        $automater_product_id = Tools::getValue('selautomater_product_id');

        $data = array(
            'automater_product_id' => pSQL($automater_product_id)

        );
        Db::getInstance()->update('product', $data, ' id_product =' . $id_product);
    }

    // Get Automater product ID using prestahsop product id.
    public function getAutomaterProductIdBasedOnShopProductId($productId)
    {
        $sql = 'SELECT automater_product_id FROM `' . _DB_PREFIX_ . 'product` WHERE id_product=' . $productId;
        return Db::getInstance()->getValue($sql);
    }

    // Get prestahsop product id using Automater product id.
    public function getProductByAutomaterProductId($automater_product_id)
    {
        $sql = 'SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE automater_product_id=' . $automater_product_id;
        return Db::getInstance()->getValue($sql);

    }

    public function getAutomaterProductNameBasedOnAutomaterProductId($automater_product_id)
    {
        $sql = 'SELECT name FORM `' . _DB_PREFIX_ . 'automater_products` WHERE automater_product_id=' . $automater_product_id;
        $value = Db::getInstance()->getValue($sql);
        return $value;
    }

    // Get all products from  Automater api.
    public function getAutomaterproductlist()
    {
        $products = $this->getProductsFromAPI();
        return $products;
    }

    /* Action Hook  called during  Order creation to create trnasaction and payment in the automater api */
    public function hookActionValidateOrder($params)
    {
        $this->processtransaction($params['order']);
    }

    /* Method to create Transaction and Payment in the Automater api */
    public function processtransaction($order)
    {
        if (!$this->apiActive) {
            return false;
        }

        $currency = new CurrencyCore($order->id_currency);
        $currencyIsoCode = $currency->iso_code;
        if (empty($currencyIsoCode)) {
            $currencyIsoCode = "PLN";
        }

        $products = $order->getProducts();

        $productsArray = [];
        foreach ($products as $product) {
            try {
                $productId = $product['id_product'];
                $qty = $product['product_quantity'];
                $automaterProductId = $this->getAutomaterProductIdBasedOnShopProductId($productId);
                if (empty($automaterProductId) || $automaterProductId == 0) {
                    continue;
                } else {
                    if (!isset($productsArray[$automaterProductId])) {
                        $productsArray[$automaterProductId] = [
                            'quantity' => 0,
                            'price' => null
                        ];
                    }

                    $productsArray[$automaterProductId]['quantity'] = $productsArray[$automaterProductId]['quantity'] + $qty;
                    $productsArray[$automaterProductId]['price'] = $product['unit_price_tax_incl'];
                }
            } catch (Exception $e) {}
        }

        $transactionProducts = [];
        foreach ($productsArray as $automaterProductId => $data) {
            $transactionProduct = new \AutomaterSDK\Request\Entity\TransactionProduct();
            $transactionProduct->setId($automaterProductId);
            $transactionProduct->setQuantity($data['quantity']);
            $transactionProduct->setCurrency($currencyIsoCode);
            $transactionProduct->setPrice($data['price']);
            $transactionProducts[] = $transactionProduct;
        }

        $customer = new Customer($order->id_customer);
        $languages = Language::getLanguages(false);
        $automaterLanguage = 'en';
        foreach ($languages as $language) {
            if ($language['id_lang'] == (int)Configuration::get('PS_LANG_DEFAULT')) {
                $automaterLanguage = $language['iso_code'];
            }
        }

        if (sizeof($productsArray)) {
            $transactionRequest = new \AutomaterSDK\Request\TransactionRequest();
            $transactionRequest->setEmail($customer->email);
            $transactionRequest->setLanguage($automaterLanguage);
            $transactionRequest->setSendStatusEmail(Configuration::get("VCAUTOMATER_TRANSACTION_STATUS") == 1 ? \AutomaterSDK\Request\TransactionRequest::SEND_STATUS_EMAIL_TRUE : \AutomaterSDK\Request\TransactionRequest::SEND_STATUS_EMAIL_FALSE);
            $transactionRequest->setCustom(sprintf($this->l("Order from %s, shop order id: %s.", "automater"), Tools::getShopDomain(true), $order->id));
            $transactionRequest->setProducts($transactionProducts);

            try {
                $transactionResponse = $this->_automater->createTransaction($transactionRequest);
                $automaterCartId = $transactionResponse->getCartId();
                $data = array(
                    'pshop_order_id' => $order->id,
                    'automaterpl_cart_id' => $automaterCartId,
                    'pshop_cart_id' => $order->id_cart,
                    'status' => "TRANSACTION CREATED"
                );

                Db::getInstance()->delete('vcautomater_trans', ' pshop_order_id=' . $order->id);
                Db::getInstance()->insert('vcautomater_trans', $data);

                if ($order->current_state == 2) {
                    $this->payTransaction($order->id);
                }
            } catch (\AutomaterSDK\Exception\ApiException $apiException) {
                $logger = new PrestaShopLogger();
                $logger->addLog(sprintf("Automater: problem with creating transaction for order number %s: [%s] %s", $order->id, $apiException->getCode(), $apiException->getMessage()), 3);
            } catch (\Exception $exception) {
                $logger = new PrestaShopLogger();
                $logger->addLog(sprintf("Automater (global exception): problem with creating transaction for order number %s: %s", $order->id, $exception->getMessage()), 3);
            }
        }
    }

    /* Get transaction_id from the prestahsop order */
    public function getTransactionId($order_reference)
    {
        $sql = 'SELECT transaction_id FROM `' . _DB_PREFIX_ . 'order_payment` WHERE order_reference=\'' . $order_reference . '\'';
        return Db::getInstance()->getValue($sql);
    }


    /* Get automater cart id from the prestahsop order */
    public function getAutomaterCartId($orderid)
    {
        $sql = 'SELECT `automaterpl_cart_id` FROM `' . _DB_PREFIX_ . 'vcautomater_trans` WHERE pshop_order_id=' . $orderid;
        return Db::getInstance()->getValue($sql);
    }

    /* Check if Automater Payment exist for the order */
    public function getAutomaterPayment($orderid)
    {
        $sql = 'SELECT automaterpl_cart_id FROM `' . _DB_PREFIX_ . 'vcautomater_trans` WHERE pshop_order_id=' . $orderid . ' AND status="PAYMENT CREATED" ';
        return Db::getInstance()->getValue($sql);
    }

    /* Method to create payment in Automater API */
    public function payTransaction($orderid)
    {
        if (!$this->apiActive) {
            return false;
        }

        $order = new Order($orderid);
        $currency = new CurrencyCore($order->id_currency);
        $currencyIsoCode = $currency->iso_code;
        if (empty($currencyIsoCode)) $currencyIsoCode = "PLN";

        $automaterCartId = $this->getAutomaterCartId($orderid);

        if (!$automaterCartId) {
            return false;
        }

        try {
            $totalamount = $order->total_paid;

            $paymentRequest = new \AutomaterSDK\Request\PaymentRequest();
            $paymentRequest->setPaymentId('PS17-' . $orderid);
            $paymentRequest->setCurrency($currencyIsoCode);
            $paymentRequest->setAmount($totalamount);
            $paymentRequest->setDescription('shop payment');

            try {
                $this->_automater->postPayment($automaterCartId, $paymentRequest);
            } catch (\AutomaterSDK\Exception\ApiException $apiException) {
                $logger = new PrestaShopLogger();
                $logger->addLog(sprintf("Automater: problem with post payment for transaction for order number %s: [%s] %s", $orderid, $apiException->getCode(), $apiException->getMessage()), 3);
            } catch (\Exception $exception) {
                $logger = new PrestaShopLogger();
                $logger->addLog(sprintf("Automater (global exception): problem with post payment for transaction for order number %s: %s", $orderid, $exception->getMessage()), 3);
            }

            $data = array(
                'status' => "PAYMENT CREATED"
            );

            Db::getInstance()->insert('vcautomater_trans', $data, ' automaterpl_cart_id=' . $automaterCartId);
        } catch (\Exception $e) {}
    }

    /* Get products from Automater API and save in automater_products table */
    public function getproducts()
    {
        Db::getInstance()->delete('automater_products', ' id>0');
        $products = $this->getProductsFromAPI();
        foreach ($products as $product) {
            $data = [
                'id' => $product->getId(),
                'name' => $product->getName()
            ];

            Db::getInstance()->insert('automater_products', $data);
        }
    }


    /* Get Products from Automater API */
    /**
     * @return \AutomaterSDK\Response\Entity\Product[]|false
     */
    public function getProductsFromAPI()
    {
        $productsRequest = new \AutomaterSDK\Request\ProductsRequest();
        $productsRequest->setType(\AutomaterSDK\Request\ProductsRequest::TYPE_SHOP);
        $productsRequest->setStatus(\AutomaterSDK\Request\ProductsRequest::STATUS_ACTIVE);
        $productsRequest->setPage(1);
        $productsRequest->setLimit(100);

        $currentPage = 1;
        $result = false;
        $data = [];

        while (empty($result) || $result->getCurrentPage() * $result->getCurrentCount() < $result->getRecordsCount()) {
            try {
                $result = $this->_automater->getProducts($productsRequest);
            } catch (\AutomaterSDK\Exception\ApiException $apiException) {
                $logger = new PrestaShopLogger();
                $logger->addLog(sprintf("Automater: problem with getting products from API: [%s] %s", $apiException->getCode(), $apiException->getMessage()), 3);
                return false;
            } catch (\Exception $exception) {
                $logger = new PrestaShopLogger();
                $logger->addLog(sprintf("Automater (global exception): problem with getting products from API: %s", $exception->getMessage()), 3);
                return false;
            }

            $data = array_merge($data, $result->getData()->toArray());

            $currentPage++;
            $productsRequest->setPage($currentPage);
        }

        return $data;
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            $apiKey = strval(Tools::getValue('VCAUTOMATER_KEY'));
            $apiSecret = strval(Tools::getValue('VCAUTOMATER_SECRET'));
            $apiActive = strval(Tools::getValue('VCAUTOMATER_ACTIVE'));
            $transactionStatus = strval(Tools::getValue('VCAUTOMATER_TRANSACTION_STATUS'));

            if (empty($apiKey) && empty($apiSecret)) {
                Configuration::updateValue('VCAUTOMATER_KEY', '');
                Configuration::updateValue('VCAUTOMATER_SECRET', '');
                Configuration::updateValue('VCAUTOMATER_ACTIVE', 0);

                return $this->displayConfirmation($this->l('Settings updated.')) . $this->displayForm();
            }

            $automaterTestClient = new \AutomaterSDK\Client\Client($apiKey, $apiSecret);

            try {
                $automaterTestClient->getProducts(new \AutomaterSDK\Request\ProductsRequest());
            } catch (\AutomaterSDK\Exception\UnauthorizedException $e) {
                $output .= $this->displayError($this->l('Unauthorized exception: please validate API Key and API Secret.'));
                return $output . $this->displayForm();
            } catch (\Exception $e) {
                $output .= $this->displayError($this->l('Automater API Error:' ) . ' ' . $e->getMessage());
                return $output . $this->displayForm();
            }

            Configuration::updateValue('VCAUTOMATER_KEY', $apiKey);
            Configuration::updateValue('VCAUTOMATER_SECRET', $apiSecret);
            Configuration::updateValue('VCAUTOMATER_ACTIVE', $apiActive);
            Configuration::updateValue('VCAUTOMATER_TRANSACTION_STATUS', $transactionStatus);

            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'select',
                    'label' => $this->l('Status'),
                    'name' => 'VCAUTOMATER_ACTIVE',
                    'required' => true,
                    'options' => [
                        'query' => [
                            [
                                'status_id' => 0,
                                'name' => $this->l('Disabled')
                            ],
                            [
                                'status_id' => 1,
                                'name' => $this->l('Enabled')
                            ]
                        ],
                        'id' => 'status_id',
                        'name' => 'name'
                    ],
                    'desc' => $this->l('If API is active Automater module is working with connected product.')
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('API Key'),
                    'name' => 'VCAUTOMATER_KEY',
                    'size' => 20,
                    'required' => true,
                    'value' => 'aaa',
                    'desc' => $this->l('You can get this value in Settings / Settings / API tab in user panel.')
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('API Secret'),
                    'name' => 'VCAUTOMATER_SECRET',
                    'size' => 20,
                    'required' => true,
                    'desc' => $this->l('You can get this value in Settings / Settings / API tab in user panel.')
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Transaction status'),
                    'name' => 'VCAUTOMATER_TRANSACTION_STATUS',
                    'required' => true,
                    'options' => [
                        'query' => [
                            [
                                'status_id' => 0,
                                'name' => $this->l('Disabled')
                            ],
                            [
                                'status_id' => 1,
                                'name' => $this->l('Enabled')
                            ]
                        ],
                        'id' => 'status_id',
                        'name' => 'name'
                    ],
                    'desc' => $this->l('If this option is active Automater will send message with transaction status to customer.')
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            )
        );

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' =>
                array(
                    'desc' => $this->l('Save'),
                    'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                        '&token=' . Tools::getAdminTokenLite('AdminModules'),
                ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // Load current value
        $helper->fields_value['VCAUTOMATER_KEY'] = Configuration::get('VCAUTOMATER_KEY');
        $helper->fields_value['VCAUTOMATER_SECRET'] = Configuration::get('VCAUTOMATER_SECRET');
        $helper->fields_value['VCAUTOMATER_URL'] = Configuration::get('VCAUTOMATER_URL');
        $helper->fields_value['VCAUTOMATER_ACTIVE'] = Configuration::get('VCAUTOMATER_ACTIVE');
        $helper->fields_value['VCAUTOMATER_TRANSACTION_STATUS'] = Configuration::get('VCAUTOMATER_TRANSACTION_STATUS');

        return $helper->generateForm($fields_form);
    }

    public function isApiActive()
    {
        return (bool)$this->apiActive;
    }
}
