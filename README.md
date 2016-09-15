# omnipay-verifone
Verifone (Commidea) Package for Omnipay

Send settings to the client with the usual Omnipay setSetting('value') methods

### Example of use:

#### Set up gateway and card
```
use Omnipay\Omnipay;
use Omnipay\Common\CreditCard;

class WhateverController extends Controller
{

//Set up gateway
$verifone = Omnipay::create('Verifone');

$cardDetails = [
	'title' => 'Mr',
	'firstName' => 'Loopy',
	'surname' => 'Rabbit',
	'number' => '1234123412341234',
	'cvv' => '927',
	'expiryMonth' => '6',
	'expiryYear' => '18'
];

//Create card
$card = new CreditCard($cardDetails);

$transactionId = '123456'; //Create a transaction id
$amount = '1'; //How much is payment for?

//Validate card (will return an error response if invalid, with some error details)
$validateResult = $card->validate();
```

#### Method 1: Authorise and capture separately:

```
//Authorise
$response = $verifone->authorise([
	'card' => $card,
	'amount' => $amount,
	'transactionId' => $transactionId
])->send();

if($response->isSuccessful())
{
	//Capture
	$transactionResponse = $verifone->capture($response)->send();

	if($transactionResponse->isSuccessful())
	{
		return $transactionResponse->getData();
	}

    //If capture not successful
	return $transactionResponse->getMessage();

}

return $response->getMessage();
```

#### Method 2: Authorise and capture in one go:

```
$transactionResponse = $verifone->purchase([
    'card' => $card,
    'amount' => $amount,
    'transactionId' => $transactionId
    ])->send();

if($transactionResponse->isSuccessful())
{
	return $transactionResponse->getData();
}

return $transactionResponse->getMessage();
```
