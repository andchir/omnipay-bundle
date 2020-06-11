<?php

namespace Andchir\OmnipayBundle\Controller;

use Andchir\OmnipayBundle\Document\PaymentInterface;
use Andchir\OmnipayBundle\Repository\OrderRepositoryInterface;
use Andchir\OmnipayBundle\Repository\PaymentRepositoryInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Persistence\ObjectRepository;
use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\AbstractRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Andchir\OmnipayBundle\Service\OmnipayService;
use App\Controller\Admin\OrderController;
use App\Service\SettingsService;
use App\MainBundle\Document\Payment;
use App\MainBundle\Document\Setting;
use App\MainBundle\Document\User;
use App\MainBundle\Document\Order;
use Symfony\Contracts\Translation\TranslatorInterface;

class DefaultController extends AbstractController
{

    /** @var ParameterBagInterface */
    protected $params;
    /** @var DocumentManager */
    protected $dm;
    /** @var TranslatorInterface */
    protected $translator;
    /** @var SettingsService $settingsService */
    protected $settingsService;
    /** @var EventDispatcherInterface $eventDispatcher */
    protected $eventDispatcher;
    /** @var OmnipayService $omnipayService */
    protected $omnipayService;

    public function __construct(
        ParameterBagInterface $params,
        DocumentManager $dm,
        TranslatorInterface $translator,
        SettingsService $settingsService,
        EventDispatcherInterface $eventDispatcher,
        OmnipayService $omnipayService
    )
    {
        $this->params = $params;
        $this->dm = $dm;
        $this->translator = $translator;
        $this->settingsService = $settingsService;
        $this->eventDispatcher = $eventDispatcher;
        $this->omnipayService = $omnipayService;
    }

    /**
     * @Route("/omnipay_start/{id}", name="omnipay_start")
     * @param Request $request
     * @param Order $order
     * @return Response|NotFoundHttpException|AccessDeniedException
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function indexAction(Request $request, Order $order)
    {
        $output = '';

        /** @var User $user */
        $user = $this->getUser();
        $userId = $user ? $user->getId() : 0;
        if ($order->getUserId() !== $userId || $order->getIsPaid()) {
            throw $this->createAccessDeniedException();
        }
        $gatewayName = $order->getPaymentValue();
        
        if (!$gatewayName || !$this->omnipayService->create($gatewayName)) {
            $this->omnipayService->logInfo("Payment gateway ({$gatewayName}) not found. Order ID: {$order->getId()}", 'start');
            throw $this->createNotFoundException('Payment gateway not found.');
        };

        $paymentDescription = 'Order #' . $order->getId();

        // Create payment
        $payment = new Payment();
        $payment
            ->setUserId($userId)
            ->setEmail($order->getEmail())
            ->setOrderId($order->getId())
            ->setCurrency($order->getCurrency())
            ->setAmount($order->getPrice())
            ->setDescription($paymentDescription)
            ->setStatus(PaymentInterface::STATUS_CREATED)
            ->setOptions(['gatewayName' => $gatewayName]);

        $this->dm->persist($payment);
        $this->dm->flush();

        $this->omnipayService->initialize($payment);

        if ($this->omnipayService->getIsPrefersAuthorize()) {
            $output = $this->omnipayService->authorizeRequest($payment, $paymentDescription);
        } else {
            $this->omnipayService->sendPurchase($payment);
        }

