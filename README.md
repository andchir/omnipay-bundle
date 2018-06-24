# omnipay-bundle

Configuration
-------------

~~~
omnipay:
    success_url: "/profile/history_orders"
    fail_url: "/"
    gateways:
        PayPal_Express:
            username: xxxxx_api1.gmail.com
            password: xxxxxxxxxx
            signature: xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
            testMode: false
~~~

Example:
~~~
/** @var OmnipayService $omnipay */
$omnipay = $this->get('omnipay');

$omnipay->create('PayPal_Express');

$purchase = $omnipay->createPurchase(
    $paymentId,// Your payment ID
    $paymentDescription,// Your payment description
    [
        'amount' => OmnipayService::toDecimal($price),// Order price
        'currency' => $currency// Your currency
    ]
);
~~~