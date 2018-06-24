<?php

namespace Andchir\OmnipayBundle\Controller;

use Andchir\OmnipayBundle\Service\OmnipayService;
use AppBundle\Controller\Admin\OrderController;
use AppBundle\Document\Payment;
use AppBundle\Document\Setting;
use AppBundle\Document\User;
use AppBundle\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use AppBundle\Document\Order;

class DefaultController extends Controller
{
    /**
     * @Route("/omnipay_start/{id}", name="omnipay_start")
     * @param Request $request
     * @param Order $order
     * @return Response|NotFoundHttpException|AccessDeniedException
     */
    public function indexAction(Request $request, Order $order)
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var \Doctrine\ODM\MongoDB\DocumentManager $dm */
        $dm = $this->get('doctrine_mongodb')->getManager();
        if ($order->getUserId() !== $user->getId() || $order->getIsPaid()) {
            throw $this->createAccessDeniedException();
        }
        $gatewayName = $order->getPaymentValue();

        /** @var OmnipayService $omnipay */
        $omnipay = $this->get('omnipay');
        if (!$gatewayName || !$omnipay->create($gatewayName)) {
            $omnipay->logInfo("Payment gateway ({$gatewayName}) not found. Order ID: {$order->getId()}", 'start');
            throw $this->createNotFoundException('Payment gateway not found.');
        };

        $currency = 'RUB';
        $paymentDescription = '';

        // Create payment
        $payment = new Payment();
        $payment
            ->setUserId($user->getId())
            ->setEmail($user->getEmail())
            ->setOrderId($order->getId())
            ->setCurrency($currency)
            ->setAmount($order->getPrice())
            ->setStatus($payment::STATUS_CREATED);

        $dm->persist($payment);
        $dm->flush();

        // Create Omnipay purchase
        $siteUrl = $request->getSchemeAndHttpHost() . $request->getBaseUrl();
        $omnipay->setConfigOptions([
            'returnUrl' => sprintf('%s/omnipay_return', $siteUrl),
            'cancelUrl' => sprintf('%s/omnipay_cancel', $siteUrl),
            'notifyUrl' => sprintf('%s/omnipay_notify', $siteUrl)
        ]);

        $purchase = $omnipay->createPurchase(
            $payment->getId(),
            $paymentDescription,
            [
                'amount' => OmnipayService::toDecimal($order->getPrice()),
                'currency' => $currency
            ]
        );
        $response = $purchase->send();

        // Save data in session
        $paymentData = [
            'transactionId' => $payment->getId(),
            'email' => $user->getEmail(),
            'userId' => $user->getId(),
            'amount' => $purchase->getAmount(),
            'currency' => $purchase->getCurrency(),
            'gatewayName' => $gatewayName
        ];
        $request->getSession()->set('paymentData', $paymentData);

        $omnipay->logInfo(json_encode($paymentData) . " Order ID: {$order->getId()}", 'start');

        // Process response
        if ($response->isSuccessful()) {

            // Payment was successful
            print_r($response);

        } elseif ($response->isRedirect()) {
            $response->redirect();
        } else {
            // Payment failed
            echo $response->getMessage();
        }

        return new Response('');
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function notifyAction(Request $request)
    {
        $paymentData = $request->getSession()->get('paymentData');
        $paymentId = !empty($paymentData['transactionId'])
            ? $paymentData['transactionId']
            : 0;
        $paymentUserId = !empty($paymentData['userId'])
            ? $paymentData['userId']
            : 0;
        $gatewayName = !empty($paymentData['gatewayName'])
            ? $paymentData['gatewayName']
            : 0;

        /** @var OmnipayService $omnipay */
        $omnipay = $this->get('omnipay');
        if (!$gatewayName || !$omnipay->create($gatewayName)) {
            $omnipay->logInfo("Payment gateway ({$gatewayName}) not found.", 'return');
            throw $this->createNotFoundException('Payment gateway not found.');
        };

        $orderData = array(
            'username' => $omnipay->getConfigOption('username'),
            'password' => $omnipay->getConfigOption('password'),
            'signature' => $omnipay->getConfigOption('signature'),
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency']
        );

        $omnipay->logInfo(json_encode($orderData), 'notify');

        /** @var Payment $payment */
        $payment = $this->getPayment($paymentId, $paymentUserId);
        if (!$payment || !$this->getOrder($payment)) {
            $omnipay->logInfo(
                "Order for payment ID {$paymentId} not found. ". json_encode($paymentData),
                'notify'
            );
            return new Response('');
        }

        $response = $omnipay->getGateway()->completePurchase($orderData)->send();
        $responseData = $response->getData();

        if ($response->isRedirect()) {
            $response->redirect();
        }
        else if (!$response->isSuccessful()){
            $omnipay->logInfo('PAYMENT FAIL. '. json_encode($responseData), 'notify');
            $this->paymentUpdateStatus($paymentId, Payment::STATUS_ERROR);
        }

        return new Response('');
    }

