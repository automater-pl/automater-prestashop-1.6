<?php
    namespace Automater;

    use Automater\Exception\AutomaterException;
    use Automater\Exception\NotFoundException;
    use Automater\Exception\TimeoutException;

    class Automater {
        protected $settings = [
            'endpoint' => 'automater.pl',
            'version' => 'api_v2',
            'protocol' => 'https',
            'timeout' => 10
        ];

        protected $_apiKey = null;
        protected $_apiSecret = null;

        protected $_ch = null;

        public function __construct( $apiKey, $apiSecret ) {
            $this->_apiKey = $apiKey;
            $this->_apiSecret = $apiSecret;
        }

        public function createTransaction( $listingIds, $email, $quantity = 1, $phone = null, $language = 'pl', $status = 1, $custom = null ) {
            if( is_numeric($listingIds) ) $listingIds = [$listingIds];
            if( is_array($quantity) ) $quantity = implode(",", $quantity);

            $packet = [
                'listing_ids' => implode(",", $listingIds),
                'email' => $email,
                'quantity' => $quantity,
                'phone' => $phone,
                'language' => $language,
                'status' => $status,
                'custom' => $custom
            ];

            return $this->_exec('/buyers', null, 'POST', $packet);
        }

        public function createPayment( $type, $ids, $paymentId, $paymentAmount, $paymentCurrency, $paymentDescription = null, $custom = null ) {
            if( $type == 'transaction' ) {
                if( is_numeric($ids) ) $ids = [$ids];
                $ids = implode(",", $ids);
            }

            $packet = [
                'type' => $type,
                'cart_id' => ($type == 'cart' ? $ids : null),
                'transaction_ids' => ($type == 'transaction' ? $ids : null),
                'payment_id' => $paymentId,
                'payment_description' => $paymentDescription,
                'payment_amount' => $paymentAmount,
                'payment_currency' => $paymentCurrency,
                'custom' => $custom
            ];

            return $this->_exec('/payment', null, 'POST', $packet);
        }

        public function getProducts( $page = 1, $limit = 50 ) {
            return $this->_exec('/products/page:'.$page.'/limit:'.$limit);
        }

        public function getDatabases( $page = 1, $limit = 50 ) {
            return $this->_exec('/databases/page:'.$page.'/limit:'.$limit);
        }

        public function addCodes( $databaseId, $codes ) {
            if(!is_array($codes)) $codes = [$codes];

            $packet = [
                'codes' => json_encode($codes)
            ];

            return $this->_exec('/codes/'.$databaseId, null, 'POST', $packet);
        }

        public function getAvailableProductsCount( $listingId ) {
            $result = $this->_exec('/products/'.$listingId.'/counter');
            return $result['data']['counter'];
        }

        public function getAvailableProductsImages( $listingId, $language = 'pl' ) {
            return $this->_exec('/products/'.$listingId.'/counter_img', ['language' => $language]);
        }

        protected function _exec( $query, $params = [], $method = "GET", $fields = null ) {
            if (!function_exists('curl_init')){
                die('Install cURL extension');
            }

            if(!isset($params)) $params = [];
            $params = array_merge($params, ['key' => $this->_apiKey]);

            if(!isset($fields)) {
                $this->_signRequest($params);
            }

            $url = $this->settings["protocol"] . "://" . $this->settings['endpoint'] . '/' . $this->settings['version'] . $query . "?" . (is_array($params)?http_build_query($params):'');

            $this->_ch = curl_init();

            curl_setopt($this->_ch, CURLOPT_URL, $url);
            curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->_ch, CURLOPT_TIMEOUT, $this->settings['timeout']);
            curl_setopt($this->_ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($this->_ch, CURLOPT_CUSTOMREQUEST, $method);

            if( isset($fields) ) {
                $this->_signRequest($fields);
                curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $fields);
            }

            if( !$output = curl_exec($this->_ch) ) {
                switch( curl_errno($this->_ch) ) {
                    case 28:
                        throw new TimeoutException();
                        break;
                }
            }

            curl_close($this->_ch);

            $result = json_decode($output, true);

            if(!isset($result)) throw new AutomaterException("Undefined result", 500);

            if($result['code'] != 200) {
                if($result['code'] == 404) throw new NotFoundException($result['message'], $result['code']);
                else throw new AutomaterException($result['message'], $result['code']);
            }

            return $result;
        }

        protected function _signRequest( &$request ) {
            if( !is_array($request) ) return false;

            if( isset($request['sign']) ) unset($request['sign']);
            ksort( $request );
            foreach( $request as $key => $row ) {
                if( empty($row) ) {
                    unset( $request[$key] );
                }
            }
            $sign = md5(implode('|', $request) . '|' . $this->_apiSecret);
            $request['sign'] = $sign;

            return true;
        }
    }