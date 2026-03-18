<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('authenticated user can upload contract pdf', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('contrato.pdf', 256, 'application/pdf');

    $response = $this->actingAs($user)
        ->post(route('contracts.upload'), [
            'contract' => $file,
        ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'file_name' => 'contrato.pdf',
        ]);

    $filePath = $response->json('file_path');

    expect($filePath)->not->toBeEmpty();

    Storage::disk('local')->assertExists($filePath);
});
