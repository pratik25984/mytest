<?php

namespace Richpanel\Analytics\Helper;

class OrderSerializer extends \Magento\Framework\App\Helper\AbstractHelper
{
	protected $coupon;
    protected $saleRule;

    private $logger;
    private $shipmentRepository;
    public function __construct(
        \Magento\Sales\Api\ShipmentRepositoryInterface $shipmentRepository,
        \Magento\Catalog\Model\ProductRepository $productRepository,
	    \Richpanel\Analytics\Helper\ImagePathResolver $imagePathResolver,
	    \Psr\Log\LoggerInterface $logger,
	    \Magento\SalesRule\Model\Coupon $coupon,
        \Magento\SalesRule\Model\Rule $saleRule
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->productRepository = $productRepository;
	    $this->imagePathResolver = $imagePathResolver;
	    $this->coupon = $coupon;
        $this->saleRule = $saleRule;
    	$this->logger = $logger;
    }

    /**
     * Build individual order data
     *
     * @param  \Magento\Sales\Model\Order $order
     * @return array
     */
    public function buildOrderForSubmission($order, $shipment = NULL)
    {
        $identityData = $this->orderIdentityData($order);

        $call = array(
            'event'  => 'order',
            'properties'      => $this->prepareOrderDetails($order, FALSE, $shipment),
            'userProperties'    => $identityData,
            'time' => array('sentAt' => round(microtime(true) * 1000))
        );

        // check if order has customer IP in it
        $ip = $order->getRemoteIp();
        if ($ip) {
            $ipContext = array('networkIP' => $ip);
            $call['context'] = array('ip' => $ipContext);
        }
        // initialize time
        if ($order->getCreatedAt()) {
            $dateObj = new \DateTime($order->getCreatedAt());
            $call['time'] = array(
                'originalTimestamp' => $dateObj->getTimestamp() * 1000, 
                'sentAt' => round(microtime(true) * 1000)
            );
        }

        ksort($call);
        return $call;
    }

    public function buildDeleteEvent($order)
    {
        $identityData = $this->orderIdentityData($order);

        $call = array(
            'event'  => 'delete_fulfillment',
            'properties'      => $this->prepareFulfillmentDeleteEvent($order),
            'userProperties'    => $identityData,
            'time' => array('sentAt' => round(microtime(true) * 1000))
        );
    }

    public function findItemInArray ($arrayOfObject, $key, $value) 
    {
        foreach($arrayOfObject as $item) {
            if (array_key_exists($key, $item)) {
                if ($item[$key] === $value) {
                    return $item;
                }
            }
        }
    }

    public function prepareFulfillmentDeleteEvent($order)
    {
        return [
            'orderId'          => $order->getIncrementId(),
            'fulfillmentId'    => $this->prepareOrderDetails($order, TRUE)
        ];
    }

