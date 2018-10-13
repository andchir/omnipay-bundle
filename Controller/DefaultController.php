<?php

namespace Andchir\OmnipayBundle\Controller;

use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\AbstractRequest;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        $output = '';

        /** @var User $user */
        $user = $this->getUser();
        $userId = $user ? $user->getId() : 0;
        /** @var \Doctrine\ODM\MongoDB\DocumentManager $dm */
        $dm = $this->get('doctrine_mongodb')->getManager();
        if ($order->getUserId() !== $userId || $order->getIsPaid()) {
            throw $this->createAccessDeniedException();
        }
        $gatewayName = $order->getPaymentValue();
        $customerEmail = $request->get('email');
        if (!$customerEmail && $user) {
            $customerEmail = $user->getEmail();
        }

        /** @var OmnipayService $omnipayService */
        $omnipayService = $this->get('omnipay');
        if (!$gatewayName || !$omnipayService->create($gatewayName)) {
            $omnipayService->logInfo("Payment gateway ({$gatewayName}) not found. Order ID: {$order->getId()}", 'start');
            throw $this->createNotFoundException('Payment gateway not found.');
        };

        $currency = 'RUB';
        $paymentDescription = 'Order #' . $order->getId();

        // Create payment
        $payment = new Payment();
        $payment
            ->setUserId($userId)
            ->setEmail($customerEmail)
            ->setOrderId($order->getId())
            ->setCurrency($currency)
            ->setAmount($order->getPrice())
            ->setDescription($paymentDescription)
            ->setStatus($payment::STATUS_CREATED);

        $dm->persist($payment);
        $dm->flush();

        // Create Omnipay purchase
        $siteUrl = $request->getSchemeAndHttpHost() . $request->getBaseUrl();
        $omnipayService->setConfigOptions([
            'returnUrl' => sprintf('%s/omnipay_return', $siteUrl),
            'cancelUrl' => sprintf('%s/omnipay_cancel', $siteUrl),
            'notifyUrl' => sprintf('%s/omnipay_notify', $siteUrl)
        ]);

        //if ($omnipayService->getGatewaySupportsAuthorize()) {
        if ($gatewayName === 'Sberbank') {

            /** @var AbstractRequest $authRequest */
            $authRequest = $omnipayService->getGateway()->authorize(
                    [
                        'orderNumber' => $payment->getId(),
                        'amount' => $payment->getAmount() * 100, // The amount of payment in kopecks (or cents)
                        'returnUrl' => $omnipayService->getConfigUrl('success'),
                        'description' => $paymentDescription
                    ]
                )
                ->setUserName(uniqid('', true))
                ->setPassword(uniqid('', true));

            /** @var AbstractResponse $response */
            $response = $authRequest->send();

            if (!$response->isSuccessful()) {
                $output = $response->getMessage();
            }

            if ($response->isRedirect()) {
                $response->redirect();
            }

        } else {

            $omnipayService->sendPurchase($payment);

        }

        return new Response($output);
    }

    /**
     * @Route("/omnipay_notify", name="omnipay_notify")
     * @param Request $request
     * @return Response|NotFoundHttpException|AccessDeniedException
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

        /** @var OmnipayService $omnipayService */
        $omnipayService = $this->get('omnipay');
        if (!$gatewayName || !$omnipayService->create($gatewayName)) {
            $omnipayService->logInfo("Payment gateway ({$gatewayName}) not found.", 'return');
            throw $this->createNotFoundException('Payment gateway not found.');
        };

        /** @var Payment $payment */
        $payment = $this->getPayment($paymentId, $paymentUserId);
        if (!$payment || !$this->getOrder($payment)) {
            $omnipayService->logInfo(
                "Order for payment ID {$paymentId} not found. ". json_encode($paymentData),
                'notify'
            );
            return new Response('');
        }

        $orderData = $omnipayService->getGatewayConfigParameters($payment);
        $orderData['amount'] =  $paymentData['amount'];
        $orderData['currency'] =  $paymentData['currency'];

        $omnipayService->logInfo(json_encode($orderData), 'notify');

        $response = $omnipayService->getGateway()->completePurchase($orderData)->send();
        $responseData = $response->getData();

        if ($response->isRedirect()) {
            $response->redirect();
        }
        else if (!$response->isSuccessful()){
            $omnipayService->logInfo('PAYMENT FAIL. '. json_encode($responseData), 'notify');
            $this->paymentUpdateStatus($paymentId, Payment::STATUS_ERROR);
        }

        return new Response('');
    }

    /**
     * @Route("/omnipay_return", name="omnipay_return")
     * @param Request $request
     * @return Response|NotFoundHttpException|AccessDeniedException
     */
    public function returnAction(Request $request)
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

        /** @var OmnipayService $omnipayService */
        $omnipayService = $this->get('omnipay');
        if (!$gatewayName || !$omnipayService->create($gatewayName)) {
            $omnipayService->logInfo("Payment gateway ({$gatewayName}) not found.", 'return');
            throw $this->createNotFoundException('Payment gateway not found.');
        };

        /** @var Payment $payment */
        $payment = $this->getPayment($paymentId, $paymentUserId);
        if (!$payment || !$this->getOrder($payment)) {
            $omnipayService->logInfo(
                "Order for payment ID {$paymentId} not found. ". json_encode($paymentData),
                'return'
            );
            return $this->redirect($omnipayService->getConfigUrl('fail'));
        }

        $orderData = $omnipayService->getGatewayConfigParameters($payment);
        $orderData['amount'] =  $paymentData['amount'];
        $orderData['currency'] =  $paymentData['currency'];

        $response = $omnipayService->getGateway()->completePurchase($orderData)->send();
        $responseData = $response->getData();

        if ($response->isSuccessful()){

            $this->paymentUpdateStatus($paymentId, Payment::STATUS_SUCCESS);
            $this->setOrderPaid($payment);

            return $this->redirect($omnipayService->getConfigUrl('success'));

        } elseif ($response->isRedirect()) {
            $omnipayService->logInfo('PAYMENT REDIRECT. '. json_encode($responseData), 'return');
            $response->redirect();
        } else {
            $omnipayService->logInfo('PAYMENT FAIL. '. json_encode($responseData), 'return');
            $this->paymentUpdateStatus($paymentId, Payment::STATUS_ERROR);
            return $this->redirect($omnipayService->getConfigUrl('fail'));
        }

        return new Response('');
    }

    /**
     * @Route("/omnipay_cancel", name="omnipay_cancel")
     * @param Request $request
     * @return Response|NotFoundHttpException|AccessDeniedException
     */
    public function cancelAction(Request $request)
    {
        /** @var OmnipayService $omnipayService */
        $omnipayService = $this->get('omnipay');
        $paymentData = $request->getSession()->get('paymentData');
        $paymentId = !empty($paymentData['transactionId'])
            ? $paymentData['transactionId']
            : 0;
        $paymentUserId = !empty($paymentData['userId'])
            ? $paymentData['userId']
            : 0;

        /** @var Payment $payment */
        $payment = $this->getRepository()->findOneBy([
            'id' => $paymentId,
            'userId' => $paymentUserId,
            'status' => Payment::STATUS_CREATED
        ]);

        if (!$payment) {
            $omnipayService->logInfo("Payment ID ({$paymentId}) not found.", 'cancel');
            throw $this->createNotFoundException('Payment not found.');
        }

        $omnipayService->logInfo("Payment ({$paymentId}) CANCELED.", 'cancel');

        if ($paymentId) {
            $this->paymentUpdateStatus($paymentId, Payment::STATUS_CANCELED);
        }

        return $this->redirect($omnipayService->getConfigUrl('fail'));
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