    /**
     * @param Request $request
     * @return RedirectResponse|Response
     */
    public function returnAction(Request $request)
    {
        /** @var User $user */
        $user = $this->getUser();
        $siteUrl = $request->getSchemeAndHttpHost() . $request->getBaseUrl();
        $paymentData = $request->getSession()->get('paymentData');
        $paymentId = !empty($paymentData['transactionId'])
            ? $paymentData['transactionId']
            : 0;

        $gatewayName = !empty($paymentData['gatewayName'])
            ? $paymentData['gatewayName']
            : 0;

        /** @var OmnipayService $omnipay */
        $omnipay = $this->get('omnipay');
        if (!$gatewayName || !$omnipay->create($gatewayName)) {
            $omnipay->logInfo("Payment gateway ({$gatewayName}) not found.", 'return');
            throw $this->createNotFoundException('Payment gateway not found.');
        };

        $orderData = array(
            'username' => $omnipay->getConfigOption('username'),
            'password' => $omnipay->getConfigOption('password'),
            'signature' => $omnipay->getConfigOption('signature'),
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency']
        );

        /** @var Payment $payment */
        $payment = $this->getPayment($paymentId, $user->getId());
        if (!$payment || !$this->getOrder($payment)) {
            $omnipay->logInfo(
                "Order for payment ID {$paymentId} not found. ". json_encode($paymentData),
                'return'
            );
            return $this->redirect($siteUrl . $omnipay->getConfigUrl('fail'));
        }

        $response = $omnipay->getGateway()->completePurchase($orderData)->send();
        $responseData = $response->getData();

        if ($response->isSuccessful()){

            $this->paymentUpdateStatus($paymentId, Payment::STATUS_SUCCESS);
            $this->setOrderPaid($payment);

            return $this->redirect($siteUrl . $omnipay->getConfigUrl('success'));

        } elseif ($response->isRedirect()) {
            $omnipay->logInfo('PAYMENT REDIRECT. '. json_encode($responseData), 'return');
            $response->redirect();
        } else {
            $omnipay->logInfo('PAYMENT FAIL. '. json_encode($responseData), 'return');
            $this->paymentUpdateStatus($paymentId, Payment::STATUS_ERROR);
            return $this->redirect($siteUrl . $omnipay->getConfigUrl('fail'));
        }

        return new Response('');
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function cancelAction(Request $request)
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var OmnipayService $omnipay */
        $omnipay = $this->get('omnipay');
        $paymentData = $request->getSession()->get('paymentData');
        $paymentId = !empty($paymentData['transactionId'])
            ? $paymentData['transactionId']
            : 0;

        /** @var Payment $payment */
        $payment = $this->getRepository()->findOneBy([
            'id' => $paymentId,
            'userId' => $user->getId(),
            'status' => Payment::STATUS_CREATED
        ]);

        if (!$payment) {
            $omnipay->logInfo("Payment ID ({$paymentId}) not found.", 'cancel');
            throw $this->createNotFoundException('Payment not found.');
        }

        $omnipay->logInfo("Payment ({$paymentId}) CANCELED.", 'cancel');

        if ($paymentId) {
            $this->paymentUpdateStatus($paymentId, Payment::STATUS_CANCELED);
        }

        $siteUrl = $request->getSchemeAndHttpHost() . $request->getBaseUrl();
        return $this->redirect($siteUrl . $omnipay->getConfigUrl('fail'));
    }

    /**
     * Update order status in Shopkeeper app
     * Update order status
     * @param Payment $payment
     * @return bool
     */
    public function setOrderPaid(Payment $payment)
    {
        $paymentStatusAfterNumber = (int) $this->getParameter('payment_status_after_number');
        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('app.settings');
        /** @var Setting $statusSetting */
        $statusSetting = $settingsService->getOrderStatusByNumber($paymentStatusAfterNumber);
        if (!$statusSetting) {
            return false;
        }
        $orderController = new OrderController();
        $orderController->setContainer($this->container);
        return $orderController->updateItemProperty(
            $payment->getOrderId(),
            'status',
            $statusSetting->getName()
        );
    }

    /**
     * Get payment object
     * @param $paymentId
     * @param $userId
     * @param null $statusName
     * @return object
     */
    public function getPayment($paymentId, $userId, $statusName = null)
    {
        if (!$statusName) {
            $statusName = Payment::STATUS_CREATED;
        }
        return $this->getRepository()->findOneBy([
            'id' => $paymentId,
            'userId' => $userId,
            'status' => $statusName
        ]);
    }

    /**
     * @param Payment $payment
     * @return object
     */
    public function getOrder(Payment $payment)
    {
        return $this->getOrderRepository()->findOneBy([
            'id' => $payment->getOrderId(),
            'userId' => $payment->getUserId()
        ]);
    }

    /**
     * @param $paymentId
     * @param $statusName
     */
    public function paymentUpdateStatus($paymentId, $statusName)
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var \Doctrine\ODM\MongoDB\DocumentManager $dm */
        $dm = $this->get('doctrine_mongodb')->getManager();

        /** @var Payment $payment */
        $payment = $this->getPayment($paymentId, $user->getId());
        if (!$payment) {
            return;
        }
        $payment->setStatus($statusName);
        $dm->flush();
    }

    /**
     * @return \AppBundle\Repository\OrderRepository
     */
    public function getOrderRepository()
    {
        return $this->get('doctrine_mongodb')
            ->getManager()
            ->getRepository(Order::class);
    }

    /**
     * @return \AppBundle\Repository\PaymentRepository
     */
    public function getRepository()
    {
        return $this->get('doctrine_mongodb')
            ->getManager()
            ->getRepository(Payment::class);
    }
}
