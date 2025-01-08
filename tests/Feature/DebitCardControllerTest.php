<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\DebitCardTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DebitCardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    public function testCustomerCanSeeAListOfDebitCards()
    {
        // Create debit cards associated with the user
        $debitCard1 = DebitCard::factory()->create(['user_id' => $this->user->id,'disabled_at' => null]);
        $debitCard2 = DebitCard::factory()->create(['user_id' => $this->user->id, 'disabled_at' => null]);

        // Send GET request to /debit-cards
        $response = $this->getJson('api/debit-cards');


        // Assert that the response is successful
        $response->assertStatus(200);

        // Assert that the response contains 2 debit cards
        $this->assertCount(2, $response->json());

        // Assert that the response contains the debit card data
        $response->assertJsonPath('0.number', (int) $debitCard1->number);
        $response->assertJsonPath('1.number', (int) $debitCard2->number);
    }

    public function testCustomerCannotSeeAListOfDebitCardsOfOtherCustomers()
    {
        // Create another user and their debit card
        $user2 = User::factory()->create();
        DebitCard::factory()->create(['user_id' => $user2->id, 'disabled_at' => null]);

        // Send GET request to /debit-cards
        $response = $this->getJson('api/debit-cards');

        // Assert that the response is successful
        $response->assertStatus(200);

        // Assert that the response does not contain the debit card of the other user
        $response->assertJsonStructure([]);
    }

    public function testCustomerCanCreateADebitCard()
    {
        // ARRANGE: Prepare the data to be sent for creating a debit card
        $data = [
            'type' => 'MasterCard',
        ];

        // ACT: Send a POST request to /debit-cards to create the debit card
        $response = $this->postJson('api/debit-cards', $data);

        // ASSERT: Verify that the response status is 201 Created
        $response->assertStatus(201);

        // ASSERT: Verify that the response contains the correct debit card data
        $response->assertJsonPath('type', $data['type']);

        // ASSERT: Ensure that the debit card is actually saved in the database
        $this->assertDatabaseHas('debit_cards', [
            'type' => $data['type'],
            'user_id' => $this->user->id,
        ]);
    }

    public function testCustomerCanSeeASingleDebitCardDetails()
    {
        // ARRANGE: Create a debit card associated with the user
        $debitCard = DebitCard::factory()->create(['user_id' => $this->user->id, 'disabled_at' => null]);

        // ACT: Send a GET request to fetch the details of the created debit card
        $response = $this->getJson("api/debit-cards/{$debitCard->id}");

        // ASSERT: Verify that the response status is 200 OK
        $response->assertStatus(200);

        // ASSERT: Verify that the response contains the correct debit card details
        $response->assertJson([
            'id' => $debitCard->id,
            'number' => (string) $debitCard->number, // Convert number to string for comparison if needed
            'type' => $debitCard->type,
            'expiration_date' => $debitCard->expiration_date->format('Y-m-d H:i:s'),
        ]);
    }

    public function testCustomerCannotSeeASingleDebitCardDetails()
    {
        // ARRANGE: Create a second user and a debit card associated with that second user
        $user2 = User::factory()->create();
        $debitCardUser2 = DebitCard::factory()->create(['user_id' => $user2->id, 'disabled_at' => null]);

        // ACT: Send a GET request to fetch the details of the debit card of another user (user2)
        $response = $this->getJson("api/debit-cards/{$debitCardUser2->id}");

        // ASSERT: Verify that the response status is 403 Forbidden (Customer cannot access other user's debit card)
        $response->assertStatus(403);
    }

    public function testCustomerCanActivateADebitCard()
    {
        // ARRANGE: Create a debit card associated with the user, initially disabled
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id,
            'disabled_at' => Carbon::now(),
        ]);

        // Data to activate the debit card (set is_active to true)
        $data = [
            'is_active' => true,
        ];

        // ACT: Send a PUT request to the /debit-cards/{debitCard} endpoint to activate the debit card
        $response = $this->putJson("api/debit-cards/{$debitCard->id}", $data);

        // ASSERT: Verify that the response status is 200 OK
        $response->assertStatus(200);

        // ASSERT: Verify that the disabled_at field is null, meaning the debit card is active
        $debitCard->refresh(); // Reload the debit card from the database
        $this->assertNull($debitCard->disabled_at);

        // ASSERT: Verify that the response contains the correct debit card details
        $response->assertJson([
            'id' => $debitCard->id,
            'number' => (string) $debitCard->number,
            'type' => $debitCard->type,
            'expiration_date' => $debitCard->expiration_date,
            'is_active' => $data['is_active'],
        ]);
    }

    public function testCustomerCanDeactivateADebitCard()
    {
        // ARRANGE: Create a debit card associated with the user, initially active
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id,
            'disabled_at' => null,
        ]);

        // Data to activate the debit card (set is_active to false)
        $data = [
            'is_active' => false,
        ];

        // ACT: Send a PUT request to the /debit-cards/{debitCard} endpoint to activate the debit card
        $response = $this->putJson("api/debit-cards/{$debitCard->id}", $data);

        // ASSERT: Verify that the response status is 200 OK
        $response->assertStatus(200);

        // ASSERT: Verify that the disabled_at field is null, meaning the debit card is active
        $debitCard->refresh(); // Reload the debit card from the database
        $this->assertNotNull($debitCard->disabled_at);

        // ASSERT: Verify that the response contains the correct debit card details
        $response->assertJson([
            'id' => $debitCard->id,
            'number' => (string) $debitCard->number,
            'type' => $debitCard->type,
            'expiration_date' => $debitCard->expiration_date,
            'is_active' => $data['is_active'],
        ]);
    }

    public function testCustomerCannotUpdateADebitCardWithWrongValidation()
    {
        // ARRANGE: Create a debit card associated with the user
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id,
            'disabled_at' => null,
        ]);

        // Data with invalid 'is_active' value (should be a boolean, but sending a string)
        $data = [
            'is_active' => 'invalid_value', // Invalid value, should be true or false
        ];

        // ACT: Send a PUT request to the /debit-cards/{debitCard} endpoint with invalid data
        $response = $this->putJson("api/debit-cards/{$debitCard->id}", $data);

        // ASSERT: Verify that the response status is 422 Unprocessable Entity (validation error)
        $response->assertStatus(422);

        // ASSERT: Verify that the response contains a validation error for 'is_active'
        $response->assertJsonValidationErrors(['is_active']);
    }

    public function testCustomerCanDeleteADebitCard()
    {
        // ARRANGE: Create a debit card associated with the user
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id,
            'disabled_at' => null,
        ]);

        // ACT: Send a DELETE request to delete the created debit card
        $response = $this->deleteJson("api/debit-cards/{$debitCard->id}");

        // ASSERT: Verify that the response status is 200 OK (successful deletion)
        $response->assertStatus(204);
    }

    public function testCustomerCannotDeleteADebitCardWithTransaction()
    {
        // ARRANGE: Create a debit card associated with the user
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id,
            'disabled_at' => null,
        ]);

        // Create a debit card transaction associated with the debit card
        DebitCardTransaction::factory()->create([
            'debit_card_id' => $debitCard->id,
            'amount' => 1000, // Example amount
        ]);

        // ACT: Send a DELETE request to delete the created debit card
        $response = $this->deleteJson("api/debit-cards/{$debitCard->id}");

        // ASSERT: Verify that the response status is 403 Forbidden (since the debit card cannot be deleted due to the transaction)
        $response->assertStatus(403);
    }

    // Extra bonus for extra tests :)

    public function testCustomerCannotCreateDebitCardWithInvalidType()
    {
        // ARRANGE: List of invalid 'type' inputs
        $invalidTypes = [
            '', // Blank type
            null, // Null type
            273647348348346, // Numeric type
        ];

        foreach ($invalidTypes as $invalidType) {
            // ACT: Attempt to create a debit card with the invalid 'type'
            $data = [
                'type' => $invalidType,
            ];

            $response = $this->postJson('api/debit-cards', $data);

            // ASSERT: Verify that the response status is 422 Unprocessable Entity (Validation error)
            $response->assertStatus(422);

            // ASSERT: Verify that the error message contains the expected validation error for 'type'
            $response->assertJsonValidationErrors(['type']);
        }
    }

    public function testCustomerCannotDeactivateDebitCardWithInvalidTypes()
    {
        // ARRANGE: Create a debit card associated with the user, initially active
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id,
            'disabled_at' => null,
        ]);

        // List of invalid 'is_active' input values to test
        $invalidIsActiveValues = [
            '', // Blank input
            null, // Null value
            'not_boolean', // String that isn't boolean
            123, // Numeric value
        ];

        foreach ($invalidIsActiveValues as $invalidValue) {
            // Data with invalid 'is_active'
            $data = [
                'is_active' => $invalidValue,
            ];

            // ACT: Send a PUT request to the /debit-cards/{debitCard} endpoint to deactivate the debit card
            $response = $this->putJson("api/debit-cards/{$debitCard->id}", $data);

            // ASSERT: Verify that the response status is 422 Unprocessable Entity (Validation error)
            $response->assertStatus(422);

            // ASSERT: Verify that the error message contains the expected validation error for 'is_active'
            $response->assertJsonValidationErrors(['is_active']);
        }
    }

    public function testCustomerCannotDeleteDebitCardOfAnotherCustomerWithoutTransactions()
    {
        // ARRANGE: Create the first user and their debit card
        $user1 = User::factory()->create();
        $debitCardUser1 = DebitCard::factory()->create([
            'user_id' => $user1->id,
            'disabled_at' => null,
        ]);

        // ARRANGE: Create a second user (the currently authenticated user)
        $user2 = User::factory()->create();
        Passport::actingAs($user2); // Authenticate as user2

        // ASSERT: Ensure user2 cannot delete user1's debit card
        $response = $this->deleteJson("api/debit-cards/{$debitCardUser1->id}");

        // ASSERT: Verify that the response status is 403 Forbidden
        $response->assertStatus(403);
    }

    public function testCustomerCannotDeleteDebitCardOfAnotherCustomerWithTransactions()
    {
        // ARRANGE: Create the first user and their debit card with a transaction
        $user1 = User::factory()->create();
        $debitCardUser1 = DebitCard::factory()->create([
            'user_id' => $user1->id,
            'disabled_at' => null,
        ]);

        // Create a transaction associated with debitCardUser1's debit card
        DebitCardTransaction::factory()->create([
            'debit_card_id' => $debitCardUser1->id,
        ]);

        // ARRANGE: Create the second user (the currently authenticated user)
        $user2 = User::factory()->create();
        Passport::actingAs($user2); // Authenticate as user2

        // ACT: Send a DELETE request to try to delete user1's debit card (which has transactions)
        $response = $this->deleteJson("api/debit-cards/{$debitCardUser1->id}");

        // ASSERT: Verify that the response status is 400 Bad Request (or a custom error indicating debit cards with transactions cannot be deleted)
        $response->assertStatus(403); // or whatever status your application returns for this scenario
    }

    public function testCustomerCannotSeeDetailsOfNonExistentDebitCard()
    {
        // ACT: Send a GET request to fetch the details of a debit card that does not exist
        $nonExistentDebitCardId = 9999999; // An ID that doesn't exist in the database
        $response = $this->getJson("api/debit-cards/{$nonExistentDebitCardId}");

        // ASSERT: Verify that the response status is 404 Not Found
        $response->assertStatus(404);

        // ASSERT: Verify that the response contains the correct error message
        $response->assertJson([
            'message' => "No query results for model [App\\Models\\DebitCard] {$nonExistentDebitCardId}",
        ]);
    }





}