    /**
     * Get order details and sort them for richpanel
     *
     * @param  Mage_Sales_Model_Order $order
     * @return array
     */
    public function prepareOrderDetails($order, $isDelete = FALSE, $shipment = NULL)
    {
        $data = [
            'order_id'          => $order->getIncrementId(),
            'orderDBKey'        => $order->getId(),
            'order_status'      => $order->getFrontendStatusLabel(),
            'amount'            => (float)$order->getBaseGrandTotal(),
            'shipping_amount'   => (float)$order->getBaseShippingAmount(),
            'tax_amount'        => $order->getBaseTaxAmount(),
            'items'             => [],
            'shipping_method'   => $order->getShippingDescription(),
            'payment_method'    => $order->getPayment()->getMethodInstance()->getTitle(),
        ];

        $this->assignBillingInfo($data, $order);

        if ($order->getCouponCode()) {
            $data['coupons'] = array($order->getCouponCode());
            $totalDiscount = 0;	
            foreach (array($order->getCouponCode()) as $coupon) {	
                $data['coupons'] = array($order->getCouponCode());	
                $discountAmount = $this->getDiscount($coupon);	
                if ($discountAmount) {	
                    $totalDiscount = $totalDiscount + $discountAmount;	
                }	
            }	
            $data['discountAmount'] = $totalDiscount;
        }

        foreach ($order->getAllVisibleItems() as $item) {
            if ($item->getParentItem() == null) {
                $data['items'][] = $this->getProductDetails($item);
            }
        }

        $trackingDetails = $this->getTrackingNumberByOrderData($order);

        if (!empty($trackingDetails)) {
            $data = array_merge($data, $trackingDetails[0]);
        }

        $shipmentDetails = $this->getShipmentsByOrder($order, $shipment);

        if (!empty($shipmentDetails)) {
            $data['fulfillment'] = $shipmentDetails;
	    }

	    // $data['custom_data'] = $order->getData();

	    $allData = $order->getData();

        /** Handle Gift Cards */
        if (!empty($allData) && array_key_exists("gift_cards", $allData) && !empty($allData["gift_cards"])) {
                $gift_cards = json_decode($allData["gift_cards"], true);

                    $data['giftCards'] = [];
                    foreach($gift_cards as $gift_card) {
                    $data['giftCards'][] = array(
                        "name" => $gift_card["c"],
                        "price" => $gift_card["a"]
                    );
                }
        }



        /** Custom for American Meadows */
        $fulfillmentIdsToDelete = [];
	    try {

            if (!empty($allData)) {
                if (array_key_exists("order_edit_flag",$allData)) {
                    $data['order_edit_flag'] = $allData["order_edit_flag"];
                }
                if (array_key_exists("acumatica_order_id",$allData)) {
                    $data['order_name'] = $allData["acumatica_order_id"];
                }
            }

            if (!empty($allData) && array_key_exists("ssaas_response",$allData) && !empty($allData["ssaas_response"])) {
                $ssaas_response = json_decode($allData["ssaas_response"], true);
                
                /*getting fulfilled items*/
		        $items_fulfilled = [];
		        if (!empty($data['fulfillment'])) {
                    $items_fulfilled = [];
                    foreach($data['fulfillment'] as $item_array) {
                        if (array_key_exists("items", $item_array)) {
                            foreach($item_array['items'] as $item) {
                                $sku = null;
                                if (array_key_exists('option_sku', $item)) {
                                    $sku = $item['option_sku'];
                                } else if (array_key_exists('sku', $item)) {
                                    $sku = $item['sku'];
                                }
    
                                if ($sku) {
                                    if (array_key_exists($sku, $items_fulfilled)) {
                                        $items_fulfilled[$sku] = $items_fulfilled[$sku] + $item['quantity'];
                                    } else {
                                        $items_fulfilled[$sku] = $item['quantity'];
                                    }
                                }
                            }
                        }
                    }
                    $data['deleteFulfillmentAndCreate'] = TRUE;
                } else {
                    $data['fulfillment'] = [];
                }

                $fulfilmentTemp = array();
                $tempIndex = count($data['fulfillment']);

                foreach($ssaas_response as $fitem) {
                    $sku = $fitem['sku'];
                    $qty_saas = $fitem['quantity'];
                    $add = FALSE;

                    if (!array_key_exists($sku, $items_fulfilled)) {
                        $add = TRUE;
                    } else {
                        $qty_shipped = $items_fulfilled[$sku];
                        if ($qty_shipped < $qty_saas) {
                            $qty_saas = $qty_saas - $qty_shipped;
                            $add = TRUE;
                        }
                    }

                    if ($add == TRUE) {
                        $itemObject = null;
                        $itemObject = $this->findItemInArray($data['items'], 'sku', $sku);
                        if (empty($itemObject)) {
                            $itemObject = $this->findItemInArray($data['items'], 'option_sku', $sku);
                        }

                        if (empty($itemObject)) {
                            $itemObject = [];
                        } else {
                            $itemObject['quantity'] = $qty_saas;
                        }

                        $fid = $data['orderDBKey'] . $fitem['shipmentIdentifier'];

                        if (!array_key_exists($fid, $fulfilmentTemp)) {
                            $fulfilmentTemp[$fid] = $tempIndex;
                            $tempIndex = $tempIndex + 1;

                            $fObject = array(
                                "id" => $fid,
                                "items" => [$itemObject],
                                "tracking" => [array(
                                    "shippingDate" => strtotime($fitem['shipDate'] . ' +12 hour') * 1000
                                )],
                                'temp' => $fulfilmentTemp
                            );
                            $data['fulfillment'][] = $fObject;
                        } else {
                            $index = $fulfilmentTemp[$fid];
                            $data['fulfillment'][$index]["items"][] = $itemObject;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
		    $this->logger->critical('Error in American Meadows custom implementation', ['exception' => $e]);
        }
        
        if ($isDelete === TRUE) {
            return $fulfillmentIdsToDelete;
        } else {
            return $data;
        }
    }

    public function getDiscount($couponCode)	
    {	
        $ruleId = $this->coupon->loadByCode($couponCode)->getRuleId();	
        $rule = $this->saleRule->load($ruleId);	
        return $rule->getDiscountAmount();	
    }

    public function getTrackingNumberByOrderData($order) {
        $trackNumbers = [];
        $tracksCollection = $order->getTracksCollection();
        if (!empty($tracksCollection)) {
            foreach ($tracksCollection->getItems() as $track) {
                $data = $track->getData();

                $result = array(
                    'trackingNumber' => $track->getTrackNumber(),
                );

                $company = $data["title"];
                if (empty($company)) {
                    $company = $data["carrier_code"];
                }

                $result['trackingCompany'] = $company;

                if (!empty($data["updated_at"])) {
                    $date = strtotime($data["updated_at"]) * 1000;
                    $result['shippingDate'] = $date;
                }

                $carrier_code = $track->getCarrierCode();
                if ($company == 'custom' || empty($carrier_code)) {
                    $trackingNumber = $track->getTrackNumber();
                    $carrier_code = $this->getCarrier($trackingNumber);
                }

                $url = '';
                
                if ($carrier_code == 'usps') {
                    $url = 'https://tools.usps.com/go/TrackConfirmAction.action?tLabels=' . $track->getTrackNumber();
                } else if ($carrier_code == 'fedex') {
                    $url = 'https://www.fedex.com/fedextrack/?tracknumbers=' . $track->getTrackNumber();
                } else if ($carrier_code == 'ups') {
                    $url = 'http://wwwapps.ups.com/WebTracking/processInputRequest?TypeOfInquiryNumber=T&InquiryNumber1=' . $track->getTrackNumber();
                }

                if (!empty($url)) {
                    $result['trackingUrl'] = $url;
                }

                $trackNumbers[] = $result;
            }
        }
        return $trackNumbers;
    }

    public function getCarrier($trackingNumber){

        $matchUPS1      = '/\b(1Z ?[0-9A-Z]{3} ?[0-9A-Z]{3} ?[0-9A-Z]{2} ?[0-9A-Z]{4} ?[0-9A-Z]{3} ?[0-9A-Z]|[\dT]\d\d\d ?\d\d\d\d ?\d\d\d)\b/';
        $matchUPS2      = '/^[kKJj]{1}[0-9]{10}$/';
    
        $matchUSPS0     = '/(\b\d{30}\b)|(\b91\d+\b)|(\b\d{20}\b)/';
        $matchUSPS1     = '/(\b\d{30}\b)|(\b91\d+\b)|(\b\d{20}\b)|(\b\d{26}\b)| ^E\D{1}\d{9}\D{2}$|^9\d{15,21}$| ^91[0-9]+$| ^[A-Za-z]{2}[0-9]+US$/i';
        $matchUSPS2     = '/^E\D{1}\d{9}\D{2}$|^9\d{15,21}$/';
        $matchUSPS3     = '/^91[0-9]+$/';
        $matchUSPS4     = '/^[A-Za-z]{2}[0-9]+US$/';
        $matchUSPS5     = '/(\b\d{30}\b)|(\b91\d+\b)|(\b\d{20}\b)|(\b\d{26}\b)| ^E\D{1}\d{9}\D{2}$|^9\d{15,21}$| ^91[0-9]+$| ^[A-Za-z]{2}[0-9]+US$/i';
    
        $matchFedex1    = '/(\b96\d{20}\b)|(\b\d{15}\b)|(\b\d{12}\b)/';
        $matchFedex2    = '/\b((98\d\d\d\d\d?\d\d\d\d|98\d\d) ?\d\d\d\d ?\d\d\d\d( ?\d\d\d)?)\b/';
        $matchFedex3    = '/^[0-9]{15}$/';
    
    
        if(preg_match($matchUPS1, $trackingNumber) || 
            preg_match($matchUPS2, $trackingNumber))
         {
            $carrier = 'ups';
            return $carrier;
        } else if(preg_match($matchUSPS0, $trackingNumber) || 
                  preg_match($matchUSPS1, $trackingNumber) ||
                  preg_match($matchUSPS2, $trackingNumber) ||
                  preg_match($matchUSPS3, $trackingNumber) ||
                  preg_match($matchUSPS4, $trackingNumber) ||
                  preg_match($matchUSPS5, $trackingNumber)) {
    
            $carrier = 'usps';
            return $carrier;
        } else if (preg_match($matchFedex1, $trackingNumber) || 
                   preg_match($matchFedex2, $trackingNumber) || 
                   preg_match($matchFedex3, $trackingNumber)) {
    
            $carrier = 'fedex';
            return $carrier;
        } else if (0){
            $carrier = 'dhl';
            return $carrier;
        } 
    
        return;
    
    }

    public function getShipmentsByOrder($order, $shipment_ = NULL) {
        $shipments = [];
        $shipmentCollection = $order->getShipmentsCollection();
        if (!empty($shipmentCollection)) {
            foreach ($shipmentCollection->getItems() as $shipment) {
                if (!empty($shipment_)) {
                    $increment_id = $shipment_->getIncrementId();
                    if ($increment_id == $shipment->getIncrementId()) {
                        $shipment = $shipment_;
                    }
                }
                $data = array();

                $data['id'] = $shipment->getIncrementId();
                $data['status'] = $shipment->getShipmentStatus();
                $data['quantity'] = $shipment->getTotalQty();
                $data['weight'] = $shipment->getTotalWeight();
                
                $createdAt = $shipment->getCreatedAt();
                if (!empty($createdAt)) {
                    $date = strtotime($createdAt) * 1000;
                    $data['createdAt'] = $date;
                }

                $updatedAt = $shipment->getUpdatedAt();
                if (!empty($updatedAt)) {
                    $date = strtotime($updatedAt) * 1000;
                    $data['updatedAt'] = $date;
                }

                // $data['comments'] = $shipment->getComments();
                $data['tracking'] = $this->getTrackingNumberByOrderData($shipment);
                $this->assignBillingInfo($data, $order);
                $count = 0;
                $itemCollection = $shipment->getItemsCollection();
                foreach ($itemCollection as $key => $item) {
                    if ($item->getQty() > 0) {
                        $data['items'][] = $this->getProductDetails($item->getOrderItem());
                        $data['items'][$count]['quantity'] = $item->getQty();
                        $count++;
                    }
                }

                $shipments[] = $data;
            }
        }
        return $shipments;
    }

    private function getProductDetails($quoteItem)
    {
        $dataItem = array(
            'contains' => array('lineItemId' => $item->getItemId()),
            'id'        => (int)$quoteItem->getProductId(),
            'price'     => (float)$quoteItem->getBasePrice(),
            'name'      => $quoteItem->getName(),
            'quantity'  => (int)$quoteItem->getQtyOrdered()
        );

        if ($quoteItem->getProductType() == 'configurable') {
            $options = (array)$quoteItem->getProductOptions();
            $dataItem['option_id'] = $options['simple_sku'];
            // for legacy reasons - we have been passing the SKU as ID for the child products
            $dataItem['option_sku'] = $options['simple_sku'];
            $dataItem['option_name'] = $options['simple_name'];
            $dataItem['option_price'] = (float)$quoteItem->getBasePrice();
        }

        // try {
        //     $dataItem['custom_data'] = $quoteItem->getData();
        // } catch (\Throwable $th) {
        // }

        try {
            if ($quoteItem->getProductType() == 'configurable') {
                $parentId = $quoteItem->getProductId();
                $product = $this->productRepository->getById($parentId);
            } else {
                $product = $quoteItem->getProduct();
            }

            if ($product) {
                $imageBasePath = $this->imagePathResolver->getBaseImage($product);
                if(!empty($imageBasePath)) {
                    $dataItem['image_url'] = array($imageBasePath);
                }
                $dataItem['url'] = $product->getProductUrl();
                $dataItem['sku'] = $product->getSku();
                $dataItem['productPrice'] = $product->getPrice();  //Confirm with Mangesh

                if(count($product->getCategoryIds())) {
                    $categories = array();
                    $collection = $product->getCategoryCollection()
                        ->addAttributeToSelect('id')
                        ->addAttributeToSelect('name');
    
                    foreach ($collection as $category) {
                        $categories[] = array(
                            'id' => $category->getId(),
                            'name' => $category->getName()
                        );
                    }
                    $dataItem['categories'] = $categories;
                }
            }

        } catch (\Exception $e) {}

        return $dataItem;
    }

    /**
     * Get Order Customer identity data
     *
     * @param  \Magento\Sales\Model\Order $order
     * @return array
     */
    public function orderIdentityData($order)
    {
        return array(
            'uid'           => $order->getCustomerEmail(),
            'email'         => $order->getCustomerEmail(),
            'first_name'    => $order->getBillingAddress()->getFirstname(),
            'last_name'     => $order->getBillingAddress()->getLastname(),
            'name'          => $order->getBillingAddress()->getName()
        );
    }

    /**
     * Assign billing information
     *
     * @param  array $data
     * @param  \Magento\Sales\Model\Order $order
     * @return void
     */
    public function assignBillingInfo(&$data, $order)
    {
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();
        // Assign billing data to order data array

        $data['billing_phone'] = $billingAddress->getTelephone();
        $data['billing_country'] = $billingAddress->getCountryId();
        $data['billing_region'] = $billingAddress->getRegion();
        $data['billing_city'] = $billingAddress->getCity();
        $data['billing_postcode'] = $billingAddress->getPostcode();
        $data['billing_address'] = ''; // Populate below
        $data['billing_company'] = $billingAddress->getCompany();
        $street = $billingAddress->getStreet();
        $data['billing_address'] = is_array($street) ? implode(PHP_EOL, $street) : $street;

        if ($shippingAddress) {
            $data['shipping_phone'] = $shippingAddress->getTelephone();
            $data['shipping_country'] = $shippingAddress->getCountryId();
            $data['shipping_region'] = $shippingAddress->getRegion();
            $data['shipping_city'] = $shippingAddress->getCity();
            $data['shipping_postcode'] = $shippingAddress->getPostcode();
            $data['shipping_address'] = ''; // Populate below
            $data['shipping_company'] = $shippingAddress->getCompany();
            $street = $shippingAddress->getStreet();
            $data['shipping_address'] = is_array($street) ? implode(PHP_EOL, $street) : $street;
            $data['shipping_first_name'] = $shippingAddress->getFirstname();
            $data['shipping_last_name'] = $shippingAddress->getLastname();
            $data['shipping_name'] = $shippingAddress->getName();
        }

        $data['email']             = $order->getCustomerEmail();
        $data['first_name']        = $billingAddress->getFirstname();
        $data['last_name']         = $billingAddress->getLastname();
        $data['name']              = $billingAddress->getName();

    }

    /**
     * Build event array ready for encoding and encrypting. Built array is returned using ksort.
     *
     * @param  string  $ident
     * @param  string  $event
     * @param  array  $properties
     * @param  boolean|array $identityData
     * @param  boolean|int $time
     * @param  boolean|array $callParameters
     * @return array
     */
    private function buildEventArray($ident, $event, $properties, $identityData = false, $time = false, $extraParameters)
    {
        $call = array(
            'event'    => $event,
            'properties'        => $properties,
            'uid'           => $ident
        );
        if ($time) {
            $call['time'] = $time;
        }
        // check for special parameters to include in the API call
        if ($extraParameters) {
            $call = array_merge($call, $extraParameters);
        }
        // put identity data in call if available
        if ($identityData) {
            $call['identity'] = $identityData;
        }
        // Prepare keys in alphabetical order
        ksort($call);
        return $call;
    }

    // public function getCustomData($orderId) {
    //     $objectManager =   \Magento\Framework\App\ObjectManager::getInstance();
    //     $connection = $objectManager->get('Magento\Framework\App\ResourceConnection')->getConnection('\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION'); 
    //     $result1 = array();
    //     try {
    //         $result1 = $connection->fetchAll("SELECT ssaas_response FROM sales_order where entity_id = " . $orderId);
    //     } catch (\Throwable $th) {
    //     }

    //     $result2 = array();
    //     try {
    //         $result2 = $connection->fetchAll("SELECT entity_id, ssaas_response FROM sales_order where ssaas_response IS NOT NULL limit 2");
    //     } catch (\Throwable $th) {
    //     }

    //     return array(
    //         'sales_order' => $result1,
    //         'result2' => $result2
    //     );
    // }
}
