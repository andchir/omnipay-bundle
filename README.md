# omnipay-bundle

Install:
~~~
composer require andchir/omnipay-bundle
~~~

Configuration:
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

Example of use:
~~~
/** @var OmnipayService $omnipayService */
$omnipayService = $this->get('omnipay');

$gatewayName = 'PayPal_Express';
$omnipayService->create($gatewayName);

// Create payment
$payment = new Payment();
$payment
    ->setUserId(0)
    ->setEmail('aaa@bbb.cc')
    ->setOrderId(1)
    ->setCurrency('RUB')
    ->setAmount(500)
    ->setDescription('Order #12')
    ->setStatus(Payment::STATUS_CREATED)
    ->setOptions(['gatewayName' => $gatewayName]);

$dm->persist($payment);
$dm->flush();

$omnipayService->initialize($payment);

$omnipayService->sendPurchase($payment);
~~~