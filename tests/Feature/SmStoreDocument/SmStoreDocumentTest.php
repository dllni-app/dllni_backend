<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmStoreDocumentFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists store documents', function (): void {
    SmStoreDocumentFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-store-documents?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates a store document', function (): void {
    $store = SmStoreFactory::new()->create();

    $payload = [
        'storeId' => $store->id,
        'documentType' => 'identity',
        'filePath' => 'documents/test.pdf',
        'verificationStatus' => 'pending',
    ];

    $response = $this->postJson('/api/v1/sm-store-documents', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_store_documents', ['store_id' => $store->id, 'file_path' => 'documents/test.pdf']);
});

it('updates a store document', function (): void {
    $document = SmStoreDocumentFactory::new()->create(['verification_status' => 'pending']);

    $payload = [
        'verificationStatus' => 'approved',
    ];

    $response = $this->putJson("/api/v1/sm-store-documents/{$document->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_store_documents', ['id' => $document->id, 'verification_status' => 'approved']);
});

it('deletes a store document', function (): void {
    $document = SmStoreDocumentFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-store-documents/{$document->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_store_documents', ['id' => $document->id]);
});
