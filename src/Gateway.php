<?php

namespace Omnipay\Verifone;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\CreditCard;

use Omnipay\Verifone\Request\RequestInterface;
use Omnipay\Verifone\Request\TransactionConfirmationRequest;
use Omnipay\Verifone\Request\TransactionRejectionRequest;
use Omnipay\Verifone\Request\TransactionRequest;

use Omnipay\Verifone\RequestMessage\EcommerceTransactionRequestMessage;
use Omnipay\Verifone\RequestMessage\TransactionConfirmationRequestMessage;
use Omnipay\Verifone\RequestMessage\TransactionRejectionRequestMessage;
use Omnipay\Verifone\RequestMessage\TransactionRequestMessage;
use Omnipay\Verifone\RequestMessage\TransactionRequestMessageInterface;

use Omnipay\Verifone\ResponseMessage\AbstractResponseMessage;
use Omnipay\Verifone\ResponseMessage\ErrorResponseMessage;
use Omnipay\Verifone\ResponseMessage\TransactionResponseMessage;

use Zend\Soap\Client;

class Gateway extends AbstractGateway
{

    private $client;
    private $testMode;
    private $requestWS;
    private $verifoneConfig;
    private $ProcessingDB;

    public function __construct()
    {

        $defaults = config('omnipay.defaults');

        $this->testMode = $defaults['testMode'];

        $this->verifoneConfig = config('omnipay.verifone');

        if(!$this->testMode)
        {
            $this->client = new Client($this->verifoneConfig['client']);
        } else {
            $this->client = new Client($this->verifoneConfig['testClient']);
        }

    }

    public function getName()
    {

        return 'Verifone';

    }

    public function getDefaultParameters()
    {

        return [
            'accountId' => $this->verifoneConfig['accountId'],
            'accountGUID' => $this->verifoneConfig['accountGUID'],
            'testMode' => $this->testMode,
        ];

    }

    /*
     * Authorize amount on the given card
     */
    public function authorise($options)
    {

        $card = $options['card'];
        $amount = $options['amount'];
        $transactionId = $options['transactionId'];

        $ecomTrans = new EcommerceTransactionRequestMessage(
            $this->verifoneConfig['accountId'], //Account id
            $this->verifoneConfig['accountPasscode'], //Account passcode (seems to be ok if empty)
            str_replace(" ", "", $card->getNumber()), //pan
            $card->getCvv(), //csc / cvv
            $card->getExpiryDate('ym'), //expiry date (in yymm format, for some reason)
            $amount //txn amount
        );

        $ecomTrans->setMerchantReference($transactionId);

        $transReq = new TransactionRequest(
            $this->verifoneConfig['systemId'], //System id
            $this->verifoneConfig['systemGUID'], //System GUID
            $this->verifoneConfig['systemPasscode'], //System passcode
            $ecomTrans
        );

        $this->requestWS = $transReq;

        return $this;

    }

    /**
     * Capture an authorised transaction
     *
     * @param TransactionResponseMessage $transactionResponseMessage
     * @return TransactionResponseMessage
     */
    public function capture(TransactionResponseMessage $transactionResponseMessage)
    {

        $transactionConfirmationRequestMessage = new TransactionConfirmationRequestMessage($transactionResponseMessage->getTransactionId());

        $transactionConfirmationRequest = new TransactionConfirmationRequest(
            $this->verifoneConfig['systemId'], //System id
            $this->verifoneConfig['systemGUID'], //System GUID
            $this->verifoneConfig['systemPasscode'], //System passcode
            $transactionResponseMessage->getProcessingDb(),
            $transactionConfirmationRequestMessage
        );

        $this->requestWS = $transactionConfirmationRequest;

        return $this;

    }

    /*
     * Authorize and capture payment on the given card in one method
     */
    public function purchase($options)
    {

        $response = $this->authorise($options)->send();

        if($response->isSuccessful())
		{

            return $this->capture($response);

		}

        //If unsuccessful
        return new TransactionResponseMessage($response->getData());

    }

    /**
     * Reject transaction? Not properly implemented into Ominpay yet, beware!
     *
     * @param TransactionResponseMessage $transactionResponseMessage
     * @param string $pan
     * @return TransactionResponseMessage
     */
    public function reject(TransactionResponseMessage $transactionResponseMessage, $pan)
    {
        $transactionRejectionRequestMessage = new TransactionRejectionRequestMessage(
            $transactionResponseMessage->getTransactionId(),
            (string) $pan
        );
        $transactionRejectionRequest = new TransactionRejectionRequest(
            $this->verifoneConfig['systemId'], //System id
            $this->verifoneConfig['systemGUID'], //System GUID
            $this->verifoneConfig['systemPasscode'], //System passcode
            $transactionResponseMessage->getProcessingDb(),
            $transactionRejectionRequestMessage
        );

        $this->requestWS = $transactionRejectionResponse;

        return $this;

    }

    /**
     * Send a message to the Verifone WS.
     *
     * @param RequestInterface $request
     * @return Response
     */
    public function send()
    {

        $request = $this->requestWS;

        $rawResponse = $this->client->ProcessMsg(
            $request->getMessage()
        );

        //Log the request?
        $response = new Response($rawResponse, $this->client);
        if (isset($this->logger) && $this->logger instanceof LoggerInterface) {
            $this->logger->info(
                $this->maskCreditCardNumber($response->getDebugInfo())
            );
        }

        return new TransactionResponseMessage($response->getMessageData());

    }

    /**
     * Searches for and masks a credit card number in a string of text.
     *
     * @param string $string
     * @return mixed
     */
    protected function maskCreditCardNumber($string)
    {
        return preg_replace('/(\d{6})(\d+)(\d{4})/', '$1****$3', $string);
    }

    /**
     * Extracts the integers from a string.
     *
     * @param string $string
     * @return int
     */
    protected function extractIntegersFromString($string)
    {
        $integers = [];
        $stringLength = strlen($string);
        for($i = 0; $i < $stringLength; $i++){
            if (is_numeric($string[$i])) {
                $integers[] = $string[$i];
            }
        }
        return (int) implode('', $integers);
    }

    public function setTestMode($boolean = true)
    {

        $this->testMode = $boolean;
        //Test SOAP details
        if(!$this->testMode)
        {
            $this->client = new Client(config('omnipay.client'));
        } else {
            $this->client = new Client(config('omnipay.testClient'));
        }
    }

}