        return new Response($output);
    }

    /**
     * @Route("/omnipay_return", name="omnipay_return")
     * @param Request $request
     * @return Response
     */
    public function returnAction(Request $request)
    {
        /** @var PaymentInterface $payment */
        $payment = $this->omnipayService->getPaymentByRequest($request, $this->dm);
        if (!$payment || !$this->getOrder($payment)) {
            $this->omnipayService->logInfo('Order not found. ', 'return');
            $this->logRequestData($request, 0, 'return');
            return new Response('');
        } else if ($payment->getStatus() !== PaymentInterface::STATUS_CREATED) {
            return new Response('');
        }

        $gatewayName = $payment->getOptionValue('gatewayName');
        if (!$gatewayName || !$this->omnipayService->create($gatewayName)) {
            $this->omnipayService->logInfo("Payment gateway ({$gatewayName}) not found.", 'return');
            return new Response('');
        };

        $this->logRequestData($request, $payment->getId(), 'return');

        $orderData = $this->omnipayService->getGatewayConfigParameters($payment, 'complete');

        try {

            $response = $this->omnipayService->getGateway()->authorize($orderData)->send();
            $responseData = $response->getData();

            if ($response->isSuccessful()){
                $this->omnipayService->logInfo('PAYMENT SUCCESS. ' . $response->getMessage(), 'return');
                return $this->createResponse($response->getMessage());
            } else if ($response->isRedirect()) {
                $this->omnipayService->logInfo('PAYMENT REDIRECT. ' . json_encode($responseData), 'return');
                $response->redirect();
            } else {
                $this->omnipayService->logInfo("PAYMENT FAIL. MESSAGE: {$response->getMessage()}" . json_encode($responseData), 'return');
                $this->paymentUpdateStatus($payment->getId(), $payment->getEmail(), PaymentInterface::STATUS_ERROR);
                return $this->createResponse($response->getMessage());
            }

        } catch (\Exception $e) {
            $this->omnipayService->logInfo('OMNIPAY ERROR: '. $e->getMessage(), 'return');
        }

        return new Response('');
    }

    /**
     * @Route("/omnipay_notify", name="omnipay_notify")
     * @param Request $request
     * @return Response|RedirectResponse
     */
    public function notifyAction(Request $request)
    {
        $this->logRequestData($request, 0, 'notify');// LOGGING

        /** @var PaymentInterface $payment */
        $payment = $this->omnipayService->getPaymentByRequest($request, $this->dm);
        if (!$payment) {
            $paymentData = $request->getSession()->get('paymentData');
            $paymentId = !empty($paymentData['transactionId'])
                ? (int) $paymentData['transactionId']
                : 0;
            $paymentEmail = !empty($paymentData['email'])
                ? $paymentData['email']
                : '';
            $payment = $this->getPayment($paymentId, $paymentEmail);
        } else if ($payment->getStatus() !== PaymentInterface::STATUS_CREATED) {
            $payment = null;
        }
        if (!$payment || !$this->getOrder($payment)) {
            $this->omnipayService->logInfo('Order not found. ', 'notify');
            $this->logRequestData($request, 0, 'notify');// LOGGING
            return new Response('');
        }

        $gatewayName = $payment->getOptionValue('gatewayName');
        if (!$gatewayName || !$this->omnipayService->create($gatewayName)) {
            $this->omnipayService->logInfo("Payment gateway ({$gatewayName}) not found.", 'notify');
            return new Response('');
        };

        $orderData = $this->omnipayService->getGatewayConfigParameters($payment, 'complete');

        $this->logRequestData($request, $payment->getId(), 'notify');// LOGGING

        try {
            $response = $this->omnipayService->getGateway()->completePurchase($orderData)->send();
            $responseData = $response->getData();

            if ($response->isSuccessful()){
                $this->logRequestData($request, $payment->getId(), 'success');// LOGGING
                $this->paymentUpdateStatus($payment->getId(), $payment->getEmail(), PaymentInterface::STATUS_COMPLETED);
                $this->setOrderPaid($payment);

                $message = $response->getMessage();
                if (!$message) {
                    return new RedirectResponse($this->omnipayService->getConfigUrl('success'));
                }
                return $this->createResponse($message);
            }
            if ($response->isRedirect()) {
                $this->logRequestData($request, $payment->getId(), 'redirect');// LOGGING
                $response->redirect();
            }
            if (!$response->isSuccessful()){
                $this->omnipayService->logInfo("PAYMENT FAIL. ERROR: {$response->getMessage()} " . json_encode($responseData), 'notify');
                $this->paymentUpdateStatus($payment->getId(), $payment->getEmail(), PaymentInterface::STATUS_ERROR);

                $message = $response->getMessage();
                if (!$message) {
                    return new RedirectResponse($this->omnipayService->getConfigUrl('fail'));
                }
                return $this->createResponse($message);
            }

        } catch (\Exception $e) {
            $this->omnipayService->logInfo('OMNIPAY ERROR: ' . $e->getMessage(), 'notify');
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
        $paymentData = $request->getSession()->get('paymentData');
        $paymentId = !empty($paymentData['transactionId'])
            ? (int) $paymentData['transactionId']
            : 0;

        $this->logRequestData($request, $paymentId, 'cancel');

        return $this->redirect($this->omnipayService->getConfigUrl('fail'));
    }

    /**
     * Update order status in Shopkeeper app
     * Update order status
     * @param PaymentInterface $payment
     * @return bool
     * @throws \Doctrine\ODM\MongoDB\LockException
     * @throws \Doctrine\ODM\MongoDB\Mapping\MappingException
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function setOrderPaid(PaymentInterface $payment)
    {
        $paymentStatusAfterNumber = (int) $this->params->get('app.payment_status_after_number');
        /** @var Setting $statusSetting */
        $statusSetting = $this->settingsService->getOrderStatusByNumber($paymentStatusAfterNumber);
        if (!$statusSetting) {
            return false;
        }
        $orderController = new OrderController(
            $this->params,
            $this->dm,
            $this->translator,
            $this->eventDispatcher,
            $this->settingsService
        );
        return $orderController->updateItemProperty(
            $payment->getOrderId(),
            'status',
            $statusSetting->getName()
        );
    }

    /**
     * Get payment object
     * @param int $paymentId
     * @param string $customerEmail
     * @param null $statusName
     * @return object
     */
    public function getPayment($paymentId, $customerEmail, $statusName = null)
    {
        if (!$statusName) {
            $statusName = PaymentInterface::STATUS_CREATED;
        }
        return $this->getRepository()->findOneBy([
            'id' => $paymentId,
            'email' => $customerEmail,
            'status' => $statusName
        ]);
    }

    /**
     * @param PaymentInterface $payment
     * @return object
     */
    public function getOrder(PaymentInterface $payment)
    {
        return $this->getOrderRepository()->findOneBy([
            'id' => $payment->getOrderId(),
            'userId' => $payment->getUserId(),
            'email' => $payment->getEmail()
        ]);
    }

    /**
     * @param int $paymentId
     * @param string $customerEmail
     * @param string $statusName
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function paymentUpdateStatus($paymentId, $customerEmail, $statusName)
    {
        /** @var PaymentInterface $payment */
        $payment = $this->getPayment($paymentId, $customerEmail);
        if (!$payment) {
            return;
        }
        $payment->setStatus($statusName);
        $this->dm->flush();
    }

    /**
     * @param Request $request
     * @param $paymentId
     * @param string $source
     */
    public function logRequestData(Request $request, $paymentId = 0, $source = 'request')
    {
        $postData = $request->request->all() ?: [];
        $getData = $request->query->all() ?: [];
        $message = $paymentId
            ? "Payment ({$paymentId}). REQUEST DATA: "
            : 'REQUEST DATA: ';
        $this->omnipayService->logInfo(
            $message . json_encode(array_merge($postData, $getData)),
            $source
        );
    }

    /**
     * @param $content
     * @return Response
     */
    public function createResponse($content)
    {
        $response = new Response($content);
        if (strpos($content, '<?xml') === 0) {
            $response->headers->set('Content-Type', 'application/xml');
        } else {
            $response->headers->set('Content-Type', 'text/html');
        }
        return $response;
    }

    /**
     * @return OrderRepositoryInterface|ObjectRepository
     */
    public function getOrderRepository()
    {
        return $this->dm->getRepository(Order::class);
    }

    /**
     * @return PaymentRepositoryInterface|ObjectRepository
     */
    public function getRepository()
    {
        return $this->dm->getRepository(Payment::class);
    }
}
