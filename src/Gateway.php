<?php

namespace Omnipay\Verifone;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\CreditCard;

use Omnipay\Verifone\ProcessMsgResponse;
use Omnipay\Verifone\ClientHeader;
use Omnipay\Verifone\ProcessMsg;
use Omnipay\Verifone\Message;

use Omnipay\Verifone\TransactionResponse;
use Omnipay\Verifone\PaymentChargeRequest;
use Omnipay\Verifone\PaymentAuthRequest;
use Omnipay\Verifone\PaymentResult;

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

use App\Models\Payment;

use Zend\Soap\Client;

class Gateway extends AbstractGateway
{

    private $client;

    private $systemId;
    private $systemPasscode;
    private $systemGUID;

    private $accountId;
    private $accountPasscode;
    private $accountGUID;
    private $testMode;

    private $ProcessingDB;

    public function __construct()
    {

        $defaults = config('omnipay.defaults');

        $this->testMode = $defaults['testMode'];

        if(!$this->testMode)
        {
            $this->client = new Client(config('omnipay.client'));
        } else {
            $this->client = new Client(config('omnipay.testClient'));
        }

    }

    public function getName()
    {

        return 'Verifone';

    }

    public function getDefaultParameters()
    {

        return [
            'accountId' => config('omnipay.accountId'),
            'accountGUID' => config('omnipay.accountGUID'),
            'testMode' => $this->testMode,
        ];

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

    /*
     * Authorize amount on the given card
     */
    public function authorise(CreditCard $card, Payment $payment)
    {

        $ecomTrans = new EcommerceTransactionRequestMessage(
            config('omnipay.accountId'), //Account id
            config('omnipay.accountPasscode'), //Account passcode
            str_replace(" ", "", $card->getNumber()), //pan
            $card->getCvv(), //csc / cvv
            $card->getExpiryDate('ym'), //expiry date (in yymm format, for some reason)
            $payment->TotalAmount //txn amount
        );

        $ecomTrans->setMerchantReference($payment->TransactionId);

        $transReq = new TransactionRequest(
            config('omnipay.systemId'), //System id
            config('omnipay.systemGUID'), //System GUID
            config('omnipay.systemPasscode'), //System passcode
            $ecomTrans
        );

        $response = $this->send($transReq);

        // @todo This should probably be set on the property txnMsg then the client can call getTxnMsg()
        // and still access the debug info.
        // e.g. $response->setTxnRespMsg(new TransactionResponseMessage($response->getMessageData());
        // and then the client can call $response->getTxnMsg(); and still call $response->GetDebugInfo();
        // or the response object just figures out the correct message to instantiate. Mmm, who's responsible for what??
        // or the response message has the whole response object set for retrieval.
        return new TransactionResponseMessage($response->getMessageData());

    }

    /**
     * Capture a previously authorised transaction
     *
     * @param TransactionResponseMessage $transactionResponseMessage
     * @return TransactionResponseMessage
     */
    public function capture(TransactionResponseMessage $transactionResponseMessage)
    {

        $transactionConfirmationRequestMessage = new TransactionConfirmationRequestMessage($transactionResponseMessage->getTransactionId());

        $transactionConfirmationRequest = new TransactionConfirmationRequest(
            config('omnipay.systemId'), //System id
            config('omnipay.systemGUID'), //System GUID
            config('omnipay.systemPasscode'), //System passcode
            $transactionResponseMessage->getProcessingDb(),
            $transactionConfirmationRequestMessage
        );

        $transactionConfirmationResponse = $this->send($transactionConfirmationRequest);

        return new TransactionResponseMessage($transactionConfirmationResponse->getMessageData());

    }

    /*
     * Authorize and capture payment on the given card in one method
     */
    public function purchase(CreditCard $card, Payment $payment)
    {

        $transaction = $this->authorise($card, $payment);

        return $this->capture($transaction);

    }

    /**
     * Reject transaction.
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
            config('omnipay.systemId'), //System id
            config('omnipay.systemGUID'), //System GUID
            config('omnipay.systemPasscode'), //System passcode
            $transactionResponseMessage->getProcessingDb(),
            $transactionRejectionRequestMessage
        );
        $transactionRejectionResponse = $this->send($transactionRejectionRequest);
        return new TransactionResponseMessage($transactionRejectionResponse->getMessageData());
    }


    /**
     * Send a message to the Verifone WS.
     *
     * @param RequestInterface $request
     * @return Response
     */
    protected function send(RequestInterface $request)
    {

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

        return $response;

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

}
