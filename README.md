# omnipay-bundle

Install:
~~~
composer require andchir/omnipay-bundle
~~~

Configuration:
~~~
omnipay:
    success_url: '/profile/history_orders'
    fail_url: '/'
    return_url: '/omnipay_return'
    notify_url: '/omnipay_notify'
    cancel_url: '/omnipay_cancel'
    data_keys:
        paymentId: ['orderNumber']
        customerEmail: ['customerNumber']
    gateways:
        PayPal_Express:
            parameters:
                username: xxxxx_api1.gmail.com
                password: xxxxxxxxxxxxxxxx
                signature: xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
            purchase:
                amount: AMOUNT
                currency: RUB
                testMode: true
                returnUrl: RETURN_URL
                cancelUrl: CANCEL_URL
            complete: []
        YandexMoney:
            parameters:
                shopid: xxxxxx
                scid: xxxxxx
                password: xxxxxxxxxxxxxxxxx
                customerNumber: CUSTOMER_EMAIL
                amount: AMOUNT
                orderId: PAYMENT_ID
                method: ~
                returnUrl: RETURN_URL
                cancelUrl: CANCEL_URL
            purchase:
                amount: AMOUNT
                currency: RUB
                testMode: true
            complete:
                shopid: ~
                scid: ~
                action: ~
                md5: ~
                orderNumber: PAYMENT_ID
                orderSumAmount: AMOUNT
                orderSumCurrencyPaycash: ~
                orderSumBankPaycash: ~
                shopid: ~
                invoiceId: ~
                customerNumber: CUSTOMER_EMAIL
                password: ~
        Sberbank:
            purchase:
                username: xxxxxx
                password: xxxxxx
                testMode: true
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