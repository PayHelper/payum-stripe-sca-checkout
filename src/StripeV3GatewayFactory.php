<?php
namespace Combodo\StripeV3;

use Combodo\StripeV3\Action\Api\CreateTokenAction;
use Combodo\StripeV3\Action\Api\ObtainTokenAction;
use Combodo\StripeV3\Action\Api\PollFullfilledPaymentsAction;
use Combodo\StripeV3\Action\AuthorizeAction;
use Combodo\StripeV3\Action\CheckoutCompletedEventAction;
use Combodo\StripeV3\Action\ConvertPaymentAction;
use Combodo\StripeV3\Action\CaptureAction;
use Combodo\StripeV3\Action\FindLostPaymentsAction;
use Combodo\StripeV3\Action\HandleLostPaymentsAction;
use Combodo\StripeV3\Action\NotifyAction;
use Combodo\StripeV3\Action\NotifyUnsafeAction;
use Combodo\StripeV3\Action\RefundAction;
use Combodo\StripeV3\Action\StatusAction;
use Combodo\StripeV3\Action\SubscriptionCancelledAction;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;

class StripeV3GatewayFactory extends GatewayFactory
{
    const FACTORY_NAME = 'stripe_sca_checkout';

    /**
     * {@inheritDoc}
     */
    protected function populateConfig(ArrayObject $config)
    {
        $config->defaults([
            'payum.factory_name' => static::FACTORY_NAME,
            'payum.factory_title' => 'Stripe checkout V3',

            'payum.template.obtain_token' => '@CombodoStripeV3/redirect_to_checkout.html.twig',

            'payum.action.capture' => new CaptureAction(),                              // standard action
            'payum.action.convert_payment' => new ConvertPaymentAction(),               // standard action, required by \Sylius\Bundle\PayumBundle\Action\CapturePaymentAction
            'payum.action.status' => new StatusAction(),                                // standard action

            'payum.action.obtain_token' => function (ArrayObject $config) {             // stripe specific action
                return new ObtainTokenAction($config['payum.template.obtain_token']);
            },        // stripe specific action + injection of configuration!

            'payum.action.notify_unsafe' => new NotifyUnsafeAction(),                   // modified standard action to handle "unsafe" ie without the token webhooks

            'payum.action.poll_fullfilled_payements' => new PollFullfilledPaymentsAction(), // custom action
            'payum.action.handle_lost_payements'     => new HandleLostPaymentsAction(),     // custom action
            'payum.action.chackout_completed'        => new CheckoutCompletedEventAction(), // custom action
            'payum.action.subscription_cancelled'    => new SubscriptionCancelledAction(), // custom action
            'payum.action.refund'                    => new RefundAction(),
        ]);

        if (false == $config['payum.api']) {
            $config['payum.default_options'] = [
                'publishableKey' => '',
                'secretKey' => '',
            ];
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = ['publishableKey', 'secretKey'];

            $config['payum.api'] = function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);

                return new Keys($config['publishableKey'], $config['secretKey']);
            };
        }

        $config['payum.paths'] = array_replace([
            'CombodoStripeV3' => __DIR__.'/../templates',
        ], $config['payum.paths'] ?: []);
    }
}
