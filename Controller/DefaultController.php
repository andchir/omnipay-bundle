<?php

namespace Andchir\OmnipayBundle\Controller;

use Andchir\OmnipayBundle\Service\OmnipayService;
use AppBundle\Document\User;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
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
        if ($order->getUserId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }
        $gatewayName = $order->getPaymentValue();

        /** @var OmnipayService $omnipay */
        $omnipay = $this->get('omnipay');
        if (!$gatewayName || !$omnipay->create($gatewayName)) {
            throw $this->createNotFoundException('Payment gateway not found.');
        };

        $paymentId = 1;
        $currency = 'RUB';
        $paymentDescription = '';

        $siteUrl = $request->getSchemeAndHttpHost() . $request->getBaseUrl();
        $omnipay->setConfigOptions([
            'returnUrl' => sprintf('%s/omnipay_return/%s', $siteUrl, $gatewayName),
            'cancelUrl' => sprintf('%s/omnipay_cancel/%s', $siteUrl, $gatewayName),
            'notifyUrl' => sprintf('%s/omnipay_notify/%s', $siteUrl, $gatewayName)
        ]);

        $purchase = $omnipay->createPurchase(
            $paymentId,
            $paymentDescription,
            [
                'amount' => OmnipayService::toDecimal($order->getPrice()),
                'currency' => $currency
            ]
        );
        $response = $purchase->send();

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
}
