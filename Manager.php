<?php
/*
 * This file is part of the Snowcap OgoneBundle package.
 *
 * (c) Snowcap <shoot@snowcap.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Snowcap\OgoneBundle;

use Monolog\Logger;

use Ogone\Passphrase;
use Ogone\PaymentRequest;
use Ogone\PaymentResponse;

use Ogone\ShaComposer\AllParametersShaComposer;
use Ogone\ShaComposer\ShaComposer;
use Ogone\ParameterFilter\ShaInParameterFilter;
use Ogone\ParameterFilter\ShaOutParameterFilter;
use Ogone\FormGenerator\FormGenerator;

class Manager
{
    protected $pspid;
    protected $environment;
    protected $shaIn;
    protected $shaOut;
    protected $options = array();

    protected $listeners = array();

    /** @var Logger */
    protected $logger;

    /**
     * @var FormGenerator
     */
    protected $formGenerator;

    /**
     * @param Logger        $logger
     * @param FormGenerator $formGenerator
     * @param string        $pspid
     * @param string        $environment
     * @param string        $shaIn
     * @param string        $shaOut
     * @param array         $options
     */
    public function __construct(Logger $logger, FormGenerator $formGenerator, $pspid, $environment, $shaIn, $shaOut, $options = array())
    {
        if ($pspid === "") {
            throw new \Exception('No PSPID defined for Ogone');
        }
        if ($environment !== "test" && $environment !== "prod") {
            throw new \Exception(sprintf('No valid Ogone environment ("test" or "prod"), "%s" given', $environment));
        }
        if ($shaIn === "") {
            throw new \Exception('No SHA-IN passphrase defined for Ogone');
        }
        if ($shaOut === "") {
            throw new \Exception('No SHA-OUT passphrase defined for Ogone');
        }

        $this->logger = $logger;
        $this->formGenerator = $formGenerator;
        $this->pspid = $pspid;
        $this->environment = $environment;
        $this->shaIn = new Passphrase($shaIn);
        $this->shaOut = new Passphrase($shaOut);
        $this->options = $options;
    }

    public function getRequestForm($locale, $orderId, $customerName, $amount, $currency = "EUR", $options = array())
    {
        $passphrase = $this->shaIn;
        $shaComposer = new AllParametersShaComposer($passphrase);
        $shaComposer->addParameterFilter(new ShaInParameterFilter); //optional

        $paymentRequest = new PaymentRequest($shaComposer);

        switch ($this->environment) {
            case 'prod':
                $paymentRequest->setOgoneUri(PaymentRequest::PRODUCTION);
                break;
            default:
                $paymentRequest->setOgoneUri(PaymentRequest::TEST);
                break;
        }

        $paymentRequest->setPspid($this->pspid);
        $paymentRequest->setCn($customerName);
        $paymentRequest->setOrderid($orderId);
        $paymentRequest->setAmount((int) ($amount * 100));
        $paymentRequest->setCurrency($currency);

        // setting options defined in config
        foreach ($this->options as $option => $value) {
            $setter = "set" . $option;
            $paymentRequest->$setter($value);
        }

        // setting options defined in method call
        foreach ($options as $option => $value) {
            $setter = "set" . $option;
            $paymentRequest->$setter($value);
        }

        $paymentRequest->setLanguage($this->localeToIso($locale));

        $paymentRequest->validate();

        return $this->formGenerator->render($paymentRequest);
    }

    public function paymentResponse($parameters)
    {
        $paymentResponse = new PaymentResponse($parameters);

        $passphrase = $this->shaOut;
        $shaComposer = new AllParametersShaComposer($passphrase);
        $shaComposer->addParameterFilter(new ShaOutParameterFilter); //optional

        if ($paymentResponse->isValid($shaComposer) && $paymentResponse->isSuccessful()) {
            foreach ($this->listeners as $listener) {
                $listener->onOgoneSuccess($parameters);
            }
            // handle payment confirmation
            $this->logger->info('success');
        } else {
            // perform logic when the validation fails
            foreach ($this->listeners as $listener) {
                $listener->onOgoneFailure($parameters);
            }
            $this->logger->info('failure');
        }
    }

    private function localeToIso($locale)
    {
        switch ($locale) {
            case 'fr':
                return 'fr_FR';
                break;
            case 'nl':
                return 'nl_NL';
                break;
            case 'en':
                return 'en_US';
                break;
            default:
                return $locale;
                break;
        }
    }

    public function addListener($listener)
    {
        $this->listeners[] = $listener;
    }
}