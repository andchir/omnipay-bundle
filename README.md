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
        paymentId: ['orderNumber', 'Shp_TransactionId']
        customerEmail: ['customerNumber', 'Email', 'Shp_Client']
    gateways:
        PayPal_Express:
            parameters:
                username: xxxxxxxxxxxxx
                password: xxxxxxxxxxxxxxxxxx
                signature: xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
            purchase:
                username: ~
                password: ~
                signature: ~
                amount: AMOUNT
                currency: CURRENCY
                testMode: true
                returnUrl: NOTIFY_URL
                cancelUrl: CANCEL_URL
            complete:
                username: ~
                password: ~
                signature: ~
                amount: AMOUNT
                currency: CURRENCY
                testMode: true
                returnUrl: NOTIFY_URL
                cancelUrl: CANCEL_URL
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
                receipt: ~
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
                invoiceId: ~
                customerNumber: CUSTOMER_EMAIL
                password: ~
        Sberbank:
            prefersAuthorize: true
            parameters:
                username: xxxxxxxxx
                password: xxxxxxxxx
                returnUrl: RETURN_URL
                cancelUrl: CANCEL_URL
            purchase:
                username: ~
                password: ~
                orderNumber: PAYMENT_ID
                amount: AMOUNT
                currency: RUB
                testMode: true
            complete:
                username: ~
                password: ~
                orderId: ~
        RoboKassa:
            parameters:
                purse: xxxxxx
                secretKey: xxxxxx
                secretKey2: xxxxxx
            purchase:
                purse: ~
                secretKey: ~
                amount: AMOUNT
                currency: CURRENCY
                currencyLabel: ~
                description: ~
                receipt: ~
                transactionId: PAYMENT_ID
                client: CUSTOMER_EMAIL
                testMode: true
            complete:
                purse: ~
                secretKey: ~
                secretKey2: ~

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

Developed for [https://shopker.org/](https://shopker.org/)
