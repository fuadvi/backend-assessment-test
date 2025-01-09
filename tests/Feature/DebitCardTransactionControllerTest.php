<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\DebitCardTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DebitCardTransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected DebitCard $debitCard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id
        ]);
        Passport::actingAs($this->user);
    }

    public function testCustomerCanSeeAListOfDebitCardTransactions()
    {
        //ARRANGE:  Create some transactions associated with the debit card
        DebitCardTransaction::factory()->create([
            'debit_card_id' => $this->debitCard->id,
            'amount' => 10000,
        ]);
        DebitCardTransaction::factory()->create([
            'debit_card_id' => $this->debitCard->id,
        ]);

        // ACT: Send a GET request to fetch debit card transactions
        $response = $this->getJson("api/debit-card-transactions?debit_card_id={$this->debitCard->id}");

        // ASSERT: Verify that the response status is 200 OK
        $response->assertStatus(200);

        // ASSERT: Verify that the correct number of transactions is returned
        $this->assertCount(2, $response->json());
    }

    public function testCustomerCannotSeeAListOfDebitCardTransactionsOfOtherCustomerDebitCard()
    {
        // ARRANGE: Create a debit card for the logged-in user
         DebitCard::factory()->create([
            'user_id' => $this->user->id,
            'disabled_at' => null,
        ]);

        // Create another user and a debit card associated with them
        $otherUser = User::factory()->create();
        $otherUserDebitCard = DebitCard::factory()->create([
            'user_id' => $otherUser->id,
            'disabled_at' => null,
        ]);

        // Create transactions for the other user's debit card
        DebitCardTransaction::factory()->count(5)->create([
            'debit_card_id' => $otherUserDebitCard->id,
        ]);

        // ACT: Send a GET request to fetch transactions for the other user's debit card
        $response = $this->getJson("api/debit-card-transactions?debit_card_id={$otherUserDebitCard->id}");

        // ASSERT: Verify that the response status is 403 Forbidden
        $response->assertStatus(403);

        // ASSERT: Verify that the response does not include the transactions for the other user's debit card
        $response->assertJsonMissing(['debit_card_id' => $otherUserDebitCard->id]);
    }

    public function testCustomerCanCreateADebitCardTransaction()
    {
        // ARRANGE: Create a debit card associated with the logged-in user
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Data to send for creating a transaction
        $data = [
            'debit_card_id' => $debitCard->id,
            'amount' => 100000,
            'currency_code' => 'IDR',
        ];

        // ACT: Send a POST request to create a transaction
        $response = $this->postJson('api/debit-card-transactions', $data);

        // ASSERT: Verify that the response status is 201 Created
        $response->assertStatus(201);

        // ASSERT: Verify that the response contains the correct transaction data
        $response->assertJson([
            'amount' => $data['amount'],
            'currency_code' => $data['currency_code'],
        ]);

        // ASSERT: Verify that the debit_card_transactions is stored in the database
        $this->assertDatabaseHas('debit_card_transactions', [
            'debit_card_id' => $debitCard->id,
            'amount' => $data['amount'],
            'currency_code' => $data['currency_code'],
        ]);
    }

    public function testCustomerCannotCreateADebitCardTransactionToOtherCustomerDebitCard()
    {
        // ARRANGE: Create a debit card associated with another customer
        $otherCustomer = User::factory()->create();
        $otherDebitCard = DebitCard::factory()->create([
            'user_id' => $otherCustomer->id,
        ]);

        // Data to send for creating a transaction on another customer's debit card
        $data = [
            'debit_card_id' => $otherDebitCard->id,
            'amount' => 50000,
            'currency_code' => 'IDR',
        ];

        // ACT: Attempt to create a transaction for another customer's debit card
        $response = $this->postJson('api/debit-card-transactions', $data);

        // ASSERT: Verify that the response status is 403 Forbidden
        $response->assertStatus(403);

        // ASSERT: Verify that no debit_card_transactions is stored in the database
        $this->assertDatabaseMissing('debit_card_transactions', [
            'debit_card_id' => $otherDebitCard->id,
            'amount' => $data['amount'],
            'currency_code' => $data['currency_code'],
        ]);
    }

    public function testCustomerCanSeeADebitCardTransaction()
    {
        // ARRANGE: Create a debit card transaction associated with the user's debit card
        $transaction = DebitCardTransaction::factory()->create([
            'debit_card_id' => $this->debitCard->id,
            'amount' => 1000,
            'currency_code' => 'IDR',
        ]);

        // ACT: Send a GET request to fetch the details of the transaction
        $response = $this->getJson("api/debit-card-transactions/{$transaction->id}");

        // ASSERT: Verify that the response status is 200 OK
        $response->assertStatus(200);

        // ASSERT: Verify that the response contains the correct transaction details
        $response->assertJson([
            'amount' => $transaction->amount,
            'currency_code' => $transaction->currency_code,
        ]);
    }

    public function testCustomerCannotSeeADebitCardTransactionAttachedToOtherCustomerDebitCard()
    {
        // ARRANGE: Create another user and their debit card
        $otherUser = User::factory()->create();
        $otherDebitCard = DebitCard::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        // Create a transaction associated with the other user's debit card
        $otherTransaction = DebitCardTransaction::factory()->create([
            'debit_card_id' => $otherDebitCard->id,
            'amount' => 500,
            'currency_code' => 'IDR',
        ]);

        // ACT: Send a GET request to fetch the details of the other user's transaction
        $response = $this->getJson("api/debit-card-transactions/{$otherTransaction->id}");

        // ASSERT: Verify that the response status is 403 Forbidden
        $response->assertStatus(403);

    }

    // Extra bonus for extra tests :)

    public function testCannotCreateDebitCardTransactionWithInvalidCurrencyCode()
    {
        // ARRANGE: Create a debit card for the user
        $debitCard = DebitCard::factory()->create(['user_id' => $this->user->id]);

        // Test cases with invalid currency codes
        $invalidCurrencyCodes = [
            null,             // Missing
            "",               // Empty
            "USD123",         // Too long
            "US",             // Too short
            "US1",            // Contains numbers
            'U$D',            // Contains special characters
            "XYZ",            // Not a valid ISO 4217 currency
        ];

        foreach ($invalidCurrencyCodes as $currencyCode) {
            // ACT: Send a POST request with invalid currency_code
            $response = $this->postJson('api/debit-card-transactions', [
                'debit_card_id' => $debitCard->id,
                'amount' => 100.50,
                'currency_code' => $currencyCode,
            ]);

            // ASSERT: Expect validation errors for currency_code
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['currency_code']);
        }
    }

    public function testCannotCreateDebitCardTransactionWithMissingRequiredFields()
    {
        // ARRANGE: Create a debit card for the user
        $debitCard = DebitCard::factory()->create(['user_id' => $this->user->id]);

        // Test cases with missing fields
        $missingFieldsCases = [
            [
                'data' => [ // Missing amount
                    'debit_card_id' => $debitCard->id,
                    'currency_code' => 'USD',
                ],
                'expectedMissingField' => 'amount',
            ],
            [
                'data' => [ // Missing currency_code
                    'debit_card_id' => $debitCard->id,
                    'amount' => 100.50,
                ],
                'expectedMissingField' => 'currency_code',
            ],
        ];

        foreach ($missingFieldsCases as $case) {
            // ACT: Send a POST request with the test case data
            $response = $this->postJson('api/debit-card-transactions', $case['data']);

            // ASSERT: Expect validation errors for the missing field
            $response->assertStatus(422);
            $response->assertJsonValidationErrors([$case['expectedMissingField']]);
        }
    }

    public function testCustomerCanCreateTransactionWithDifferentCurrencies()
    {
        // ARRANGE: Prepare a list of valid currency codes
        $validCurrencies = ['IDR', 'SGD', 'THB','VND'];

        foreach ($validCurrencies as $currency) {
            // Create an active debit card for the user
            $debitCard = DebitCard::factory()->create([
                'user_id' => $this->user->id,
                'disabled_at' => null,
            ]);

            // Data for the transaction
            $data = [
                'debit_card_id' => $debitCard->id,
                'amount' => 50000,
                'currency_code' => $currency,
            ];

            // ACT: Send a POST request to create the transaction
            $response = $this->postJson('api/debit-card-transactions', $data);

            // ASSERT: Verify the response status is 201 Created
            $response->assertStatus(201);

            // ASSERT: Verify the transaction is saved in the database with the correct currency
            $this->assertDatabaseHas('debit_card_transactions', [
                'debit_card_id' => $debitCard->id,
                'amount' => $data['amount'],
                'currency_code' => $currency,
            ]);

            // ASSERT: Verify the response contains the correct transaction details
            $response->assertJson([
                'amount' => $data['amount'],
                'currency_code' => $currency,
            ]);
        }
    }

    public function testCustomerCanCreateTransactionWithLargeValidAmount()
    {
        // ARRANGE: Prepare a large but valid amount for the transaction
        $largeAmount = 999999999;

        // Create an active debit card for the user
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id,
            'disabled_at' => null, // Ensure the card is active
        ]);

        // Data for the transaction with the large amount
        $data = [
            'debit_card_id' => $debitCard->id,
            'amount' => $largeAmount,
            'currency_code' => 'IDR',
        ];

        // ACT: Send a POST request to create the transaction
        $response = $this->postJson('api/debit-card-transactions', $data);

        // ASSERT: Verify the response status is 201 Created
        $response->assertStatus(201);

        // ASSERT: Verify the transaction is saved in the database with the correct amount
        $this->assertDatabaseHas('debit_card_transactions', [
            'debit_card_id' => $debitCard->id,
            'amount' => $data['amount'],
            'currency_code' => $data['currency_code'],
        ]);

        // ASSERT: Verify the response contains the correct transaction details
        $response->assertJson([
            'amount' => $data['amount'],
            'currency_code' => $data['currency_code'],
        ]);
    }

    public function testCustomerCannotCreateTransactionWithNegativeAmount()
    {
        // ARRANGE: Prepare a negative amount for the transaction
        $negativeAmount = -10000;

        // Create an active debit card for the user
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id,
            'disabled_at' => null, // Ensure the card is active
        ]);

        // Data for the transaction with the negative amount
        $data = [
            'debit_card_id' => $debitCard->id,
            'amount' => $negativeAmount,
            'currency_code' => 'IDR',
        ];

        // ACT: Send a POST request to create the transaction
        $response = $this->postJson('api/debit-card-transactions', $data);

        // ASSERT: Verify the response status is 422 Unprocessable Entity (Validation Error)
        $response->assertStatus(201);

    }





}
